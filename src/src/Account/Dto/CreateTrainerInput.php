<?php

declare(strict_types=1);

namespace App\Account\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-021 — the Super Admin creating a trainer account.
 *
 * Every field the spec calls required is required here (business name, trainer name, email,
 * phone). Uniqueness of the email is *not* asserted with a constraint: a Validator lookup
 * cannot close the window between the check and the insert, so the real guarantee is the
 * unique index and `TrainerAccountCreator` maps the violation back to this field.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class CreateTrainerInput
{
    #[Assert\NotBlank(message: 'Enter the business name.')]
    #[Assert\Length(max: 255)]
    public ?string $businessName = null;

    #[Assert\NotBlank(message: 'Enter the trainer\'s name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Enter an email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank(message: 'Enter a phone number.')]
    #[Assert\Length(max: 32)]
    #[Assert\Regex(
        pattern: '/^[0-9+().\- ]+$/',
        message: 'Use digits, spaces and the characters + ( ) - . only.',
    )]
    public ?string $phone = null;
}
