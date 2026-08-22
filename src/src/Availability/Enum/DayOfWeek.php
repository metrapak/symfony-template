<?php

declare(strict_types=1);

namespace App\Availability\Enum;

/**
 * A weekday in a recurring availability pattern (FR-080, FR-082).
 *
 * Backed by the ISO-8601 numbers PHP's own `date('N')` produces, so a `\DateTimeImmutable` maps
 * onto a case without a lookup table and Monday sorts first — which is the order every grid in
 * this task renders and the order `ORDER BY day_of_week` gives for free.
 *
 * A `SMALLINT` column rather than a string: the availability index is `(subject_type,
 * day_of_week, …)` and is read on every trainer filter (NFR-080), so the narrower key is the one
 * worth having. The enum is what keeps `3` from meaning Tuesday somewhere and Wednesday
 * somewhere else.
 */
enum DayOfWeek: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public static function fromDate(\DateTimeImmutable $moment): self
    {
        return self::from((int) $moment->format('N'));
    }

    /**
     * Every day, Monday first — the canonical order for a week of anything in this module.
     *
     * `self::cases()` already returns them in declaration order; this exists so a template or a
     * form does not have to know that the declaration order happens to be the display order.
     *
     * @return list<self>
     */
    public static function week(): array
    {
        return self::cases();
    }

    /**
     * The five days a "copy Monday to all weekdays" control applies to (a convenience the grid
     * offers, and the reason the set is named here rather than spelled out in JavaScript).
     *
     * @return list<self>
     */
    public static function weekdays(): array
    {
        return [self::Monday, self::Tuesday, self::Wednesday, self::Thursday, self::Friday];
    }

    public function label(): string
    {
        return match ($this) {
            self::Monday => 'Monday',
            self::Tuesday => 'Tuesday',
            self::Wednesday => 'Wednesday',
            self::Thursday => 'Thursday',
            self::Friday => 'Friday',
            self::Saturday => 'Saturday',
            self::Sunday => 'Sunday',
        };
    }

    /**
     * The three-letter form the spec's player card uses: "Best Times: Mon 5-8pm, Wed 6-9pm".
     */
    public function shortLabel(): string
    {
        return substr($this->label(), 0, 3);
    }

    /**
     * A stable, lowercase key for form field names and template lookups.
     *
     * The form's `days` collection is keyed by this rather than by the integer, because a Twig
     * template indexing `form.days[1]` reads as an array offset and hides which day it means.
     */
    public function key(): string
    {
        return strtolower($this->label());
    }

    public static function fromKey(string $key): self
    {
        foreach (self::cases() as $day) {
            if ($day->key() === strtolower(trim($key))) {
                return $day;
            }
        }

        throw new \ValueError(\sprintf('"%s" is not a day of the week.', $key));
    }
}
