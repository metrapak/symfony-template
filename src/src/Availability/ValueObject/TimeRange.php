<?php

declare(strict_types=1);

namespace App\Availability\ValueObject;

/**
 * A window inside one day, as minutes since midnight (FR-080, FR-082, G-29).
 *
 * **Why minutes and not a `TIME` column or a `\DateTimeInterval`.** Weekly availability is a
 * recurring wall-clock pattern with no date attached: "Mondays, 5 to 8 in the evening". A
 * `\DateTimeImmutable` would drag a date and a time zone into a value that has neither, and
 * would invite the DST arithmetic G-29 warns about — the pattern does not shift when the clocks
 * do, the events it informs do. Two small integers say exactly what is known and nothing more,
 * they make the overlap predicate two comparisons an index can serve (NFR-080), and they can
 * express `24:00` as an end, which a `TIME` column cannot without wrapping to zero and turning
 * "until midnight" into an empty range.
 *
 * Half-open by definition: `[start, end)`. So 17:00-18:00 and 18:00-19:00 do **not** overlap —
 * they are adjacent, and `merge()` joins them into 17:00-19:00. That distinction is the whole
 * reason both methods exist: a grid of hourly checkboxes produces a run of adjacent hours which
 * has to become one readable range, while a genuine overlap in submitted input is a mistake the
 * validator rejects rather than something to silently absorb.
 */
final readonly class TimeRange
{
    /** Midnight at the end of the day. A valid `end`, never a valid `start`. */
    public const DAY_END_MINUTE = 1440;

    private function __construct(
        public int $startMinute,
        public int $endMinute,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the window is empty, inverted, or outside the day
     */
    public static function fromMinutes(int $startMinute, int $endMinute): self
    {
        if ($startMinute < 0 || $startMinute >= self::DAY_END_MINUTE) {
            throw new \InvalidArgumentException(\sprintf('A start of %d is not a time of day.', $startMinute));
        }

        if ($endMinute <= $startMinute) {
            throw new \InvalidArgumentException('A time range has to end after it starts.');
        }

        if ($endMinute > self::DAY_END_MINUTE) {
            throw new \InvalidArgumentException('A time range cannot run past midnight.');
        }

        return new self($startMinute, $endMinute);
    }

    public static function tryFromMinutes(?int $startMinute, ?int $endMinute): ?self
    {
        if (null === $startMinute || null === $endMinute) {
            return null;
        }

        try {
            return self::fromMinutes($startMinute, $endMinute);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * One block of a grid: the cell that starts at `$startMinute` and lasts `$lengthMinutes`.
     *
     * Clamped to midnight rather than rejected, so the last block of a day is a real block
     * whatever granularity divides into 1440 unevenly.
     */
    public static function block(int $startMinute, int $lengthMinutes): self
    {
        return self::fromMinutes($startMinute, min($startMinute + $lengthMinutes, self::DAY_END_MINUTE));
    }

    /**
     * Whether the two windows share any time at all.
     *
     * Boundary behaviour is the point: windows that only *touch* return false, and windows that
     * share an endpoint while genuinely intersecting (17:00-20:00 against 17:00-18:00) return
     * true. Both cases are pinned down by unit tests, because the trainer filter's meaning
     * depends on them.
     */
    public function overlaps(self $other): bool
    {
        return $this->startMinute < $other->endMinute && $this->endMinute > $other->startMinute;
    }

    /** Back-to-back with no gap and no overlap, in either order. */
    public function isAdjacentTo(self $other): bool
    {
        return $this->endMinute === $other->startMinute || $other->endMinute === $this->startMinute;
    }

    public function touches(self $other): bool
    {
        return $this->overlaps($other) || $this->isAdjacentTo($other);
    }

    /**
     * Whether this window contains all of another — the predicate the availability question
     * actually asks.
     *
     * "Is this coach free from 6 to 8?" is not "do those hours intersect their schedule": a
     * coach who is free from 6 to 7 cannot take a session that runs until 8. Coverage, not
     * overlap, is therefore what `AvailabilityMatcher` matches on, and what the SQL predicate
     * mirrors. It works against a single stored row only because saving merges adjacent
     * ranges, so 16:00-18:00 plus 18:00-21:00 is one row covering 16:00-21:00.
     */
    public function covers(self $other): bool
    {
        return $this->startMinute <= $other->startMinute && $this->endMinute >= $other->endMinute;
    }

    public function containsMinute(int $minute): bool
    {
        return $minute >= $this->startMinute && $minute < $this->endMinute;
    }

    /**
     * @throws \InvalidArgumentException when the windows neither overlap nor touch, because the
     *                                   result would silently include a gap nobody declared
     */
    public function merge(self $other): self
    {
        if (!$this->touches($other)) {
            throw new \InvalidArgumentException('Only overlapping or adjacent ranges can be merged.');
        }

        return new self(
            min($this->startMinute, $other->startMinute),
            max($this->endMinute, $other->endMinute),
        );
    }

    public function durationMinutes(): int
    {
        return $this->endMinute - $this->startMinute;
    }

    public function equals(self $other): bool
    {
        return $this->startMinute === $other->startMinute && $this->endMinute === $other->endMinute;
    }

    /** Earliest first, then shortest first, so a normalized day has one stable order. */
    public static function compare(self $a, self $b): int
    {
        return [$a->startMinute, $a->endMinute] <=> [$b->startMinute, $b->endMinute];
    }

    /** `17:00-20:00` — the unambiguous form, used in form labels and accessible names. */
    public function format(): string
    {
        return self::formatMinute($this->startMinute) . '–' . self::formatMinute($this->endMinute);
    }

    /**
     * `5–8pm` — the compact form the spec's player card uses ("Best Times: Mon 5-8pm").
     *
     * The meridiem is printed once when both ends share it and twice when they do not, so
     * "11am–1pm" stays readable and "5–8pm" stays short.
     */
    public function formatCompact(): string
    {
        $start = self::formatMinuteCompact($this->startMinute);
        $end = self::formatMinuteCompact($this->endMinute);

        $startMeridiem = self::meridiem($this->startMinute);
        $endMeridiem = self::meridiem($this->endMinute);

        if (null !== $startMeridiem && $startMeridiem === $endMeridiem) {
            // Both in the same half of the day: "5-8pm" rather than "5pm-8pm".
            $start = substr($start, 0, -\strlen($startMeridiem));
        }

        return $start . '–' . $end;
    }

    /** `5:00 PM` — spoken form, for the accessible name of a grid cell (NFR-081). */
    public function formatSpoken(): string
    {
        return \sprintf('%s to %s', self::formatMinuteSpoken($this->startMinute), self::formatMinuteSpoken($this->endMinute));
    }

    public static function formatMinute(int $minute): string
    {
        return \sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /**
     * `5pm`, `5:30pm`, `midnight`.
     *
     * Midnight is named rather than numbered at both ends of the day: "10pm–12am" reads as a
     * typo, and `24:00` is not a clock face anybody recognizes.
     */
    public static function formatMinuteCompact(int $minute): string
    {
        if (0 === $minute % self::DAY_END_MINUTE) {
            return 'midnight';
        }

        if (720 === $minute) {
            return 'noon';
        }

        $hour = intdiv($minute, 60);
        $minutes = $minute % 60;
        $twelve = $hour % 12;

        return (0 === $twelve ? 12 : $twelve)
            . (0 !== $minutes ? \sprintf(':%02d', $minutes) : '')
            . (string) self::meridiem($minute);
    }

    public static function formatMinuteSpoken(int $minute): string
    {
        if (0 === $minute % self::DAY_END_MINUTE) {
            return 'midnight';
        }

        $hour = intdiv($minute, 60);
        $minutes = $minute % 60;
        $twelve = $hour % 12;

        return \sprintf('%d:%02d %s', 0 === $twelve ? 12 : $twelve, $minutes, 'am' === self::meridiem($minute) ? 'AM' : 'PM');
    }

    /** `am`, `pm`, or null at midnight, where naming the half of the day is meaningless. */
    private static function meridiem(int $minute): ?string
    {
        if (0 === $minute % self::DAY_END_MINUTE) {
            return null;
        }

        return $minute < 720 ? 'am' : 'pm';
    }
}
