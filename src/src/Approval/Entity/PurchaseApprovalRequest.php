<?php

declare(strict_types=1);

namespace App\Approval\Entity;

use App\Account\Entity\User;
use App\Approval\Enum\ApprovalStatus;
use App\Approval\Enum\PaymentType;
use App\Approval\Exception\ApprovalAlreadyDecided;
use App\Approval\Exception\IllegalApprovalTransition;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use App\Approval\ValueObject\Money;
use App\Profile\Entity\PlayerProfile;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One child purchase and everything that happened to it (FR-098, spec §8 "Child Purchase
 * Approvals").
 *
 * The row is the audit trail the requirement asks for, so it carries the whole story rather than
 * only the current state: who bought what, for how much, in which currency, paid how, when it was
 * asked for, when it expires, when it was answered, what the parent wrote, and — once a payment
 * has actually happened — the processor's reference for it.
 *
 * **The state machine lives here, not in the services that call it.** `approve()`, `deny()` and
 * `expire()` each ask `ApprovalStatus::canTransitionTo()` first, so approving a denied request is
 * refused whether the caller is the parent's screen, the expiry sweep, or Epic-05. A guard that
 * only exists in a controller is a guard that the next caller does not have.
 *
 * **`version` is what makes NFR-092 true.** Two Approve submits that arrive at the same instant
 * both load this row at version *n*; the first to flush moves it to *n+1*, and the second's
 * `UPDATE … WHERE version = n` matches nothing and raises Doctrine's optimistic lock error before
 * any payment is attempted. A double-click whose requests arrive one after the other never gets
 * that far — the second load already reads `approved` and `approve()` refuses it. Both paths end
 * with exactly one call to the processor, which is the requirement.
 *
 * **`purchaseReference` is a string with no foreign key, and that is deliberate.** Epic-02 owns
 * events and Epic-05 owns payments; neither table exists, so there is nothing to constrain
 * against. The column is wide enough for whatever identifier those epics bring, and
 * `purchaseDescription` carries the human-readable version so that a request created today is
 * still readable in a year — a reference alone would make the parent's history a list of opaque
 * ids. Adding the real foreign key belongs to Epic-02's first migration, alongside R6.
 */
#[ORM\Entity(repositoryClass: PurchaseApprovalRequestRepository::class)]
#[ORM\Table(name: 'purchase_approval_request')]
#[ORM\Index(name: 'IDX_APPROVAL_PARENT_STATUS', columns: ['parent_id', 'status'])]
#[ORM\Index(name: 'IDX_APPROVAL_DUE', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'IDX_APPROVAL_CHILD', columns: ['child_profile_id', 'requested_at'])]
class PurchaseApprovalRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Optimistic lock. See the class note: this column, and nothing else, is what stops two
     * simultaneous approvals from both reaching the payment processor.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\ManyToOne(targetEntity: PlayerProfile::class)]
    #[ORM\JoinColumn(name: 'child_profile_id', nullable: false, onDelete: 'RESTRICT')]
    private PlayerProfile $childProfile;

    /**
     * The account that may decide this request — the profile's owner at the moment of purchase.
     *
     * Stored rather than derived from `childProfile.owner` on each read, because BR-094 is about
     * who was asked, and a profile that later moves to a different account must not silently
     * hand a stranger the right to approve a request they were never told about. It is also the
     * account an adult buying for themselves is their own parent of — see `withoutApproval()`.
     *
     * Singular, and the epic index records why that is a question for the client: multi-parent
     * families are not modelled anywhere in Epic-01, so a second guardian can neither see nor
     * act on approvals.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: false, onDelete: 'RESTRICT')]
    private User $parent;

    /** Epic-02's event, Epic-05's payment. A loose reference until those tables exist. */
    #[ORM\Column(length: 128)]
    private string $purchaseReference;

    /** What the parent reads: "City Cup entry fee", not an id. */
    #[ORM\Column(length: 255)]
    private string $purchaseDescription;

    #[ORM\Column(type: Types::INTEGER)]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: PaymentType::class)]
    private PaymentType $paymentType;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ApprovalStatus::class)]
    private ApprovalStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    /**
     * Null for a purchase that never needed approval: nothing is waiting, so nothing expires.
     * A pending request always has one — see `awaitingApproval()`.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** BR-093 — the parent may attach notes to either decision. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $parentNotes = null;

    /**
     * The processor's receipt, written only after a payment has actually been taken (FR-097).
     *
     * Not in the spec's field list and worth its column anyway: it is the difference between
     * "the workflow says this was approved" and "money moved, once, and here is the proof".
     * NFR-092's test asserts on it.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $paymentReference = null;

    private function __construct(
        PlayerProfile $childProfile,
        User $parent,
        string $purchaseReference,
        string $purchaseDescription,
        Money $amount,
        PaymentType $paymentType,
        ApprovalStatus $status,
        \DateTimeImmutable $requestedAt,
    ) {
        $this->childProfile = $childProfile;
        $this->parent = $parent;
        $this->purchaseReference = $purchaseReference;
        $this->purchaseDescription = $purchaseDescription;
        $this->amountMinor = $amount->amountMinor;
        $this->currency = $amount->currency;
        $this->paymentType = $paymentType;
        $this->status = $status;
        $this->requestedAt = $requestedAt;
    }

    /**
     * A purchase that is waiting on a parent (FR-090, FR-096).
     *
     * The expiry is passed in rather than computed here, because 48 hours is a configured window
     * (`app.approval_window_hours`) and an entity that read configuration would make the rule
     * true in two places.
     */
    public static function awaitingApproval(
        PlayerProfile $childProfile,
        User $parent,
        string $purchaseReference,
        string $purchaseDescription,
        Money $amount,
        PaymentType $paymentType,
        \DateTimeImmutable $requestedAt,
        \DateTimeImmutable $expiresAt,
    ): self {
        $request = new self(
            $childProfile,
            $parent,
            $purchaseReference,
            $purchaseDescription,
            $amount,
            $paymentType,
            ApprovalStatus::Pending,
            $requestedAt,
        );
        $request->expiresAt = $expiresAt;

        return $request;
    }

    /**
     * A purchase that went through without needing approval (FR-092).
     *
     * Recorded rather than left invisible: the child has to see the reservation, the parent's
     * informational notification has to point at something, and the payment reference has to
     * live somewhere. `respondedAt` is set to the same instant as `requestedAt` because nobody
     * responded — the decision and the request are one event.
     */
    public static function withoutApproval(
        PlayerProfile $childProfile,
        User $parent,
        string $purchaseReference,
        string $purchaseDescription,
        Money $amount,
        PaymentType $paymentType,
        \DateTimeImmutable $requestedAt,
    ): self {
        $request = new self(
            $childProfile,
            $parent,
            $purchaseReference,
            $purchaseDescription,
            $amount,
            $paymentType,
            ApprovalStatus::NotRequired,
            $requestedAt,
        );
        $request->respondedAt = $requestedAt;

        return $request;
    }

    /**
     * FR-095 — the parent said yes. The payment has not happened yet; see `recordPayment()`.
     *
     * @throws ApprovalAlreadyDecided when somebody has already decided this request
     */
    public function approve(\DateTimeImmutable $at, ?string $notes): static
    {
        return $this->decide(ApprovalStatus::Approved, $at, $notes);
    }

    /**
     * FR-095 — the parent said no. Nothing is charged, and `paymentReference` stays null.
     *
     * @throws ApprovalAlreadyDecided
     */
    public function deny(\DateTimeImmutable $at, ?string $notes): static
    {
        return $this->decide(ApprovalStatus::Denied, $at, $notes);
    }

    /**
     * FR-096 — 48 hours passed with no answer, which is an automatic denial.
     *
     * Carries no notes because there is no author: the platform did this, and attributing a
     * sentence to the parent who did *not* answer would be a false record.
     *
     * @throws ApprovalAlreadyDecided when the request was decided before the sweep reached it
     */
    public function expire(\DateTimeImmutable $at): static
    {
        return $this->decide(ApprovalStatus::Expired, $at, null);
    }

    /**
     * Writes the processor's receipt onto an approved purchase (FR-097, NFR-092).
     *
     * Refuses to overwrite an existing reference. A second payment for one approval is the exact
     * failure NFR-092 forbids, and if the optimistic lock and the status guard were ever both
     * bypassed, this is the last place that can still say no.
     */
    public function recordPayment(string $reference, \DateTimeImmutable $at): static
    {
        if (null !== $this->paymentReference) {
            throw new \LogicException('This purchase has already been paid for.');
        }

        if (!$this->status->isConfirmed()) {
            throw new \LogicException(\sprintf('A purchase that is "%s" has not been paid for.', $this->status->value));
        }

        $this->paymentReference = $reference;
        $this->respondedAt ??= $at;

        return $this;
    }

    private function decide(ApprovalStatus $target, \DateTimeImmutable $at, ?string $notes): static
    {
        if (!$this->status->canTransitionTo($target)) {
            // Two different failures, told apart because the screens treat them differently: a
            // second Approve on an already-approved request is somebody's double-click, while
            // approving an expired one is a move the workflow does not have.
            throw $this->status->isFinal() ? new ApprovalAlreadyDecided($this->status) : IllegalApprovalTransition::between($this->status, $target);
        }

        $this->status = $target;
        $this->respondedAt = $at;
        $this->parentNotes = self::blankToNull($notes);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChildProfile(): PlayerProfile
    {
        return $this->childProfile;
    }

    public function getParent(): User
    {
        return $this->parent;
    }

    public function getPurchaseReference(): string
    {
        return $this->purchaseReference;
    }

    public function getPurchaseDescription(): string
    {
        return $this->purchaseDescription;
    }

    public function getAmount(): Money
    {
        return Money::of($this->amountMinor, $this->currency);
    }

    public function getPaymentType(): PaymentType
    {
        return $this->paymentType;
    }

    public function getStatus(): ApprovalStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Whether the 48-hour window has run out, as of the given moment.
     *
     * The boundary is inclusive of the mark itself: a request created at 09:00 on Monday is due
     * at 09:00 on Wednesday, not a second later. The sweep and this method have to agree, so the
     * repository's query uses the same comparison.
     */
    public function hasExpiredBy(\DateTimeImmutable $moment): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $moment;
    }

    public function getParentNotes(): ?string
    {
        return $this->parentNotes;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function isPaid(): bool
    {
        return null !== $this->paymentReference;
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
