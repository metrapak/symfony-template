<?php

declare(strict_types=1);

namespace App\Approval\Security;

use App\Account\Entity\User;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Security\ChildActionVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may act on a purchase, and who may only look at one (BR-094, FR-099).
 *
 * `access_control` puts `/family` behind `ROLE_PLAYER`, which — as everywhere else in this epic —
 * stops protecting anything the moment a URL carries an id: every parent and every child login
 * holds that role, so without an object-level rule `/family/approvals/7/approve` would let any
 * account on the platform spend another family's money.
 *
 * The three attributes:
 *
 *  - **DECIDE** is the child's own parent, and nobody else (BR-094). Not another parent, not a
 *    Super Admin — they have impersonation, which is audited (FR-032), and a silent approval from
 *    the admin role would not be — and above all not the child (FR-099). The child half is not
 *    re-derived here: it delegates to `ChildActionVoter::MANAGE_PAYMENT_METHODS`, which is the one
 *    implementation of "is this account a child?" in the codebase, declared by TASK-004 for
 *    exactly this task. A second implementation is how the two stop agreeing.
 *  - **VIEW** additionally admits the child the purchase belongs to, because FR-090 and FR-095
 *    require them to see the status change from Pending to Confirmed. Seeing is all it grants.
 *  - **START** is on a `PlayerProfile` rather than a request, and answers "may this account begin
 *    a checkout for this player?": the profile's own login, or the account that manages it. Note
 *    what it deliberately does *not* do — it does not refuse a child. A child reaching checkout is
 *    FR-090's whole premise; what the child cannot do is complete the purchase, and that is
 *    decided per payment type and per child by `ApprovalRequestFactory`, not by a role.
 *
 * FR-099's "returns 403, not merely a hidden link" is why these are `#[IsGranted]` attributes on
 * the actions rather than `is_granted()` in the templates that draw the buttons.
 */
final class ApprovalVoter extends Voter
{
    public const VIEW = 'PURCHASE_APPROVAL_VIEW';
    public const DECIDE = 'PURCHASE_APPROVAL_DECIDE';
    public const START = 'PURCHASE_START';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW, self::DECIDE => $subject instanceof PurchaseApprovalRequest,
            self::START => $subject instanceof PlayerProfile,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        return match (true) {
            self::START === $attribute && $subject instanceof PlayerProfile => $this->mayStartCheckout($subject, $actor),
            self::DECIDE === $attribute && $subject instanceof PurchaseApprovalRequest => $this->mayDecide($subject, $actor),
            self::VIEW === $attribute && $subject instanceof PurchaseApprovalRequest => $this->mayView($subject, $actor),
            default => false,
        };
    }

    private function mayDecide(PurchaseApprovalRequest $request, User $actor): bool
    {
        // Both halves are required and neither is sufficient: the capability says this account is
        // a parent rather than a child, and the identity check says it is *this* child's parent.
        return $this->security->isGranted(ChildActionVoter::MANAGE_PAYMENT_METHODS)
            && $request->getParent()->getId() === $actor->getId();
    }

    private function mayView(PurchaseApprovalRequest $request, User $actor): bool
    {
        if ($this->mayDecide($request, $actor)) {
            return true;
        }

        return $request->getChildProfile()->getAccount()?->getId() === $actor->getId();
    }

    private function mayStartCheckout(PlayerProfile $player, User $actor): bool
    {
        return $player->getAccount()?->getId() === $actor->getId()
            || $player->getOwner()->getId() === $actor->getId();
    }
}
