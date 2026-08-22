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
 * Seeded by TASK-003 with the fields the invitation flow writes; completed by TASK-004, which
 * added the profile screens and with them school, jersey number, photo and skill level
 * (spec §8, FR-061). Emergency contact stayed out: it belongs to the *parent account*, not to
 * one player, so it is its own entity rather than columns repeated across a family's rows.
 *
 * Skill level is stored but never self-editable (BR-067) — a trainer assesses it, and no
 * screen in this epic writes it. It is a nullable string rather than an enum because Q-01.01
 * is unanswered; the values are the client's to define, and inventing four of them now would
 * make the wrong four authoritative.
 *
 * Two users hang off a profile and they answer different questions:
 *
 *  - `owner` is the account that *manages* it. For an adult registering themselves that is
 *    their own account; for a child it is the parent's. Family-member selection (FR-044)
 *    reads this column, and BR-046 — a child may not add a trainer — is meaningful only
 *    because it exists.
 *  - `account` is the login the profile *signs in as*, and is null for a child who has no
 *    login of their own. TASK-004's `ChildLoginManager` is the only thing that fills it in,
 *    and FR-048 turns on it: it is how "is the user in front of me a child?" is answered.
 *
 * There is deliberately no `parent_child_link` table and no `loginEnabled` column, though the
 * task breakdown lists both. `owner` already *is* the parent link — a join table repeating
 * `(parent_user_id, child_profile_id)` would store the same fact twice and let the two
 * disagree — and "has a login" is `account !== null`, while "may currently use it" is that
 * account's own `UserStatus`. A third boolean saying either thing again is a column that can
 * contradict the row it lives in.
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

    /**
     * FR-061's player-specific fields. Optional throughout: FR-063 makes only name, age and
     * gender required, and a parent adding a child mid-season should not have to invent a
     * jersey number to save the form.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $school = null;

    /**
     * A string, not an int. Jersey numbers are worn, not counted: "07" and "7" are different
     * shirts, and a squad that uses "00" would lose it to an integer column.
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $jerseyNumber = null;

    /**
     * Where the stored photo lives, relative to the private upload root — never a URL.
     *
     * NFR-066 keeps uploads out of the web root, so nothing can link to a file directly; the
     * path is resolved by a controller that checks who is asking. Storing a URL here would
     * bake the serving strategy into every row.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoThumbnailPath = null;

    /**
     * Trainer-assessed, never self-edited (BR-067). Nullable string pending Q-01.01.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $skillLevel = null;

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

    public function getSchool(): ?string
    {
        return $this->school;
    }

    public function setSchool(?string $school, \DateTimeImmutable $now): static
    {
        $this->school = self::blankToNull($school);
        $this->updatedAt = $now;

        return $this;
    }

    public function getJerseyNumber(): ?string
    {
        return $this->jerseyNumber;
    }

    public function setJerseyNumber(?string $jerseyNumber, \DateTimeImmutable $now): static
    {
        $this->jerseyNumber = self::blankToNull($jerseyNumber);
        $this->updatedAt = $now;

        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function getPhotoThumbnailPath(): ?string
    {
        return $this->photoThumbnailPath;
    }

    public function hasPhoto(): bool
    {
        return null !== $this->photoPath;
    }

    /**
     * Both paths move together, because a thumbnail of a photo that is no longer here is a
     * broken image nobody can explain. A processor that could not produce a thumbnail passes
     * null and the full photo is used in its place.
     */
    public function setPhoto(?string $photoPath, ?string $thumbnailPath, \DateTimeImmutable $now): static
    {
        $this->photoPath = $photoPath;
        $this->photoThumbnailPath = null !== $photoPath ? $thumbnailPath : null;
        $this->updatedAt = $now;

        return $this;
    }

    public function getSkillLevel(): ?string
    {
        return $this->skillLevel;
    }

    /**
     * Trainer-set (BR-067). No self-service screen calls this; it exists so the column has a
     * writer when the trainer's assessment screens arrive, and so nothing is tempted to reach
     * into the property.
     */
    public function setSkillLevel(?string $skillLevel, \DateTimeImmutable $now): static
    {
        $this->skillLevel = self::blankToNull($skillLevel);
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Gives this profile its own login (FR-067, G-23).
     *
     * Guarded rather than a plain setter: a profile that already signs in as somebody must not
     * be silently repointed at a second account, because the first one would keep its session
     * and its associations while no longer being reachable from the family it belongs to.
     *
     * @throws \LogicException when the profile already has a login, or is not a child
     */
    public function attachLogin(User $account, \DateTimeImmutable $now): static
    {
        if (null !== $this->account) {
            throw new \LogicException('This profile already has a login.');
        }

        if (!$this->child) {
            throw new \LogicException('Only a child profile is given a login separately from its owner.');
        }

        $this->account = $account;
        $this->updatedAt = $now;

        return $this;
    }

    public function hasOwnLogin(): bool
    {
        return null !== $this->account;
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
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
