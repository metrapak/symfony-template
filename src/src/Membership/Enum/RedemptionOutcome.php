<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * What happened when somebody opened a ShareLink (FR-047).
 *
 * Every attempt that reaches a *resolvable* link is recorded, including the ones that did not
 * associate anybody: a child blocked by BR-046 is exactly the signal a trainer's onboarding
 * funnel needs to show, and dropping it would make the usage count disagree with reality.
 */
enum RedemptionOutcome: string
{
    /** A brand-new account was created by this redemption (FR-042). */
    case NewAccount = 'new_account';

    /** An existing account gained an association or a coach assignment (FR-043, FR-045). */
    case Association = 'association';

    /** A logged-in child account was refused and their parent was notified (FR-048). */
    case BlockedChild = 'blocked_child';

    public function label(): string
    {
        return match ($this) {
            self::NewAccount => 'New account',
            self::Association => 'Association',
            self::BlockedChild => 'Blocked (child account)',
        };
    }
}
