<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Entity;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function roleProvider(): iterable
    {
        yield 'super admin' => [UserRole::SuperAdmin];
        yield 'trainer' => [UserRole::Trainer];
        yield 'coach' => [UserRole::Coach];
        yield 'player' => [UserRole::Player];
    }

    #[DataProvider('roleProvider')]
    public function testGetRolesReturnsExactlyThePrimaryRolePlusRoleUser(UserRole $role): void
    {
        $user = $this->createUser('user@example.com', $role);

        self::assertSame([$role->value, 'ROLE_USER'], $user->getRoles());
    }

    public function testEmailIsNormalizedOnWrite(): void
    {
        $user = $this->createUser('  Foo@Bar.COM ', UserRole::Player);

        self::assertSame('foo@bar.com', $user->getEmail());
        self::assertSame('foo@bar.com', $user->getUserIdentifier());
    }

    public function testNewUserIsActiveAndUnverified(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Player);

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertFalse($user->isEmailVerified());
        self::assertFalse($user->mustChangePassword());
    }

    public function testIsEqualToIsTrueForAnIdenticalCopy(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Coach);

        self::assertTrue($user->isEqualTo(clone $user));
    }

    public function testIsEqualToIsFalseWhenStatusDiffers(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Coach);
        $refreshed = (clone $user)->setStatus(UserStatus::Inactive);

        self::assertFalse($user->isEqualTo($refreshed));
    }

    public function testIsEqualToIsFalseWhenRoleDiffers(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Coach);
        $refreshed = (clone $user)->setRole(UserRole::Player);

        self::assertFalse($user->isEqualTo($refreshed));
    }

    public function testIsEqualToIsFalseWhenEmailDiffers(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Coach);
        $refreshed = (clone $user)->setEmail('other@example.com');

        self::assertFalse($user->isEqualTo($refreshed));
    }

    public function testIsEqualToIsFalseWhenThePasswordChangeStampDiffers(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Coach);
        $refreshed = (clone $user)->recordPasswordChange(new \DateTimeImmutable('2026-08-21 10:00:00'));

        // This is what ends every other session after a password change.
        self::assertFalse($user->isEqualTo($refreshed));
    }

    public function testIsEqualToIsTrueWhenBothCarryTheSamePasswordChangeStamp(): void
    {
        $at = new \DateTimeImmutable('2026-08-21 10:00:00');

        $user = $this->createUser('user@example.com', UserRole::Coach)->recordPasswordChange($at);
        $refreshed = $this->createUser('user@example.com', UserRole::Coach)->recordPasswordChange($at);

        self::assertTrue($user->isEqualTo($refreshed));
    }

    /**
     * The column is TIMESTAMP(0). A stamp carrying microseconds would not survive the round
     * trip unchanged, and isEqualTo() would then sign out the user who changed their own
     * password on their very next request.
     */
    public function testRecordPasswordChangeTruncatesToWholeSeconds(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Coach);

        $user->recordPasswordChange(new \DateTimeImmutable('2026-08-21 10:00:00.987654'));

        self::assertSame('2026-08-21 10:00:00.000000', $user->getPasswordChangedAt()?->format('Y-m-d H:i:s.u'));
    }

    public function testMarkEmailVerifiedStampsTheGivenTime(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Player);
        $at = new \DateTimeImmutable('2026-08-21 10:00:00');

        $user->markEmailVerified($at);

        self::assertTrue($user->isEmailVerified());
        self::assertEquals($at, $user->getEmailVerifiedAt());
    }

    private function createUser(string $email, UserRole $role): User
    {
        return new User($email, 'Example User', $role, new \DateTimeImmutable('2026-08-21 09:00:00'));
    }
}
