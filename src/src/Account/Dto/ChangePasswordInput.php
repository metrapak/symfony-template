<?php

declare(strict_types=1);

namespace App\Account\Dto;

use App\Shared\Domain\Validator\Constraint\PasswordRequirements;
use Symfony\Component\Security\Core\Validator\Constraints as SecurityAssert;

/**
 * Input for a voluntary or forced password change (FR-006).
 *
 * Mutable because Symfony Forms writes into it; nothing downstream mutates it further.
 */
final class ChangePasswordInput
{
    #[SecurityAssert\UserPassword(message: 'Your current password is not correct.')]
    public ?string $currentPassword = null;

    #[PasswordRequirements]
    public ?string $plainPassword = null;
}
