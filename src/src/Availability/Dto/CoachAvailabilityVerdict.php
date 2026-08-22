<?php

declare(strict_types=1);

namespace App\Availability\Dto;

use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\TimeRange;

/**
 * The answer `CoachAvailabilityChecker` gives, and the contract Epic-02's assignment flow codes
 * against (FR-085, FR-088).
 *
 * A three-state answer, not a boolean, and that is the whole reason the type exists:
 *
 *  - **available** — the coach's declared week covers the window. Assign, say nothing.
 *  - **conflict** — they have declared a week and it does not cover the window. Warn, and let
 *    the trainer override with a reason (FR-086).
 *  - **undeclared** — they have never filled My Times in. There is nothing to conflict with, so
 *    there is nothing to warn about and nothing to override; warning here would train trainers
 *    to click through a message that means "no data".
 *
 * `conflict()` is therefore not `!available` — a caller that wrote it that way would demand an
 * override reason from a trainer whose coach has simply never used the feature.
 *
 * Nothing on this type can block an assignment. FR-088 is a property of the caller, and it is
 * stated here because this is where a future caller will look for permission it does not have.
 */
final readonly class CoachAvailabilityVerdict
{
    /**
     * @param list<TimeRange> $declaredWindows what the coach *did* declare for that day, so the
     *                                         warning can say "they are free 4-6pm" rather than
     *                                         only "not then"
     */
    public function __construct(
        public bool $available,
        public bool $declared,
        public DayOfWeek $day,
        public TimeRange $window,
        public array $declaredWindows = [],
    ) {
    }

    public function conflict(): bool
    {
        return $this->declared && !$this->available;
    }

    public function requiresOverride(): bool
    {
        return $this->conflict();
    }

    /**
     * FR-085's warning, verbatim from the acceptance criterion.
     */
    public function warning(string $coachName): string
    {
        return \sprintf('Coach %s is not available at this time per their schedule. Continue anyway?', $coachName);
    }

    /** What the coach declared for that day, in words, or null when they declared nothing. */
    public function declaredSummary(): ?string
    {
        if (!$this->declared) {
            return null;
        }

        if ([] === $this->declaredWindows) {
            return \sprintf('%s: not available', $this->day->label());
        }

        return \sprintf(
            '%s: %s',
            $this->day->label(),
            implode(', ', array_map(static fn (TimeRange $range): string => $range->formatCompact(), $this->declaredWindows)),
        );
    }
}
