<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Profile\Enum\PlayerGender;
use App\Shared\Domain\Validator\Constraint\PasswordRequirements;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-042 — the registration form behind a player ShareLink.
 *
 * The spec's field list ("Name, email, password, phone (parent), player name/age/gender")
 * never says who the registrant is registering, which is G-21. This DTO answers it with an
 * explicit `registeringChild` flag rather than by guessing from the age: a parent and their
 * child are two people and the schema stores them as two rows, so the form has to ask.
 *
 * The two branches are separated by **validation groups**, resolved from the submitted flag by
 * the form type. That is what keeps NFR-043's progressive enhancement honest — the child
 * fields may be hidden by JavaScript, but the server applies the child rules whenever the flag
 * says child, and a hidden field is not an unvalidated one.
 *
 * Uniqueness of the email is deliberately not a constraint here: a Validator lookup cannot
 * close the window between the check and the insert, so the guarantee is the unique index and
 * `PlayerRegistrationService` maps the violation back onto this field.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class PlayerRegistrationInput
{
    #[Assert\NotBlank(message: 'Enter your name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Enter an email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[PasswordRequirements]
    public ?string $plainPassword = null;

    #[Assert\NotBlank(message: 'Enter a phone number.')]
    #[Assert\Length(max: 32)]
    #[Assert\Regex(
        pattern: '/^[0-9+().\- ]+$/',
        message: 'Use digits, spaces and the characters + ( ) - . only.',
    )]
    public ?string $phone = null;

    /** Whether the person who will train is a child of the account holder, rather than the account holder. */
    public bool $registeringChild = false;

    /**
     * The player's name. Required only on the child branch: an adult registering themselves
     * trains under the name they already gave above, and asking for it twice invites two
     * spellings of one person.
     */
    #[Assert\NotBlank(message: 'Enter the player\'s name.', groups: ['child'])]
    #[Assert\Length(max: 255, groups: ['child', 'self'])]
    public ?string $playerName = null;

    /**
     * Birth date, not age (Q-01.02). Spec §9 asks for "age validation for children (1-18
     * years)"; an age column is correct on the day it is typed and wrong every birthday after,
     * so the range is checked here against a date that stays true.
     */
    #[Assert\NotNull(message: 'Enter a date of birth.', groups: ['child', 'self'])]
    #[Assert\LessThanOrEqual(value: '-1 year', message: 'A player must be at least one year old.', groups: ['child'])]
    #[Assert\GreaterThan(value: '-19 years', message: 'A child profile is for players aged 18 or under. Register an adult player as yourself instead.', groups: ['child'])]
    #[Assert\LessThanOrEqual(value: '-18 years', message: 'You must be 18 or over to register your own account. Register as a parent and add a child profile instead.', groups: ['self'])]
    public ?\DateTimeImmutable $birthDate = null;

    #[Assert\NotNull(message: 'Select a gender, or "Prefer not to say".', groups: ['child', 'self'])]
    public ?PlayerGender $gender = null;

    /**
     * The name to store on the player profile, whichever branch was taken.
     */
    public function resolvedPlayerName(): string
    {
        if ($this->registeringChild) {
            return (string) $this->playerName;
        }

        return (string) $this->name;
    }
}
