<?php

declare(strict_types=1);

namespace App\Approval\Payment;

use App\Approval\Exception\ApprovalException;

/**
 * A payment processor refused or could not complete a payment (FR-097).
 *
 * Part of the port's contract rather than an implementation detail, because the workflow's
 * behaviour depends on it: an approval whose payment fails is rolled back to pending, so the
 * parent can try again rather than being left with an approved purchase nobody paid for. An
 * implementation that signalled failure any other way — a null return, a false — would break
 * that, which is why the contract names this type.
 *
 * Nothing throws it today: `FakePaymentProcessor` always succeeds, because a stand-in that
 * failed randomly would make every test flaky. It exists so Epic-05's adapter has the exception
 * to throw and the workflow already handles it.
 */
final class PaymentFailed extends \RuntimeException implements ApprovalException
{
}
