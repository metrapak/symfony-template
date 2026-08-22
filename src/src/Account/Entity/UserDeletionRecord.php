<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Repository\UserDeletionRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The compliance record for a GDPR deletion (FR-027, spec §8 "User Deletion Compliance").
 *
 * **Deliberately holds no personal data** (architecture D8, gap G-16). FR-027 asks for the
 * original email in clear plus "a backup of the original data"; storing either would put the
 * personal data an erasure request just removed into a second table that nobody erases, so
 * the record would defeat the operation it exists to document.
 *
 * What is stored instead answers the question the requirement is for — "was the account for
 * this address deleted, when, by whom, and why?" — for anyone who can supply the address, and
 * answers nothing to someone who cannot. `originalEmailDigest` is a verification token, not a
 * secret: a holder of this table can confirm an address they already have, but cannot read
 * addresses out of it.
 *
 * Reverting to a full snapshot, should legal require it, is a migration plus a service change.
 */
#[ORM\Entity(repositoryClass: UserDeletionRecordRepository::class)]
#[ORM\Table(name: 'user_deletion_record')]
#[ORM\Index(name: 'IDX_DELETION_EMAIL_DIGEST', columns: ['original_email_digest'])]
class UserDeletionRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Stored as a plain integer, not an association: it is the identifier the anonymized row
     * still carries, and the record must remain readable as history even if the user table is
     * ever partitioned or archived away from this one.
     */
    #[ORM\Column]
    private int $originalUserId;

    #[ORM\Column(length: 64)]
    private string $originalEmailDigest;

    /**
     * The address the account now carries (`deleted_{id}@example.com`). Not personal data —
     * it is what an operator sees in the directory, recorded so the two views agree.
     */
    #[ORM\Column(length: 180)]
    private string $anonymizedEmail;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $deletedBy;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $deletedAt;

    public function __construct(
        int $originalUserId,
        string $originalEmailDigest,
        string $anonymizedEmail,
        User $deletedBy,
        string $reason,
        \DateTimeImmutable $deletedAt,
    ) {
        $this->originalUserId = $originalUserId;
        $this->originalEmailDigest = $originalEmailDigest;
        $this->anonymizedEmail = $anonymizedEmail;
        $this->deletedBy = $deletedBy;
        $this->reason = $reason;
        $this->deletedAt = $deletedAt;
    }

    /**
     * The one place that knows how a stored digest is produced, so a lookup and a write can
     * never disagree about normalization. The input is normalized the same way
     * `User::setEmail()` normalizes, because that is the form the address was stored in.
     */
    public static function digestFor(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOriginalUserId(): int
    {
        return $this->originalUserId;
    }

    public function getOriginalEmailDigest(): string
    {
        return $this->originalEmailDigest;
    }

    public function getAnonymizedEmail(): string
    {
        return $this->anonymizedEmail;
    }

    public function getDeletedBy(): User
    {
        return $this->deletedBy;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
