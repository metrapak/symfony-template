<?php

declare(strict_types=1);

namespace App\Availability\Dto;

use App\Availability\ValueObject\WeeklySchedule;

/**
 * One row of the trainer's availability view: a player, their week, and their summary (FR-083,
 * FR-084).
 *
 * A read model, so the template never holds a `PlayerProfile` it might be tempted to write
 * through — BR-082 says a trainer views availability and never edits it, and the cheapest way to
 * keep that true is to hand the view no object that could be edited.
 */
final readonly class PlayerAvailabilityView
{
    public function __construct(
        public int $playerProfileId,
        public string $playerName,
        public WeeklySchedule $schedule,
        /** The "Best Times: Mon 5-8pm, Wed 6-9pm" line for the player card. */
        public string $summary,
        /** Null when no filter was applied; otherwise whether this player matched it. */
        public ?bool $matchesFilter = null,
    ) {
    }

    public function hasDeclared(): bool
    {
        return $this->schedule->isDeclared();
    }
}
