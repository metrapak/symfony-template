<?php

declare(strict_types=1);

namespace App\Account\Exception;

use App\Account\Enum\UserStatus;

/**
 * BR-023: `Deleted` is terminal, so an anonymized account cannot be reactivated (FR-025).
 */
final class InvalidStatusTransition extends \DomainException implements AccountException
{
    public static function between(UserStatus $from, UserStatus $to): self
    {
        return new self(\sprintf('An account cannot move from "%s" to "%s".', $from->value, $to->value));
    }
}
