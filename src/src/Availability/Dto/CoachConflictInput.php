<?php

declare(strict_types=1);

namespace App\Availability\Dto;

use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\TimeRange;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The window a trainer wants to schedule a coach for, and the reason if they are overriding a
 * conflict (FR-085, FR-086).
 *
 * One object across both steps of the flow rather than two, because the second step has to carry
 * the first step's window: an override recorded against a window the trainer can no longer see
 * on screen is an override of something else.
 *
 * `reason` is required **only when `confirm` is set**, through the `override` validation group.
 * That is what makes FR-086's two behaviours a single form: checking for a conflict must not
 * demand an explanation, and going ahead past one must. A blank reason on a confirming submit is
 * a validation error and the override is not recorded — the acceptance criterion phrased as code.
 *
 * `eventId` is the hook for Epic-02. Nothing in this task supplies one; it is here so the
 * override this flow records and the override Epic-02's assignment screen records are the same
 * write, rather than two writers with two opinions about the same table.
 */
final class CoachConflictInput
{
    #[Assert\NotNull(message: 'Choose a day.')]
    public ?DayOfWeek $day = null;

    #[Assert\NotNull(message: 'Choose a start time.')]
    public ?int $startMinute = null;

    #[Assert\NotNull(message: 'Choose an end time.')]
    public ?int $endMinute = null;

    /**
     * FR-086's required explanation. Length-capped so the column holds a reason and not a log.
     */
    #[Assert\NotBlank(message: 'Enter a reason for scheduling this coach outside their stated times.', groups: ['override'])]
    #[Assert\Length(max: 2000, groups: ['override'])]
    public ?string $reason = null;

    /** Set by the "Continue anyway" submit, which is what turns the `override` group on. */
    public bool $confirm = false;

    public ?int $eventId = null;

    public function window(): ?TimeRange
    {
        return TimeRange::tryFromMinutes($this->startMinute, $this->endMinute);
    }

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

    public function requireDay(): DayOfWeek
    {
        return $this->day ?? throw new \LogicException('A validated conflict check always has a day.');
    }

    public function requireWindow(): TimeRange
    {
        return $this->window() ?? throw new \LogicException('A validated conflict check always has a window.');
    }
}
