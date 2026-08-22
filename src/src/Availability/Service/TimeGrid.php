<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Availability\ValueObject\TimeRange;

/**
 * The blocks every availability grid is built from, and the time zone they are read in (G-27,
 * G-29).
 *
 * **G-27, decided: one granularity, configured.** US-01.09 asks for "hourly blocks or custom
 * ranges" — both, which is not a choice a schema can hold. Fixed blocks won, because the grid
 * FR-080 describes and the accessible checkbox table NFR-081 requires are the same control, and
 * a run of adjacent blocks merges into "Monday 17:00-20:00" on save, so what a family *sees* is
 * blocks and what a trainer *reads* is ranges. Arbitrary minute boundaries would buy 17:37 as a
 * start time, and pay for it with a grid nobody can render and a summary nobody can skim.
 *
 * The block length is `app.availability_slot_minutes`, hourly by default. Answering G-27
 * differently — half-hour blocks for a club that trains in 30-minute slots — is an environment
 * variable, not a rewrite, which is this project's standing convention for an unresolved
 * requirement. Stored ranges are unaffected: they are plain minutes, so shortening the block
 * length does not invalidate a week saved under the old one.
 *
 * **G-29, stated rather than solved.** Nothing in the epic defines a time zone, and a weekly
 * grid without one is ambiguous the moment a trainer and a player are in different places. This
 * ships single-zone: every stored minute is wall-clock time in `app.availability_timezone`, and
 * every screen says which zone that is, so the ambiguity is visible instead of silent. Per-user
 * zones would mean converting a *recurring* pattern across DST — where "Mondays at 5" is
 * genuinely a different instant in July and January — and that is a decision for whoever answers
 * G-29, not one to guess at here.
 */
final readonly class TimeGrid
{
    private const MIN_SLOT_MINUTES = 5;
    private const MAX_SLOT_MINUTES = 240;

    public function __construct(
        private int $availabilitySlotMinutes = 60,
        private string $availabilityTimezone = 'UTC',
    ) {
        if (
            $availabilitySlotMinutes < self::MIN_SLOT_MINUTES
            || $availabilitySlotMinutes > self::MAX_SLOT_MINUTES
            || 0 !== TimeRange::DAY_END_MINUTE % $availabilitySlotMinutes
        ) {
            // A misconfiguration, not a user error: a block length that does not divide the day
            // would produce a ragged last row and a grid whose cells do not tile the week.
            throw new \InvalidArgumentException(\sprintf('app.availability_slot_minutes must divide 1440 and be between %d and %d minutes, got %d.', self::MIN_SLOT_MINUTES, self::MAX_SLOT_MINUTES, $availabilitySlotMinutes));
        }

        if (!\in_array($availabilityTimezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a time zone identifier.', $availabilityTimezone));
        }
    }

    public function slotMinutes(): int
    {
        return $this->availabilitySlotMinutes;
    }

    /**
     * Every block of one day, midnight to midnight.
     *
     * The whole day rather than a "sensible" 06:00-22:00 window, because a window would make
     * some declared times uneditable: a coach with a 05:00 slot who opened a trimmed grid and
     * saved it would silently lose that slot, since saving replaces the week.
     *
     * @return list<TimeRange>
     */
    public function blocks(): array
    {
        $blocks = [];

        for ($minute = 0; $minute < TimeRange::DAY_END_MINUTE; $minute += $this->availabilitySlotMinutes) {
            $blocks[] = TimeRange::block($minute, $this->availabilitySlotMinutes);
        }

        return $blocks;
    }

    /**
     * The block starting at this minute, or null when the minute is not a block boundary.
     */
    public function blockStartingAt(int $minute): ?TimeRange
    {
        if ($minute < 0 || $minute >= TimeRange::DAY_END_MINUTE || 0 !== $minute % $this->availabilitySlotMinutes) {
            return null;
        }

        return TimeRange::block($minute, $this->availabilitySlotMinutes);
    }

    /**
     * The blocks a stored range covers, so an edit form can re-tick what a week already holds.
     *
     * A block counts as selected only when the range covers *all* of it. A week saved under a
     * coarser granularity therefore round-trips exactly, while one saved under a finer
     * granularity loses the partial block rather than rounding it up and silently widening
     * somebody's availability.
     *
     * @return list<int> the start minutes of the covered blocks
     */
    public function coveredBlockStarts(TimeRange $range): array
    {
        $starts = [];

        foreach ($this->blocks() as $block) {
            if ($range->covers($block)) {
                $starts[] = $block->startMinute;
            }
        }

        return $starts;
    }

    /**
     * `[label => minutes]` for a start-time select — the trainer's filter and the conflict
     * check, which ask for a window rather than tick a grid.
     *
     * @return array<string, int>
     */
    public function startChoices(): array
    {
        $choices = [];

        foreach ($this->blocks() as $block) {
            $choices[TimeRange::formatMinute($block->startMinute)] = $block->startMinute;
        }

        return $choices;
    }

    /**
     * `[label => minutes]` for an end-time select, including midnight as `24:00`.
     *
     * @return array<string, int>
     */
    public function endChoices(): array
    {
        $choices = [];

        foreach ($this->blocks() as $block) {
            $label = TimeRange::DAY_END_MINUTE === $block->endMinute ? '24:00' : TimeRange::formatMinute($block->endMinute);
            $choices[$label] = $block->endMinute;
        }

        return $choices;
    }

    /**
     * The short label a grid column carries.
     *
     * Hourly blocks get the bare hour, because 24 two-character headings fit a phone and read
     * like a day planner; anything finer needs the full `HH:MM` to be unambiguous. Either way the
     * checkbox itself carries the full spoken name, so the heading is a visual aid rather than
     * the accessible name (NFR-081).
     */
    public function columnLabel(TimeRange $block): string
    {
        return 60 === $this->availabilitySlotMinutes
            ? \sprintf('%02d', intdiv($block->startMinute, 60))
            : TimeRange::formatMinute($block->startMinute);
    }

    /**
     * The zone every time on every availability screen is expressed in (G-29).
     */
    public function timezone(): string
    {
        return $this->availabilityTimezone;
    }

    /**
     * The sentence each grid prints under its heading, so a time never appears without its zone.
     */
    public function timezoneNotice(): string
    {
        return \sprintf('All times are %s.', $this->availabilityTimezone);
    }
}
