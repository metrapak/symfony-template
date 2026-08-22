<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Membership\Dto\CoachInvitationView;
use App\Membership\Enum\CoachInvitationStatus;
use App\Membership\Repository\CoachAssignmentRepository;
use App\Membership\Repository\ShareLinkRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Builds the trainer's Coaches list (FR-046).
 *
 * A read model, and the only place the three invitation states are derived. Storing the state
 * on the row would mean a pending invitation silently becomes wrong the minute it expires,
 * unless something runs at midnight to fix it; deriving it means the answer is right whenever
 * somebody asks.
 *
 * Organization-scoped by an id passed in, like every other query in this epic.
 */
final readonly class CoachDirectory
{
    public function __construct(
        private ShareLinkRepository $links,
        private CoachAssignmentRepository $assignments,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<CoachInvitationView>
     */
    public function listFor(int $organizationId): array
    {
        $now = $this->clock->now();
        $views = [];

        foreach ($this->links->findCoachInvitationsFor($organizationId) as $link) {
            $assignment = $this->assignments->findOneByShareLink($link);

            $views[] = new CoachInvitationView($link, $assignment, match (true) {
                null !== $assignment => CoachInvitationStatus::Accepted,
                !$link->isActive() || $link->isExpired($now) => CoachInvitationStatus::Expired,
                default => CoachInvitationStatus::Pending,
            });
        }

        return $views;
    }
}
