<?php

declare(strict_types=1);

namespace App\Account\Exception;

final class VerificationLinkInvalid extends \RuntimeException implements AccountException
{
    public static function create(?\Throwable $previous = null): self
    {
        return new self('This verification link is not valid.', 0, $previous);
    }
}
