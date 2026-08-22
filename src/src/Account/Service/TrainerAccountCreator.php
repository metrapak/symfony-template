<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Dto\CreateTrainerInput;
use App\Account\Dto\TrainerCreated;
use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Mail\AccountMailer;
use App\Account\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Creates a trainer account, its organization and its first credential (FR-021, FR-022,
 * FR-033, BR-020).
 *
 * This is the only way a trainer comes into existence — there is no public registration route
 * and BR-020 forbids one, so the authorization for it lives entirely on `/admin`.
 */
final readonly class TrainerAccountCreator
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private TemporaryPasswordGenerator $temporaryPasswords,
        private AuditLogger $auditLogger,
        private AccountMailer $mailer,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EmailAlreadyRegistered
     */
    public function create(CreateTrainerInput $input, User $actor): TrainerCreated
    {
        $email = (string) $input->email;

        if (null !== $this->users->findOneByEmail($email)) {
            throw EmailAlreadyRegistered::forEmail($email);
        }

        $now = $this->clock->now();
        $temporaryPassword = $this->temporaryPasswords->generate();
        $businessName = (string) $input->businessName;

        $user = new User($email, (string) $input->name, UserRole::Trainer, $now);
        $user->setPhone($input->phone);
        $user->setStatus(UserStatus::Active);
        $user->setPassword($this->passwordHasher->hashPassword($user, $temporaryPassword));

        // FR-022: the temporary password must be replaced at first login. TASK-001's
        // RequirePasswordChangeSubscriber turns this flag into the actual redirect.
        $user->setMustChangePassword(true);

        // Created verified: an administrator typing the address is the same trust level as
        // the CLI-created Super Admin, and AccountStatusChecker already exempts trainers from
        // the verification gate — leaving it unverified would produce an account that is
        // "Active" in the directory and unable to sign in if that policy ever changes.
        $user->markEmailVerified($now);

        $organization = new Organization($businessName, $user, $now);

        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $organization, $actor, $businessName): void {
                $this->users->add($user);
                $this->entityManager->persist($organization);

                // Flushed inside the transaction so the audit entry can carry the new user's
                // id, and so FR-033's record commits with the account or not at all.
                $this->entityManager->flush();

                $this->auditLogger->log($actor, AuditAction::TrainerCreated, $user, [
                    'email' => $user->getEmail(),
                    'name' => $user->getName(),
                    'businessName' => $businessName,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The lookup above cannot rule out a concurrent insert; the unique index can.
            throw EmailAlreadyRegistered::forEmail($email, $e);
        }

        return new TrainerCreated($user, $this->sendInvitation($user, $temporaryPassword, $businessName));
    }

    /**
     * Dispatched after the transaction commits, per the epic rule for side effects that
     * cannot join one. A bounced invitation must not roll back a created account: the
     * invitation is re-sendable, and the account is not re-creatable — the email address is
     * taken by the very row that would have been discarded.
     *
     * @return bool whether the message reached the transport
     */
    private function sendInvitation(User $user, string $temporaryPassword, string $businessName): bool
    {
        $loginUrl = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $this->mailer->sendTrainerInvitation($user, $temporaryPassword, $loginUrl, $businessName);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Trainer invitation could not be sent.', [
                'userId' => $user->getId(),
                'exception' => $e,
            ]);

            return false;
        }
    }
}
