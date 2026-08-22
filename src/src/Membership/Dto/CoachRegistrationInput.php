<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Shared\Domain\Validator\Constraint\PasswordRequirements;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for the registration half of FR-045 — a coach who has no account yet opening their
 * invitation.
 *
 * Shorter than the player form on purpose: a coach has no player profile, no age and no
 * gender to record. Bio, credentials and certifications (spec §8, "For Coach Profiles") are
 * not part of this task; a coach's first job here is to exist and to be attached to the
 * organization that invited them.
 *
 * The email is prefilled from the invitation but stays editable, because the invitation may
 * have been sent to a work address the coach does not want as their login. Whichever address
 * they choose is the one BR-044 is enforced against, since the rule is about the account and
 * not about the envelope.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class CoachRegistrationInput
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

    #[Assert\Length(max: 32)]
    #[Assert\Regex(
        pattern: '/^[0-9+().\- ]+$/',
        message: 'Use digits, spaces and the characters + ( ) - . only.',
    )]
    public ?string $phone = null;
}
