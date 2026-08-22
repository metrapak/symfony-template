<?php

declare(strict_types=1);

namespace App\Membership\Security;

use App\Account\Entity\User;
use App\Membership\Entity\ShareLink;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the signed-in trainer may manage a specific ShareLink.
 *
 * `access_control` already restricts `/trainer/*` to `ROLE_TRAINER`, and that is exactly the
 * check that is not enough here: every trainer holds that role, so without an object-level
 * rule the id in `/trainer/share-links/{id}/deactivate` would let any trainer withdraw any
 * other trainer's invitation. This is the classic IDOR, and the multi-tenancy requirement
 * (NFR-X01) is unmet without it.
 *
 * Ownership is read from the link's organization rather than from `createdBy`: the
 * organization is the tenant boundary, and a link created by a trainer who later hands their
 * organization over still belongs to the organization.
 */
final class ShareLinkVoter extends Voter
{
    public const MANAGE = 'SHARE_LINK_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof ShareLink;
    }

    /**
     * @param ShareLink $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        return $subject->getOrganization()->getOwner()->getId() === $actor->getId();
    }
}
