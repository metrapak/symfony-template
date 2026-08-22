<?php

declare(strict_types=1);

namespace App\Availability\Dto;

use App\Account\Entity\User;

/**
 * A coach on one organization's roster (FR-085's "assign a coach" picker).
 *
 * Carries the `User` rather than an id, unlike `RosterPlayer`: the conflict check reads the
 * coach's availability *and* records an override against them, and both need the entity. The
 * account is already this module's own dependency — `Account` is what every module here depends
 * on — so nothing is inverted by naming it.
 */
final readonly class RosterCoach
{
    public function __construct(
        public User $coach,
        public bool $active,
    ) {
    }

    public function displayName(): string
    {
        return $this->coach->getDisplayName();
    }
}
