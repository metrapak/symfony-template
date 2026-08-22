<?php

declare(strict_types=1);

namespace App\Profile\Security;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Repository\PlayerProfileRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The prohibitions a child login is held to (FR-068, BR-065).
 *
 * FR-068's acceptance criterion is the important sentence: each prohibition "returns 403 when
 * attempted directly, not merely a hidden link". A child account holds `ROLE_PLAYER` exactly
 * like their parent — that is what lets them sign in at all — so `access_control` cannot tell
 * them apart, and every `/family` route would be open to them by role alone. This voter is the
 * difference, and it is applied with `#[IsGranted]` on the actions rather than by the templates
 * that render the buttons.
 *
 * The attributes are phrased as capabilities the account *has* rather than as prohibitions,
 * because a Symfony voter's vote is positive: `supports()` claims the attribute, so returning
 * false denies it to everyone, and the parent has to be granted explicitly. Reading
 * "MANAGE_ASSOCIATIONS is granted to a parent and refused to a child" is also closer to what
 * the code does than a double negative would be.
 *
 * Who counts as a child is read from the profile, never from the role or from the account's age
 * (BR-065, and the same rule `RedemptionPlanner` applies to FR-048): the only account that is a
 * child is one whose profile is managed by somebody else.
 */
final class ChildActionVoter extends Voter
{
    /** Adding or removing a child-trainer association (FR-068: "change trainer associations"). */
    public const MANAGE_ASSOCIATIONS = 'FAMILY_MANAGE_ASSOCIATIONS';

    /** Creating and editing child profiles, and giving a child a login (FR-063, FR-067). */
    public const MANAGE_CHILDREN = 'FAMILY_MANAGE_CHILDREN';

    /** The family's contact details, which BR-064 says the parent owns. */
    public const MANAGE_CONTACTS = 'FAMILY_MANAGE_CONTACTS';

    /**
     * FR-068's payment prohibitions.
     *
     * No endpoint in this epic reaches them — there is nothing to buy yet — and they are
     * declared here rather than invented later because TASK-006 is the child purchase-approval
     * workflow and is specified to need exactly this distinction. A capability defined in one
     * place is what stops that task from re-deriving "is this a child?" against the role.
     */
    public const MANAGE_PAYMENT_METHODS = 'ACCOUNT_MANAGE_PAYMENT_METHODS';
    public const COMPLETE_PURCHASE = 'ACCOUNT_COMPLETE_PURCHASE';

    private const ATTRIBUTES = [
        self::MANAGE_ASSOCIATIONS,
        self::MANAGE_CHILDREN,
        self::MANAGE_CONTACTS,
        self::MANAGE_PAYMENT_METHODS,
        self::COMPLETE_PURCHASE,
    ];

    public function __construct(
        private readonly PlayerProfileRepository $profiles,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Subject-free: these are facts about the account, not about a particular row. The
        // object-level question — "is this *your* child?" — is `ProfileVoter`'s, and both are
        // checked, because a parent who may manage children generally must still not manage
        // another family's.
        return null === $subject && \in_array($attribute, self::ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        // Family capabilities belong to the account that has a family. A trainer or coach has
        // no `/family` section at all, and a Super Admin acts through impersonation, which is
        // audited.
        if (UserRole::Player !== $actor->getRole()) {
            return false;
        }

        return !$this->isChildAccount($actor);
    }

    private function isChildAccount(User $actor): bool
    {
        $profile = $this->profiles->findProfileForAccount($actor);

        return null !== $profile && $profile->isChild();
    }
}
