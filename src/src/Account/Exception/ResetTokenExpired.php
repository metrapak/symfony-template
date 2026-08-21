<?php

declare(strict_types=1);

namespace App\Account\Exception;

final class ResetTokenExpired extends \RuntimeException implements AccountException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('This password reset link has expired. Please request a new one.', 0, $previous);
    }
}
