<?php

declare(strict_types=1);

namespace App\Approval\Exception;

use App\Approval\Enum\ApprovalStatus;

/**
 * Somebody acted on a purchase that had already been decided (NFR-092).
 *
 * The case this exists for is mundane and expected: a parent submits Approve twice, or a browser
 * retries a POST. The workflow refuses the second decision and the screen says so — one payment,
 * one message, no error page. It is a separate type from `IllegalApprovalTransition` precisely so
 * a controller can tell "you already did this" from "that move is not part of the workflow".
 */
final class ApprovalAlreadyDecided extends \RuntimeException implements ApprovalException
{
    public function __construct(
        public readonly ApprovalStatus $currentStatus,
    ) {
        parent::__construct(\sprintf('This purchase has already been %s.', $currentStatus->label()));
    }
}
