<?php

declare(strict_types=1);

namespace App\Approval\Exception;

use App\Approval\Enum\ApprovalStatus;

/**
 * A purchase was asked to move to a state it cannot legally reach (FR-095, FR-096).
 *
 * Thrown by `PurchaseApprovalRequest` itself, so a state change is refused wherever it is
 * attempted from — the parent's screen, the expiry sweep, or a future Epic-05 caller — rather
 * than only where somebody remembered to write the check.
 *
 * Approving a request that is already decided is the common case and it is deliberately *not*
 * this exception: see `ApprovalAlreadyDecided`, which the screens turn into a message rather
 * than an error, because a parent double-clicking Approve has not done anything wrong.
 */
final class IllegalApprovalTransition extends \DomainException implements ApprovalException
{
    public static function between(ApprovalStatus $from, ApprovalStatus $to): self
    {
        return new self(\sprintf(
            'A purchase that is "%s" cannot become "%s".',
            $from->value,
            $to->value,
        ));
    }
}
