<?php

declare(strict_types=1);

namespace App\Availability\Enum;

/**
 * Whose availability a slot describes (FR-080, FR-082).
 *
 * One table holds both, discriminated by this column, because the two are the same fact about
 * different people: a weekday, a window, and whether the person can attend. Every question the
 * module answers — replace a week, summarize it, find everybody free at a time — is identical
 * for a player and a coach, so two tables would be two copies of one query shape and one index.
 *
 * The consequence is that `subject_id` cannot carry a foreign key: it points at
 * `player_profile.id` for a player and at `"user".id` for a coach, and no column references two
 * tables. That is the price of the shared shape, and it is paid deliberately — the ids are never
 * user-supplied (they arrive from an authorized profile or from the signed-in coach), and
 * `AvailabilitySubject` is the only thing that constructs a pair, so a coach id can never be
 * written with a player type by a caller that mixed up its arguments.
 */
enum AvailabilitySubjectType: string
{
    /** A `PlayerProfile` id — the person who trains, not the account that manages them. */
    case Player = 'player';

    /** A coach's `User` id. A coach has no profile row of their own to hang this off. */
    case Coach = 'coach';

    public function label(): string
    {
        return match ($this) {
            self::Player => 'Player',
            self::Coach => 'Coach',
        };
    }
}
