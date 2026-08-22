<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Repository\OrganizationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The tenant boundary every organization-scoped query is filtered by (FR-012).
 *
 * Deliberately minimal: branding and profile fields belong to a later task. It
 * exists here because the tenancy primitive cannot be built without it.
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'UNIQ_ORGANIZATION_OWNER', fields: ['owner'])]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $owner;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, User $owner, \DateTimeImmutable $now)
    {
        $this->name = $name;
        $this->owner = $owner;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name, \DateTimeImmutable $now): static
    {
        $this->name = $name;
        $this->updatedAt = $now;

        return $this;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
