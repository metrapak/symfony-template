<?php

declare(strict_types=1);

namespace App\Account\Enum;

/**
 * The single primary role every user carries (FR-007, BR-002).
 *
 * Values double as Symfony security role strings so `User::getRoles()` can return
 * them directly. There is deliberately no hierarchy between these four roles:
 * a Super Admin inheriting ROLE_TRAINER would be routed into organization-scoped
 * trainer views with no organization of their own.
 */
enum UserRole: string
{
    case SuperAdmin = 'ROLE_SUPER_ADMIN';
    case Trainer = 'ROLE_TRAINER';
    case Coach = 'ROLE_COACH';
    case Player = 'ROLE_PLAYER';
}
