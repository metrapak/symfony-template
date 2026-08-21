<?php

declare(strict_types=1);

namespace App\Account\Exception;

final class NoOrganizationInContext extends \LogicException implements AccountException
{
    public static function forCurrentUser(): self
    {
        return new self('The current user is not scoped to an organization.');
    }
}
