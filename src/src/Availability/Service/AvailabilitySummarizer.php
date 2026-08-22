<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\TimeRange;
use App\Availability\ValueObject\WeeklySchedule;

/**
 * Turns a week into the one line a player card shows: "Mon 5-8pm, Wed 6-9pm" (FR-083, spec §9).
 *
 * A service rather than a Twig filter, and unit-tested, because the sentence is a rule about
 * data and not a detail of a template: the same string appears on the trainer's roster, in the
 * availability view, and — when Epic-02 arrives — beside a session's attendee list. Three
 * templates spelling it three ways is how "Mon 5-8pm" becomes "Monday 17:00-20:00" on one screen
 * and nobody notices which is authoritative.
 *
 * The three answers it can give are deliberately different sentences:
 *
 *  - times declared → the times;
 *  - nothing declared → "No preferred times set", which is an absence of information;
 *  - declared, but every day marked unavailable → "No available times", which is information.
 *
 * Collapsing the last two into one string would tell a trainer that somebody has said nothing
 * when they have said no.
 */
final readonly class AvailabilitySummarizer
{
    public const NOT_SET = 'No preferred times set';
    public const NONE_AVAILABLE = 'No available times';

    public function summarize(WeeklySchedule $schedule): string
    {
        if (!$schedule->isDeclared()) {
            return self::NOT_SET;
        }

        if (!$schedule->hasAnyAvailability()) {
            return self::NONE_AVAILABLE;
        }

        $parts = [];

        foreach ($schedule->availableDays() as $day) {
            $parts[] = $this->summarizeDay($day, $schedule);
        }

        return implode(', ', $parts);
    }

    /**
     * One day: "Mon 5-8pm", or "Mon 4-6pm and 7-9pm" when the day has gaps (FR-082's split
     * schedule).
     */
    public function summarizeDay(DayOfWeek $day, WeeklySchedule $schedule): string
    {
        $ranges = $schedule->forDay($day);

        if ([] === $ranges) {
            return \sprintf(
                '%s %s',
                $day->shortLabel(),
                $schedule->isMarkedUnavailable($day) ? 'not available' : 'no times',
            );
        }

        return \sprintf(
            '%s %s',
            $day->shortLabel(),
            self::joinRanges(array_map(static fn (TimeRange $range): string => $range->formatCompact(), $ranges)),
        );
    }

    /**
     * The whole week as one line per day, for a screen with room for it.
     *
     * @return list<string>
     */
    public function describeWeek(WeeklySchedule $schedule): array
    {
        $lines = [];

        foreach (DayOfWeek::week() as $day) {
            if ([] === $schedule->forDay($day) && !$schedule->isMarkedUnavailable($day)) {
                continue;
            }

            $lines[] = $this->summarizeDay($day, $schedule);
        }

        return $lines;
    }

    /**
     * "4-6pm and 7-9pm" — "and" rather than a comma, because commas already separate days and a
     * summary of two split days would otherwise be one flat list of four times.
     *
     * @param list<string> $formatted
     */
    private static function joinRanges(array $formatted): string
    {
        if (\count($formatted) < 2) {
            return implode('', $formatted);
        }

        $last = array_pop($formatted);

        return implode(', ', $formatted) . ' and ' . $last;
    }
}
