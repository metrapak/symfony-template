<?php

declare(strict_types=1);

namespace App\Tests\Availability\Unit\ValueObject;

use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\TimeRange;
use App\Availability\ValueObject\WeeklySchedule;
use PHPUnit\Framework\TestCase;

/**
 * Normalization, and the declared-versus-empty distinction the trainer's count depends on.
 */
final class WeeklyScheduleTest extends TestCase
{
    public function testMergesAdjacentBlocksIntoOneRange(): void
    {
        // What a grid produces when somebody ticks 17:00, 18:00 and 19:00.
        $schedule = WeeklySchedule::build([
            DayOfWeek::Monday->value => [
                TimeRange::fromMinutes(17 * 60, 18 * 60),
                TimeRange::fromMinutes(18 * 60, 19 * 60),
                TimeRange::fromMinutes(19 * 60, 20 * 60),
            ],
        ]);

        $monday = $schedule->forDay(DayOfWeek::Monday);

        self::assertCount(1, $monday);
        self::assertSame('17:00–20:00', $monday[0]->format());
    }

    public function testKeepsNonContiguousRangesApart(): void
    {
        // US-01.10's split shift: "Monday 4-6pm AND 7-9pm".
        $schedule = WeeklySchedule::build([
            DayOfWeek::Monday->value => [
                TimeRange::fromMinutes(19 * 60, 21 * 60),
                TimeRange::fromMinutes(16 * 60, 18 * 60),
            ],
        ]);

        $monday = $schedule->forDay(DayOfWeek::Monday);

        self::assertCount(2, $monday);
        self::assertSame('16:00–18:00', $monday[0]->format(), 'sorted earliest first');
        self::assertSame('19:00–21:00', $monday[1]->format());
    }

    public function testFoldsOverlappingRangesTogether(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Tuesday->value => [
                TimeRange::fromMinutes(16 * 60, 19 * 60),
                TimeRange::fromMinutes(18 * 60, 21 * 60),
            ],
        ]);

        self::assertSame('16:00–21:00', $schedule->forDay(DayOfWeek::Tuesday)[0]->format());
    }

    public function testAnUnavailableDayKeepsNoRanges(): void
    {
        $schedule = WeeklySchedule::build(
            [DayOfWeek::Wednesday->value => [TimeRange::fromMinutes(18 * 60, 20 * 60)]],
            [DayOfWeek::Wednesday],
        );

        self::assertSame([], $schedule->forDay(DayOfWeek::Wednesday));
        self::assertTrue($schedule->isMarkedUnavailable(DayOfWeek::Wednesday));
    }

    public function testAnEmptyWeekIsUndeclared(): void
    {
        $schedule = WeeklySchedule::empty();

        self::assertFalse($schedule->isDeclared());
        self::assertFalse($schedule->hasAnyAvailability());
        self::assertSame([], $schedule->availableDays());
    }

    /**
     * The distinction the "15 of 20" count turns on: saying "never on Wednesdays" is information,
     * and it must not read as silence.
     */
    public function testAWeekOfNothingButRefusalsIsDeclared(): void
    {
        $schedule = WeeklySchedule::build([], DayOfWeek::week());

        self::assertTrue($schedule->isDeclared());
        self::assertFalse($schedule->hasAnyAvailability());
        self::assertCount(7, $schedule->unavailableDays());
    }

    public function testCoversRequiresTheWholeWindow(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Monday->value => [TimeRange::fromMinutes(17 * 60, 20 * 60)],
        ]);

        self::assertTrue($schedule->covers(DayOfWeek::Monday, TimeRange::fromMinutes(18 * 60, 19 * 60)));
        self::assertTrue($schedule->covers(DayOfWeek::Monday, TimeRange::fromMinutes(17 * 60, 20 * 60)));
        self::assertFalse($schedule->covers(DayOfWeek::Monday, TimeRange::fromMinutes(19 * 60, 21 * 60)));
        self::assertFalse($schedule->covers(DayOfWeek::Tuesday, TimeRange::fromMinutes(18 * 60, 19 * 60)));
    }

    public function testCoverageWorksAcrossMergedAdjacentRanges(): void
    {
        // Two declared blocks that touch become one row, which is why a session spanning both is
        // covered at all.
        $schedule = WeeklySchedule::build([
            DayOfWeek::Monday->value => [
                TimeRange::fromMinutes(16 * 60, 18 * 60),
                TimeRange::fromMinutes(18 * 60, 21 * 60),
            ],
        ]);

        self::assertTrue($schedule->covers(DayOfWeek::Monday, TimeRange::fromMinutes(17 * 60, 19 * 60)));
    }

    public function testOverlapIsWeakerThanCoverage(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Monday->value => [TimeRange::fromMinutes(16 * 60, 18 * 60)],
        ]);

        $session = TimeRange::fromMinutes(17 * 60, 19 * 60);

        self::assertTrue($schedule->overlaps(DayOfWeek::Monday, $session));
        self::assertFalse($schedule->covers(DayOfWeek::Monday, $session));
    }

    public function testPairsAreOrderedMondayFirst(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Saturday->value => [TimeRange::fromMinutes(9 * 60, 12 * 60)],
            DayOfWeek::Monday->value => [TimeRange::fromMinutes(17 * 60, 18 * 60)],
        ]);

        $pairs = $schedule->pairs();

        self::assertCount(2, $pairs);
        self::assertSame(DayOfWeek::Monday, $pairs[0]['day']);
        self::assertSame(DayOfWeek::Saturday, $pairs[1]['day']);
        self::assertSame(240, $schedule->totalMinutes());
    }

    public function testMidnightBoundariesSurviveNormalization(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Sunday->value => [
                TimeRange::fromMinutes(0, 60),
                TimeRange::fromMinutes(23 * 60, TimeRange::DAY_END_MINUTE),
            ],
        ]);

        $sunday = $schedule->forDay(DayOfWeek::Sunday);

        self::assertCount(2, $sunday, 'the first and last hour of a day are not adjacent');
        self::assertSame('00:00–01:00', $sunday[0]->format());
        self::assertSame('23:00–24:00', $sunday[1]->format());
    }

    public function testAWholeDayIsOneRange(): void
    {
        $blocks = [];

        for ($hour = 0; $hour < 24; ++$hour) {
            $blocks[] = TimeRange::fromMinutes($hour * 60, ($hour + 1) * 60);
        }

        $schedule = WeeklySchedule::build([DayOfWeek::Friday->value => $blocks]);

        self::assertCount(1, $schedule->forDay(DayOfWeek::Friday));
        self::assertSame('00:00–24:00', $schedule->forDay(DayOfWeek::Friday)[0]->format());
    }
}
