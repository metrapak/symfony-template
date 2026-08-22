<?php

declare(strict_types=1);

namespace App\Profile\Dto;

/**
 * One "this player trains with this trainer" fact, flattened for the Profile module.
 *
 * A read model rather than an entity, because the module that consumes it must not depend on
 * `TrainerPlayerAssociation` — see `TrainerAssociationGateway` for why. It carries the
 * organization's *name* along with its id so the family page and the context switcher can
 * render without a second lookup per row, which is also what keeps FR-069's switcher off the
 * N+1 path when a family has three children across two trainers.
 */
final readonly class AssociationRecord
{
    public function __construct(
        public int $playerProfileId,
        public string $playerName,
        /** Whether the profile is the viewer's own rather than one of their children's. */
        public bool $ownProfile,
        public int $organizationId,
        public string $organizationName,
        public \DateTimeImmutable $connectedAt,
    ) {
    }
}
