<?php

declare(strict_types=1);

namespace App\Approval\Entity;

use App\Account\Entity\User;
use App\Approval\Repository\ChildSpendingSettingRepository;
use App\Profile\Entity\PlayerProfile;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One child's token-spending permission (FR-092, BR-091, BR-096).
 *
 * **Per child, enforced by a unique index**, because BR-096 says the setting is per child and not
 * per family: a parent who trusts a fifteen-year-old with tokens has said nothing about their
 * seven-year-old. A row per child with a unique constraint is the difference between that being
 * a rule and being a convention.
 *
 * **Absence means off.** BR-091 makes the default OFF, and the honest way to express a default is
 * to have no row until somebody changes it: a row written at profile creation would claim a
 * parent made a choice they never made, and `updatedByUserId` would have to name somebody. So
 * `SpendingSettingService::get()` returns an unpersisted default for a child nobody has decided
 * about, and the column is only written when a parent actually flips the switch.
 *
 * `updatedBy` is nullable for exactly that unpersisted default and for no other reason — every
 * row that reaches the database has an author, because only `update()` writes one.
 */
#[ORM\Entity(repositoryClass: ChildSpendingSettingRepository::class)]
#[ORM\Table(name: 'child_spending_setting')]
#[ORM\UniqueConstraint(name: 'UNIQ_CHILD_SPENDING_SETTING_CHILD', fields: ['childProfile'])]
class ChildSpendingSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlayerProfile::class)]
    #[ORM\JoinColumn(name: 'child_profile_id', nullable: false, onDelete: 'CASCADE')]
    private PlayerProfile $childProfile;

    /**
     * FR-092's switch. Default false in the column as well as in the constructor, so a row
     * inserted by anything other than this class still means "approval required".
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $allowTokenSpendingWithoutApproval = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(PlayerProfile $childProfile, \DateTimeImmutable $now)
    {
        $this->childProfile = $childProfile;
        $this->updatedAt = $now;
    }

    /**
     * The state of a child nobody has decided about: approval required, and nobody to credit.
     *
     * Never persisted by the reader that builds it — see the class note.
     */
    public static function defaultFor(PlayerProfile $childProfile, \DateTimeImmutable $now): self
    {
        return new self($childProfile, $now);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChildProfile(): PlayerProfile
    {
        return $this->childProfile;
    }

    public function allowsTokenSpendingWithoutApproval(): bool
    {
        return $this->allowTokenSpendingWithoutApproval;
    }

    public function decide(bool $allow, User $actor, \DateTimeImmutable $now): static
    {
        $this->allowTokenSpendingWithoutApproval = $allow;
        $this->updatedBy = $actor;
        $this->updatedAt = $now;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    /**
     * Whether a parent has ever made this choice, as opposed to inheriting the default.
     *
     * The settings screen says so out loud: "not set — approval is required" reads differently
     * from "you turned this off", and a parent auditing their family's permissions needs to be
     * able to tell them apart.
     */
    public function wasChosen(): bool
    {
        return null !== $this->id;
    }
}
