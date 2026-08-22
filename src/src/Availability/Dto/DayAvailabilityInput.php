<?php

declare(strict_types=1);

namespace App\Availability\Dto;

use App\Availability\Enum\DayOfWeek;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One column of the availability grid: a day, its ticked blocks, and the "not available" toggle
 * FR-080 asks for.
 *
 * Mutable because Symfony Forms writes into it. The day is set on construction and never
 * submitted — a day arriving from the request would be a field the form does not need and an
 * identifier somebody could tamper with.
 */
final class DayAvailabilityInput
{
    /**
     * Start minutes of the ticked blocks. Values are constrained by the form's choice list, so
     * a forged minute is rejected before validation runs.
     *
     * @var list<int>
     */
    public array $slots = [];

    /** FR-080's explicit "Wednesday: Not Available". */
    public bool $unavailable = false;

    public function __construct(
        public readonly DayOfWeek $day,
    ) {
    }

    /**
     * @param list<int> $slots
     */
    public static function with(DayOfWeek $day, array $slots, bool $unavailable = false): self
    {
        $input = new self($day);
        $input->slots = $slots;
        $input->unavailable = $unavailable;

        return $input;
    }

    /**
     * Rejects a day that says both things at once.
     *
     * Honouring one silently would be worse: a family who ticks two evening hours and then marks
     * the day unavailable has contradicted themselves, and whichever half the code discarded
     * would look like the save had lost their work.
     */
    #[Assert\Callback]
    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if ($this->unavailable && [] !== $this->slots) {
            $context->buildViolation(\sprintf(
                '%s is marked as not available, so it cannot also have selected times. Clear the times or the "not available" box.',
                $this->day->label(),
            ))
                ->atPath('unavailable')
                ->addViolation();
        }
    }

    public function isEmpty(): bool
    {
        return [] === $this->slots && !$this->unavailable;
    }
}
