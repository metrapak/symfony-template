<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Profile\Entity\PlayerProfile;

/**
 * Cancels a player's future RSVPs with one trainer (FR-066, BR-066).
 *
 * FR-066's confirmation text promises it in so many words — "Remove {child} from {trainer}?
 * This will cancel all upcoming RSVPs" — and there are no events, and therefore no RSVPs, until
 * Epic-02. So this is the seam: declared here because the *promise* is made here, implemented by
 * whichever module ends up owning reservations.
 *
 * A no-op default ships (`NoReservationsToCancel`), and that is a deliberate choice over the two
 * alternatives. Leaving the call out entirely means the day RSVPs exist, every removal silently
 * strands them and a parent who read the warning is misled — and nothing in the codebase points
 * at where the call should have gone. Blocking the feature on Epic-02 means FR-066 does not ship.
 * An interface with a no-op says exactly what is true: the removal happens, the cancellation is
 * defined and wired, and there is presently nothing to cancel.
 */
interface UpcomingReservationCanceller
{
    /**
     * @return int how many reservations were cancelled, for the message the parent reads
     */
    public function cancelUpcomingFor(PlayerProfile $profile, int $organizationId): int;
}
