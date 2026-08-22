<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserDeletionRecord;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\CannotModifyOwnAccount;
use App\Account\Exception\InvalidStatusTransition;
use App\Account\Exception\LastSuperAdminProtected;
use App\Account\Repository\UserDeletionRecordRepository;
use App\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The irreversible half of the removal model: GDPR erasure by anonymization (FR-025, FR-026,
 * FR-027, BR-023, BR-024).
 *
 * **The user row is never deleted.** Overwriting the personal fields in place is what makes
 * FR-026 hold: every attendance row, payment and analytics aggregate keeps pointing at the
 * same id, so the counts, sums and rates the platform reports are numerically unchanged by an
 * erasure, and each of them renders "Deleted User" because they all read `getDisplayName()`.
 * A `DELETE` — or a cascade — would take the history with it and quietly change last quarter's
 * revenue figure.
 *
 * The personal *media* — photographs, a coach's bio, the family's emergency contacts — is
 * cleared through `PersonalDataEraser`, which is the resolution of G-19: when this service was
 * written there were no such columns, and TASK-004 added them. The files themselves are unlinked
 * after the commit, because an unlink is the one step in an erasure that cannot be rolled back.
 */
final readonly class UserAnonymizer
{
    public const ANONYMOUS_NAME = 'Deleted User';
    public const ANONYMOUS_EMAIL_FORMAT = 'deleted_%d@example.com';

    public function __construct(
        private UserRepository $users,
        private UserDeletionRecordRepository $deletionRecords,
        private AuditLogger $auditLogger,
        private PersonalDataEraser $dataEraser,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CannotModifyOwnAccount
     * @throws LastSuperAdminProtected
     * @throws InvalidStatusTransition
     */
    public function anonymize(User $target, User $actor, string $reason): UserDeletionRecord
    {
        if ($target->getId() === $actor->getId()) {
            throw CannotModifyOwnAccount::forAction('delete');
        }

        if (UserStatus::Deleted === $target->getStatus()) {
            throw InvalidStatusTransition::between(UserStatus::Deleted, UserStatus::Deleted);
        }

        if (UserRole::SuperAdmin === $target->getRole()
            && UserStatus::Active === $target->getStatus()
            && 0 === $this->users->countActiveSuperAdmins(excluding: $target)
        ) {
            throw LastSuperAdminProtected::forAction('deleting it');
        }

        $userId = (int) $target->getId();
        $originalEmail = $target->getEmail();
        $anonymizedEmail = \sprintf(self::ANONYMOUS_EMAIL_FORMAT, $userId);
        $now = $this->clock->now();

        /** @var list<string> $orphanedFiles */
        $orphanedFiles = [];

        $record = $this->entityManager->wrapInTransaction(
            function () use ($target, $actor, $reason, $userId, $originalEmail, $anonymizedEmail, $now, &$orphanedFiles): UserDeletionRecord {
                $target->setName(self::ANONYMOUS_NAME);
                $target->setEmail($anonymizedEmail);
                $target->setPhone(null);
                $target->setStatus(UserStatus::Deleted);

                // Erasing the identity without erasing the credential would leave a working
                // password on the row. It can no longer be used to sign in — the status check
                // refuses first — but a hash that still verifies is personal data of its own
                // and has no reason to survive the request that removed everything else.
                $target->setPassword(bin2hex(random_bytes(32)));

                $target->markEmailUnverified();
                $target->setMustChangePassword(false);

                // FR-025's "photo → default avatar" and "personal identifiers → NULL", for the
                // columns TASK-004 added (G-19). Inside the transaction: an erasure that
                // anonymized the name and committed without the photograph would report success
                // while leaving the most identifying file on disk.
                $orphanedFiles = $this->dataEraser->erasePersonalDataFor($target);

                // Ends every live session the deleted user had, through the same mechanism a
                // password change uses (User::isEqualTo compares this stamp).
                $target->recordPasswordChange($now);
                $target->setUpdatedAt($now);

                $record = new UserDeletionRecord(
                    originalUserId: $userId,
                    originalEmailDigest: UserDeletionRecord::digestFor($originalEmail),
                    anonymizedEmail: $anonymizedEmail,
                    deletedBy: $actor,
                    reason: $reason,
                    deletedAt: $now,
                );

                $this->deletionRecords->add($record);

                // Deliberately carries no email address. The audit log is read far more
                // widely than the compliance record, and re-recording the address here would
                // reintroduce exactly what the operation removed (D8).
                $this->auditLogger->log($actor, AuditAction::UserAnonymized, $target, [
                    'originalUserId' => $userId,
                    'anonymizedEmail' => $anonymizedEmail,
                    'reason' => $reason,
                ]);

                return $record;
            },
        );

        // After the commit, never before: the rows that referenced these files have been
        // anonymized, so nothing points at them any more. Deleting first would destroy a
        // photograph that a rolled-back transaction still expected to be there.
        $this->dataEraser->deleteOrphanedFiles(...$orphanedFiles);

        return $record;
    }
}
