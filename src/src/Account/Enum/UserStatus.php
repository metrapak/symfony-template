<?php

declare(strict_types=1);

namespace App\Account\Enum;

/**
 * Account lifecycle status (FR-009, BR-005, BR-006).
 *
 * `Deleted` is terminal: a deleted account can never be reactivated.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deleted = 'deleted';

    public function canAuthenticate(): bool
    {
        return self::Active === $this;
    }

    /**
     * A deleted account is terminal — no transition out of it exists.
     */
    public function canTransitionTo(self $target): bool
    {
        if (self::Deleted === $this) {
            return false;
        }

        return $target !== $this;
    }
}
