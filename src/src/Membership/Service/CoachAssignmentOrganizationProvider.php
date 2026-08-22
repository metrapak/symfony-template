<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\User;
use App\Account\Service\CoachOrganizationProvider;
use App\Membership\Repository\CoachAssignmentRepository;

/**
 * Membership's answer to Account's question: a coach's tenant is their active assignment.
 *
 * Deliberately thin. Everything interesting is the schema guarantee behind it — the partial
 * unique index means "the active assignment" is a well-defined phrase, so this can return one
 * id rather than a list and `TenantContext` never has to choose between two organizations.
 */
final readonly class CoachAssignmentOrganizationProvider implements CoachOrganizationProvider
{
    public function __construct(
        private CoachAssignmentRepository $assignments,
    ) {
    }

    public function organizationIdForCoach(User $coach): ?int
    {
        return $this->assignments->findActiveForCoach($coach)?->getOrganization()->getId();
    }
}
