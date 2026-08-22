<?php

declare(strict_types=1);

namespace App\Approval\Payment;

use App\Approval\Enum\PaymentType;
use App\Approval\ValueObject\Money;

/**
 * Everything a payment processor needs to take one payment, and nothing else (FR-097).
 *
 * A DTO rather than the `PurchaseApprovalRequest` entity, because the port is the boundary
 * Epic-05 will implement against: handing it a Doctrine entity would let a payment gateway
 * adapter reach into the approval workflow's state, and would make the contract change every
 * time a column is added to a table that is none of its business.
 *
 * **`idempotencyKey` is the part that matters for NFR-092.** This application refuses to call the
 * processor twice — the optimistic lock on the request row sees to that — but a network timeout
 * looks the same to the caller whether the payment happened or not, and only the processor can
 * settle it. The key is derived from the approval request id, so a retry of the same purchase
 * carries the same key and a real gateway can recognise it. Epic-05's implementation is required
 * to honour it; the fake asserts on it in tests.
 */
final readonly class PaymentInstruction
{
    public function __construct(
        public string $idempotencyKey,
        public int $payerUserId,
        public int $playerProfileId,
        public string $purchaseReference,
        public string $purchaseDescription,
        public Money $amount,
        public PaymentType $paymentType,
    ) {
    }
}
