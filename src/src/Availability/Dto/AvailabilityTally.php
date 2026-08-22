<?php

declare(strict_types=1);

namespace App\Availability\Dto;

/**
 * "Players available at this time: 15 of 20" (FR-083, spec §10 Flow 6).
 *
 * Three numbers rather than two, because the spec's sentence hides an ambiguity its own gap
 * analysis notes: the denominator is every player associated with the organization, and some of
 * them have never filled the grid in. Counting those as unavailable would tell a trainer that
 * five players are busy when the truth is that nobody knows — so `undeclared` is reported
 * separately and the view says so.
 *
 * FR-088 applies to every number here: they inform a decision and constrain nothing.
 */
final readonly class AvailabilityTally
{
    public function __construct(
        /** Players whose declared availability covers the whole window. */
        public int $available,
        /** Players who have declared a week at all, available or not. */
        public int $declared,
        /** Every player in scope — the denominator. */
        public int $total,
    ) {
    }

    public function undeclared(): int
    {
        return max(0, $this->total - $this->declared);
    }

    /** Declared a week, and it does not cover this window. */
    public function unavailable(): int
    {
        return max(0, $this->declared - $this->available);
    }

    /** The sentence the spec asks for, built in one place so both views say it identically. */
    public function summary(): string
    {
        return \sprintf('%d of %d', $this->available, $this->total);
    }
}
