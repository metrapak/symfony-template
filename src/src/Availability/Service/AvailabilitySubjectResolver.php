<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Account\Entity\User;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\PlayerProfileRepository;

/**
 * Whose availability a signed-in player account may edit (FR-081, FR-068).
 *
 * FR-081's "separate availability for each child, via the profile switcher" needs a list, and the
 * list is not simply "the profiles this account owns": a child login owns nothing and must still
 * reach their own week, while a parent must reach theirs and every child's and nobody else's.
 *
 * The precedence is the same one `TrainingContextResolver` and `OrganizationMembershipResolver`
 * apply, and for the same reason — a child holds `ROLE_PLAYER` exactly like their parent, so
 * asking what the *account* owns would hand a child whatever their own account happens to own.
 * Checked here so the two controllers do not each re-derive it, and re-checked by
 * `AvailabilityVoter` on the profile that is actually opened: this decides what to *offer*, the
 * voter decides what is *allowed*, and a list is not an authorization.
 */
final readonly class AvailabilitySubjectResolver
{
    public function __construct(
        private PlayerProfileRepository $profiles,
    ) {
    }

    /**
     * The profiles to show in the switcher: the account's own first, then their children.
     *
     * @return list<PlayerProfile>
     */
    public function editableProfilesFor(User $user): array
    {
        $own = $this->profiles->findProfileForAccount($user);

        // FR-068: a child login is scoped to itself, before anything looks at ownership.
        if (null !== $own && $own->isChild()) {
            return [$own];
        }

        $managed = $this->profiles->findManagedBy($user);

        if ([] !== $managed) {
            return $managed;
        }

        // A player whose own profile is not owned by their account — the shape a converted or
        // repaired account can have. Their own week is still theirs to set.
        return null !== $own ? [$own] : [];
    }

    /**
     * The profile `/availability` opens by default: the person themself when they have a
     * profile, otherwise the first child.
     *
     * A parent who registered only children has no profile of their own, and sending them to
     * their eldest child's grid is more useful than an empty screen explaining that they are not
     * a player.
     */
    public function defaultProfileFor(User $user): ?PlayerProfile
    {
        $profiles = $this->editableProfilesFor($user);

        foreach ($profiles as $profile) {
            if (!$profile->isChild()) {
                return $profile;
            }
        }

        return $profiles[0] ?? null;
    }
}
