<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Membership\Entity\CoachAssignment;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\CoachInvitationStatus;

/**
 * One row of the trainer's Coaches list (FR-046, US-01.08).
 *
 * Pairs an invitation with the assignment it produced, if any, so the template renders a
 * status rather than deciding one. Templates that compute state end up disagreeing with the
 * services that enforce it.
 */
final readonly class CoachInvitationView
{
    public function __construct(
        public ShareLink $link,
        public ?CoachAssignment $assignment,
        public CoachInvitationStatus $status,
    ) {
    }

    public function coachName(): string
    {
        return $this->assignment?->getCoach()->getDisplayName()
            ?? $this->link->getTargetName()
            ?? (string) $this->link->getTargetEmail();
    }

    public function email(): string
    {
        return $this->assignment?->getCoach()->getEmail() ?? (string) $this->link->getTargetEmail();
    }

    public function canBeResent(): bool
    {
        return CoachInvitationStatus::Accepted !== $this->status;
    }
}
