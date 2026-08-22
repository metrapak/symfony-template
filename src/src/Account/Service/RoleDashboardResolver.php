<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Enum\UserRole;

/**
 * Maps a role to the route name of its landing page (FR-008).
 *
 * A pure function with no HTTP awareness, so the mapping is unit-testable and there is
 * exactly one place to change when a role gains or loses a dashboard.
 */
final readonly class RoleDashboardResolver
{
    public function resolveRouteName(UserRole $role): string
    {
        return match ($role) {
            UserRole::SuperAdmin => 'admin_dashboard',
            UserRole::Trainer => 'trainer_dashboard',
            UserRole::Coach => 'coach_dashboard',
            UserRole::Player => 'family_dashboard',
        };
    }
}
