<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Profile\Repository\CoachProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A coach's professional details (FR-061, US-01.11 "Coach: bio, credentials, certifications,
 * public profile checkbox").
 *
 * Scoped to `(user, organization)` and unique on the pair. That is not defensive modelling —
 * TASK-003's partial index already allows a coach to hold one *active* assignment at a time and
 * to move between trainers, so a coach who leaves Northside for Elite has two organizations in
 * their history. Their bio at Elite is not automatically the one they wrote for Northside, and
 * a single row keyed on the user alone would rewrite history the moment they moved.
 *
 * `isPublic` is the visibility checkbox and defaults to **false**. A profile written for one
 * trainer's parents becoming publicly readable by default is the kind of disclosure nobody
 * asks for and everybody remembers; opting in is a click, opting out after the fact is not.
 */
#[ORM\Entity(repositoryClass: CoachProfileRepository::class)]
#[ORM\Table(name: 'coach_profile')]
#[ORM\UniqueConstraint(name: 'UNIQ_COACH_PROFILE_USER_ORG', fields: ['user', 'organization'])]
class CoachProfile
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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $credentials = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $certifications = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $public = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Organization $organization, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->organization = $organization;
        $this->joinedAt = $now;
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

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function getCredentials(): ?string
    {
        return $this->credentials;
    }

    public function getCertifications(): ?string
    {
        return $this->certifications;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function update(
        ?string $bio,
        ?string $credentials,
        ?string $certifications,
        bool $public,
        \DateTimeImmutable $now,
    ): static {
        $this->bio = self::blankToNull($bio);
        $this->credentials = self::blankToNull($credentials);
        $this->certifications = self::blankToNull($certifications);
        $this->public = $public;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Clears the free-text fields and un-publishes (FR-025).
     *
     * A bio is the most identifying text on the platform — it is a person describing
     * themselves by name — so an erasure that anonymized the account and left the bio
     * readable would not be an erasure. Un-publishing is part of it: a public profile that
     * survives is still being served to strangers.
     */
    public function anonymize(\DateTimeImmutable $now): static
    {
        return $this->update(null, null, null, false, $now);
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
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
