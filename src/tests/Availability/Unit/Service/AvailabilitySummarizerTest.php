<?php

declare(strict_types=1);

namespace App\Tests\Availability\Unit\Service;

use App\Availability\Enum\DayOfWeek;
use App\Availability\Service\AvailabilitySummarizer;
use App\Availability\ValueObject\TimeRange;
use App\Availability\ValueObject\WeeklySchedule;
use PHPUnit\Framework\TestCase;

/**
 * The player card's line, including the spec's own example.
 */
final class AvailabilitySummarizerTest extends TestCase
{
    private AvailabilitySummarizer $summarizer;

    protected function setUp(): void
    {
        $this->summarizer = new AvailabilitySummarizer();
    }

    /**
     * The spec asks for exactly this string: "Best Times: Mon 5-8pm, Wed 6-9pm" (US-01.09).
     */
    public function testProducesTheSpecsExample(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Monday->value => [TimeRange::fromMinutes(17 * 60, 20 * 60)],
            DayOfWeek::Wednesday->value => [TimeRange::fromMinutes(18 * 60, 21 * 60)],
        ]);

        self::assertSame('Mon 5–8pm, Wed 6–9pm', $this->summarizer->summarize($schedule));
    }

    public function testJoinsASplitDayWithAnd(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Monday->value => [
                TimeRange::fromMinutes(16 * 60, 18 * 60),
                TimeRange::fromMinutes(19 * 60, 21 * 60),
            ],
        ]);

        self::assertSame('Mon 4–6pm and 7–9pm', $this->summarizer->summarize($schedule));
    }

    public function testNothingDeclaredReadsAsAnAbsenceOfInformation(): void
    {
        self::assertSame(AvailabilitySummarizer::NOT_SET, $this->summarizer->summarize(WeeklySchedule::empty()));
    }

    /**
     * The distinction that must survive into the wording: a declared "no" is not silence.
     */
    public function testAWeekOfRefusalsReadsAsInformation(): void
    {
        $schedule = WeeklySchedule::build([], [DayOfWeek::Monday, DayOfWeek::Tuesday]);

        self::assertSame(AvailabilitySummarizer::NONE_AVAILABLE, $this->summarizer->summarize($schedule));
    }

    public function testDescribeWeekListsOnlyTheDaysThatSaySomething(): void
    {
        $schedule = WeeklySchedule::build(
            [DayOfWeek::Monday->value => [TimeRange::fromMinutes(17 * 60, 20 * 60)]],
            [DayOfWeek::Wednesday],
        );

        self::assertSame(['Mon 5–8pm', 'Wed not available'], $this->summarizer->describeWeek($schedule));
    }

    public function testMidnightAndNoonAreNamedRatherThanNumbered(): void
    {
        $schedule = WeeklySchedule::build([
            DayOfWeek::Saturday->value => [TimeRange::fromMinutes(9 * 60, 12 * 60)],
            DayOfWeek::Sunday->value => [TimeRange::fromMinutes(22 * 60, TimeRange::DAY_END_MINUTE)],
        ]);

        self::assertSame('Sat 9am–noon, Sun 10pm–midnight', $this->summarizer->summarize($schedule));
    }
}
