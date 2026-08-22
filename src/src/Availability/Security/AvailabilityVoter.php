<?php

declare(strict_types=1);

namespace App\Availability\Security;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Availability\Enum\AvailabilitySubjectType;
use App\Availability\Service\OrganizationRosterProvider;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\PlayerProfileRepository;
use App\Profile\Security\ProfileVoter;
use App\Profile\Service\OrganizationMembershipResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may read, and who may change, one subject's availability (FR-081, FR-082, BR-082, BR-087).
 *
 * `access_control` puts `/availability` behind `ROLE_PLAYER` and `/coach/my-times` behind
 * `ROLE_COACH`, which — as everywhere else in this epic — stops protecting anything the moment a
 * URL carries an id: every parent on the platform holds `ROLE_PLAYER`, so without an
 * object-level rule `/availability/player/7` would let any parent rewrite another family's
 * child's week.
 *
 * The rules, and where each comes from:
 *
 *  - **EDIT on a player** is the owning parent or the player's own login. Availability is a
 *    preference, which FR-068 explicitly leaves to a child account ("update basic profile info
 *    (photo, preferences)"), so this delegates to `ProfileVoter::EDIT_OWN_BASICS` rather than to
 *    the stricter `EDIT` a name change needs.
 *  - **EDIT on a coach** is that coach and nobody else (BR-081). Not their trainer: BR-082 says
 *    a trainer views availability and never edits it, and a trainer who could edit "My Times"
 *    could manufacture the absence of a conflict instead of recording an override for it.
 *  - **VIEW** additionally admits the trainer and coaches of an organization the subject
 *    actually belongs to — for a player through `ProfileVoter::VIEW`, which already knows what
 *    an active association is, and for a coach through the viewer's own tenant.
 *
 * Delegating the player cases to `ProfileVoter` is the point of the class rather than a
 * shortcut: family membership, active associations and child logins are decided in one place,
 * and a second implementation of "is this your child?" is how the two stop agreeing.
 */
final class AvailabilityVoter extends Voter
{
    public const VIEW = 'AVAILABILITY_VIEW';
    public const EDIT = 'AVAILABILITY_EDIT';

    public function __construct(
        private readonly Security $security,
        private readonly PlayerProfileRepository $profiles,
        private readonly OrganizationMembershipResolver $memberships,
        private readonly OrganizationRosterProvider $roster,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof AvailabilitySubject;
    }

    /**
     * @param AvailabilitySubject $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        return match ($subject->type) {
            AvailabilitySubjectType::Player => $this->voteOnPlayer($attribute, $subject->id),
            AvailabilitySubjectType::Coach => $this->voteOnCoach($attribute, $subject->id, $actor),
        };
    }

    private function voteOnPlayer(string $attribute, int $playerProfileId): bool
    {
        $profile = $this->profiles->findOneById($playerProfileId);

        // A subject nobody can load is refused rather than granted-and-404'd later, so an id
        // fished for in the URL bar cannot be used to tell existing profiles from absent ones.
        if (!$profile instanceof PlayerProfile) {
            return false;
        }

        return match ($attribute) {
            // BR-082: a trainer never reaches this branch — `EDIT_OWN_BASICS` is the owner and
            // the profile's own login, and nothing else.
            self::EDIT => $this->security->isGranted(ProfileVoter::EDIT_OWN_BASICS, $profile),
            self::VIEW => $this->security->isGranted(ProfileVoter::VIEW, $profile),
            default => false,
        };
    }

    private function voteOnCoach(string $attribute, int $coachUserId, User $actor): bool
    {
        if ($actor->getId() === $coachUserId) {
            // A coach reads and writes their own week. Also true for a trainer or a player who
            // somehow addressed themselves as a coach subject: it is their own row either way.
            return true;
        }

        if (self::EDIT === $attribute) {
            return false;
        }

        // A trainer (and their coaches, who share the roster) may read a coach's declared times
        // for the assignment they are about to make. Scoped through the *viewer's* tenant and
        // the coach's assignment in it, so a trainer of another academy is refused without
        // learning whether the coach exists.
        if (!\in_array($actor->getRole(), [UserRole::Trainer, UserRole::Coach], true)) {
            return false;
        }

        return $this->coachBelongsToViewersOrganization($coachUserId, $actor);
    }

    private function coachBelongsToViewersOrganization(int $coachUserId, User $actor): bool
    {
        foreach ($this->memberships->organizationIdsFor($actor) as $organizationId) {
            if (null !== $this->roster->coachFor($organizationId, $coachUserId)) {
                return true;
            }
        }

        return false;
    }
}
