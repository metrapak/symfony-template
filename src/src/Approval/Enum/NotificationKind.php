<?php

declare(strict_types=1);

namespace App\Approval\Enum;

/**
 * What an in-app notification is about (FR-093, FR-095).
 *
 * A backed enum rather than a free-text type, for the same reason `AuditAction` is one: the
 * indicator counts by kind, and a typo in a string would produce a notification nobody can
 * filter for and a count that is quietly wrong.
 */
enum NotificationKind: string
{
    /** FR-093 — a parent is being asked to decide. */
    case ApprovalNeeded = 'approval_needed';

    /** FR-092 — a parent is being *told*, because they waived approval for this child's tokens. */
    case TokenSpendNotice = 'token_spend_notice';

    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';

    /**
     * Whether this notification asks the reader to do something.
     *
     * The indicator uses it to distinguish "you have three things to read" from "three purchases
     * are waiting on you", which are different sentences and different urgencies.
     */
    public function needsAction(): bool
    {
        return self::ApprovalNeeded === $this;
    }
}
