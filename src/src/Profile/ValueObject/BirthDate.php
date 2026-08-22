<?php

declare(strict_types=1);

namespace App\Profile\ValueObject;

/**
 * The bridge between the age a parent types and the date the platform stores (FR-063, Q-01.02).
 *
 * FR-063 asks for an age field, and TASK-003 decided the column is a birth date because a
 * stored age is correct on the day it is typed and wrong every year after. Both are true at
 * once, so something has to convert — and the conversion is lossy in a way that matters.
 *
 * An age of 9 means "somewhere in a 365-day window", and this picks the *most recent* birthday
 * consistent with it: a child said to be 9 today is stored as born nine years ago today, so
 * `ageOn()` returns 9 for the next twelve months and never returns 10 early. Choosing the
 * other end of the window would show the parent an age one year above what they entered the
 * very next day, which reads as data corruption. The parent can correct the exact date later
 * on the edit screen; nothing else in the platform depends on the day being right.
 *
 * The 1-18 bound the requirement asks for is checked against a *derived* age rather than
 * against the raw input, so a parent who types a real birth date on the edit form is held to
 * the same rule as one who typed an age on the create form.
 */
final readonly class BirthDate
{
    public const MIN_CHILD_AGE = 1;
    public const MAX_CHILD_AGE = 18;

    private function __construct(public \DateTimeImmutable $value)
    {
    }

    public static function fromDate(\DateTimeImmutable $date): self
    {
        // Midnight, because the column is DATE: leaving a time on it makes two equal birth
        // dates compare unequal in PHP while being one row in the database.
        return new self($date->setTime(0, 0));
    }

    /**
     * @throws \InvalidArgumentException when the age could not be a person's age
     */
    public static function fromAgeOn(int $age, \DateTimeImmutable $today): self
    {
        if ($age < 0 || $age > 130) {
            throw new \InvalidArgumentException(\sprintf('%d is not a plausible age.', $age));
        }

        return self::fromDate($today->modify(\sprintf('-%d years', $age)));
    }

    /**
     * Whole years elapsed, which is what "age" means colloquially and legally.
     *
     * `DateInterval::$y` already truncates, so somebody whose birthday is tomorrow is still
     * the younger age today — the behaviour the birthday-boundary test pins down.
     */
    public function ageOn(\DateTimeImmutable $moment): int
    {
        return $this->value->diff($moment->setTime(0, 0))->y;
    }

    public function isWithinChildRangeOn(\DateTimeImmutable $moment): bool
    {
        $age = $this->ageOn($moment);

        return $age >= self::MIN_CHILD_AGE && $age <= self::MAX_CHILD_AGE;
    }

    public function isInFuture(\DateTimeImmutable $moment): bool
    {
        return $this->value > $moment->setTime(0, 0);
    }
}
