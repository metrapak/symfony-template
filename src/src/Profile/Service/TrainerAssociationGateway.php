<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\User;
use App\Profile\Dto\AssociationRecord;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\TrainerNotJoinable;

/**
 * Everything the Profile module needs to know about who trains with whom.
 *
 * This is an interface for the same reason `CoachOrganizationProvider` is, and the reason is
 * not testability. `TrainerPlayerAssociation` lives in Membership and holds a foreign key to
 * `PlayerProfile`, so Membership already depends on Profile. Reaching back into
 * `TrainerPlayerAssociationRepository` from the family screens would close that loop and make
 * the two modules one — the exact cycle TASK-003 refused between Account and Membership. So
 * the dependency is inverted: Profile declares the questions and the operations it needs,
 * Membership answers them over its own entity.
 *
 * Everything here speaks in ids and read models, never in Membership entities, so that
 * inversion is real rather than nominal — a signature returning `TrainerPlayerAssociation`
 * would reintroduce the coupling through the type system.
 *
 * The write methods are here rather than being reimplemented in Profile because association
 * correctness — the unique `(organization, player)` index, reactivating an ended membership
 * instead of inserting a second row, consuming a ShareLink use exactly once — belongs to the
 * module that owns the table, and a second writer with its own opinion is how two of those
 * invariants stop holding.
 */
interface TrainerAssociationGateway
{
    /**
     * Every active association across the profiles this account manages: their own and their
     * children's. The list the context switcher is built from (FR-069).
     *
     * @return list<AssociationRecord>
     */
    public function activeAssociationsForOwner(User $owner): array;

    /**
     * Every active association of one profile — the list a *child* login sees, which is a flat
     * list of their own trainers and nothing of their parent's (FR-069, FR-068).
     *
     * @return list<AssociationRecord>
     */
    public function activeAssociationsForProfile(PlayerProfile $profile): array;

    public function hasActiveAssociation(PlayerProfile $profile, int $organizationId): bool;

    /**
     * Attaches a profile to a trainer the caller already trains with (FR-066, "Option B").
     *
     * No ShareLink is consumed: the parent is not joining a new trainer, they are extending a
     * relationship the trainer already granted them. Idempotent, so a double submit adds one
     * association.
     */
    public function associateWithKnownTrainer(PlayerProfile $profile, int $organizationId): void;

    /**
     * Attaches a profile using a ShareLink the parent pasted (FR-066, "Option A").
     *
     * @throws TrainerNotJoinable when the code is unknown, withdrawn, exhausted, expired, or
     *                            is a coach invitation rather than a player link
     */
    public function associateViaShareLink(string $code, User $actor, PlayerProfile $profile): int;

    /**
     * Ends a membership without deleting it (FR-066, BR-066, NFR-X06).
     */
    public function deactivate(PlayerProfile $profile, int $organizationId): void;

    public function organizationNameFor(int $organizationId): ?string;
}
