<?php

declare(strict_types=1);

namespace App\Account\Exception;

final class VerificationLinkExpired extends \RuntimeException implements AccountException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('This verification link has expired. Request a new one below.', 0, $previous);
    }
}
