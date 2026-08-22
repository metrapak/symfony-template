<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Exception\NoOrganizationInContext;
use App\Account\Repository\OrganizationRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolves the organization (tenant) the current user acts within (FR-012, BR-007).
 *
 * **Binding convention for every task in this epic**: an organization-scoped repository
 * method takes the organization id as a *required* parameter. Forgetting to scope a query
 * then fails as an argument error at compile time instead of leaking another tenant's rows.
 *
 * A Doctrine SQL filter was considered and rejected: it applies globally and silently,
 * administrative tooling legitimately needs cross-tenant reads, and a filter that gets
 * disabled fails open. Cross-tenant queries are separate, explicitly named methods.
 *
 * Players are deliberately unanswerable here. A player has no single organization — they
 * have a selected training context, which is a different resolver in a later task.
 */
final readonly class TenantContext
{
    public function __construct(
        private Security $security,
        private OrganizationRepository $organizations,
        private CoachOrganizationProvider $coachOrganizations,
    ) {
    }

    public function currentOrganizationId(): ?int
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return match ($user->getRole()) {
            UserRole::Trainer => $this->organizations->findOneByOwner($user)?->getId(),
            // A coach reaches their organization through the assignment TASK-003 introduced.
            // Answered through a provider interface rather than by reading the Membership
            // repository directly, so this module does not depend on the one that depends
            // on it. A coach with no active assignment still has no tenant.
            UserRole::Coach => $this->coachOrganizations->organizationIdForCoach($user),
            UserRole::Player, UserRole::SuperAdmin => null,
        };
    }

    public function requireOrganizationId(): int
    {
        return $this->currentOrganizationId() ?? throw NoOrganizationInContext::forCurrentUser();
    }
}
