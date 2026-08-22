<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use App\Account\Entity\Organization;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Repository\TrainerPlayerAssociationRepository;
use App\Profile\Entity\PlayerProfile;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One player training with one trainer (spec §8, BR-047).
 *
 * This row is what multi-tenancy is made of. A player may hold several of them and sees a
 * separated view per association; a trainer's every organization-scoped query filters on the
 * organization side of it.
 *
 * The unique constraint on `(organization, playerProfile)` is the whole of FR-043's
 * idempotency guarantee. The service checks for an existing association first for the sake of
 * a friendly message, but two simultaneous redemptions of the same link both pass that check
 * — the index is what makes the second one a no-op instead of a duplicate.
 *
 * `viaShareLink` is nullable and `ON DELETE SET NULL`: BR-045 wants to know which link
 * produced a registration, and an association whose link is gone is still a valid membership.
 */
#[ORM\Entity(repositoryClass: TrainerPlayerAssociationRepository::class)]
#[ORM\Table(name: 'trainer_player_association')]
#[ORM\UniqueConstraint(name: 'UNIQ_ASSOCIATION_ORG_PLAYER', fields: ['organization', 'playerProfile'])]
#[ORM\Index(name: 'IDX_ASSOCIATION_ORG_STATUS', columns: ['organization_id', 'status'])]
class TrainerPlayerAssociation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: PlayerProfile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlayerProfile $playerProfile;

    #[ORM\ManyToOne(targetEntity: ShareLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ShareLink $viaShareLink = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $connectedAt;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: MembershipStatus::class)]
    private MembershipStatus $status = MembershipStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function __construct(
        Organization $organization,
        PlayerProfile $playerProfile,
        ?ShareLink $viaShareLink,
        \DateTimeImmutable $connectedAt,
    ) {
        $this->organization = $organization;
        $this->playerProfile = $playerProfile;
        $this->viaShareLink = $viaShareLink;
        $this->connectedAt = $connectedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getPlayerProfile(): PlayerProfile
    {
        return $this->playerProfile;
    }

    public function getViaShareLink(): ?ShareLink
    {
        return $this->viaShareLink;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return MembershipStatus::Active === $this->status;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function deactivate(\DateTimeImmutable $now): static
    {
        $this->status = MembershipStatus::Inactive;
        $this->deactivatedAt = $now;

        return $this;
    }

    /**
     * Brings a previously ended membership back, which is what redeeming a link again has to
     * do: the unique index means there is no second row to create, so reactivating this one
     * is the only way the player rejoins.
     */
    public function reactivate(?ShareLink $viaShareLink, \DateTimeImmutable $now): static
    {
        $this->status = MembershipStatus::Active;
        $this->deactivatedAt = null;
        $this->connectedAt = $now;

        if (null !== $viaShareLink) {
            $this->viaShareLink = $viaShareLink;
        }

        return $this;
    }
}
