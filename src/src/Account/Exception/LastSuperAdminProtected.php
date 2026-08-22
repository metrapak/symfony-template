<?php

declare(strict_types=1);

namespace App\Account\Exception;

/**
 * G-17: the last account that can still sign in as a Super Admin may not be removed or
 * demoted.
 *
 * There is no self-registration and no UI path to create a Super Admin, so the only way back
 * into a platform with none is shell access to run `app:account:create-super-admin`.
 */
final class LastSuperAdminProtected extends \DomainException implements AccountException
{
    public static function forAction(string $action): self
    {
        return new self(\sprintf('This is the last active Super Admin; %s would lock everyone out of the admin tools.', $action));
    }
}
