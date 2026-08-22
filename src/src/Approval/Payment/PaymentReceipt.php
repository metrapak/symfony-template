<?php

declare(strict_types=1);

namespace App\Approval\Payment;

/**
 * The processor's proof that a payment happened (FR-097).
 *
 * The reference is stored on the purchase, so "this was approved" and "money moved, once" are two
 * separate facts in the database rather than one inferred from the other.
 */
final readonly class PaymentReceipt
{
    public function __construct(
        public string $reference,
        public \DateTimeImmutable $processedAt,
    ) {
    }
}
