<?php

declare(strict_types=1);

namespace App\Approval\Dto;

use App\Approval\Entity\PurchaseApprovalRequest;

/**
 * What happened at checkout: the child is waiting, or the child is registered (FR-090, FR-092).
 *
 * Two named constructors rather than a boolean flag, because the caller's next sentence differs
 * and a `bool $approved` at a call site reads as neither. The purchase comes back either way, so
 * the screen can link to the thing it is talking about.
 */
final readonly class CheckoutOutcome
{
    private function __construct(
        public PurchaseApprovalRequest $request,
        public bool $awaitingApproval,
    ) {
    }

    public static function awaitingApproval(PurchaseApprovalRequest $request): self
    {
        return new self($request, true);
    }

    public static function confirmed(PurchaseApprovalRequest $request): self
    {
        return new self($request, false);
    }
}
