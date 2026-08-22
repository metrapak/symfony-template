<?php

declare(strict_types=1);

namespace App\Approval\Service;

use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Service\AuditLogger;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Exception\ApprovalAlreadyDecided;
use App\Approval\Notification\ApprovalNotifier;
use App\Approval\Payment\PaymentInstruction;
use App\Approval\Payment\PaymentProcessor;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Clock\ClockInterface;

/**
 * The three things that can happen to a pending purchase (FR-095, FR-096, NFR-090, NFR-092).
 *
 * **Order of operations, and why it is this order.** Approving does four things — move the state,
 * record the audit entry, take the payment, store the receipt — and only one arrangement of them
 * survives a double-click, a crash and a refused payment:
 *
 *  1. `approve()` on the entity, which refuses outright if somebody already decided this;
 *  2. `flush()`, *inside* the transaction, which is where the optimistic lock does its work: a
 *     simultaneous second approval finds the version moved and fails here, before any money;
 *  3. the payment, through the port;
 *  4. the receipt, flushed with everything else when the transaction commits.
 *
 * Reversing 2 and 3 would take the payment first and discover the race afterwards, which is
 * NFR-092's exact failure. Moving the payment outside the transaction would leave an approved
 * purchase with no payment whenever the processor refuses, so `PaymentFailed` is allowed to
 * propagate and roll the whole thing back to pending — the parent can try again.
 *
 * **The cost of that choice is an external call inside a database transaction**, which holds a
 * row lock for as long as the gateway takes. It is the right trade while `FakePaymentProcessor`
 * is what ships (nothing leaves the process), and it is the thing Epic-05 should revisit: the
 * usual answer is an intent row plus an outbox, so the transaction commits on a local write and
 * the gateway call is retried out of band. The `idempotencyKey` on every `PaymentInstruction` is
 * already the piece that makes such a retry safe.
 *
 * **Notifications happen after the commit**, never inside it. Mail is synchronous here, and a
 * message about a decision that then rolled back is worse than a late one — the same rule
 * TASK-003 applies to its invitation mail.
 */
final readonly class ApprovalWorkflow
{
    public function __construct(
        private PurchaseApprovalRequestRepository $requests,
        private PaymentProcessor $payments,
        private ApprovalNotifier $notifier,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * FR-095 — the parent approves, the payment is taken, the child is confirmed.
     *
     * @throws ApprovalAlreadyDecided when this purchase has already been decided (NFR-092)
     * @throws \App\Approval\Payment\PaymentFailed when the processor refuses; nothing is changed
     */
    public function approve(PurchaseApprovalRequest $request, User $parent, ?string $notes): PurchaseApprovalRequest
    {
        $now = $this->clock->now();

        try {
            $this->entityManager->wrapInTransaction(function () use ($request, $parent, $notes, $now): void {
                $request->approve($now, $notes);

                $this->auditLogger->log(
                    actor: $parent,
                    action: AuditAction::ChildPurchaseApproved,
                    subject: $request,
                    payload: $this->auditPayload($request),
                );

                // Claims the decision. A concurrent approval loses the version check here and
                // never reaches the processor below.
                $this->entityManager->flush();

                $receipt = $this->payments->process($this->instructionFor($request));

                $request->recordPayment($receipt->reference, $receipt->processedAt);
            });
        } catch (OptimisticLockException) {
            // The other request won the race and has already approved this. Same outcome for the
            // caller as a sequential double-submit, and the same message for the parent.
            $this->entityManager->refresh($request);

            throw new ApprovalAlreadyDecided($request->getStatus());
        }

        $this->notifier->notifyOutcome($request, $parent);

        return $request;
    }

    /**
     * FR-095 — the parent denies. No payment is attempted and none is recorded.
     *
     * @throws ApprovalAlreadyDecided
     */
    public function deny(PurchaseApprovalRequest $request, User $parent, ?string $notes): PurchaseApprovalRequest
    {
        $now = $this->clock->now();

        try {
            $this->entityManager->wrapInTransaction(function () use ($request, $parent, $notes, $now): void {
                $request->deny($now, $notes);

                $this->auditLogger->log(
                    actor: $parent,
                    action: AuditAction::ChildPurchaseDenied,
                    subject: $request,
                    payload: $this->auditPayload($request),
                );

                $this->entityManager->flush();
            });
        } catch (OptimisticLockException) {
            $this->entityManager->refresh($request);

            throw new ApprovalAlreadyDecided($request->getStatus());
        }

        $this->notifier->notifyOutcome($request, $parent);

        return $request;
    }

    /**
     * FR-096 — 48 hours passed, so the request auto-denies and both sides are told.
     *
     * Returns false rather than throwing when the request is no longer pending, because that is
     * the ordinary case for a re-delivered expiry message: the sweep found it, a parent answered
     * in between, and there is nothing to do. Making the caller catch an exception for the normal
     * path would turn idempotency into error handling.
     *
     * No audit entry: nobody performed this. See `AuditAction::ChildPurchaseApproved`.
     */
    public function expire(PurchaseApprovalRequest $request): bool
    {
        if (!$request->isPending()) {
            return false;
        }

        $now = $this->clock->now();

        try {
            $this->entityManager->wrapInTransaction(function () use ($request, $now): void {
                $request->expire($now);
                $this->entityManager->flush();
            });
        } catch (ApprovalAlreadyDecided|OptimisticLockException) {
            // A parent decided it between the check above and the flush. Their decision stands.
            $this->entityManager->refresh($request);

            return false;
        }

        $this->notifier->notifyOutcome($request, null);

        return true;
    }

    /**
     * FR-092 — a purchase that needed no approval: pay for it now and tell the parent afterwards.
     *
     * The same order as `approve()`, for the same reasons: the row is committed before the
     * processor is called, so a refused payment leaves nothing behind, and the notification is
     * sent after the transaction rather than inside it.
     *
     * The informational notification goes out only when somebody *else's* money was spent —
     * an adult buying for themselves is not their own guardian and has no one to inform.
     *
     * @throws \App\Approval\Payment\PaymentFailed when the processor refuses; nothing is stored
     */
    public function completeWithoutApproval(PurchaseApprovalRequest $request, User $actor): PurchaseApprovalRequest
    {
        $this->entityManager->wrapInTransaction(function () use ($request): void {
            $this->requests->add($request);
            $this->entityManager->flush();

            $receipt = $this->payments->process($this->instructionFor($request));

            $request->recordPayment($receipt->reference, $receipt->processedAt);
        });

        if ($request->getParent()->getId() !== $actor->getId()) {
            $this->notifier->notifyParentTokenSpend($request);
        }

        return $request;
    }

    /**
     * What the processor is told, and the key that lets it recognise a retry (FR-097, NFR-092).
     *
     * The key is the request id, so every attempt at this one purchase — a retried HTTP request,
     * a redelivered message, an Epic-05 reconciliation job — carries the same value.
     */
    private function instructionFor(PurchaseApprovalRequest $request): PaymentInstruction
    {
        return new PaymentInstruction(
            idempotencyKey: \sprintf('approval-%d', (int) $request->getId()),
            payerUserId: (int) $request->getParent()->getId(),
            playerProfileId: (int) $request->getChildProfile()->getId(),
            purchaseReference: $request->getPurchaseReference(),
            purchaseDescription: $request->getPurchaseDescription(),
            amount: $request->getAmount(),
            paymentType: $request->getPaymentType(),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function auditPayload(PurchaseApprovalRequest $request): array
    {
        $amount = $request->getAmount();

        return [
            'child_profile_id' => $request->getChildProfile()->getId(),
            'purchase_reference' => $request->getPurchaseReference(),
            'amount_minor' => $amount->amountMinor,
            'currency' => $amount->currency,
            'payment_type' => $request->getPaymentType()->value,
            // The note is the parent's reasoning and belongs on the row; the audit log is read as
            // a list, so it carries a bounded copy for context rather than the whole text.
            'notes' => null !== $request->getParentNotes() ? mb_substr($request->getParentNotes(), 0, 255) : null,
        ];
    }
}
