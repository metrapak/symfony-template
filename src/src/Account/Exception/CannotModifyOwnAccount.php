<?php

declare(strict_types=1);

namespace App\Account\Exception;

/**
 * G-17: a Super Admin may not deactivate or delete themselves.
 *
 * The spec does not forbid it, which is exactly why it is blocked here: an operator who
 * anonymizes their own account cannot undo it, cannot sign in afterwards, and leaves no
 * obvious way to explain what happened.
 */
final class CannotModifyOwnAccount extends \DomainException implements AccountException
{
    public static function forAction(string $action): self
    {
        return new self(\sprintf('You cannot %s your own account.', $action));
    }
}
