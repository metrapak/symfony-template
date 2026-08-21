<?php

declare(strict_types=1);

namespace App\Account\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for requesting a password reset link (FR-004).
 */
final class ForgotPasswordInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
