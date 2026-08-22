<?php

declare(strict_types=1);

namespace App\Account\Exception;

/**
 * FR-030 / BR-021: a Super Admin may not be impersonated, and only a Super Admin may
 * impersonate.
 */
final class CannotImpersonate extends \DomainException implements AccountException
{
    public static function superAdminTarget(): self
    {
        return new self('Super Admin accounts cannot be impersonated.');
    }

    public static function deletedTarget(): self
    {
        return new self('A deleted account cannot be impersonated.');
    }
}
