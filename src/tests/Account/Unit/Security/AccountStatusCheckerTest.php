<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Security;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Security\AccountStatusChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

class AccountStatusCheckerTest extends TestCase
{
    public function testActiveAccountPassesPreAuth(): void
    {
        $this->expectNotToPerformAssertions();

        (new AccountStatusChecker(true))->checkPreAuth($this->user(UserRole::Player, UserStatus::Active));
    }

    public function testInactiveAccountIsRefusedWithTheSpecifiedMessage(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage(AccountStatusChecker::INACTIVE_MESSAGE);

        (new AccountStatusChecker(true))->checkPreAuth($this->user(UserRole::Player, UserStatus::Inactive));
    }

    public function testDeletedAccountIsRefused(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage(AccountStatusChecker::DELETED_MESSAGE);

        (new AccountStatusChecker(true))->checkPreAuth($this->user(UserRole::Player, UserStatus::Deleted));
    }

    /**
     * @return iterable<string, array{UserRole, bool, bool, bool}>
     */
    public static function verificationMatrixProvider(): iterable
    {
        // role, gate enabled, email verified, expected to be refused
        yield 'unverified player, gate on' => [UserRole::Player, true, false, true];
        yield 'unverified coach, gate on' => [UserRole::Coach, true, false, true];
        yield 'unverified trainer, gate on' => [UserRole::Trainer, true, false, false];
        yield 'unverified super admin, gate on' => [UserRole::SuperAdmin, true, false, false];
        yield 'verified player, gate on' => [UserRole::Player, true, true, false];
        yield 'unverified player, gate off' => [UserRole::Player, false, false, false];
        yield 'unverified coach, gate off' => [UserRole::Coach, false, false, false];
    }

    #[DataProvider('verificationMatrixProvider')]
    public function testPostAuthVerificationGate(UserRole $role, bool $gateEnabled, bool $verified, bool $expectRefusal): void
    {
        $user = $this->user($role, UserStatus::Active);

        if ($verified) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-08-21 09:00:00'));
        }

        $checker = new AccountStatusChecker($gateEnabled);

        if ($expectRefusal) {
            $this->expectException(CustomUserMessageAccountStatusException::class);
            $this->expectExceptionMessage(AccountStatusChecker::UNVERIFIED_MESSAGE);
        } else {
            $this->expectNotToPerformAssertions();
        }

        $checker->checkPostAuth($user);
    }

    private function user(UserRole $role, UserStatus $status): User
    {
        $user = new User('user@example.com', 'Test User', $role, new \DateTimeImmutable('2026-08-21 09:00:00'));
        $user->setStatus($status);

        return $user;
    }
}
