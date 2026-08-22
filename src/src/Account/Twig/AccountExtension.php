<?php

declare(strict_types=1);

namespace App\Account\Twig;

use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Presentation labels for the account enums.
 *
 * The enum values are security role strings (`ROLE_SUPER_ADMIN`) and lifecycle keys
 * (`inactive`); neither is what an operator should read. Keeping the mapping here rather than
 * in each template means the directory, the edit form and the audit report cannot drift into
 * calling the same role three different things.
 */
final class AccountExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('role_label', $this->roleLabel(...)),
            new TwigFunction('status_label', $this->statusLabel(...)),
        ];
    }

    public function roleLabel(UserRole $role): string
    {
        return match ($role) {
            UserRole::SuperAdmin => 'Super Admin',
            UserRole::Trainer => 'Trainer',
            UserRole::Coach => 'Coach',
            UserRole::Player => 'Player / Parent',
        };
    }

    public function statusLabel(UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Active => 'Active',
            UserStatus::Inactive => 'Inactive',
            UserStatus::Deleted => 'Deleted',
        };
    }
}
