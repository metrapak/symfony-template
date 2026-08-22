<?php

declare(strict_types=1);

namespace App\Account\Dto;

use App\Account\Entity\User;

/**
 * The outcome of FR-021, including whether the invitation actually went out.
 *
 * The account is committed before the mail is attempted (side effects run after the
 * transaction), so a transport failure cannot be reported by throwing — the account exists
 * either way. Returning the delivery result instead lets the Super Admin see that the trainer
 * has no credential yet and act on it, rather than the failure living only in a log file.
 */
final readonly class TrainerCreated
{
    public function __construct(
        public User $user,
        public bool $invitationSent,
    ) {
    }
}
