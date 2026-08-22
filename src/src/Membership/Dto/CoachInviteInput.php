<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-041 — a trainer inviting one coach (US-01.08: "Enter coach email, optional
 * (name, message)").
 *
 * Whether the address already belongs to an active coach elsewhere is not asserted here.
 * That is a cross-tenant question about another organization's roster, it can change between
 * the invitation and its acceptance, and BR-044 is enforced where it can actually hold — at
 * acceptance, by `CoachInvitationService` and a partial unique index.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class CoachInviteInput
{
    #[Assert\NotBlank(message: 'Enter the coach\'s email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /**
     * Rendered into the invitation email. Bounded because it is free text from one user that
     * is delivered to another; Twig escapes it, and the length cap keeps the message from
     * becoming a payload delivery channel of its own.
     */
    #[Assert\Length(max: 2000, maxMessage: 'Keep the message under {{ limit }} characters.')]
    public ?string $message = null;
}
