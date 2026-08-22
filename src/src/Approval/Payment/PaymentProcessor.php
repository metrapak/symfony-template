<?php

declare(strict_types=1);

namespace App\Approval\Payment;

/**
 * The port through which an approved purchase is paid for (FR-097, decision D-04).
 *
 * Epic-05 owns payments and does not exist. The approval workflow is nevertheless complete and
 * worth shipping on its own — a state machine, an expiry, notifications and an audit trail are
 * all independently valuable — so the one thing it cannot do is the one thing behind this
 * interface. `FakePaymentProcessor` ships as the implementation, and swapping in the real one is
 * a container alias: no workflow code changes, which is FR-097's acceptance criterion.
 *
 * This is the same seam, for the same reason, as `UpcomingReservationCanceller` in the Profile
 * module: an interface with an honest stand-in says "this call belongs here and there is
 * presently nothing behind it", where leaving the call out would silently strand the feature the
 * day the missing epic lands.
 *
 * The contract an implementation must honour:
 *
 *  - it takes payment **once** for a given `idempotencyKey`, returning the original receipt if
 *    asked again;
 *  - it returns a `PaymentReceipt` on success, whose reference identifies the payment for the
 *    rest of its life;
 *  - it throws `PaymentFailed` — and nothing else — when the payment does not happen, because
 *    the caller rolls the approval back on exactly that signal.
 */
interface PaymentProcessor
{
    /**
     * @throws PaymentFailed when the payment did not happen
     */
    public function process(PaymentInstruction $instruction): PaymentReceipt;
}
