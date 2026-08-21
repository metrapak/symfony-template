<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Enum;

use App\Account\Enum\UserStatus;
use PHPUnit\Framework\TestCase;

class UserStatusTest extends TestCase
{
    public function testOnlyActiveCanAuthenticate(): void
    {
        self::assertTrue(UserStatus::Active->canAuthenticate());
        self::assertFalse(UserStatus::Inactive->canAuthenticate());
        self::assertFalse(UserStatus::Deleted->canAuthenticate());
    }

    public function testDeletedIsTerminal(): void
    {
        self::assertFalse(UserStatus::Deleted->canTransitionTo(UserStatus::Active));
        self::assertFalse(UserStatus::Deleted->canTransitionTo(UserStatus::Inactive));
    }

    public function testActiveAndInactiveTransitionBothWays(): void
    {
        self::assertTrue(UserStatus::Active->canTransitionTo(UserStatus::Inactive));
        self::assertTrue(UserStatus::Inactive->canTransitionTo(UserStatus::Active));
        self::assertTrue(UserStatus::Active->canTransitionTo(UserStatus::Deleted));
    }

    public function testTransitionToSameStatusIsNotATransition(): void
    {
        self::assertFalse(UserStatus::Active->canTransitionTo(UserStatus::Active));
    }
}
