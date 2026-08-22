<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Service\TenantContext;
use App\Profile\Dto\EditChildInput;
use App\Profile\Dto\StoredImage;
use App\Profile\Dto\UpdateProfileInput;
use App\Profile\Entity\AdminPreferences;
use App\Profile\Entity\CoachProfile;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Entity\TrainerProfile;
use App\Profile\Exception\ChildAgeOutOfRange;
use App\Profile\Exception\ImageRejected;
use App\Profile\Repository\AdminPreferencesRepository;
use App\Profile\Repository\CoachProfileRepository;
use App\Profile\Repository\PlayerProfileRepository;
use App\Profile\Repository\TrainerProfileRepository;
use App\Profile\ValueObject\BirthDate;
use App\Profile\ValueObject\PhoneNumber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applies a user's edit to their own profile (FR-060, FR-061, BR-067).
 *
 * The role-specific half of FR-061 is a *dispatch*, not a set of conditionals sprinkled through
 * a controller: one `match` on the role, one method per role, each touching only the rows that
 * role owns. A trainer's submit cannot reach a coach's bio because the code that writes a bio
 * is not on the trainer's branch at all.
 *
 * **What is not writable here is what makes BR-067 true.** Email, role, skill level and the
 * creation date are absent from `UpdateProfileInput`, so there is nothing to bind and nothing
 * to ignore. This service never touches them, and the reason it never touches them is that it
 * has not been given them.
 *
 * The role-specific rows are created on first save rather than at registration. A coach who
 * never opens the profile screen has no `coach_profile` row, and that is the right state: an
 * empty row would be indistinguishable from a bio somebody deleted, and every count of "coaches
 * with a profile" would be wrong.
 *
 * Everything commits together, photo included. A profile save that stored the photo and lost the
 * name — or the reverse — is the outcome NFR-060's one-second budget must not be bought with.
 */
final readonly class ProfileUpdater
{
    public function __construct(
        private PlayerProfileRepository $playerProfiles,
        private CoachProfileRepository $coachProfiles,
        private TrainerProfileRepository $trainerProfiles,
        private AdminPreferencesRepository $adminPreferences,
        private ImageUploader $uploader,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ImageRejected when a submitted photo is refused
     */
    public function updateFor(User $user, UpdateProfileInput $input): void
    {
        $now = $this->clock->now();

        // The upload happens before the transaction on purpose. It writes to the filesystem,
        // which no transaction can roll back, so doing it inside one would leave an orphaned
        // file behind whenever the commit failed. A rejected image, conversely, must abort the
        // whole save — hence before, not after.
        $photo = null !== $input->photo ? $this->uploader->storeProfilePhoto($input->photo) : null;

        $replacedPaths = [];

        $this->entityManager->wrapInTransaction(function () use ($user, $input, $photo, $now, &$replacedPaths): void {
            $user->setName((string) $input->name);
            $user->setPhone(PhoneNumber::tryParse($input->phone)?->value);
            $user->setUpdatedAt($now);

            match ($user->getRole()) {
                UserRole::Player => $replacedPaths = $this->applyPlayerFields($user, $input, $photo, $now),
                UserRole::Coach => $replacedPaths = $this->applyCoachFields($user, $input, $photo, $now),
                UserRole::Trainer => $replacedPaths = $this->applyTrainerFields($user, $input, $photo, $now),
                UserRole::SuperAdmin => $replacedPaths = $this->applyAdminFields($user, $input, $photo, $now),
            };

            $this->entityManager->flush();
        });

        // Only once the rows that reference them have committed. Deleting first would take the
        // old photo with a transaction that then rolled back, leaving a row pointing at a file
        // that is gone.
        $this->uploader->delete(...$replacedPaths);
    }

    /**
     * Applies a parent's edit to one of their children's profiles (FR-063, FR-060).
     *
     * Separate from `updateFor()` and not a branch of it, because the subject is different: this
     * one edits a profile the caller *manages* rather than the account they *are*. Nothing here
     * touches a `User` row — a child's name change is not a change to the parent's account, and
     * for a child with their own login the account row belongs to that login, not to this form.
     *
     * Whether the caller may edit *this* child is settled before the request reaches here, by
     * `ProfileVoter::EDIT` on the route. This service is given a profile it is allowed to write.
     *
     * The age bound is re-checked against the submitted date (BR-068), for the reason
     * `ChildProfileCreator` re-checks the submitted age: a constraint that lives only in a form
     * is not enforced against a request that skipped the form. A profile whose *stored* date has
     * simply aged past 18 is not what this refuses — the check runs only on a date the parent is
     * actually submitting, so G-22's aged-out profile still saves its school and jersey number.
     *
     * @throws ChildAgeOutOfRange when the submitted date implies an age outside 1-18
     * @throws ImageRejected when a submitted photo is refused
     */
    public function updateChild(PlayerProfile $child, EditChildInput $input): void
    {
        $now = $this->clock->now();

        if (null !== $input->birthDate) {
            $birthDate = BirthDate::fromDate($input->birthDate);

            if ($birthDate->isInFuture($now) || !$birthDate->isWithinChildRangeOn($now)) {
                throw ChildAgeOutOfRange::forAge($birthDate->ageOn($now));
            }
        }

        // Outside the transaction, as in `updateFor()`: the filesystem does not roll back, and a
        // rejected image must abort the save before any row is touched.
        $photo = null !== $input->photo ? $this->uploader->storeProfilePhoto($input->photo) : null;

        $replacedPaths = [];

        $this->entityManager->wrapInTransaction(function () use ($child, $input, $photo, $now, &$replacedPaths): void {
            $child->rename((string) $input->name, $now);
            $child->setBirthDate($input->birthDate?->setTime(0, 0), $now);
            $child->setGender($input->gender, $now);
            $child->setSchool($input->school, $now);
            $child->setJerseyNumber($input->jerseyNumber, $now);

            $replacedPaths = $this->applyChildPhoto($child, $input, $photo, $now);

            $this->entityManager->flush();
        });

        $this->uploader->delete(...$replacedPaths);
    }

    /**
     * A player's own photo lives on their player profile, not on their account row — see the
     * note on `User::$photoPath`. School and jersey number live there too (FR-061).
     *
     * @return list<string>
     */
    private function applyPlayerFields(User $user, UpdateProfileInput $input, ?StoredImage $photo, \DateTimeImmutable $now): array
    {
        $profile = $this->playerProfiles->findSelfProfileFor($user);

        if (null === $profile) {
            // A player with no self profile is a parent who registered a child and whose own
            // profile was created alongside it, so this is nearly unreachable — but an account
            // created by an administrator has none, and their profile screen must still save.
            $profile = PlayerProfile::forSelf($user, (string) $input->name, $now);
            $this->playerProfiles->add($profile);
        } else {
            $profile->rename((string) $input->name, $now);
        }

        $profile->setSchool($input->school, $now);
        $profile->setJerseyNumber($input->jerseyNumber, $now);

        return $this->applyProfilePhoto($profile, $input, $photo, $now);
    }

    /**
     * @return list<string>
     */
    private function applyCoachFields(User $user, UpdateProfileInput $input, ?StoredImage $photo, \DateTimeImmutable $now): array
    {
        $organizationId = $this->tenantContext->currentOrganizationId();

        // A coach between assignments has no organization, so there is no row to scope their
        // bio to. Their name, phone and photo still save; the professional fields wait until
        // they are assigned. Silently writing them against the wrong tenant would be worse.
        if (null !== $organizationId) {
            $profile = $this->coachProfiles->findOneFor($user, $organizationId);

            if (null === $profile) {
                $organization = $this->entityManager->getReference(Organization::class, $organizationId);
                $profile = new CoachProfile($user, $organization, $now);
                $this->coachProfiles->add($profile);
            }

            $profile->update(
                $input->bio,
                $input->credentials,
                $input->certifications,
                $input->publicProfile,
                $now,
            );
        }

        return $this->applyAccountPhoto($user, $input, $photo);
    }

    /**
     * @return list<string>
     */
    private function applyTrainerFields(User $user, UpdateProfileInput $input, ?StoredImage $photo, \DateTimeImmutable $now): array
    {
        $organizationId = $this->tenantContext->currentOrganizationId();

        if (null !== $organizationId) {
            $profile = $this->trainerProfiles->findOneForOrganization($organizationId);

            if (null === $profile) {
                $organization = $this->entityManager->getReference(Organization::class, $organizationId);
                $profile = new TrainerProfile($user, $organization, $now);
                $this->trainerProfiles->add($profile);
            }

            $profile->update($input->businessName, $input->address, $input->website, $input->description, $now);
        }

        return $this->applyAccountPhoto($user, $input, $photo);
    }

    /**
     * @return list<string>
     */
    private function applyAdminFields(User $user, UpdateProfileInput $input, ?StoredImage $photo, \DateTimeImmutable $now): array
    {
        $preferences = $this->adminPreferences->findOneForUser($user);

        if (null === $preferences) {
            $preferences = new AdminPreferences($user, $now);
            $this->adminPreferences->add($preferences);
        }

        $preferences->update($input->notifyOnTrainerCreated, $input->notifyOnAccountErasure, $now);

        return $this->applyAccountPhoto($user, $input, $photo);
    }

    /**
     * @return list<string> the paths the save orphaned, for deletion after the commit
     */
    private function applyAccountPhoto(User $user, UpdateProfileInput $input, ?StoredImage $photo): array
    {
        if (null === $photo && !$input->removePhoto) {
            return [];
        }

        $replaced = array_values(array_filter([$user->getPhotoPath(), $user->getPhotoThumbnailPath()]));

        // FR-062: an upload replaces the previous photo. A removal clears it.
        $user->setPhoto($photo?->path, $photo?->thumbnailPath);

        return $replaced;
    }

    /**
     * The same replace-or-clear rule as `applyProfilePhoto()`, for the child edit form's DTO.
     *
     * Two methods rather than one taking a union: the two inputs share these three fields by
     * coincidence of what a photo needs, not because they are the same shape, and an interface
     * asserting they are would make either DTO harder to change than it should be.
     *
     * @return list<string>
     */
    private function applyChildPhoto(PlayerProfile $child, EditChildInput $input, ?StoredImage $photo, \DateTimeImmutable $now): array
    {
        if (null === $photo && !$input->removePhoto) {
            return [];
        }

        $replaced = array_values(array_filter([$child->getPhotoPath(), $child->getPhotoThumbnailPath()]));

        $child->setPhoto($photo?->path, $photo?->thumbnailPath, $now);

        return $replaced;
    }

    /**
     * @return list<string>
     */
    private function applyProfilePhoto(PlayerProfile $profile, UpdateProfileInput $input, ?StoredImage $photo, \DateTimeImmutable $now): array
    {
        if (null === $photo && !$input->removePhoto) {
            return [];
        }

        $replaced = array_values(array_filter([$profile->getPhotoPath(), $profile->getPhotoThumbnailPath()]));

        $profile->setPhoto($photo?->path, $photo?->thumbnailPath, $now);

        return $replaced;
    }
}
