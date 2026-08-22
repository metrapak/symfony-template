<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Profile\Enum\PlayerGender;
use App\Profile\ValueObject\BirthDate;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-063 — "+ Add Child".
 *
 * FR-063 asks for an **age**, and the column is a birth date (Q-01.02). Both are honoured: the
 * form asks for the age the requirement specifies, and `BirthDate::fromAgeOn()` converts, with
 * the reasoning for which day it picks stated there. The parent can enter the exact date later
 * on the edit form.
 *
 * `acknowledgedDuplicate` is what makes FR-063's duplicate check a **warning rather than a
 * rejection**. The first submit that looks like an existing child comes back with the warning
 * and this flag unset; submitting again with it set goes through. Twins with rhyming names and
 * two cousins named after the same grandmother are both real, so the parent — who knows their
 * own family — gets the last word, and the platform only makes sure they saw the question.
 * Carrying the acknowledgement in the form rather than in the session means a parent with two
 * tabs open cannot have one tab's acknowledgement apply to the other's child.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class CreateChildInput
{
    #[Assert\NotBlank(message: 'Enter the child\'s name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /**
     * BR-068's 1-18 range, on the field the parent actually fills in.
     *
     * `ChildProfileCreator` checks the same bound against the derived birth date, because a
     * rule enforced only by a form is not enforced — and because the edit screen accepts a date
     * rather than an age and has to be held to the same rule.
     */
    #[Assert\NotNull(message: 'Enter the child\'s age.')]
    #[Assert\Range(
        notInRangeMessage: 'A child profile is for ages {{ min }} to {{ max }}. An adult should use their own account.',
        min: BirthDate::MIN_CHILD_AGE,
        max: BirthDate::MAX_CHILD_AGE,
    )]
    public ?int $age = null;

    #[Assert\NotNull(message: 'Select a gender, or "Prefer not to say".')]
    public ?PlayerGender $gender = null;

    #[Assert\Length(max: 255)]
    public ?string $school = null;

    public ?UploadedFile $photo = null;

    /**
     * Organization ids the parent chose to associate this child with (FR-064).
     *
     * Checked against the trainers the parent actually trains with before anything is written —
     * the checklist is rendered from that list, so a value outside it is a tampered id rather
     * than a mistake a form can make.
     *
     * @var list<int>
     */
    public array $organizationIds = [];

    /** Set when the parent confirmed a duplicate-name warning they have already been shown. */
    public bool $acknowledgedDuplicate = false;
}
