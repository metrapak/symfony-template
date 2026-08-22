<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserStatus;
use App\Account\Service\PersonalDataEraser;
use App\Profile\Repository\CoachProfileRepository;
use App\Profile\Repository\EmergencyContactRepository;
use App\Profile\Repository\PlayerProfileRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Profile's answer to what a GDPR erasure must reach (FR-025, FR-026, G-19).
 *
 * Four kinds of data, each with its reasoning on the entity that holds it:
 *
 *  - the account's own photograph;
 *  - every photograph on the player profiles this account **manages**, which for a parent means
 *    their children's — those are pictures of people who never had an account here, and the
 *    parent is the only person who could have consented to them;
 *  - every coach profile's bio, credentials and public flag, across every organization the coach
 *    has worked for, because a bio is a person describing themselves by name;
 *  - the family's emergency contacts, which are third parties' phone numbers.
 *
 * It also **deactivates the logins of managed children**. A parent's erasure would otherwise
 * leave a child account able to sign in with a credential nobody is left to manage. Deactivated
 * rather than erased: FR-026 keeps their history, BR-006 makes deletion terminal, and erasing a
 * second person is a decision an administrator should take deliberately rather than inherit.
 *
 * **Profile names are not cleared, and that is deliberate.** FR-026 requires history to survive:
 * a player profile renamed "Deleted User" would rewrite last season's roster, and this erasure's
 * whole design is that historical rows and aggregate totals do not move. `User::$name` is
 * anonymized because it identifies the *account*; a child's profile name is the label on a
 * history that has to keep making sense. That distinction is load-bearing, so it is stated here
 * rather than left to be inferred from what the code does not do.
 */
final readonly class ProfileDataEraser implements PersonalDataEraser
{
    public function __construct(
        private PlayerProfileRepository $playerProfiles,
        private CoachProfileRepository $coachProfiles,
        private EmergencyContactRepository $emergencyContacts,
        private ImageUploader $uploader,
        private ClockInterface $clock,
    ) {
    }

    public function erasePersonalDataFor(User $user): array
    {
        $now = $this->clock->now();
        $paths = [$user->getPhotoPath(), $user->getPhotoThumbnailPath()];

        $user->setPhoto(null, null);

        // `findManagedBy` rather than the account's own profile: a parent's erasure has to reach
        // their children's rows, and those are owned by the parent.
        foreach ($this->playerProfiles->findManagedBy($user) as $profile) {
            $paths[] = $profile->getPhotoPath();
            $paths[] = $profile->getPhotoThumbnailPath();
            $profile->setPhoto(null, null, $now);

            $childAccount = $profile->getAccount();

            if (null !== $childAccount
                && $childAccount->getId() !== $user->getId()
                && UserStatus::Active === $childAccount->getStatus()
            ) {
                $childAccount->setStatus(UserStatus::Inactive);
                // Ends the child's live session on their next request, the same way a password
                // change does (User::isEqualTo compares this stamp).
                $childAccount->recordPasswordChange($now);
                $childAccount->setUpdatedAt($now);
            }
        }

        foreach ($this->coachProfiles->findAllForUser($user) as $coachProfile) {
            $coachProfile->anonymize($now);
        }

        foreach ($this->emergencyContacts->findForParent($user) as $contact) {
            $contact->anonymize($now);
        }

        return array_values(array_filter($paths));
    }

    public function deleteOrphanedFiles(string ...$paths): void
    {
        $this->uploader->delete(...$paths);
    }
}
