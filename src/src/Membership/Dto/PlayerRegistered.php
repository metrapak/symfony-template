<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Account\Entity\User;
use App\Profile\Entity\PlayerProfile;

/**
 * The outcome of a registration through a ShareLink (FR-042).
 *
 * `verificationRequired` is what the controller needs to decide whether it may sign the new
 * account in: the firewall's user checker refuses an unverified player while
 * `EMAIL_VERIFICATION_REQUIRED` is on (Q-01.05), and the registration page has to say so
 * rather than bounce them off an unexplained login failure.
 */
final readonly class PlayerRegistered
{
    public function __construct(
        public User $user,
        public PlayerProfile $player,
        public bool $verificationRequired,
        public bool $confirmationSent,
    ) {
    }
}
