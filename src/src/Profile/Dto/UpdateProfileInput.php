<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Entity\CoachProfile;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Entity\TrainerProfile;
use App\Profile\ValueObject\PhoneNumber;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for FR-060 and FR-061 — a user editing their own profile.
 *
 * **The read-only fields are not here.** BR-067 says email, role and skill level are never
 * self-editable, and the way that is enforced is by them being absent from the object the form
 * writes into: a POST carrying `email=` has nothing to bind to, so the "read-only fields reject
 * modification even when POSTed directly" test passes by construction rather than by a check
 * somebody has to remember. A DTO with an ignored property would be one refactor away from
 * being honoured.
 *
 * One class for every role, with the role-specific fields grouped by **validation group** and
 * the form type adding only the fields that role owns. The alternative — five DTOs — duplicates
 * name, phone and photo five times and lets them drift; FR-061's requirement that "each role
 * sees only its own fields" is a question of which fields the *form* builds, which is where it
 * is answered.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class UpdateProfileInput
{
    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /**
     * Optional for everyone. A phone number is required at registration for a parent, and an
     * account created by an administrator may never have had one (see `EditUserInput`);
     * demanding it on the edit screen would lock those users out of their own profile.
     */
    #[Assert\Length(max: 32)]
    public ?string $phone = null;

    /**
     * The upload is validated by `ImageUploader`, not by a constraint.
     *
     * `Assert\Image` would check the extension and the reported MIME type, both of which the
     * client controls, and NFR-066 asks for validation "by content rather than extension". So
     * the file goes to the uploader, which parses it, and the rejection comes back as a form
     * error on this field. Two half-checks in two places would be worse than one real one.
     */
    public ?UploadedFile $photo = null;

    public bool $removePhoto = false;

    // --- Player / parent (group: player) -------------------------------------------------

    #[Assert\Length(max: 255, groups: ['player'])]
    public ?string $school = null;

    #[Assert\Length(max: 8, groups: ['player'])]
    #[Assert\Regex(
        pattern: '/^[0-9]{1,3}$/',
        message: 'A jersey number is up to three digits.',
        groups: ['player'],
    )]
    public ?string $jerseyNumber = null;

    // --- Coach (group: coach) ------------------------------------------------------------

    #[Assert\Length(max: 2000, groups: ['coach'])]
    public ?string $bio = null;

    #[Assert\Length(max: 1000, groups: ['coach'])]
    public ?string $credentials = null;

    #[Assert\Length(max: 1000, groups: ['coach'])]
    public ?string $certifications = null;

    /** FR-061's public-profile checkbox. Off unless the coach turns it on. */
    public bool $publicProfile = false;

    // --- Trainer (group: trainer) --------------------------------------------------------

    #[Assert\Length(max: 255, groups: ['trainer'])]
    public ?string $businessName = null;

    #[Assert\Length(max: 1000, groups: ['trainer'])]
    public ?string $address = null;

    #[Assert\Length(max: 255, groups: ['trainer'])]
    #[Assert\Url(
        message: 'Enter a full web address, including https://.',
        requireTld: true,
        groups: ['trainer'],
    )]
    public ?string $website = null;

    #[Assert\Length(max: 2000, groups: ['trainer'])]
    public ?string $description = null;

    // --- Super Admin (group: admin) ------------------------------------------------------

    public bool $notifyOnTrainerCreated = true;
    public bool $notifyOnAccountErasure = true;

    /**
     * Phone shape is checked here rather than with a `Regex`, so the rule and the normalization
     * that follows it cannot disagree: `PhoneNumber` is what stores the value, so it should be
     * what decides whether the value is storable.
     */
    #[Assert\Callback]
    public function validatePhone(ExecutionContextInterface $context): void
    {
        if (null === $this->phone || '' === trim($this->phone)) {
            return;
        }

        if (null === PhoneNumber::tryParse($this->phone)) {
            $context->buildViolation('Enter a phone number with 7 to 15 digits — for example +48 22 123 45 67.')
                ->atPath('phone')
                ->addViolation();
        }
    }

    /**
     * The validation groups that apply to a given role.
     *
     * Returned as a list the form type hands to `validation_groups`, so "which rules apply" and
     * "which fields are built" are decided from the same value.
     *
     * @return list<string>
     */
    public static function groupsFor(UserRole $role): array
    {
        return match ($role) {
            UserRole::Player => ['Default', 'player'],
            UserRole::Coach => ['Default', 'coach'],
            UserRole::Trainer => ['Default', 'trainer'],
            UserRole::SuperAdmin => ['Default', 'admin'],
        };
    }

    public static function forUser(
        User $user,
        ?PlayerProfile $playerProfile,
        ?CoachProfile $coachProfile,
        ?TrainerProfile $trainerProfile,
        bool $notifyOnTrainerCreated = true,
        bool $notifyOnAccountErasure = true,
    ): self {
        $input = new self();
        $input->name = $user->getName();
        $input->phone = $user->getPhone();

        if (null !== $playerProfile) {
            $input->school = $playerProfile->getSchool();
            $input->jerseyNumber = $playerProfile->getJerseyNumber();
        }

        if (null !== $coachProfile) {
            $input->bio = $coachProfile->getBio();
            $input->credentials = $coachProfile->getCredentials();
            $input->certifications = $coachProfile->getCertifications();
            $input->publicProfile = $coachProfile->isPublic();
        }

        if (null !== $trainerProfile) {
            $input->businessName = $trainerProfile->getBusinessName();
            $input->address = $trainerProfile->getAddress();
            $input->website = $trainerProfile->getWebsite();
            $input->description = $trainerProfile->getDescription();
        }

        $input->notifyOnTrainerCreated = $notifyOnTrainerCreated;
        $input->notifyOnAccountErasure = $notifyOnAccountErasure;

        return $input;
    }
}
