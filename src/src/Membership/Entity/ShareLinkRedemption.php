<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use App\Account\Entity\User;
use App\Membership\Enum\RedemptionOutcome;
use App\Membership\Repository\ShareLinkRedemptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One recorded use of a ShareLink: which link, by whom, when, and what it produced (FR-047).
 *
 * Append-only by construction — no setters, no `updatedAt`. Epic-06 reads this table to answer
 * "where did this player come from" and "how is this campaign converting", and a row that can
 * be edited after the fact answers neither.
 *
 * The count on `share_link.use_count` is kept separately rather than derived from `COUNT(*)`
 * here: the counter is what the single-use limit is enforced against inside one atomic UPDATE,
 * and an aggregate over another table cannot be part of that statement.
 */
#[ORM\Entity(repositoryClass: ShareLinkRedemptionRepository::class)]
#[ORM\Table(name: 'share_link_redemption')]
#[ORM\Index(name: 'IDX_REDEMPTION_LINK_TIME', columns: ['share_link_id', 'redeemed_at'])]
class ShareLinkRedemption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShareLink::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShareLink $shareLink;

    /**
     * Never hard-deleted, hence RESTRICT: accounts are anonymized in place, so this row keeps
     * pointing at the same id and the funnel totals stay numerically true (FR-026).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $redeemedAt;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: RedemptionOutcome::class)]
    private RedemptionOutcome $outcome;

    public function __construct(
        ShareLink $shareLink,
        User $user,
        RedemptionOutcome $outcome,
        \DateTimeImmutable $redeemedAt,
    ) {
        $this->shareLink = $shareLink;
        $this->user = $user;
        $this->outcome = $outcome;
        $this->redeemedAt = $redeemedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShareLink(): ShareLink
    {
        return $this->shareLink;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRedeemedAt(): \DateTimeImmutable
    {
        return $this->redeemedAt;
    }

    public function getOutcome(): RedemptionOutcome
    {
        return $this->outcome;
    }
}
