<?php

declare(strict_types=1);

namespace App\Account\Dto;

use App\Shared\Domain\Validator\Constraint\PasswordRequirements;

/**
 * Input for setting a new password from a valid reset link (FR-004).
 *
 * The current password is deliberately absent: whoever holds the emailed token has
 * already proven control of the mailbox and by definition cannot supply it.
 */
final class ResetPasswordInput
{
    #[PasswordRequirements]
    public ?string $plainPassword = null;
}
