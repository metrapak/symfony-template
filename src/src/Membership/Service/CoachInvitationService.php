<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Repository\UserRepository;
use App\Account\Service\EmailVerificationService;
use App\Membership\Dto\CoachInvited;
use App\Membership\Dto\CoachInviteInput;
use App\Membership\Dto\CoachRegistered;
use App\Membership\Dto\CoachRegistrationInput;
use App\Membership\Entity\CoachAssignment;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\RedemptionOutcome;
use App\Membership\Enum\ShareLinkType;
use App\Membership\Exception\CoachAlreadyAssignedElsewhere;
use App\Membership\Exception\ShareLinkNotUsable;
use App\Membership\Mail\MembershipMailer;
use App\Membership\Repository\CoachAssignmentRepository;
use App\Membership\Repository\ShareLinkRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The coach half of the invitation system (FR-041, FR-045, FR-046, BR-044).
 *
 * BR-044 — "a coach may be active under only one trainer at a time" — is enforced three
 * times over, and that is deliberate rather than redundant:
 *
 *  1. At invitation, so the trainer learns immediately instead of after a coach has spent
 *     time registering (spec §US-01.08, "If coach already exists: cannot be active under a
 *     different trainer").
 *  2. At acceptance, which is the moment the rule is actually about.
 *  3. By a partial unique index on `coach_assignment`, which is the only one of the three
 *     that holds under concurrency and for callers that never heard of this service — a
 *     fixture, a console command, next year's code.
 *
 * FR-045 asks for exactly that: "enforcement is a database constraint plus a service check,
 * not UI-only".
 */
final readonly class CoachInvitationService
{
    public function __construct(
        private ShareLinkGenerator $generator,
        private ShareLinkRepository $links,
        private CoachAssignmentRepository $assignments,
        private UserRepository $users,
        private RedemptionRecorder $redemptions,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationService $emailVerification,
        private MembershipMailer $mailer,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private bool $emailVerificationRequired,
    ) {
    }

    /**
     * Issues an invitation and emails it (FR-041).
     *
     * An outstanding invitation to the same address is re-issued rather than duplicated, so
     * the Coaches list stays one line per invited coach and an impatient trainer clicking
     * "Invite" twice does not produce two live codes for one person.
     *
     * @throws CoachAlreadyAssignedElsewhere
     */
    public function invite(Organization $organization, User $trainer, CoachInviteInput $input): CoachInvited
    {
        $email = mb_strtolower(trim((string) $input->email));

        $this->assertAvailable($email);

        $outstanding = $this->links->findActiveCoachInvitationTo((int) $organization->getId(), $email);
        $reusable = null !== $outstanding && null === $this->assignments->findOneByShareLink($outstanding);

        if ($reusable) {
            /** @var ShareLink $outstanding */
            $link = $this->generator->reissue($outstanding);
            // Re-issuing replaces the code and the window; a name or message the trainer
            // changed on this second attempt has to be written over the old ones explicitly.
            $link->addressTo($email, $input->name, $input->message);
        } else {
            $link = $this->generator->createCoachLink($organization, $trainer, $email, $input->name, $input->message);
        }

        $this->entityManager->flush();

        return new CoachInvited($link, $this->dispatchInvitation($link, $trainer));
    }

    /**
     * Sends the invitation again with a fresh code and a fresh seven-day window (FR-046).
     */
    public function resend(ShareLink $link, User $trainer): CoachInvited
    {
        $this->generator->reissue($link);
        $this->entityManager->flush();

        return new CoachInvited($link, $this->dispatchInvitation($link, $trainer));
    }

    /**
     * Attaches a coach who already has an account (FR-045).
     *
     * Idempotent for the organization that already employs them: re-opening a link they have
     * already accepted is a no-op success, the same rule FR-043 sets for players.
     *
     * @throws CoachAlreadyAssignedElsewhere
     * @throws ShareLinkNotUsable
     */
    public function accept(ShareLink $link, User $coach): CoachAssignment
    {
        $this->assertCoachLink($link);

        $existing = $this->assignments->findActiveForCoach($coach);

        if (null !== $existing) {
            if ($existing->getOrganization()->getId() === $link->getOrganization()->getId()) {
                return $existing;
            }

            throw CoachAlreadyAssignedElsewhere::forCoach($coach->getEmail());
        }

        $now = $this->clock->now();
        $assignment = new CoachAssignment($link->getOrganization(), $coach, $link, $now);

        try {
            $this->entityManager->wrapInTransaction(function () use ($link, $coach, $assignment, $now): void {
                if (!$this->links->consume($link, $now)) {
                    throw ShareLinkNotUsable::code($link->getCode());
                }

                $this->assignments->add($assignment);
                $this->redemptions->record($link, $coach, RedemptionOutcome::Association);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException $e) {
            // The partial unique index caught what the read above could not: another
            // organization's acceptance committed in between.
            throw CoachAlreadyAssignedElsewhere::forCoach($coach->getEmail(), $e);
        }

        return $assignment;
    }

    /**
     * Creates a coach account from their invitation and attaches it in one transaction
     * (FR-045).
     *
     * @throws EmailAlreadyRegistered when the chosen address already has an account —
     *                                that coach should sign in and open the link instead
     * @throws CoachAlreadyAssignedElsewhere
     * @throws ShareLinkNotUsable
     */
    public function registerAndAccept(ShareLink $link, CoachRegistrationInput $input): CoachRegistered
    {
        $this->assertCoachLink($link);

        $email = mb_strtolower(trim((string) $input->email));

        if (null !== $this->users->findOneByEmail($email)) {
            throw EmailAlreadyRegistered::forEmail($email);
        }

        $this->assertAvailable($email);

        $now = $this->clock->now();
        $coach = new User($email, (string) $input->name, UserRole::Coach, $now);
        $coach->setPhone($input->phone);
        $coach->setStatus(UserStatus::Active);
        $coach->setPassword($this->passwordHasher->hashPassword($coach, (string) $input->plainPassword));

        $assignment = new CoachAssignment($link->getOrganization(), $coach, $link, $now);

        try {
            $this->entityManager->wrapInTransaction(function () use ($link, $coach, $assignment, $now): void {
                if (!$this->links->consume($link, $now)) {
                    throw ShareLinkNotUsable::code($link->getCode());
                }

                $this->users->add($coach);
                $this->assignments->add($assignment);
                $this->redemptions->record($link, $coach, RedemptionOutcome::NewAccount);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException $e) {
            throw EmailAlreadyRegistered::forEmail($email, $e);
        }

        if ($this->emailVerificationRequired) {
            $this->sendVerification($coach);
        }

        return new CoachRegistered($coach, $assignment, $this->emailVerificationRequired);
    }

    /**
     * @throws CoachAlreadyAssignedElsewhere
     */
    private function assertAvailable(string $email): void
    {
        $existing = $this->users->findOneByEmail($email);

        if (null === $existing) {
            return;
        }

        if (null !== $this->assignments->findActiveForCoach($existing)) {
            throw CoachAlreadyAssignedElsewhere::forCoach($email);
        }
    }

    private function assertCoachLink(ShareLink $link): void
    {
        if (ShareLinkType::Coach !== $link->getType()) {
            throw new \LogicException('This share link is not a coach invitation.');
        }
    }

    /**
     * Dispatched outside the transaction: a bounced invitation must not roll back the code it
     * describes, because the trainer's remedy is to resend it, not to invent it again.
     */
    private function dispatchInvitation(ShareLink $link, User $trainer): bool
    {
        $joinUrl = $this->urlGenerator->generate(
            'membership_join',
            ['code' => $link->getCode()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $this->mailer->sendCoachInvitation($link, $trainer->getDisplayName(), $joinUrl);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Coach invitation could not be sent.', [
                'shareLinkId' => $link->getId(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function sendVerification(User $coach): void
    {
        try {
            $this->emailVerification->sendVerification($coach);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Coach verification email could not be sent.', [
                'userId' => $coach->getId(),
                'exception' => $e,
            ]);
        }
    }
}
