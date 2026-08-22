<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\User;
use App\Membership\Entity\ShareLink;
use App\Membership\Entity\ShareLinkRedemption;
use App\Membership\Enum\RedemptionOutcome;
use App\Membership\Repository\ShareLinkRedemptionRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Writes the analytics trail behind every use of a link (FR-047, BR-045).
 *
 * **Persists without flushing**, for the reason `AuditLogger` does: the record and the thing
 * it describes must commit together or not at all. A recorder that flushed on its own would
 * report registrations that later rolled back, and a funnel built on invented conversions is
 * worse than one with a gap.
 */
final readonly class RedemptionRecorder
{
    public function __construct(
        private ShareLinkRedemptionRepository $redemptions,
        private ClockInterface $clock,
    ) {
    }

    public function record(ShareLink $link, User $user, RedemptionOutcome $outcome): ShareLinkRedemption
    {
        $redemption = new ShareLinkRedemption($link, $user, $outcome, $this->clock->now());

        $this->redemptions->add($redemption);

        return $redemption;
    }
}
