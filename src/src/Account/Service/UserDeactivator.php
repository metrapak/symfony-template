<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\CannotModifyOwnAccount;
use App\Account\Exception\InvalidStatusTransition;
use App\Account\Exception\LastSuperAdminProtected;
use App\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The reversible half of the removal model (FR-024, BR-022).
 *
 * Deactivation writes nothing but the status: every attendance row, payment and analytics
 * total the user appears in stays exactly where it was, which is the whole point of having
 * two removal verbs.
 *
 * The user is signed out of their existing sessions as a side effect of the status change:
 * TASK-001's `User::isEqualTo()` compares status, so `ContextListener` de-authenticates a
 * session whose copy of the user disagrees with the database on the next request. Without
 * that, "a deactivated user cannot log in" would only be true of new logins.
 *
 * The guards live here rather than in a voter because they are invariants of the account
 * model — a fixture or a console command must not be able to strand the platform either.
 */
final readonly class UserDeactivator
{
    public function __construct(
        private UserRepository $users,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CannotModifyOwnAccount
     * @throws LastSuperAdminProtected
     * @throws InvalidStatusTransition
     */
    public function deactivate(User $target, User $actor, ?string $reason = null): void
    {
        $this->assertNotSelf($target, $actor, 'deactivate');
        $this->assertNotLastSuperAdmin($target, 'deactivating it');
        $this->assertCanTransition($target, UserStatus::Inactive);

        $this->transition($target, $actor, UserStatus::Inactive, AuditAction::UserDeactivated, $reason);
    }

    /**
     * @throws InvalidStatusTransition when the account was anonymized — `Deleted` is terminal
     *                                 (BR-023), so reactivation is refused rather than
     *                                 restoring an account whose identity no longer exists
     */
    public function reactivate(User $target, User $actor): void
    {
        $this->assertCanTransition($target, UserStatus::Active);

        $this->transition($target, $actor, UserStatus::Active, AuditAction::UserReactivated, null);
    }

    private function transition(
        User $target,
        User $actor,
        UserStatus $status,
        AuditAction $action,
        ?string $reason,
    ): void {
        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($target, $actor, $status, $action, $reason, $now): void {
            $target->setStatus($status);
            $target->setUpdatedAt($now);

            $this->auditLogger->log($actor, $action, $target, [
                'email' => $target->getEmail(),
                'status' => $status->value,
                'reason' => $reason,
            ]);
        });
    }

    private function assertNotSelf(User $target, User $actor, string $action): void
    {
        if ($target->getId() === $actor->getId()) {
            throw CannotModifyOwnAccount::forAction($action);
        }
    }

    private function assertNotLastSuperAdmin(User $target, string $action): void
    {
        if (UserRole::SuperAdmin !== $target->getRole() || UserStatus::Active !== $target->getStatus()) {
            return;
        }

        if (0 === $this->users->countActiveSuperAdmins(excluding: $target)) {
            throw LastSuperAdminProtected::forAction($action);
        }
    }

    private function assertCanTransition(User $target, UserStatus $to): void
    {
        if (!$target->getStatus()->canTransitionTo($to)) {
            throw InvalidStatusTransition::between($target->getStatus(), $to);
        }
    }
}
