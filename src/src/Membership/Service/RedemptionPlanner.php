<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Membership\Dto\RedemptionPlan;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\ShareLinkType;
use App\Profile\Repository\PlayerProfileRepository;

/**
 * Decides what a given visitor may do with a given link.
 *
 * Pulled out of the controller because it is the part of the redemption flow with real rules
 * in it — BR-046's child block, the coach/player link mismatch, whether a family checklist is
 * needed — and because those rules then have unit tests that do not need an HTTP kernel.
 *
 * It answers only "what should happen"; nothing here writes. The controller runs the plan, and
 * the services it calls re-check what they enforce, so a plan that was correct when the page
 * rendered cannot authorize a submit made after the link was withdrawn.
 */
final readonly class RedemptionPlanner
{
    public function __construct(
        private PlayerProfileRepository $profiles,
    ) {
    }

    public function planFor(ShareLink $link, ?User $user): RedemptionPlan
    {
        if (null === $user) {
            return ShareLinkType::Coach === $link->getType()
                ? RedemptionPlan::registerCoach()
                : RedemptionPlan::registerPlayer();
        }

        return match ($user->getRole()) {
            UserRole::Player => $this->planForPlayer($link, $user),
            UserRole::Coach => ShareLinkType::Coach === $link->getType()
                ? RedemptionPlan::acceptCoachInvitation()
                : RedemptionPlan::notEligible('This is a player invitation, and you are signed in as a coach. Ask the trainer for a coach invitation instead.'),
            UserRole::Trainer => RedemptionPlan::notEligible('You are signed in as a trainer. Sign out first if you want to join another trainer\'s program.'),
            UserRole::SuperAdmin => RedemptionPlan::notEligible('Administrator accounts cannot join a trainer\'s program.'),
        };
    }

    private function planForPlayer(ShareLink $link, User $user): RedemptionPlan
    {
        if (ShareLinkType::Coach === $link->getType()) {
            return RedemptionPlan::notEligible('This invitation is for a coach. Ask your trainer for a player link instead.');
        }

        $profile = $this->profiles->findProfileForAccount($user);

        // FR-048 / BR-046. Checked before anything else a player may do, because a child
        // account holds ROLE_PLAYER like any other and would otherwise fall straight through
        // into the association branch.
        if (null !== $profile && $profile->isChild()) {
            return RedemptionPlan::blockChild($profile);
        }

        return RedemptionPlan::associatePlayer($this->profiles->findManagedBy($user));
    }
}
