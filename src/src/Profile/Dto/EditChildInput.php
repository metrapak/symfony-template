<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Profile\Entity\PlayerProfile;
use App\Profile\Enum\PlayerGender;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for editing an existing child profile (FR-063, FR-060).
 *
 * Takes a **date** where `CreateChildInput` takes an age. That asymmetry is intentional: the
 * create form matches FR-063's field list and the parent is usually working from memory, while
 * by the time they come back to edit they either know the date or have no reason to touch it.
 * Storing a derived date and then only ever offering an age to change it would make the exact
 * date unreachable forever.
 *
 * The 1-18 bound applies to the value being *entered* (BR-068), not to the profile's current
 * age: a child who turns 19 is not editable-into-validity and their profile is not broken
 * (G-22). So a birth date is refused for being in the future or implying an age over 18 at the
 * moment it is submitted, and a profile that has simply aged past the bound saves unchanged.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class EditChildInput
{
    #[Assert\NotBlank(message: 'Enter the child\'s name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotNull(message: 'Enter a date of birth.')]
    #[Assert\LessThanOrEqual(value: 'today', message: 'A date of birth cannot be in the future.')]
    public ?\DateTimeImmutable $birthDate = null;

    #[Assert\NotNull(message: 'Select a gender, or "Prefer not to say".')]
    public ?PlayerGender $gender = null;

    #[Assert\Length(max: 255)]
    public ?string $school = null;

    #[Assert\Length(max: 8)]
    #[Assert\Regex(pattern: '/^[0-9]{1,3}$/', message: 'A jersey number is up to three digits.')]
    public ?string $jerseyNumber = null;

    public ?UploadedFile $photo = null;

    public bool $removePhoto = false;

    public static function fromProfile(PlayerProfile $profile): self
    {
        $input = new self();
        $input->name = $profile->getDisplayName();
        $input->birthDate = $profile->getBirthDate();
        $input->gender = $profile->getGender() ?? PlayerGender::Undisclosed;
        $input->school = $profile->getSchool();
        $input->jerseyNumber = $profile->getJerseyNumber();

        return $input;
    }
}
