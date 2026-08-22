<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * What a trainer sees next to each coach invitation (FR-046).
 *
 * Derived, never stored. The three states are already implied by the link's expiry, its
 * usage count and whether an assignment exists, and a stored copy of them would be a fourth
 * source of truth that a lapsed invitation silently makes wrong at midnight.
 */
enum CoachInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Expired => 'Expired',
        };
    }
}
