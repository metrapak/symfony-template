<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Account\Entity\Organization;
use App\Profile\Repository\OrganizationBrandingRepository;
use App\Profile\ValueObject\HexColor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A trainer's portal branding (FR-071, FR-072, BR-069).
 *
 * One row per organization, which is BR-069 as a constraint: branding is scoped to one tenant
 * and visible to all of its members. A player who trains with two trainers therefore has two
 * brandings available and sees exactly one — whichever their active training context points at
 * (G-26). That resolution happens per request, not per user, and this row holds no opinion
 * about it.
 *
 * `primaryColorHex` is nullable and null means "the platform default". Storing the default
 * string instead would make FR-072's reset-to-default indistinguishable from a trainer who
 * deliberately picked the same colour, and a later change to the default would silently skip
 * every organization that had been reset.
 */
#[ORM\Entity(repositoryClass: OrganizationBrandingRepository::class)]
#[ORM\Table(name: 'organization_branding')]
#[ORM\UniqueConstraint(name: 'UNIQ_ORGANIZATION_BRANDING_ORG', fields: ['organization'])]
class OrganizationBranding
{
    /**
     * The platform's own accent. Used whenever an organization has not chosen one, and the
     * value FR-072's reset restores by clearing the column.
     */
    public const DEFAULT_PRIMARY_COLOR = '#1d4ed8';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    /**
     * Relative to the private upload root, like every other stored file (NFR-066). An SVG is
     * never here: see G-24 and `ImageUploader`.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $primaryColorHex = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Organization $organization, \DateTimeImmutable $now)
    {
        $this->organization = $organization;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function hasLogo(): bool
    {
        return null !== $this->logoPath;
    }

    public function setLogoPath(?string $logoPath, \DateTimeImmutable $now): static
    {
        $this->logoPath = $logoPath;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * The chosen colour, or null when this organization uses the platform default.
     */
    public function getPrimaryColor(): ?HexColor
    {
        return null !== $this->primaryColorHex ? HexColor::tryParse($this->primaryColorHex) : null;
    }

    /**
     * What the layout paints with: the chosen colour or the default, never null.
     */
    public function resolvePrimaryColor(): HexColor
    {
        return $this->getPrimaryColor() ?? self::defaultPrimaryColor();
    }

    public function usesDefaultColor(): bool
    {
        return null === $this->primaryColorHex;
    }

    public function setPrimaryColor(?HexColor $color, \DateTimeImmutable $now): static
    {
        $this->primaryColorHex = $color?->value;
        $this->updatedAt = $now;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public static function defaultPrimaryColor(): HexColor
    {
        $default = HexColor::tryParse(self::DEFAULT_PRIMARY_COLOR);

        // The constant is ours and well formed; asserting it here means a typo in a future
        // edit fails loudly at the first render instead of silently painting nothing.
        return $default ?? throw new \LogicException('The default brand colour is not a valid hex colour.');
    }
}
