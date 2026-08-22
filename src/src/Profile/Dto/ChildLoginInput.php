<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Shared\Domain\Validator\Constraint\PasswordRequirements;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-067 — a parent creating their child's login (G-23).
 *
 * G-23 is the gap this resolves: US-01.06 says a child "can optionally have separate login
 * (shares parent's contact info)" and no story says how those credentials come into existence.
 * A child usually has no email address, and `User.email` is the login identifier, so the parent
 * chooses a **username** and the account is created with a derived, undeliverable address. See
 * `ChildLoginManager` for why that address is shaped the way it is and what it costs.
 *
 * The parent sets the first password and the child is required to change it on first sign-in,
 * reusing the `mustChangePassword` mechanism TASK-001 built for administrator-created trainers.
 * That is not ceremony: the parent knows the password they typed, and a credential shared
 * between two people is not the child's own login.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class ChildLoginInput
{
    /**
     * Deliberately narrow. No `@`, so a username can never be mistaken for an email address by
     * the user provider (see `UserRepository::loadUserByIdentifier`); no spaces or uppercase, so
     * a child typing it on a phone keyboard gets the same string every time; at least four
     * characters, so it is not a single letter somebody else has already taken.
     */
    #[Assert\NotBlank(message: 'Choose a username for your child.')]
    #[Assert\Length(
        min: 4,
        max: 64,
        minMessage: 'A username needs at least {{ limit }} characters.',
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9._-]+$/',
        message: 'Use letters, digits and the characters . _ - only.',
    )]
    public ?string $username = null;

    #[PasswordRequirements]
    public ?string $plainPassword = null;
}
