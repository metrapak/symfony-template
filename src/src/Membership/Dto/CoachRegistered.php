<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Account\Entity\User;
use App\Membership\Entity\CoachAssignment;

/**
 * The outcome of a coach accepting their invitation with a brand-new account (FR-045).
 */
final readonly class CoachRegistered
{
    public function __construct(
        public User $user,
        public CoachAssignment $assignment,
        public bool $verificationRequired,
    ) {
    }
}
