<?php

declare(strict_types=1);

namespace App\Account\Enum;

/**
 * Why an impersonation session stopped (FR-031, FR-032).
 *
 * `Expiry` and `Exit` are distinguished because they answer different compliance questions:
 * an operator who always runs to expiry is not exiting deliberately, which is worth seeing in
 * the history report.
 */
enum ImpersonationEndReason: string
{
    case Exit = 'exit';
    case Expiry = 'expiry';
    case Logout = 'logout';

    public function label(): string
    {
        return match ($this) {
            self::Exit => 'Exited',
            self::Expiry => 'Expired after the time limit',
            self::Logout => 'Ended by signing out',
        };
    }
}
