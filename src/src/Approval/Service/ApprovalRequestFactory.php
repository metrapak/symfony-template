<?php

declare(strict_types=1);

namespace App\Approval\Service;

use App\Account\Entity\User;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Enum\PaymentType;
use App\Approval\ValueObject\Money;
use App\Profile\Entity\PlayerProfile;
use Symfony\Component\Clock\ClockInterface;

/**
 * Decides whether a purchase needs a parent's approval, and builds the request when it does
 * (FR-090, FR-091, FR-092, BR-090, BR-091, BR-095).
 *
 * The decision has three inputs and one rule each, and `approvalIsRequired()` is deliberately
 * static and pure so the whole matrix can be tested without a database:
 *
 *  - **Who is buying.** Approval is a child's constraint, not a purchase's. An adult buying for
 *    themselves needs nobody's permission, and a *parent* buying for their child is already the
 *    person the approval would be asked of — requiring one would ask them to approve themselves.
 *    "Is a child buying?" is read from the profile, never from the role or the age: BR-065
 *    defines a child as somebody whose profile another account manages, and every child login
 *    holds `ROLE_PLAYER` exactly like their parent.
 *  - **How they are paying.** USD always requires approval and no setting can waive it (BR-090,
 *    FR-091). That is why the check below asks `PaymentType::isWaivable()` before it looks at the
 *    setting at all — a bug that let the setting apply to dollars would be invisible until
 *    somebody's child spent real money.
 *  - **What the parent has allowed.** Only then, and only for tokens, does the per-child setting
 *    decide (FR-092, BR-096).
 *
 * **What this does not consult: `ChildActionVoter::COMPLETE_PURCHASE`.** That capability is the
 * blanket prohibition FR-068 puts on a child account, and applying it here would make FR-092's
 * waived token spend impossible — a parent who explicitly allowed it would still be refused. The
 * per-child setting is the narrower, later rule and it wins. The capability keeps its meaning for
 * Epic-05's payment-method screens, where no per-child waiver exists.
 *
 * The decision is data-driven rather than read from the security context on purpose: Epic-02 will
 * call this from a checkout that may not be a web request at all, and a rule that depended on who
 * is signed in would behave differently in a queue.
 */
final readonly class ApprovalRequestFactory
{
    public function __construct(
        private SpendingSettingService $settings,
        private ClockInterface $clock,
        private int $approvalWindowHours,
    ) {
    }

    /**
     * The whole decision matrix, with no dependencies (FR-091, FR-092, BR-090, BR-091).
     *
     * @param bool $tokenSpendingWaived whether this child's parent has allowed unapproved token
     *                                  spending; ignored entirely for a non-waivable payment type
     */
    public static function approvalIsRequired(
        PlayerProfile $player,
        User $actor,
        PaymentType $paymentType,
        bool $tokenSpendingWaived,
    ): bool {
        if (!self::isChildBuyingForThemselves($player, $actor)) {
            return false;
        }

        if (!$paymentType->isWaivable()) {
            return true;
        }

        return !$tokenSpendingWaived;
    }

    /**
     * The request a purchase needs, or null when it may go straight through.
     *
     * Returns an unpersisted entity: whether it is saved, and what else happens in the same
     * transaction, is `ChildCheckout`'s decision and not a side effect of asking a question.
     */
    public function createIfRequired(
        PlayerProfile $player,
        User $actor,
        string $purchaseReference,
        string $purchaseDescription,
        Money $amount,
        PaymentType $paymentType,
    ): ?PurchaseApprovalRequest {
        $required = self::approvalIsRequired(
            $player,
            $actor,
            $paymentType,
            // Read only when it can matter. A USD purchase never consults the setting, so it
            // never pays for the query either.
            $paymentType->isWaivable() && $this->settings->tokenSpendingWaivedFor($player),
        );

        if (!$required) {
            return null;
        }

        $now = $this->clock->now();

        return PurchaseApprovalRequest::awaitingApproval(
            $player,
            $player->getOwner(),
            $purchaseReference,
            $purchaseDescription,
            $amount,
            $paymentType,
            $now,
            $this->expiryFor($now),
        );
    }

    /**
     * The purchase record for something that needed no approval (FR-092).
     *
     * Built here rather than in the checkout so that both halves of FR-092's branch produce the
     * same kind of row, with the same parent and the same reference, and a reader comparing an
     * approved purchase with a waived one is comparing like with like.
     */
    public function createCompleted(
        PlayerProfile $player,
        string $purchaseReference,
        string $purchaseDescription,
        Money $amount,
        PaymentType $paymentType,
    ): PurchaseApprovalRequest {
        return PurchaseApprovalRequest::withoutApproval(
            $player,
            $player->getOwner(),
            $purchaseReference,
            $purchaseDescription,
            $amount,
            $paymentType,
            $this->clock->now(),
        );
    }

    /**
     * FR-096's 48 hours, from the moment the request is made.
     *
     * The window is a container parameter (`app.approval_window_hours`) so an environment can
     * shorten it for testing or a client can change it without a deploy; 48 is the spec's number
     * and the shipping default.
     */
    public function expiryFor(\DateTimeImmutable $requestedAt): \DateTimeImmutable
    {
        return $requestedAt->modify(\sprintf('+%d hours', $this->approvalWindowHours));
    }

    /**
     * Whether the account making the purchase is a child acting for their own profile.
     *
     * Both halves matter. `isManagedByAnotherAccount()` is BR-065's definition of a child, and
     * the identity check is what excludes the parent: a parent buying for their child reaches
     * this with a managed profile too, and they are not the person the rule constrains.
     */
    private static function isChildBuyingForThemselves(PlayerProfile $player, User $actor): bool
    {
        return $player->isManagedByAnotherAccount()
            && $player->getAccount()?->getId() === $actor->getId();
    }
}
