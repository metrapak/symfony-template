<?php

declare(strict_types=1);

namespace App\Availability\Dto;

/**
 * A player on one organization's roster, as this module needs them: an id and a name.
 *
 * The return shape of `PlayerRosterProvider`, and deliberately not a `PlayerProfile` or a
 * membership entity — see that interface for why the dependency points this way.
 */
final readonly class RosterPlayer
{
    public function __construct(
        public int $playerProfileId,
        public string $displayName,
    ) {
    }
}
