<?php

declare(strict_types=1);

namespace App\Availability\ValueObject;

use App\Availability\Enum\DayOfWeek;

/**
 * One subject's whole week, normalized (FR-080, FR-082, BR-080, BR-081).
 *
 * The unit everything in this module works in. `AvailabilityService` reads and writes one of
 * these rather than loose rows, which is what makes "saving a week is atomic" a property of the
 * type and not a habit each caller has to remember: there is no API here for adding one slot.
 *
 * Normalization happens on construction and is the reason the persistence layer stays simple:
 *
 *  - ranges are sorted, and overlapping or adjacent ones are merged, so a run of hourly grid
 *    cells becomes "17:00-20:00" and the coverage query can be a single-row comparison;
 *  - a day marked unavailable holds no ranges, because "not available, from 5 to 8" is not a
 *    statement anybody can act on.
 *
 * **Declared versus empty** is the distinction the trainer's "15 of 20" count turns on. A
 * schedule with nothing in it means the person has never said anything, which is *not* the same
 * as saying they are never free — so `isDeclared()` is false and the trainer view reports them
 * as unknown rather than counting them as unavailable. Marking a day unavailable is how somebody
 * says "no" out loud, and that produces a row, which makes the week declared.
 */
final readonly class WeeklySchedule
{
    /**
     * @param array<int, list<TimeRange>> $availableByDay keyed by `DayOfWeek::value`, sorted and merged
     * @param list<int> $unavailableDays `DayOfWeek::value`s explicitly marked unavailable
     */
    private function __construct(
        private array $availableByDay,
        private array $unavailableDays,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param array<int, list<TimeRange>> $rangesByDay keyed by `DayOfWeek::value`, in any order
     * @param list<DayOfWeek> $unavailableDays days the subject said they are not available
     */
    public static function build(array $rangesByDay, array $unavailableDays = []): self
    {
        $unavailable = [];

        foreach ($unavailableDays as $day) {
            $unavailable[$day->value] = true;
        }

        $normalized = [];
        $declaredUnavailable = [];

        foreach (DayOfWeek::week() as $day) {
            // An unavailable day keeps no ranges. The form rejects a submission that says both,
            // so reaching here with both means a caller built the schedule in code — and the
            // explicit "no" is the safer of the two to honour.
            if (isset($unavailable[$day->value])) {
                $declaredUnavailable[] = $day->value;

                continue;
            }

            $ranges = self::normalizeDay($rangesByDay[$day->value] ?? []);

            if ([] !== $ranges) {
                $normalized[$day->value] = $ranges;
            }
        }

        return new self($normalized, $declaredUnavailable);
    }

    /**
     * Sorts a day's ranges and folds every overlapping or adjacent pair into one.
     *
     * @param list<TimeRange> $ranges
     *
     * @return list<TimeRange>
     */
    private static function normalizeDay(array $ranges): array
    {
        if ([] === $ranges) {
            return [];
        }

        usort($ranges, TimeRange::compare(...));

        $merged = [array_shift($ranges)];

        foreach ($ranges as $range) {
            $last = $merged[\count($merged) - 1];

            if ($last->touches($range)) {
                $merged[\count($merged) - 1] = $last->merge($range);

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * @return list<TimeRange>
     */
    public function forDay(DayOfWeek $day): array
    {
        return $this->availableByDay[$day->value] ?? [];
    }

    public function isMarkedUnavailable(DayOfWeek $day): bool
    {
        return \in_array($day->value, $this->unavailableDays, true);
    }

    /**
     * @return list<DayOfWeek>
     */
    public function unavailableDays(): array
    {
        return array_map(DayOfWeek::from(...), $this->unavailableDays);
    }

    /**
     * The days with at least one available window, Monday first.
     *
     * @return list<DayOfWeek>
     */
    public function availableDays(): array
    {
        return array_values(array_filter(
            DayOfWeek::week(),
            fn (DayOfWeek $day): bool => [] !== $this->forDay($day),
        ));
    }

    /**
     * Whether the subject has said anything at all — see the class note on declared versus
     * empty.
     */
    public function isDeclared(): bool
    {
        return [] !== $this->availableByDay || [] !== $this->unavailableDays;
    }

    public function hasAnyAvailability(): bool
    {
        return [] !== $this->availableByDay;
    }

    /**
     * Whether the subject is available for the whole of `$window` on `$day`.
     *
     * Coverage rather than overlap — see `TimeRange::covers()` for why an availability question
     * is not an intersection question. A subject who has declared nothing is not available by
     * this measure and is not *unavailable* either; callers that need to tell those apart ask
     * `isDeclared()`, and FR-088 is why no caller may turn either answer into a refusal.
     */
    public function covers(DayOfWeek $day, TimeRange $window): bool
    {
        foreach ($this->forDay($day) as $range) {
            if ($range->covers($window)) {
                return true;
            }
        }

        return false;
    }

    public function overlaps(DayOfWeek $day, TimeRange $window): bool
    {
        foreach ($this->forDay($day) as $range) {
            if ($range->overlaps($window)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every (day, range) pair, Monday first — what the persistence layer writes out.
     *
     * @return list<array{day: DayOfWeek, range: TimeRange}>
     */
    public function pairs(): array
    {
        $pairs = [];

        foreach (DayOfWeek::week() as $day) {
            foreach ($this->forDay($day) as $range) {
                $pairs[] = ['day' => $day, 'range' => $range];
            }
        }

        return $pairs;
    }

    public function totalMinutes(): int
    {
        $total = 0;

        foreach ($this->pairs() as $pair) {
            $total += $pair['range']->durationMinutes();
        }

        return $total;
    }
}
