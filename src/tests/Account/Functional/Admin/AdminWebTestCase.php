<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Entity\AuditLogEntry;
use App\Account\Entity\ImpersonationSession;
use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Tests\Account\Functional\AccountWebTestCase;

/**
 * Shared setup for the Super Admin tooling tests: one signed-in operator and the assertions
 * about audit rows that nearly every test in this directory makes.
 */
abstract class AdminWebTestCase extends AccountWebTestCase
{
    protected const ADMIN_EMAIL = 'admin@example.com';

    protected function signInAsSuperAdmin(string $email = self::ADMIN_EMAIL, string $name = 'Ada Admin'): User
    {
        $admin = $this->createUser($email, UserRole::SuperAdmin, name: $name);
        $this->submitLogin($email);
        self::assertResponseRedirects();

        return $admin;
    }

    /**
     * @return list<AuditLogEntry>
     */
    protected function auditEntries(?AuditAction $action = null): array
    {
        $repository = $this->freshEntityManager()->getRepository(AuditLogEntry::class);

        return $repository->findBy(
            null !== $action ? ['action' => $action] : [],
            ['occurredAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * @return list<ImpersonationSession>
     */
    protected function impersonationSessions(): array
    {
        return $this->freshEntityManager()
            ->getRepository(ImpersonationSession::class)
            ->findBy([], ['startedAt' => 'ASC', 'id' => 'ASC']);
    }

    protected function assertUserStatus(string $email, UserStatus $expected): void
    {
        self::assertSame($expected, $this->reloadUser($email)->getStatus());
    }

    /**
     * The kernel reboots between requests, so entities loaded before one are detached
     * afterwards. Every read-back in these tests goes through a freshly resolved manager with
     * a cleared identity map, which also proves the write reached the database.
     */
    protected function freshEntityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }
}
