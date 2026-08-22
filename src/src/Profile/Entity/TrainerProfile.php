<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Profile\Repository\TrainerProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A trainer's business details (FR-061, "Trainer: business name, organization details").
 *
 * Keyed on the organization and unique on it, not on the user: `Organization` already has
 * exactly one owner (UNIQ_ORGANIZATION_OWNER), so keying on the tenant says the same thing
 * while making the row reachable from the side every reader approaches it from — a player
 * looking at "who am I training with" has an organization in hand, not a user.
 *
 * `businessName` is separate from `Organization::getName()` and both are kept. The
 * organization's name is what the platform calls the tenant everywhere, including in the
 * Super Admin directory; the business name is the trading name a trainer wants parents to
 * read. They are usually the same string and occasionally not, and overwriting one with the
 * other would let a trainer rename a tenant that administrators are looking at.
 */
#[ORM\Entity(repositoryClass: TrainerProfileRepository::class)]
#[ORM\Table(name: 'trainer_profile')]
#[ORM\UniqueConstraint(name: 'UNIQ_TRAINER_PROFILE_ORG', fields: ['organization'])]
class TrainerProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Organization $organization, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->organization = $organization;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getBusinessName(): ?string
    {
        return $this->businessName;
    }

    /**
     * What a player should see this trainer called: the trading name if one was given,
     * otherwise the tenant's name. One accessor, so no template has to remember the fallback.
     */
    public function getDisplayName(): string
    {
        return $this->businessName ?? $this->organization->getName();
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function update(
        ?string $businessName,
        ?string $address,
        ?string $website,
        ?string $description,
        \DateTimeImmutable $now,
    ): static {
        $this->businessName = self::blankToNull($businessName);
        $this->address = self::blankToNull($address);
        $this->website = self::blankToNull($website);
        $this->description = self::blankToNull($description);
        $this->updatedAt = $now;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
