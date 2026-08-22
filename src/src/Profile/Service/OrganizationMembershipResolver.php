<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Service\TenantContext;
use App\Profile\Dto\AssociationRecord;
use App\Profile\Repository\PlayerProfileRepository;

/**
 * Which organizations a given user belongs to, for any role (BR-069).
 *
 * Branding is visible to "the trainer's players, coaches, and parents", and photos are visible
 * within an organization — both of which need one answer to "is this person inside that tenant?"
 * `TenantContext` already answers it for a trainer and a coach and deliberately refuses to for
 * a player, because a player has no single organization. This is the other half: a player
 * belongs to every organization they or their children actively train with.
 *
 * A player can therefore be inside several tenants at once, which is the whole reason FR-070
 * exists. Membership is *not* the same question as "which context am I in" — belonging to two
 * organizations is what makes the switcher necessary, while the context is which one is
 * currently selected. Authorizing a read against membership rather than against the selected
 * context is correct for things that are not context-scoped (a logo, an avatar) and wrong for
 * anything that is (a calendar, tokens) — those go through `TrainingContextResolver`.
 */
final readonly class OrganizationMembershipResolver
{
    public function __construct(
        private TenantContext $tenantContext,
        private TrainerAssociationGateway $associations,
        private PlayerProfileRepository $profiles,
    ) {
    }

    /**
     * @return list<int>
     */
    public function organizationIdsFor(User $user): array
    {
        return match ($user->getRole()) {
            // One tenant each, and TenantContext already knows how to find it: a trainer owns
            // theirs, a coach reaches theirs through an assignment.
            UserRole::Trainer, UserRole::Coach => $this->singleTenantIds(),
            UserRole::Player => $this->playerOrganizationIds($user),
            // A Super Admin belongs to no tenant on purpose (D3). Cross-tenant access is
            // granted by their role at the access-control layer, not by pretending they are a
            // member of everything.
            UserRole::SuperAdmin => [],
        };
    }

    public function belongsTo(User $user, int $organizationId): bool
    {
        return \in_array($organizationId, $this->organizationIdsFor($user), true);
    }

    /**
     * The one organization a trainer or a coach belongs to, or none.
     *
     * A list of zero or one rather than a nullable int, so every branch of
     * `organizationIdsFor()` returns the same shape and no caller has to special-case a role.
     *
     * @return list<int>
     */
    private function singleTenantIds(): array
    {
        $organizationId = $this->tenantContext->currentOrganizationId();

        return null !== $organizationId ? [$organizationId] : [];
    }

    /**
     * @return list<int>
     */
    private function playerOrganizationIds(User $user): array
    {
        $profile = $this->profiles->findProfileForAccount($user);

        // FR-068: a child belongs to their own trainers, never to their parent's. Same
        // precedence as in the context resolver, and for the same reason — a child holds
        // ROLE_PLAYER, so the owner path would otherwise answer for them.
        $records = null !== $profile && $profile->isChild()
            ? $this->associations->activeAssociationsForProfile($profile)
            : $this->associations->activeAssociationsForOwner($user);

        return array_values(array_unique(array_map(
            static fn (AssociationRecord $record): int => $record->organizationId,
            $records,
        )));
    }
}
