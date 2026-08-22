<?php

declare(strict_types=1);

namespace App\Profile\Security;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Service\OrganizationMembershipResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may change an organization's branding, and who may see it (FR-071, FR-072, BR-069).
 *
 * EDIT is the organization's owner. `/trainer/*` is already `ROLE_TRAINER`, which every trainer
 * holds, so nothing but this stops one trainer replacing another's logo — the same object-level
 * gap `ShareLinkVoter` closes for invitations.
 *
 * VIEW is every member of the organization, which is BR-069 ("branding is visible to all of its
 * members") stated as an authorization rule. It matters because the logo is a stored file
 * served through a controller: without this check, a logo URL would be readable by any
 * authenticated stranger, and an organization's mark is not something a competitor should be
 * able to enumerate out of the platform. A Super Admin is granted VIEW so administrative
 * screens can render the tenant they are looking at.
 */
final class BrandingVoter extends Voter
{
    public const VIEW = 'BRANDING_VIEW';
    public const EDIT = 'BRANDING_EDIT';

    public function __construct(
        private readonly OrganizationMembershipResolver $memberships,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof Organization;
    }

    /**
     * @param Organization $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        $isOwner = $subject->getOwner()->getId() === $actor->getId();

        if (self::EDIT === $attribute) {
            return $isOwner && UserRole::Trainer === $actor->getRole();
        }

        return $isOwner
            || UserRole::SuperAdmin === $actor->getRole()
            || $this->memberships->belongsTo($actor, (int) $subject->getId());
    }
}
