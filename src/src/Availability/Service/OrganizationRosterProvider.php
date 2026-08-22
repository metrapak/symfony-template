<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Availability\Dto\RosterCoach;
use App\Availability\Dto\RosterPlayer;

/**
 * Who is on one organization's roster — the scope every trainer-facing availability read is
 * confined to (FR-083, FR-084, BR-087).
 *
 * An interface for the reason `CoachOrganizationProvider` and `TrainerAssociationGateway` are:
 * the answer lives in `Membership`, whose entities already point at `Profile`, and this module
 * must not reach into another module's repositories to find out who belongs where. So the
 * dependency is inverted — Availability states the question in its own vocabulary (ids and
 * names), Membership answers it over `trainer_player_association` and `coach_assignment`.
 *
 * **This is the tenancy boundary.** BR-087 says a trainer sees availability only for their own
 * organization's members, and the way that is enforced here is that no availability query in
 * this module accepts an unscoped subject list: the candidates always come from this provider,
 * for one organization id. A filter that returned "all players available on Monday" would be
 * one forgotten `WHERE` away from another academy's roster, so it does not exist.
 */
interface OrganizationRosterProvider
{
    /**
     * The players actively associated with this organization, ordered by name.
     *
     * Only active associations: BR-066 says a trainer stops seeing a player whose association
     * ended, and an ended row must not contribute to a count either.
     *
     * @return list<RosterPlayer>
     */
    public function playersFor(int $organizationId): array;

    /**
     * The coaches of this organization, active assignments first.
     *
     * @return list<RosterCoach>
     */
    public function coachesFor(int $organizationId): array;

    /**
     * One of this organization's coaches, or null.
     *
     * The lookup the conflict check authorizes with: a coach id that is not in the trainer's own
     * organization must be indistinguishable from one that does not exist, so this returns null
     * rather than a coach the caller then has to remember to reject.
     */
    public function coachFor(int $organizationId, int $coachUserId): ?RosterCoach;
}
