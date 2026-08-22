<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Membership\Enum\ShareLinkType;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\ValueObject\ShareLinkCode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An invitation code that turns a visitor into a member of one organization (FR-040, FR-041).
 *
 * One row covers both link types (spec §8) because the columns are the same and only the
 * values differ: a player link is `maxUses = null, expiresAt = null`, a coach link is
 * `maxUses = 1, expiresAt = now + 7 days` addressed to one email. Modelling them as two
 * tables would duplicate the code column, the usage counter and every query that resolves a
 * code, to express a difference that two nullable columns already express.
 *
 * Nothing here decides whether the link may be used — `isUsable()` reports, `consume()` does
 * not exist on the entity at all. Consumption is a conditional UPDATE in the repository, for
 * the reason NFR-041 exists: a hundred people opening the same link at the same moment must
 * not each read `useCount = 0` and each decide they are the one allowed use.
 */
#[ORM\Entity(repositoryClass: ShareLinkRepository::class)]
#[ORM\Table(name: 'share_link')]
#[ORM\UniqueConstraint(name: 'UNIQ_SHARE_LINK_CODE', fields: ['code'])]
#[ORM\Index(name: 'IDX_SHARE_LINK_ORG_TYPE', columns: ['organization_id', 'type'])]
class ShareLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ShareLinkType::class)]
    private ShareLinkType $type;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    /** Coach links only: the address the invitation was sent to (spec §8). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $targetEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxUses = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $useCount = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        ShareLinkCode $code,
        ShareLinkType $type,
        Organization $organization,
        User $createdBy,
        \DateTimeImmutable $now,
    ) {
        $this->code = $code->value;
        $this->type = $type;
        $this->organization = $organization;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getType(): ShareLinkType
    {
        return $this->type;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getTargetEmail(): ?string
    {
        return $this->targetEmail;
    }

    public function getTargetName(): ?string
    {
        return $this->targetName;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Addresses this link at one coach and fixes its single-use, time-boxed terms (BR-041).
     *
     * The email is normalized the same way `User::setEmail()` normalizes one, so the check
     * "is this the person the invitation was addressed to?" compares two values that were
     * produced by the same rule.
     */
    public function addressTo(string $email, ?string $name, ?string $message): static
    {
        $this->targetEmail = mb_strtolower(trim($email));
        $this->targetName = null !== $name && '' !== trim($name) ? trim($name) : null;
        $this->message = null !== $message && '' !== trim($message) ? trim($message) : null;
        $this->maxUses = 1;

        return $this;
    }

    public function expiresOn(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Stops future redemptions (FR-040).
     *
     * Existing associations are untouched, which answers G-19: deactivating a link withdraws
     * an invitation, it does not expel the players who already accepted one.
     */
    public function deactivate(\DateTimeImmutable $now): static
    {
        $this->active = false;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Puts a fresh code and a fresh expiry on an existing invitation (FR-046).
     *
     * The row is reused rather than replaced so the Coaches list keeps showing one line per
     * invited coach instead of one per attempt. The old code stops resolving the moment this
     * commits — that is the point of a resend, and it is why the previous email is safe to
     * ignore.
     */
    public function reissue(ShareLinkCode $code, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): static
    {
        $this->code = $code->value;
        $this->expiresAt = $expiresAt;
        $this->useCount = 0;
        $this->active = true;
        $this->updatedAt = $now;

        return $this;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function isConsumed(): bool
    {
        return null !== $this->maxUses && $this->useCount >= $this->maxUses;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->active && !$this->isExpired($now) && !$this->isConsumed();
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
