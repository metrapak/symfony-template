<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Availability\Dto\RosterCoach;
use App\Availability\Dto\RosterPlayer;
use App\Availability\Service\OrganizationRosterProvider;
use App\Membership\Entity\CoachAssignment;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Repository\CoachAssignmentRepository;
use App\Membership\Repository\TrainerPlayerAssociationRepository;

/**
 * Membership's answer to "who is in this organization?", for the availability module (FR-083,
 * FR-084, FR-085, BR-087).
 *
 * The third adapter of its kind in this codebase, and for the same reason as
 * `AssociationDirectory` and `CoachAssignmentOrganizationProvider`: the roster is Membership's
 * data, the availability screens need it, and the module that needs it must not depend on the
 * repositories that hold it. Availability declares the question, this answers it in ids and
 * names — never in `TrainerPlayerAssociation` or `CoachAssignment`, which would put the coupling
 * back through the type system.
 *
 * Holds no rules of its own. The one judgement is which rows count, and both answers come from
 * requirements rather than from this class: players must be *actively* associated (BR-066 —
 * an ended association stops the trainer seeing them, including in a count), while coaches
 * include ended assignments, marked inactive, because a trainer looking at their coach list
 * after somebody left should see them there rather than watch them vanish.
 */
final readonly class AvailabilityRosterProvider implements OrganizationRosterProvider
{
    public function __construct(
        private TrainerPlayerAssociationRepository $associations,
        private CoachAssignmentRepository $assignments,
    ) {
    }

    public function playersFor(int $organizationId): array
    {
        $players = array_map(
            static function (TrainerPlayerAssociation $association): RosterPlayer {
                $profile = $association->getPlayerProfile();

                return new RosterPlayer((int) $profile->getId(), $profile->getDisplayName());
            },
            $this->associations->findActiveFor($organizationId),
        );

        // Alphabetical, because this list is read by a person looking for a name. The repository
        // orders by join date, which is the right order for the CRM's "newest first" roster and
        // the wrong one for a roster somebody is scanning.
        usort($players, static fn (RosterPlayer $a, RosterPlayer $b): int => strcasecmp($a->displayName, $b->displayName));

        return $players;
    }

    public function coachesFor(int $organizationId): array
    {
        return array_map(
            static fn (CoachAssignment $assignment): RosterCoach => new RosterCoach($assignment->getCoach(), $assignment->isActive()),
            $this->assignments->findFor($organizationId),
        );
    }

    public function coachFor(int $organizationId, int $coachUserId): ?RosterCoach
    {
        foreach ($this->coachesFor($organizationId) as $coach) {
            if ($coach->coach->getId() === $coachUserId) {
                return $coach;
            }
        }

        // Not "no such coach" — "not one of yours". The caller turns this into a 404 so the two
        // are indistinguishable from outside (the same rule `EmergencyContactController` follows).
        return null;
    }
}
