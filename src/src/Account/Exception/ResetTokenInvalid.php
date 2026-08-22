<?php

declare(strict_types=1);

namespace App\Account\Exception;

final class ResetTokenInvalid extends \RuntimeException implements AccountException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('This password reset link is invalid or has already been used.', 0, $previous);
    }
}
