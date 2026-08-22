<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;

/**
 * Answers which organization a coach currently works for.
 *
 * This exists as an interface for one reason, and it is not testability: a coach's tenant is
 * defined by an assignment record that belongs to the Membership module, while `TenantContext`
 * — the thing that needs the answer — belongs to Account, which Membership already depends on.
 * Depending back on `CoachAssignmentRepository` from here would close that loop and make the
 * two modules one. The dependency is inverted instead: Account declares the question,
 * Membership answers it.
 *
 * Before TASK-003 there was no assignment record and `TenantContext` returned null for coaches
 * behind a documented TODO. This is that TODO's resolution.
 */
interface CoachOrganizationProvider
{
    public function organizationIdForCoach(User $coach): ?int;
}
