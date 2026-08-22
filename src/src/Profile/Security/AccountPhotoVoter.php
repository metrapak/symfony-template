<?php

declare(strict_types=1);

namespace App\Profile\Security;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Repository\CoachProfileRepository;
use App\Profile\Service\OrganizationMembershipResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may load another account's photograph (FR-060, FR-062, NFR-066).
 *
 * The requirements say a user uploads a photo and never say who may look at it, so this is a
 * decision rather than a transcription — and it is made narrowly, because a photograph of a
 * named person is the most identifying file the platform stores. Three grants:
 *
 *  - **Yourself.** The profile screen has to render your own avatar.
 *  - **A Super Admin**, who administers accounts and already sees every other field of them.
 *  - **A coach who published their profile**, to the members of that coach's organization.
 *    This is the one outward grant, and it exists because FR-061 gives coaches a
 *    public-profile checkbox: a public profile with an unloadable portrait is not public.
 *
 * Everything else is refused, including trainer-to-player and player-to-coach where no public
 * profile was published. That is deliberately stricter than `ProfileVoter::VIEW`, which lets a
 * trainer see their roster: a name on a roster is operationally necessary, a photograph is not,
 * and the looser rule can be granted later against a requirement that asks for it. The reverse
 * — tightening a photo endpoint people have started relying on — is the change nobody makes.
 *
 * Player and child photographs are not here at all. They live on `PlayerProfile` and are
 * guarded by `ProfileVoter`, so a child's picture is reachable only by their family and by a
 * trainer they actively train with.
 */
final class AccountPhotoVoter extends Voter
{
    public const VIEW = 'ACCOUNT_PHOTO_VIEW';

    public function __construct(
        private readonly OrganizationMembershipResolver $memberships,
        private readonly CoachProfileRepository $coachProfiles,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof User;
    }

    /**
     * @param User $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        if ($subject->getId() === $actor->getId() || UserRole::SuperAdmin === $actor->getRole()) {
            return true;
        }

        if (UserRole::Coach !== $subject->getRole()) {
            return false;
        }

        $viewerOrganizationIds = $this->memberships->organizationIdsFor($actor);

        foreach ($this->coachProfiles->findAllForUser($subject) as $coachProfile) {
            if (!$coachProfile->isPublic()) {
                continue;
            }

            if (\in_array((int) $coachProfile->getOrganization()->getId(), $viewerOrganizationIds, true)) {
                return true;
            }
        }

        return false;
    }
}
