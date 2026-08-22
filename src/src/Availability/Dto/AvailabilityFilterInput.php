<?php

declare(strict_types=1);

namespace App\Availability\Dto;

use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\TimeRange;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The trainer's "show players available on {day} at {time}" filter (FR-084, spec §10 Flow 6).
 *
 * Every field is optional, and that is the default state of the screen: a trainer arriving at
 * the availability view sees their whole roster with each player's Best Times, and filters only
 * when they are planning a particular session. An empty filter is therefore not an error — it is
 * the page.
 *
 * A window rather than a single instant, because a session has a length: "who can make Monday
 * 5-7?" is the question a trainer is actually asking, and a point-in-time filter would return
 * players who have to leave at half past.
 */
final class AvailabilityFilterInput
{
    public ?DayOfWeek $day = null;

    public ?int $startMinute = null;

    public ?int $endMinute = null;

    /**
     * A filter is applied only when all three parts are present.
     *
     * Partial input is treated as "still choosing" rather than as an error, so the page does not
     * shout at a trainer who has picked a day and is reaching for the time.
     */
    public function isApplied(): bool
    {
        return null !== $this->day && null !== $this->startMinute && null !== $this->endMinute;
    }

    public function window(): ?TimeRange
    {
        return TimeRange::tryFromMinutes($this->startMinute, $this->endMinute);
    }

    /**
     * The one thing worth rejecting: a window that ends before it starts.
     *
     * Out-of-range minutes cannot get here — the form's choice lists come from `TimeGrid`, so a
     * value it does not offer is refused before validation — but 19:00 to 17:00 is two valid
     * choices that make no window, and silently swapping them would answer a question the
     * trainer did not ask.
     */
    #[Assert\Callback]
    public function validateWindow(ExecutionContextInterface $context): void
    {
        if (null === $this->startMinute || null === $this->endMinute) {
            return;
        }

        if ($this->endMinute <= $this->startMinute) {
            $context->buildViolation('The end time has to be after the start time.')
                ->atPath('endMinute')
                ->addViolation();
        }
    }
}
