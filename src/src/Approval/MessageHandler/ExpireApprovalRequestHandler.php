<?php

declare(strict_types=1);

namespace App\Approval\MessageHandler;

use App\Approval\Message\ExpireApprovalRequest;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use App\Approval\Service\ApprovalWorkflow;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Expires one request (FR-096, NFR-091).
 *
 * A thin adapter, as every handler in this codebase is: it resolves the row and calls the
 * workflow, which owns the transaction, the state change and the notifications.
 *
 * **Idempotent by construction.** A request that no longer exists, or that a parent answered
 * between the sweep and this handler, is a no-op — `ApprovalWorkflow::expire()` returns false and
 * nothing is written or sent. That matters because `sync://` is the configured transport today
 * and a real queue tomorrow: at-least-once delivery is the norm, and re-delivery must not
 * double-notify.
 */
#[AsMessageHandler]
final readonly class ExpireApprovalRequestHandler
{
    public function __construct(
        private PurchaseApprovalRequestRepository $requests,
        private ApprovalWorkflow $workflow,
    ) {
    }

    public function __invoke(ExpireApprovalRequest $message): void
    {
        $request = $this->requests->find($message->requestId);

        if (null === $request) {
            return;
        }

        $this->workflow->expire($request);
    }
}
