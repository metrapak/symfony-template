<?php

declare(strict_types=1);

namespace App\Availability\Dto;

use App\Availability\Enum\DayOfWeek;
use App\Availability\Service\TimeGrid;
use App\Availability\ValueObject\TimeRange;
use App\Availability\ValueObject\WeeklySchedule;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A whole week as the grid submits it: seven days of ticked blocks (FR-080, FR-082).
 *
 * The form's data object, and the only thing between a request and `WeeklySchedule`. It converts
 * in both directions so the mapping lives in one place — a rendered form and a saved week that
 * disagreed about what a ticked box means would be invisible until somebody's Tuesday
 * disappeared.
 *
 * The seven days always exist, whether or not anything is ticked, because a grid with a missing
 * column is a grid that cannot be rendered. A day nobody touched contributes nothing to the
 * schedule.
 */
final class WeeklyAvailabilityInput
{
    /**
     * Keyed by `DayOfWeek::key()`, so the form children are `[monday]`, `[tuesday]`, … and a
     * template can look one up by name rather than by position.
     *
     * @var array<string, DayAvailabilityInput>
     */
    #[Assert\Valid]
    public array $days = [];

    public static function emptyWeek(): self
    {
        $input = new self();

        foreach (DayOfWeek::week() as $day) {
            $input->days[$day->key()] = new DayAvailabilityInput($day);
        }

        return $input;
    }

    /**
     * The form as it should render for a week that is already saved.
     *
     * Blocks are ticked only where the stored range covers them entirely — see
     * `TimeGrid::coveredBlockStarts()` for why a partially covered block is left clear.
     */
    public static function fromSchedule(WeeklySchedule $schedule, TimeGrid $grid): self
    {
        $input = self::emptyWeek();

        foreach (DayOfWeek::week() as $day) {
            $slots = [];

            foreach ($schedule->forDay($day) as $range) {
                foreach ($grid->coveredBlockStarts($range) as $start) {
                    $slots[] = $start;
                }
            }

            sort($slots);

            $input->days[$day->key()] = DayAvailabilityInput::with(
                $day,
                array_values(array_unique($slots)),
                $schedule->isMarkedUnavailable($day),
            );
        }

        return $input;
    }

    /**
     * The submitted week as a schedule, with adjacent blocks folded into ranges.
     *
     * Each ticked block becomes a one-block range and `WeeklySchedule` merges the runs, which is
     * how "17:00, 18:00 and 19:00" becomes the single "17:00-20:00" a trainer reads and the
     * single row the coverage query compares against.
     */
    public function toSchedule(TimeGrid $grid): WeeklySchedule
    {
        $rangesByDay = [];
        $unavailableDays = [];

        foreach (DayOfWeek::week() as $day) {
            $dayInput = $this->days[$day->key()] ?? new DayAvailabilityInput($day);

            if ($dayInput->unavailable) {
                $unavailableDays[] = $day;

                continue;
            }

            $ranges = [];

            foreach ($dayInput->slots as $startMinute) {
                $block = $grid->blockStartingAt($startMinute);

                // Silently dropped rather than rejected: the only way to get here is a value the
                // form's choice list already accepted, and the only way for that to be off-grid
                // is a granularity change between rendering and submitting.
                if (null !== $block) {
                    $ranges[] = $block;
                }
            }

            if ([] !== $ranges) {
                $rangesByDay[$day->value] = $ranges;
            }
        }

        return WeeklySchedule::build($rangesByDay, $unavailableDays);
    }

    /**
     * Whether the week says anything at all — used to phrase the confirmation honestly when
     * somebody saves an empty grid.
     */
    public function isEmpty(): bool
    {
        foreach ($this->days as $day) {
            if (!$day->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The total ticked time, for the running summary the grid shows while it is being edited.
     */
    public function selectedMinutes(TimeGrid $grid): int
    {
        $minutes = 0;

        foreach ($this->days as $day) {
            $minutes += \count($day->slots) * $grid->slotMinutes();
        }

        return min($minutes, 7 * TimeRange::DAY_END_MINUTE);
    }
}
