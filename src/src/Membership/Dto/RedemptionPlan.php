<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Membership\Enum\RedemptionAction;
use App\Profile\Entity\PlayerProfile;

/**
 * The decision `RedemptionPlanner` reached, and the data the chosen branch needs.
 */
final readonly class RedemptionPlan
{
    /**
     * @param list<PlayerProfile> $profiles family members who may be selected (AssociatePlayer only)
     * @param PlayerProfile|null $childProfile the refused child (BlockChild only)
     * @param string|null $reason why this visitor cannot use this link (NotEligible only)
     */
    private function __construct(
        public RedemptionAction $action,
        public array $profiles = [],
        public ?PlayerProfile $childProfile = null,
        public ?string $reason = null,
    ) {
    }

    public static function registerPlayer(): self
    {
        return new self(RedemptionAction::RegisterPlayer);
    }

    public static function registerCoach(): self
    {
        return new self(RedemptionAction::RegisterCoach);
    }

    /**
     * @param list<PlayerProfile> $profiles
     */
    public static function associatePlayer(array $profiles): self
    {
        return new self(RedemptionAction::AssociatePlayer, profiles: $profiles);
    }

    public static function acceptCoachInvitation(): self
    {
        return new self(RedemptionAction::AcceptCoachInvitation);
    }

    public static function blockChild(PlayerProfile $child): self
    {
        return new self(RedemptionAction::BlockChild, childProfile: $child);
    }

    public static function notEligible(string $reason): self
    {
        return new self(RedemptionAction::NotEligible, reason: $reason);
    }

    public function is(RedemptionAction $action): bool
    {
        return $action === $this->action;
    }
}
