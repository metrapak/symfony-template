<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Dto\EditUserInput;
use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Exception\LastSuperAdminProtected;
use App\Account\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applies a Super Admin's edit to any account, including its role (FR-023).
 *
 * Changing either the email or the role de-authenticates the edited user's existing sessions,
 * because `User::isEqualTo()` compares both. That is the intended behavior for a demotion: a
 * trainer downgraded to coach must not keep browsing trainer pages until their session
 * happens to expire.
 */
final readonly class UserProfileEditor
{
    public function __construct(
        private UserRepository $users,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EmailAlreadyRegistered
     * @throws LastSuperAdminProtected
     */
    public function apply(User $target, EditUserInput $input, User $actor): void
    {
        $email = (string) $input->email;
        $role = $input->role ?? $target->getRole();

        $existing = $this->users->findOneByEmail($email);

        if (null !== $existing && $existing->getId() !== $target->getId()) {
            throw EmailAlreadyRegistered::forEmail($email);
        }

        // Demoting the last Super Admin is the same lockout as deactivating them (G-17), and
        // it is easier to do by accident because the role field is just another dropdown.
        if (UserRole::SuperAdmin === $target->getRole()
            && UserRole::SuperAdmin !== $role
            && UserStatus::Active === $target->getStatus()
            && 0 === $this->users->countActiveSuperAdmins(excluding: $target)
        ) {
            throw LastSuperAdminProtected::forAction('changing its role');
        }

        $before = [
            'email' => $target->getEmail(),
            'name' => $target->getName(),
            'role' => $target->getRole()->value,
        ];

        $now = $this->clock->now();

        try {
            $this->entityManager->wrapInTransaction(function () use ($target, $input, $email, $role, $actor, $before, $now): void {
                $target->setName((string) $input->name);
                $target->setEmail($email);
                $target->setPhone($input->phone);
                $target->setRole($role);
                $target->setUpdatedAt($now);

                $this->auditLogger->log($actor, AuditAction::UserUpdated, $target, [
                    'emailBefore' => $before['email'],
                    'emailAfter' => $target->getEmail(),
                    'nameBefore' => $before['name'],
                    'nameAfter' => $target->getName(),
                    'roleBefore' => $before['role'],
                    'roleAfter' => $target->getRole()->value,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The lookup above cannot rule out a concurrent write; the unique index can.
            throw EmailAlreadyRegistered::forEmail($email, $e);
        }
    }
}
