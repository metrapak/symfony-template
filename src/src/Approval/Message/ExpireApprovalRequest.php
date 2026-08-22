<?php

declare(strict_types=1);

namespace App\Approval\Message;

/**
 * Expire one purchase approval request whose window has closed (FR-096).
 *
 * Carries an id and not the entity: a message may be serialized, queued and handled minutes later
 * by a process with its own entity manager, and a snapshot taken at dispatch would by then
 * describe a request a parent may already have answered. The handler loads the current row.
 */
final readonly class ExpireApprovalRequest
{
    public function __construct(
        public int $requestId,
    ) {
    }
}
