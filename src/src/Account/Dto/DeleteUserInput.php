<?php

declare(strict_types=1);

namespace App\Account\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-025/FR-027 — the irreversible GDPR deletion.
 *
 * The reason is required and has a minimum length, because it is the only field of the
 * compliance record a human writes and "asdf" would make the record worthless at exactly the
 * moment it is needed.
 */
final class DeleteUserInput
{
    #[Assert\NotBlank(message: 'Enter the reason for this deletion; it is recorded for compliance.')]
    #[Assert\Length(
        min: 10,
        max: 2000,
        minMessage: 'Give at least {{ limit }} characters of context — this is the compliance record.',
    )]
    public ?string $reason = null;
}
