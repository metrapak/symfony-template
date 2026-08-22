<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit\ValueObject;

use App\Profile\ValueObject\BirthDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-063, BR-068 and Q-01.02 — the age a parent types, stored as a date.
 *
 * The birthday boundary is the whole point of this class: an age that ticks over a day early,
 * or a day late, is the bug that makes a 19-year-old fail the 1-18 rule while they are still
 * 18. Every case below fixes a clock explicitly rather than reading the real one.
 */
final class BirthDateTest extends TestCase
{
    private const TODAY = '2026-08-22';

    private static function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 00:00:00');
    }

    public function testAgeIsWholeYearsElapsed(): void
    {
        $birthDate = BirthDate::fromDate(self::at('2016-08-22'));

        self::assertSame(10, $birthDate->ageOn(self::at(self::TODAY)));
    }

    /**
     * The boundary itself: still nine the day before, ten on the day, ten the day after.
     */
    public function testAgeTicksOverOnTheBirthdayAndNotBefore(): void
    {
        $birthDate = BirthDate::fromDate(self::at('2016-08-22'));

        self::assertSame(9, $birthDate->ageOn(self::at('2026-08-21')));
        self::assertSame(10, $birthDate->ageOn(self::at('2026-08-22')));
        self::assertSame(10, $birthDate->ageOn(self::at('2026-08-23')));
    }

    /**
     * A time component on either side must not shift the answer — the column is a DATE.
     */
    public function testTimeOfDayDoesNotAffectTheAge(): void
    {
        $birthDate = BirthDate::fromDate(new \DateTimeImmutable('2016-08-22 18:45:00'));

        self::assertSame('00:00:00', $birthDate->value->format('H:i:s'));
        self::assertSame(10, $birthDate->ageOn(new \DateTimeImmutable('2026-08-22 06:15:00')));
        self::assertSame(10, $birthDate->ageOn(new \DateTimeImmutable('2026-08-22 23:59:59')));
    }

    /**
     * 29 February has no anniversary in a common year. Whichever way the platform rounds, it
     * must not report someone as a year older before 1 March.
     */
    public function testLeapDayBirthdayDoesNotAgeEarlyInACommonYear(): void
    {
        $birthDate = BirthDate::fromDate(self::at('2016-02-29'));

        self::assertSame(8, $birthDate->ageOn(self::at('2025-02-28')));
        self::assertSame(9, $birthDate->ageOn(self::at('2025-03-01')));
    }

    /**
     * The lossy conversion FR-063 forces, and the direction it must round: a child said to be
     * nine today reads as nine tomorrow, never as ten.
     */
    #[DataProvider('ages')]
    public function testAnAgeRoundTripsThroughTheStoredDate(int $age): void
    {
        $today = self::at(self::TODAY);
        $birthDate = BirthDate::fromAgeOn($age, $today);

        self::assertSame($age, $birthDate->ageOn($today));
        self::assertSame($age, $birthDate->ageOn($today->modify('+1 day')));
        self::assertSame($age, $birthDate->ageOn($today->modify('+364 days')));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function ages(): iterable
    {
        foreach ([1, 5, 9, 13, 17, 18] as $age) {
            yield $age . ' years old' => [$age];
        }
    }

    public function testImplausibleAgesAreRefused(): void
    {
        $today = self::at(self::TODAY);

        $this->expectException(\InvalidArgumentException::class);
        BirthDate::fromAgeOn(-1, $today);
    }

    public function testAbsurdlyHighAgesAreRefused(): void
    {
        $today = self::at(self::TODAY);

        $this->expectException(\InvalidArgumentException::class);
        BirthDate::fromAgeOn(131, $today);
    }

    /**
     * BR-068 — the 1-18 window, checked at both edges and just outside them.
     */
    public function testChildRangeCoversOneToEighteenInclusive(): void
    {
        $today = self::at(self::TODAY);

        self::assertFalse(BirthDate::fromAgeOn(0, $today)->isWithinChildRangeOn($today));
        self::assertTrue(BirthDate::fromAgeOn(1, $today)->isWithinChildRangeOn($today));
        self::assertTrue(BirthDate::fromAgeOn(18, $today)->isWithinChildRangeOn($today));
        self::assertFalse(BirthDate::fromAgeOn(19, $today)->isWithinChildRangeOn($today));
    }

    /**
     * G-22 — a profile created inside the range ages out of it on its nineteenth birthday, and
     * not a day sooner. The requirement leaves what happens next open; this pins when.
     */
    public function testAProfileAgesOutOfTheChildRangeOnTheNineteenthBirthday(): void
    {
        $birthDate = BirthDate::fromDate(self::at('2007-08-22'));

        self::assertTrue($birthDate->isWithinChildRangeOn(self::at('2026-08-21')));
        self::assertFalse($birthDate->isWithinChildRangeOn(self::at('2026-08-22')));
    }

    public function testFutureDatesAreRecognized(): void
    {
        $today = self::at(self::TODAY);

        self::assertTrue(BirthDate::fromDate(self::at('2026-08-23'))->isInFuture($today));
        self::assertFalse(BirthDate::fromDate(self::at('2026-08-22'))->isInFuture($today));
        self::assertFalse(BirthDate::fromDate(self::at('2016-01-01'))->isInFuture($today));
    }
}
