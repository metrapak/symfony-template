<?php

declare(strict_types=1);

namespace App\Approval\Payment;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The stand-in payment processor that ships until Epic-05 arrives (FR-097, decision D-04).
 *
 * It records the intent and succeeds. Nothing here moves money, talks to a gateway, or holds a
 * credential — and the log line it writes on every call is deliberate: an environment running
 * this by accident once Epic-05 exists leaves evidence in the application log rather than
 * silently registering children for events nobody paid for.
 *
 * **The call log is the test seam.** FR-097 asks for a fake that makes the workflow testable, and
 * the question the tests ask is "how many times was the processor called for this purchase?"
 * (NFR-092). `recordedInstructions()` answers it. The log lives for the life of the service
 * instance — one request — which is all an assertion made after that request needs, and it is
 * cleared by `forget()` for a test that spans several.
 *
 * It is a real service in `src/` rather than a test double, because it is what the application
 * *ships* with while Epic-05 does not exist. A double in `tests/` would leave production with an
 * unimplemented interface, which is a container error at boot rather than a working workflow.
 */
final class FakePaymentProcessor implements PaymentProcessor
{
    /** @var list<PaymentInstruction> */
    private array $instructions = [];

    /** @var array<string, PaymentReceipt> keyed by idempotency key */
    private array $receipts = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(PaymentInstruction $instruction): PaymentReceipt
    {
        // The contract's idempotency clause, honoured rather than assumed: a caller that somehow
        // retried with the same key gets the first receipt back and no second "payment".
        if (isset($this->receipts[$instruction->idempotencyKey])) {
            return $this->receipts[$instruction->idempotencyKey];
        }

        $this->instructions[] = $instruction;

        $receipt = new PaymentReceipt(
            \sprintf('fake-%s', $instruction->idempotencyKey),
            $this->clock->now(),
        );

        $this->receipts[$instruction->idempotencyKey] = $receipt;

        $this->logger->warning('No payment was taken: the fake payment processor is active (Epic-05 is not implemented).', [
            'idempotency_key' => $instruction->idempotencyKey,
            'purchase_reference' => $instruction->purchaseReference,
            'amount' => $instruction->amount->format(),
            'payment_type' => $instruction->paymentType->value,
        ]);

        return $receipt;
    }

    /**
     * Every instruction this instance has been given, in order.
     *
     * @return list<PaymentInstruction>
     */
    public function recordedInstructions(): array
    {
        return $this->instructions;
    }

    public function forget(): void
    {
        $this->instructions = [];
        $this->receipts = [];
    }
}
