<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Enum\AuditAction;
use App\Account\Repository\AuditLogEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One append-only record of a sensitive operation (BR-025, NFR-X02, G-18).
 *
 * `actor` is the identity the action was performed as; `impersonator` is the Super Admin
 * behind it, if the actor was being impersonated at the time. Keeping both answers US-01.07's
 * requirement that "all actions during impersonation [are] logged with admin_id context"
 * without losing the fact that, to the rest of the system, the trainer is who acted.
 *
 * The subject is deliberately polymorphic — a type name plus an id, with no foreign key — so
 * a later module can audit its own entities without this table growing a column per module.
 *
 * There are no setters and no updatedAt. An audit row that can be edited is not an audit row.
 */
#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log_entry')]
#[ORM\Index(name: 'IDX_AUDIT_ACTOR_OCCURRED', columns: ['actor_id', 'occurred_at'])]
#[ORM\Index(name: 'IDX_AUDIT_SUBJECT', columns: ['subject_type', 'subject_id'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * RESTRICT, not CASCADE: FR-026 forbids history disappearing with a user, and this task
     * never hard-deletes a user row anyway — it anonymizes it. A future code path that tried
     * to DELETE a user would fail here, loudly, instead of erasing the trail of what that
     * user did.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $actor;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $impersonator = null;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    /**
     * @var array<string, scalar|null>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        User $actor,
        AuditAction $action,
        \DateTimeImmutable $occurredAt,
        ?User $impersonator = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $payload = [],
    ) {
        $this->actor = $actor;
        $this->action = $action;
        $this->occurredAt = $occurredAt;
        $this->impersonator = $impersonator;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->payload = $payload;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): User
    {
        return $this->actor;
    }

    public function getImpersonator(): ?User
    {
        return $this->impersonator;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
