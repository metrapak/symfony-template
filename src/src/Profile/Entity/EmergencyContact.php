<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Account\Entity\User;
use App\Profile\Repository\EmergencyContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who to call about this family (FR-061, BR-064).
 *
 * Hangs off the *parent account* rather than off a child profile, which is BR-064 stated as a
 * schema: "the parent owns all contact information for the family". Repeating the same
 * emergency number on three sibling rows would let them drift, and a trainer ringing the stale
 * one is the failure this table exists to prevent.
 *
 * The spec lists "emergency contact info" for parents and never says how many, so the schema
 * allows several — a mother and a grandparent are two different people to call, and a
 * one-to-one column would force the parent to choose. `displayOrder` is what makes "call this
 * one first" expressible.
 *
 * `relationship` is free text. The set of relationships a family can have is not enumerable
 * ("neighbour", "au pair", "my sister-in-law"), and an enum here would only teach parents to
 * pick "Other".
 */
#[ORM\Entity(repositoryClass: EmergencyContactRepository::class)]
#[ORM\Table(name: 'emergency_contact')]
#[ORM\Index(name: 'IDX_EMERGENCY_CONTACT_PARENT', columns: ['parent_id', 'display_order'])]
class EmergencyContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $parent;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $relationship;

    /**
     * Stored in the normalized form `PhoneNumber` produces, so the directory does not hold two
     * spellings of one number.
     */
    #[ORM\Column(length: 32)]
    private string $phone;

    #[ORM\Column(options: ['default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $parent,
        string $name,
        string $relationship,
        string $phone,
        int $displayOrder,
        \DateTimeImmutable $now,
    ) {
        $this->parent = $parent;
        $this->name = $name;
        $this->relationship = $relationship;
        $this->phone = $phone;
        $this->displayOrder = $displayOrder;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): User
    {
        return $this->parent;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRelationship(): string
    {
        return $this->relationship;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function update(
        string $name,
        string $relationship,
        string $phone,
        int $displayOrder,
        \DateTimeImmutable $now,
    ): static {
        $this->name = $name;
        $this->relationship = $relationship;
        $this->phone = $phone;
        $this->displayOrder = $displayOrder;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Erases the contact's details in place (FR-025, G-19).
     *
     * A parent's erasure has to take the numbers of people who never had an account with it —
     * the grandparent named here did not consent to being on our servers once the family is
     * gone. The row survives so the parent's own history stays referentially intact; what it
     * holds afterwards identifies nobody.
     */
    public function anonymize(\DateTimeImmutable $now): static
    {
        $this->name = 'Removed contact';
        $this->relationship = 'unknown';
        $this->phone = '';
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
}
