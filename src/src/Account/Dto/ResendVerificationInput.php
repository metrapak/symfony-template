<?php

declare(strict_types=1);

namespace App\Account\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for re-requesting an email verification link (FR-005).
 */
final class ResendVerificationInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
