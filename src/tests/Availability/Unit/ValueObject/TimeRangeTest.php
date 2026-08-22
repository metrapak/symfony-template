<?php

declare(strict_types=1);

namespace App\Tests\Availability\Unit\ValueObject;

use App\Availability\ValueObject\TimeRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The boundary rules the trainer filter's meaning rests on.
 *
 * Every case here is one the requirement calls out: end before start, ranges that touch without
 * overlapping, ranges that share an endpoint while genuinely intersecting, merging adjacent
 * blocks, and midnight at both ends of the day.
 */
final class TimeRangeTest extends TestCase
{
    public function testRejectsAnEndAtOrBeforeItsStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TimeRange::fromMinutes(19 * 60, 17 * 60);
    }

    public function testRejectsAnEmptyRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TimeRange::fromMinutes(17 * 60, 17 * 60);
    }

    public function testRejectsATimePastMidnight(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TimeRange::fromMinutes(23 * 60, 25 * 60);
    }

    public function testAcceptsMidnightAsAnEnd(): void
    {
        $range = TimeRange::fromMinutes(22 * 60, TimeRange::DAY_END_MINUTE);

        self::assertSame(120, $range->durationMinutes());
        self::assertSame('22:00–24:00', $range->format());
    }

    public function testTryFromMinutesAnswersNullRatherThanThrowing(): void
    {
        self::assertNull(TimeRange::tryFromMinutes(19 * 60, 17 * 60));
        self::assertNull(TimeRange::tryFromMinutes(null, 17 * 60));
        self::assertNotNull(TimeRange::tryFromMinutes(17 * 60, 19 * 60));
    }

    /**
     * Adjacency is not overlap. This is the distinction the filter's SQL mirrors, and the one a
     * naive `<=` would erase.
     */
    public function testAdjacentRangesTouchButDoNotOverlap(): void
    {
        $afternoon = TimeRange::fromMinutes(17 * 60, 18 * 60);
        $evening = TimeRange::fromMinutes(18 * 60, 19 * 60);

        self::assertFalse($afternoon->overlaps($evening));
        self::assertFalse($evening->overlaps($afternoon));
        self::assertTrue($afternoon->isAdjacentTo($evening));
        self::assertTrue($afternoon->touches($evening));
    }

    public function testRangesSharingABoundaryWhileIntersectingDoOverlap(): void
    {
        $evening = TimeRange::fromMinutes(17 * 60, 20 * 60);
        $firstHour = TimeRange::fromMinutes(17 * 60, 18 * 60);
        $lastHour = TimeRange::fromMinutes(19 * 60, 20 * 60);

        self::assertTrue($evening->overlaps($firstHour));
        self::assertTrue($evening->overlaps($lastHour));
        self::assertTrue($firstHour->overlaps($evening));
    }

    public function testCoverageIsInclusiveAtBothBoundaries(): void
    {
        $declared = TimeRange::fromMinutes(17 * 60, 20 * 60);

        self::assertTrue($declared->covers(TimeRange::fromMinutes(17 * 60, 20 * 60)), 'an identical window');
        self::assertTrue($declared->covers(TimeRange::fromMinutes(17 * 60, 18 * 60)), 'touching the start');
        self::assertTrue($declared->covers(TimeRange::fromMinutes(19 * 60, 20 * 60)), 'touching the end');
        self::assertFalse($declared->covers(TimeRange::fromMinutes(16 * 60, 18 * 60)), 'starting earlier');
        self::assertFalse($declared->covers(TimeRange::fromMinutes(19 * 60, 21 * 60)), 'ending later');
    }

    public function testPartialOverlapIsNotCoverage(): void
    {
        // The case the requirement is really about: a coach free 16:00-18:00 cannot take a session
        // that runs to 19:00, however much of it they could attend.
        $declared = TimeRange::fromMinutes(16 * 60, 18 * 60);
        $session = TimeRange::fromMinutes(17 * 60, 19 * 60);

        self::assertTrue($declared->overlaps($session));
        self::assertFalse($declared->covers($session));
    }

    public function testMergesAdjacentRanges(): void
    {
        $merged = TimeRange::fromMinutes(17 * 60, 18 * 60)->merge(TimeRange::fromMinutes(18 * 60, 20 * 60));

        self::assertSame('17:00–20:00', $merged->format());
    }

    public function testMergesOverlappingRangesToTheirUnion(): void
    {
        $merged = TimeRange::fromMinutes(17 * 60, 19 * 60)->merge(TimeRange::fromMinutes(18 * 60, 20 * 60));

        self::assertSame('17:00–20:00', $merged->format());
    }

    public function testRefusesToMergeAcrossAGap(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TimeRange::fromMinutes(16 * 60, 18 * 60)->merge(TimeRange::fromMinutes(19 * 60, 21 * 60));
    }

    public function testEqualityAndOrdering(): void
    {
        $first = TimeRange::fromMinutes(16 * 60, 18 * 60);
        $second = TimeRange::fromMinutes(16 * 60, 19 * 60);
        $third = TimeRange::fromMinutes(19 * 60, 21 * 60);

        self::assertTrue($first->equals(TimeRange::fromMinutes(16 * 60, 18 * 60)));
        self::assertFalse($first->equals($second));
        self::assertLessThan(0, TimeRange::compare($first, $second));
        self::assertLessThan(0, TimeRange::compare($second, $third));
        self::assertGreaterThan(0, TimeRange::compare($third, $first));
    }

    public function testContainsMinuteIsHalfOpen(): void
    {
        $range = TimeRange::fromMinutes(17 * 60, 18 * 60);

        self::assertTrue($range->containsMinute(17 * 60));
        self::assertTrue($range->containsMinute(17 * 60 + 59));
        self::assertFalse($range->containsMinute(18 * 60));
    }

    public function testBlockIsClampedToMidnight(): void
    {
        $block = TimeRange::block(23 * 60 + 30, 60);

        self::assertSame(TimeRange::DAY_END_MINUTE, $block->endMinute);
    }

    #[DataProvider('compactForms')]
    public function testCompactFormatting(int $startMinute, int $endMinute, string $expected): void
    {
        self::assertSame($expected, TimeRange::fromMinutes($startMinute, $endMinute)->formatCompact());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function compactForms(): iterable
    {
        // The spec's own example: "Best Times: Mon 5-8pm".
        yield 'both in the afternoon share one meridiem' => [17 * 60, 20 * 60, '5–8pm'];
        yield 'crossing noon keeps both' => [11 * 60, 13 * 60, '11am–1pm'];
        yield 'half hours are printed' => [17 * 60 + 30, 19 * 60, '5:30–7pm'];
        yield 'noon is named' => [10 * 60, 12 * 60, '10am–noon'];
        yield 'midnight is named at the end' => [22 * 60, TimeRange::DAY_END_MINUTE, '10pm–midnight'];
        yield 'midnight is named at the start' => [0, 6 * 60, 'midnight–6am'];
        yield 'noon to midnight' => [12 * 60, TimeRange::DAY_END_MINUTE, 'noon–midnight'];
    }

    #[DataProvider('spokenForms')]
    public function testSpokenFormatting(int $startMinute, int $endMinute, string $expected): void
    {
        self::assertSame($expected, TimeRange::fromMinutes($startMinute, $endMinute)->formatSpoken());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function spokenForms(): iterable
    {
        yield 'an evening hour' => [17 * 60, 18 * 60, '5:00 PM to 6:00 PM'];
        yield 'a morning hour' => [9 * 60, 10 * 60, '9:00 AM to 10:00 AM'];
        yield 'noon' => [12 * 60, 13 * 60, '12:00 PM to 1:00 PM'];
        yield 'the first hour of the day' => [0, 60, 'midnight to 1:00 AM'];
        yield 'the last hour of the day' => [23 * 60, TimeRange::DAY_END_MINUTE, '11:00 PM to midnight'];
    }
}
