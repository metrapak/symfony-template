<?php

declare(strict_types=1);

namespace App\Approval\Service;

use App\Approval\Message\ExpireApprovalRequest;
use App\Approval\Repository\PurchaseApprovalRequestRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finds the requests whose 48 hours have run out and hands each one to the workflow (FR-096,
 * NFR-091).
 *
 * **Why a sweep and not a timer.** NFR-091 asks for expiry "within a bounded window of the
 * 48-hour mark, not next time someone looks", so the two candidate designs are a scheduled sweep
 * and a delayed message dispatched at creation. The delayed message needs a transport that
 * supports delays; the only configured transport is `sync://`, which executes immediately, and a
 * request that expired the moment it was created is worse than one that expires a few minutes
 * late. The sweep needs nothing but a cron entry, and its lateness is bounded by the cron
 * interval — a number an operator can see and change. See `ExpireApprovalRequestsCommand` for
 * the schedule this expects.
 *
 * **One message per request, not one big loop.** Each expiry is its own unit of work with its own
 * transaction and its own notifications, so one request that fails — a mail transport error, a
 * lock — does not take the rest of the batch with it, and a redelivery re-expires only that one.
 * The handler is idempotent (`ApprovalWorkflow::expire()` returns false for anything no longer
 * pending), so a message delivered twice notifies once.
 */
final readonly class ApprovalExpiryHandler
{
    public function __construct(
        private PurchaseApprovalRequestRepository $requests,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Dispatches one expiry message per due request.
     *
     * @param int $limit how many to take in one pass; the next run picks up the remainder, which
     *                   keeps a backlog from turning into one unbounded transaction
     *
     * @return int how many were dispatched
     */
    public function expireDue(int $limit = 200): int
    {
        $due = $this->requests->dueForExpiry($this->clock->now(), $limit);

        foreach ($due as $id) {
            $this->bus->dispatch(new ExpireApprovalRequest($id));
        }

        return \count($due);
    }
}
