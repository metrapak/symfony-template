<?php

declare(strict_types=1);

namespace App\Account\Exception;

final class EmailAlreadyRegistered extends \DomainException implements AccountException
{
    public static function forEmail(string $email, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('An account already exists for "%s".', $email), 0, $previous);
    }
}
