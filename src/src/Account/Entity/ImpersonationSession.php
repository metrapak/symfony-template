<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Enum\ImpersonationEndReason;
use App\Account\Repository\ImpersonationSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One impersonation, from the switch to the return (FR-032, spec §8 "Impersonation Audit Log").
 *
 * Opened with `endedAt` null. A row with a null `endedAt` *is* the "currently impersonating"
 * state: FR-031's expiry check reads its `startedAt` rather than a session key, so there is
 * one authoritative answer to how long the operator has been switched, and it survives a
 * session restored from a cookie.
 */
#[ORM\Entity(repositoryClass: ImpersonationSessionRepository::class)]
#[ORM\Table(name: 'impersonation_session')]
#[ORM\Index(name: 'IDX_IMPERSONATION_TARGET_STARTED', columns: ['target_user_id', 'started_at'])]
#[ORM\Index(name: 'IDX_IMPERSONATION_ADMIN_ENDED', columns: ['admin_id', 'ended_at'])]
class ImpersonationSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $admin;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $targetUser;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /**
     * Denormalized on close rather than computed in the report's SELECT. The spec lists
     * duration as stored data, and a stored value cannot drift when a row is exported,
     * archived, or read by a tool that does not know the two timestamps' semantics.
     */
    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: ImpersonationEndReason::class)]
    private ?ImpersonationEndReason $endReason = null;

    public function __construct(User $admin, User $targetUser, \DateTimeImmutable $startedAt)
    {
        $this->admin = $admin;
        $this->targetUser = $targetUser;
        $this->startedAt = $startedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdmin(): User
    {
        return $this->admin;
    }

    public function getTargetUser(): User
    {
        return $this->targetUser;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function getEndReason(): ?ImpersonationEndReason
    {
        return $this->endReason;
    }

    public function isOpen(): bool
    {
        return null === $this->endedAt;
    }

    /**
     * Closing is idempotent: a second call is ignored rather than overwriting the first
     * reason. Two paths can race to close the same row — the operator clicking "Exit" on a
     * request that the expiry subscriber has already decided is stale — and the first one to
     * arrive is the one that actually happened.
     */
    public function close(\DateTimeImmutable $endedAt, ImpersonationEndReason $reason): static
    {
        if (null !== $this->endedAt) {
            return $this;
        }

        $this->endedAt = $endedAt;
        $this->endReason = $reason;
        $this->durationSeconds = max(0, $endedAt->getTimestamp() - $this->startedAt->getTimestamp());

        return $this;
    }

    /**
     * Seconds elapsed since the switch, for the expiry check (FR-031).
     */
    public function elapsedSeconds(\DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
