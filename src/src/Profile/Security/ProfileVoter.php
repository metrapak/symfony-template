<?php

declare(strict_types=1);

namespace App\Profile\Security;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Service\OrganizationMembershipResolver;
use App\Profile\Service\TrainerAssociationGateway;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may look at, and who may change, one player profile (FR-063, FR-066, FR-070).
 *
 * `access_control` puts `/family/*` behind `ROLE_PLAYER`, and that is precisely the check that
 * protects nothing once a URL contains `{id}`: every parent on the platform holds that role,
 * so without an object-level rule `/family/children/7/edit` lets any parent rename any other
 * family's child. Same IDOR as `ShareLinkVoter` guards, on more sensitive data.
 *
 * The three attributes are deliberately different rules rather than one:
 *
 *  - **EDIT** is the owning account and nobody else. Not the child themself (G-25 leaves what a
 *    child may change undefined, and name and birth date are the fields a parent would be
 *    astonished to find editable — so a child gets `EDIT_OWN_BASICS` instead), and not the
 *    trainer, who assesses skill level through their own screens and has no business renaming
 *    a player.
 *  - **EDIT_OWN_BASICS** is FR-067's "update basic profile info (photo, preferences)", granted
 *    to the profile's own login. It is a *narrower* attribute rather than a flag on EDIT so the
 *    controller cannot accidentally accept a name change from a child by checking the wrong
 *    one. Which fields it covers is enforced by the form, and stated there.
 *  - **VIEW** additionally admits the trainer and coaches of an organization the profile
 *    actively trains with, because a roster that cannot show a player's name is not a roster.
 *    An *ended* association grants nothing: BR-066 says the trainer stops seeing the child.
 *
 * A Super Admin is granted VIEW and nothing more. They have impersonation for anything further,
 * which is audited (FR-032); a silent cross-family write from the admin role would not be.
 */
final class ProfileVoter extends Voter
{
    public const VIEW = 'PLAYER_PROFILE_VIEW';
    public const EDIT = 'PLAYER_PROFILE_EDIT';
    public const EDIT_OWN_BASICS = 'PLAYER_PROFILE_EDIT_OWN_BASICS';

    public function __construct(
        private readonly TrainerAssociationGateway $associations,
        private readonly OrganizationMembershipResolver $memberships,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::EDIT_OWN_BASICS], true)
            && $subject instanceof PlayerProfile;
    }

    /**
     * @param PlayerProfile $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::EDIT => $this->isOwner($subject, $actor),
            self::EDIT_OWN_BASICS => $this->isOwner($subject, $actor) || $this->isOwnLogin($subject, $actor),
            self::VIEW => $this->canView($subject, $actor),
            default => false,
        };
    }

    private function canView(PlayerProfile $profile, User $actor): bool
    {
        if ($this->isOwner($profile, $actor) || $this->isOwnLogin($profile, $actor)) {
            return true;
        }

        if (UserRole::SuperAdmin === $actor->getRole()) {
            return true;
        }

        // A trainer or coach sees the players of their own organization, and only while the
        // association is active. Resolved through the viewer's tenant rather than by asking
        // the profile which trainers it has, so a viewer with no tenant is refused without a
        // query against the roster.
        if (!\in_array($actor->getRole(), [UserRole::Trainer, UserRole::Coach], true)) {
            return false;
        }

        foreach ($this->memberships->organizationIdsFor($actor) as $organizationId) {
            if ($this->associations->hasActiveAssociation($profile, $organizationId)) {
                return true;
            }
        }

        return false;
    }

    private function isOwner(PlayerProfile $profile, User $actor): bool
    {
        return $profile->getOwner()->getId() === $actor->getId();
    }

    /**
     * Whether the actor *is* this player.
     *
     * True for a child signed in with their own login, and for an adult whose self profile is
     * their own account. False for a parent looking at a child — that is `isOwner()`, and the
     * two are separate because a child must not inherit what a parent may do.
     */
    private function isOwnLogin(PlayerProfile $profile, User $actor): bool
    {
        return $profile->getAccount()?->getId() === $actor->getId();
    }
}
