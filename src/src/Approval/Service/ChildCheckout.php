<?php

declare(strict_types=1);

namespace App\Approval\Service;

use App\Account\Entity\User;
use App\Approval\Dto\CheckoutOutcome;
use App\Approval\Enum\PaymentType;
use App\Approval\Notification\ApprovalNotifier;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use App\Approval\ValueObject\Money;
use App\Profile\Entity\PlayerProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The checkout branch: a purchase either waits for a parent or goes straight through (FR-090,
 * FR-091, FR-092).
 *
 * **This is the entry point Epic-02 will call.** There are no events yet, so nothing in this epic
 * has a price or a thing to buy; what exists is the decision either side of that purchase, and it
 * is complete. When the event catalogue lands, its checkout calls `requestPurchase()` with the
 * event's own reference and price and gets back the same two outcomes. Nothing here needs
 * rewriting for that — the same shape TASK-005 used for the coach conflict check.
 *
 * The branch itself is two lines because the decision belongs to `ApprovalRequestFactory` and the
 * payment orchestration to `ApprovalWorkflow`; what this class owns is the sequence, which is the
 * part a reader of FR-090 to FR-092 is looking for:
 *
 *  - approval required → store the request, tell the parent, and the child sees "Pending parent
 *    approval". **No payment is attempted**, which is FR-090's acceptance criterion;
 *  - approval not required → take the payment, confirm the child immediately, and send the
 *    parent an informational notice (FR-092).
 */
final readonly class ChildCheckout
{
    public function __construct(
        private ApprovalRequestFactory $factory,
        private ApprovalWorkflow $workflow,
        private ApprovalNotifier $notifier,
        private PurchaseApprovalRequestRepository $requests,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string $purchaseReference Epic-02's event id, or a stand-in until there is one
     */
    public function requestPurchase(
        PlayerProfile $player,
        User $actor,
        string $purchaseReference,
        string $purchaseDescription,
        Money $amount,
        PaymentType $paymentType,
    ): CheckoutOutcome {
        $request = $this->factory->createIfRequired(
            $player,
            $actor,
            $purchaseReference,
            $purchaseDescription,
            $amount,
            $paymentType,
        );

        if (null === $request) {
            return CheckoutOutcome::confirmed($this->workflow->completeWithoutApproval(
                $this->factory->createCompleted($player, $purchaseReference, $purchaseDescription, $amount, $paymentType),
                $actor,
            ));
        }

        // Committed before the parent is told, so a notification can never point at a request
        // that does not exist — and, with the notification stored durably, NFR-093's "must not be
        // silently lost" holds even when mail fails.
        $this->entityManager->wrapInTransaction(function () use ($request): void {
            $this->requests->add($request);
            $this->entityManager->flush();
        });

        $this->notifier->notifyParentApprovalNeeded($request);

        return CheckoutOutcome::awaitingApproval($request);
    }
}
