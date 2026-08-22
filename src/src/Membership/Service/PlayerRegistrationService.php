<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Repository\UserRepository;
use App\Account\Service\EmailVerificationService;
use App\Membership\Dto\PlayerRegistered;
use App\Membership\Dto\PlayerRegistrationInput;
use App\Membership\Entity\ShareLink;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Enum\RedemptionOutcome;
use App\Membership\Exception\ShareLinkNotUsable;
use App\Membership\Mail\MembershipMailer;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\Repository\TrainerPlayerAssociationRepository;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\PlayerProfileRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates an account, a player profile and a trainer association from one form submit
 * (FR-042, Flow 1).
 *
 * Everything the flow writes commits together (NFR-041): the account, the profiles, the
 * association and the redemption record. A half-registered visitor — an account with no
 * trainer, or a trainer association pointing at a profile that was never created — is the one
 * outcome this service must never produce, because the email address is then taken and the
 * visitor cannot simply try again.
 *
 * The account holder always gets a profile of their own, even when they are registering a
 * child: US-01.03 treats the parent as a player too, and FR-044's "Me" checkbox needs
 * something to point at when this family redeems their next link. Only the person who will
 * actually train is associated with the trainer.
 *
 * Mail is dispatched after the transaction commits, per the epic's side-effect rule. A bounced
 * confirmation must not roll back a created account: the mail can be re-sent, the account
 * cannot be re-created — its address is taken by the very row that would have been discarded.
 */
final readonly class PlayerRegistrationService
{
    public function __construct(
        private UserRepository $users,
        private PlayerProfileRepository $profiles,
        private TrainerPlayerAssociationRepository $associations,
        private ShareLinkRepository $links,
        private RedemptionRecorder $redemptions,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationService $emailVerification,
        private MembershipMailer $mailer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private bool $emailVerificationRequired,
    ) {
    }

    /**
     * @throws EmailAlreadyRegistered
     * @throws ShareLinkNotUsable
     */
    public function registerViaShareLink(ShareLink $link, PlayerRegistrationInput $input): PlayerRegistered
    {
        $email = (string) $input->email;

        if (null !== $this->users->findOneByEmail($email)) {
            throw EmailAlreadyRegistered::forEmail($email);
        }

        $now = $this->clock->now();
        $user = new User($email, (string) $input->name, UserRole::Player, $now);
        $user->setPhone($input->phone);
        $user->setStatus(UserStatus::Active);
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $input->plainPassword));

        $self = PlayerProfile::forSelf($user, (string) $input->name, $now);
        $child = $input->registeringChild
            ? PlayerProfile::forChildOf($user, (string) $input->playerName, $now)
            : null;

        $trainee = $child ?? $self;
        $trainee->setBirthDate($input->birthDate, $now);
        $trainee->setGender($input->gender, $now);

        try {
            $this->entityManager->wrapInTransaction(function () use ($link, $user, $self, $child, $trainee, $now): void {
                if (!$this->links->consume($link, $now)) {
                    throw ShareLinkNotUsable::code($link->getCode());
                }

                $this->users->add($user);
                $this->profiles->add($self);

                if (null !== $child) {
                    $this->profiles->add($child);
                }

                $this->associations->add(new TrainerPlayerAssociation(
                    $link->getOrganization(),
                    $trainee,
                    $link,
                    $now,
                ));

                $this->redemptions->record($link, $user, RedemptionOutcome::NewAccount);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException $e) {
            // The lookup above cannot rule out a concurrent registration with the same
            // address; the unique index can. This is the race NFR-041 asks about, and losing
            // it produces a clear "that email is taken" rather than a second account.
            throw EmailAlreadyRegistered::forEmail($email, $e);
        }

        $verificationRequired = $this->emailVerificationRequired;

        return new PlayerRegistered(
            $user,
            $trainee,
            $verificationRequired,
            $this->sendWelcome($user, $link, $trainee, $verificationRequired),
        );
    }

    /**
     * @return bool whether every message reached the transport
     */
    private function sendWelcome(User $user, ShareLink $link, PlayerProfile $trainee, bool $verificationRequired): bool
    {
        try {
            $this->mailer->sendRegistrationConfirmation(
                $user,
                $link->getOrganization()->getName(),
                $trainee->getDisplayName(),
                $verificationRequired,
            );

            // A player cannot sign in until the address is confirmed while the gate is on
            // (Q-01.05), so the verification link is part of the same welcome, not a separate
            // step the user has to discover.
            if ($verificationRequired) {
                $this->emailVerification->sendVerification($user);
            }

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Registration confirmation could not be sent.', [
                'userId' => $user->getId(),
                'shareLinkId' => $link->getId(),
                'exception' => $e,
            ]);

            return false;
        }
    }
}
