<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * Whether a membership record (a trainer-player association or a coach assignment) is
 * currently in force.
 *
 * Records are never deleted — ending a membership writes `Inactive` and a timestamp — so the
 * history that Epic-06 analytics and BR-044's single-trainer rule both read stays intact.
 * The value is also load-bearing in SQL: the partial unique index that stops a coach being
 * active under two trainers is defined `WHERE status = 'active'`.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
