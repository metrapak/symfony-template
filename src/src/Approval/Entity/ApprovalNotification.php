<?php

declare(strict_types=1);

namespace App\Approval\Entity;

use App\Account\Entity\User;
use App\Approval\Enum\NotificationKind;
use App\Approval\Repository\ApprovalNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An in-app message about one purchase (FR-093, FR-095, G-33).
 *
 * **G-33 is the reason to read this class before extending it.** FR-093 requires an in-app
 * notification and no notification system exists anywhere in Epic-01; the gap analysis offers two
 * ways out — specify the platform notification system as its own deliverable, or cut the MVP to
 * email only. Neither is available here, because cutting it would leave a *child* with no channel
 * at all: a child login's address is `@children.invalid` (RFC 2606, see `ChildLoginManager`) and
 * can never receive mail, so "the child is notified" in FR-095 is in-app or it is nothing.
 *
 * So this is the narrow third option: a notification table that belongs to the approval workflow
 * and knows only about it. It has no channels, no preferences, no templates, no digest and no
 * subscriptions — the things a platform notification system would need and this task has no
 * requirements for. When one is specified, the migration is to move these rows into it; nothing
 * here pretends to be that system, and the deliberately module-scoped table name says so.
 *
 * Rows are never edited except to mark them read, and never deleted: a parent's record of what
 * they were told, and when, is part of the same trail FR-098 asks for.
 */
#[ORM\Entity(repositoryClass: ApprovalNotificationRepository::class)]
#[ORM\Table(name: 'approval_notification')]
#[ORM\Index(name: 'IDX_APPROVAL_NOTIFICATION_INBOX', columns: ['recipient_id', 'read_at', 'created_at'])]
class ApprovalNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recipient_id', nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: NotificationKind::class)]
    private NotificationKind $kind;

    #[ORM\Column(length: 255)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /**
     * The purchase this is about, as an id rather than a relation.
     *
     * A relation would make the notification undeletable independently of the request and would
     * pull the whole request into every inbox render; the inbox needs a link, and the link is
     * built by the template from this id. Nullable so that a future notification about something
     * other than one request still fits the table.
     */
    #[ORM\Column(nullable: true)]
    private ?int $purchaseApprovalRequestId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        User $recipient,
        NotificationKind $kind,
        string $summary,
        string $body,
        \DateTimeImmutable $now,
        ?int $purchaseApprovalRequestId = null,
    ) {
        $this->recipient = $recipient;
        $this->kind = $kind;
        $this->summary = $summary;
        $this->body = $body;
        $this->createdAt = $now;
        $this->purchaseApprovalRequestId = $purchaseApprovalRequestId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getKind(): NotificationKind
    {
        return $this->kind;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getPurchaseApprovalRequestId(): ?int
    {
        return $this->purchaseApprovalRequestId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isUnread(): bool
    {
        return null === $this->readAt;
    }

    /**
     * Idempotent: reading something twice does not change when it was first read.
     */
    public function markRead(\DateTimeImmutable $now): static
    {
        $this->readAt ??= $now;

        return $this;
    }
}
