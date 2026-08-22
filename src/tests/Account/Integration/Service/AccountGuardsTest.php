<?php

declare(strict_types=1);

namespace App\Tests\Account\Integration\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\CannotModifyOwnAccount;
use App\Account\Exception\LastSuperAdminProtected;
use App\Account\Service\UserAnonymizer;
use App\Account\Service\UserDeactivator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * G-17 — the guards that stop the platform being locked out of its own admin tools.
 *
 * Tested against the services rather than through HTTP on purpose: the rule has to hold for a
 * console command or a fixture too, and a test that only exercised the controller would pass
 * while the invariant was reachable from three other directions.
 */
final class AccountGuardsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheLastActiveSuperAdminCannotBeDeactivated(): void
    {
        $onlyAdmin = $this->persistUser('only-admin@example.com', UserRole::SuperAdmin);
        $actor = $this->persistUser('other-admin@example.com', UserRole::SuperAdmin, UserStatus::Inactive);

        $this->expectException(LastSuperAdminProtected::class);

        static::getContainer()->get(UserDeactivator::class)->deactivate($onlyAdmin, $actor);
    }

    public function testTheLastActiveSuperAdminCannotBeAnonymized(): void
    {
        $onlyAdmin = $this->persistUser('only-admin@example.com', UserRole::SuperAdmin);
        $actor = $this->persistUser('other-admin@example.com', UserRole::SuperAdmin, UserStatus::Inactive);

        $this->expectException(LastSuperAdminProtected::class);

        static::getContainer()->get(UserAnonymizer::class)->anonymize($onlyAdmin, $actor, 'Requested by the user.');
    }

    public function testASecondActiveSuperAdminMakesDeactivationAllowed(): void
    {
        $target = $this->persistUser('first-admin@example.com', UserRole::SuperAdmin);
        $actor = $this->persistUser('second-admin@example.com', UserRole::SuperAdmin);

        static::getContainer()->get(UserDeactivator::class)->deactivate($target, $actor);

        self::assertSame(UserStatus::Inactive, $target->getStatus());
    }

    public function testAnOperatorCannotAnonymizeTheirOwnAccount(): void
    {
        $actor = $this->persistUser('admin@example.com', UserRole::SuperAdmin);
        $this->persistUser('backup-admin@example.com', UserRole::SuperAdmin);

        $this->expectException(CannotModifyOwnAccount::class);

        static::getContainer()->get(UserAnonymizer::class)->anonymize($actor, $actor, 'Requested by the user.');
    }

    /**
     * A guard that refuses is only useful if it refuses before writing anything.
     */
    public function testARefusedDeactivationLeavesNoAuditTrailAndNoStatusChange(): void
    {
        $onlyAdmin = $this->persistUser('only-admin@example.com', UserRole::SuperAdmin);
        $actor = $this->persistUser('other-admin@example.com', UserRole::SuperAdmin, UserStatus::Inactive);

        try {
            static::getContainer()->get(UserDeactivator::class)->deactivate($onlyAdmin, $actor);
            self::fail('Expected the last-Super-Admin guard to refuse.');
        } catch (LastSuperAdminProtected) {
            // expected
        }

        self::assertSame(UserStatus::Active, $onlyAdmin->getStatus());
        self::assertCount(0, $this->entityManager->getRepository(\App\Account\Entity\AuditLogEntry::class)->findAll());
    }

    private function persistUser(string $email, UserRole $role, UserStatus $status = UserStatus::Active): User
    {
        $user = new User($email, ucfirst(strstr($email, '@', true) ?: $email), $role, new \DateTimeImmutable());
        $user->setStatus($status);
        $user->setPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Password123'),
        );
        $user->markEmailVerified(new \DateTimeImmutable());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
