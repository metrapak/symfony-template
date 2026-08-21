<?php

declare(strict_types=1);

namespace App\Account\DataFixtures;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One account per branch the later tasks and the functional tests need to exercise:
 * every role, every status, plus the forced-change and unverified edge cases.
 */
class AccountFixtures extends Fixture
{
    public const PASSWORD = 'Password123';

    public const SUPER_ADMIN = 'admin@example.com';
    public const TRAINER = 'trainer@example.com';
    public const COACH = 'coach@example.com';
    public const PLAYER = 'player@example.com';
    public const INACTIVE_PLAYER = 'inactive@example.com';
    public const DELETED_PLAYER = 'deleted@example.com';
    public const MUST_CHANGE_PASSWORD_TRAINER = 'temp-password@example.com';
    public const UNVERIFIED_PLAYER = 'unverified@example.com';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        $superAdmin = $this->createUser(self::SUPER_ADMIN, UserRole::SuperAdmin, $now);
        $trainer = $this->createUser(self::TRAINER, UserRole::Trainer, $now);
        $coach = $this->createUser(self::COACH, UserRole::Coach, $now);
        $player = $this->createUser(self::PLAYER, UserRole::Player, $now);

        $inactive = $this->createUser(self::INACTIVE_PLAYER, UserRole::Player, $now);
        $inactive->setStatus(UserStatus::Inactive);

        $deleted = $this->createUser(self::DELETED_PLAYER, UserRole::Player, $now);
        $deleted->setStatus(UserStatus::Deleted);

        // A trainer, because the forced-change flag comes from admin-created accounts and
        // trainers are never self-registered.
        $mustChange = $this->createUser(self::MUST_CHANGE_PASSWORD_TRAINER, UserRole::Trainer, $now);
        $mustChange->setMustChangePassword(true);

        $unverified = $this->createUser(self::UNVERIFIED_PLAYER, UserRole::Player, $now, verified: false);

        foreach ([$superAdmin, $trainer, $coach, $player, $inactive, $deleted, $mustChange, $unverified] as $user) {
            $manager->persist($user);
        }

        $manager->persist(new Organization('Example Academy', $trainer, $now));

        $manager->flush();
    }

    private function createUser(string $email, UserRole $role, \DateTimeImmutable $now, bool $verified = true): User
    {
        $user = new User($email, $role, $now);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));

        if ($verified) {
            $user->markEmailVerified($now);
        }

        return $user;
    }
}
