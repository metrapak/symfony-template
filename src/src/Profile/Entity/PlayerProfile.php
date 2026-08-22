<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Account\Entity\User;
use App\Profile\Enum\PlayerGender;
use App\Profile\Repository\PlayerProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person who trains: the account holder themself, or one of their children.
 *
 * **This is TASK-004's entity, seeded here.** TASK-003 shipped first and cannot associate a
 * player with a trainer without something to associate, so this carries only the fields the
 * invitation flow actually writes (spec §8: name, birth date, gender, "is this a child
 * profile?", link to the parent account). Skill level, school, jersey number, photo and
 * emergency contact belong to TASK-004 and are deliberately absent rather than nullable
 * placeholders.
 *
 * Two users hang off a profile and they answer different questions:
 *
 *  - `owner` is the account that *manages* it. For an adult registering themselves that is
 *    their own account; for a child it is the parent's. Family-member selection (FR-044)
 *    reads this column, and BR-046 — a child may not add a trainer — is meaningful only
 *    because it exists.
 *  - `account` is the login the profile *signs in as*, and is null for a child who has no
 *    login of their own. TASK-004 fills it in when it ships child accounts; TASK-003 needs
 *    it to answer "is the user in front of me a child?" (FR-048).
 */
#[ORM\Entity(repositoryClass: PlayerProfileRepository::class)]
#[ORM\Table(name: 'player_profile')]
#[ORM\UniqueConstraint(name: 'UNIQ_PLAYER_PROFILE_ACCOUNT', fields: ['account'])]
#[ORM\Index(name: 'IDX_PLAYER_PROFILE_OWNER', columns: ['owner_id'])]
class PlayerProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $account = null;

    /**
     * The player's own name, which is not always the account name: a parent's account says
     * "Dana Ruiz" while the profile trains as "Mateo Ruiz" (spec §8).
     */
    #[ORM\Column(length: 255)]
    private string $displayName;

    /**
     * Birth date rather than an age integer, deliberately (Q-01.02). An age is correct on the
     * day it is typed and silently wrong every year after; the age validation the spec asks
     * for (1-18 for a child) is applied to the value derived from this at registration.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: PlayerGender::class)]
    private ?PlayerGender $gender = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $child = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $displayName, bool $child, \DateTimeImmutable $now)
    {
        $this->owner = $owner;
        $this->displayName = $displayName;
        $this->child = $child;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * The profile an adult registering themselves gets: managed by, and signed in as, the
     * same account.
     */
    public static function forSelf(User $account, string $displayName, \DateTimeImmutable $now): self
    {
        $profile = new self($account, $displayName, false, $now);
        $profile->account = $account;

        return $profile;
    }

    public static function forChildOf(User $parent, string $displayName, \DateTimeImmutable $now): self
    {
        return new self($parent, $displayName, true, $now);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getAccount(): ?User
    {
        return $this->account;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function rename(string $displayName, \DateTimeImmutable $now): static
    {
        $this->displayName = $displayName;
        $this->updatedAt = $now;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate, \DateTimeImmutable $now): static
    {
        $this->birthDate = $birthDate;
        $this->updatedAt = $now;

        return $this;
    }

    public function ageOn(\DateTimeImmutable $moment): ?int
    {
        return $this->birthDate?->diff($moment)->y;
    }

    public function getGender(): ?PlayerGender
    {
        return $this->gender;
    }

    public function setGender(?PlayerGender $gender, \DateTimeImmutable $now): static
    {
        $this->gender = $gender;
        $this->updatedAt = $now;

        return $this;
    }

    public function isChild(): bool
    {
        return $this->child;
    }

    /**
     * Whether this profile belongs to somebody other than the account that manages it.
     *
     * FR-048 turns on this and not on `child` alone: what BR-046 forbids is an account acting
     * for a person it does not control, and a 17-year-old with their own login who manages
     * their own profile is not that.
     */
    public function isManagedByAnotherAccount(): bool
    {
        return $this->account?->getId() !== $this->owner->getId();
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
