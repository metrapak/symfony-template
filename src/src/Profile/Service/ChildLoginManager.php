<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Repository\UserRepository;
use App\Profile\Dto\ChildLoginInput;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ChildLoginAlreadyExists;
use App\Profile\Exception\ProfileNotManaged;
use App\Profile\Exception\UsernameAlreadyTaken;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gives a child their own login, and takes it away again (FR-067, G-23).
 *
 * G-23 is the gap this closes. US-01.06 specifies what a child login may do in detail and never
 * says how one comes into existence; D-03 says they ship in MVP. The shape below is a decision,
 * and these are its parts:
 *
 *  - **The parent chooses a username, and the email is derived.** `User.email` is the login
 *    identifier and is unique and required, and a seven-year-old usually has no address. BR-064
 *    says the parent owns the family's contact information, so inventing an address for the
 *    child — rather than asking for one, or borrowing the parent's, which the unique index
 *    forbids — is the option consistent with the rest of the model. `UserRepository` accepts the
 *    username as an alternative identifier.
 *  - **The derived address is deliberately undeliverable.** `@children.invalid` is reserved by
 *    RFC 2606 and can never resolve, so nothing will ever accidentally send a child a
 *    verification link, a password reset, or a marketing email. The cost is stated plainly: a
 *    child cannot recover their own password, and the parent resets it. For an account the
 *    parent owns, that is the correct owner of the recovery path anyway.
 *  - **It is created email-verified.** The verification gate exists to prove somebody controls
 *    the address they claimed (Q-01.05); there is no claim here and no address to control. The
 *    proof that already happened is the parent's — they are signed in, their own address is
 *    verified, and they are the account's owner. Leaving the child unverified would mean
 *    creating a login that can never sign in.
 *  - **The child must change the password on first use**, through the mechanism TASK-001 built
 *    for administrator-created trainers. The parent typed it, so until it is changed it is a
 *    credential two people know.
 *  - **Revoking deactivates; it never deletes.** The child's associations, attendance and
 *    history hang off the profile and the account, and FR-026's rule that history survives
 *    applies to a nine-year-old leaving a club as much as to anyone. A deactivated account is
 *    refused by `AccountStatusChecker` on its next request with the message that requirement
 *    already specifies.
 *
 * The role is `ROLE_PLAYER`, the same as any player. FR-068's prohibitions are enforced by
 * `ChildActionVoter` reading the *profile*, not by a narrower role — because BR-065 defines a
 * child as somebody whose profile another account manages, and a role string cannot express
 * that relationship.
 */
final readonly class ChildLoginManager
{
    /**
     * RFC 2606 reserves `.invalid` precisely so that a name is guaranteed never to resolve.
     * Using it makes "this address does not receive mail" a property of the DNS rather than a
     * convention somebody could later break by pointing a real domain at it.
     */
    public const DERIVED_EMAIL_DOMAIN = 'children.invalid';

    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ChildLoginAlreadyExists
     * @throws ProfileNotManaged
     * @throws UsernameAlreadyTaken
     */
    public function enable(User $parent, PlayerProfile $child, ChildLoginInput $input): User
    {
        $this->assertManagedChild($parent, $child);

        if ($child->hasOwnLogin()) {
            throw ChildLoginAlreadyExists::forChild($child->getDisplayName());
        }

        $username = mb_strtolower(trim((string) $input->username));

        if (null !== $this->users->findOneByLoginUsername($username)) {
            throw UsernameAlreadyTaken::forUsername($username);
        }

        $now = $this->clock->now();

        // The address is derived from the username rather than from the profile id, because the
        // profile has no id until it is flushed and this account is created alongside it in the
        // same transaction. The username is already unique, so the address is too — the unique
        // index on `email` is what actually guarantees it.
        $account = new User(
            $username . '@' . self::DERIVED_EMAIL_DOMAIN,
            $child->getDisplayName(),
            UserRole::Player,
            $now,
        );
        $account->setLoginUsername($username);
        $account->setStatus(UserStatus::Active);
        $account->setPassword($this->passwordHasher->hashPassword($account, (string) $input->plainPassword));
        $account->setMustChangePassword(true);

        // See the class docblock: there is no address to verify, and an unverified player is
        // refused at the firewall while EMAIL_VERIFICATION_REQUIRED is on.
        $account->markEmailVerified($now);

        try {
            $this->entityManager->wrapInTransaction(function () use ($account, $child, $now): void {
                $this->users->add($account);
                $this->entityManager->flush();

                $child->attachLogin($account, $now);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException $e) {
            // The lookup above cannot rule out a concurrent claim of the same username; the
            // unique index can. Two parents naming their children the same thing at the same
            // moment is the race, and losing it produces "choose another one" rather than a
            // second account.
            throw UsernameAlreadyTaken::forUsername($username, $e);
        }

        return $account;
    }

    /**
     * Suspends a child's login without destroying it (FR-067).
     *
     * Deactivation rather than deletion, and rather than a `loginEnabled` flag of our own: the
     * account lifecycle already has exactly this state, the firewall already refuses it with the
     * right message (FR-009), and `User::isEqualTo()` already ends the child's live sessions on
     * their next request. A second mechanism would be a second thing to keep in step.
     *
     * @throws ProfileNotManaged
     */
    public function revoke(User $parent, PlayerProfile $child): void
    {
        $this->assertManagedChild($parent, $child);

        $account = $child->getAccount();

        if (null === $account || UserStatus::Active !== $account->getStatus()) {
            return;
        }

        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($account, $now): void {
            $account->setStatus(UserStatus::Inactive);
            // Ends the child's current session immediately rather than at session expiry, the
            // same way a password change does.
            $account->recordPasswordChange($now);
            $account->setUpdatedAt($now);

            $this->entityManager->flush();
        });
    }

    /**
     * Turns a suspended child login back on.
     *
     * @throws ProfileNotManaged
     */
    public function restore(User $parent, PlayerProfile $child): void
    {
        $this->assertManagedChild($parent, $child);

        $account = $child->getAccount();

        // `Deleted` is terminal (BR-006): an erased account is not brought back by a parent
        // toggling a switch.
        if (null === $account || !$account->getStatus()->canTransitionTo(UserStatus::Active)) {
            return;
        }

        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($account, $now): void {
            $account->setStatus(UserStatus::Active);
            $account->setUpdatedAt($now);

            $this->entityManager->flush();
        });
    }

    /**
     * Replaces a child's password (FR-067, and the recovery path a derived address removes).
     *
     * @throws ProfileNotManaged
     */
    public function resetPassword(User $parent, PlayerProfile $child, string $plainPassword): void
    {
        $this->assertManagedChild($parent, $child);

        $account = $child->getAccount();

        if (null === $account) {
            return;
        }

        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($account, $plainPassword, $now): void {
            $account->setPassword($this->passwordHasher->hashPassword($account, $plainPassword));
            $account->setMustChangePassword(true);
            $account->recordPasswordChange($now);
            $account->setUpdatedAt($now);

            $this->entityManager->flush();
        });
    }

    /**
     * @throws ProfileNotManaged
     */
    private function assertManagedChild(User $parent, PlayerProfile $child): void
    {
        if (!$child->isChild() || $child->getOwner()->getId() !== $parent->getId()) {
            throw ProfileNotManaged::create();
        }
    }
}
