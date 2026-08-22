<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Profile\Entity\PlayerProfile;

/**
 * The shipping implementation of `UpcomingReservationCanceller` while no reservations exist.
 *
 * Returns zero because zero is the true answer: Epic-01 has no events and no RSVPs, so removing
 * a child from a trainer cancels nothing. Epic-02 replaces this service, and the call site does
 * not change.
 *
 * It is a class rather than an anonymous default in the container so that replacing it is a
 * one-line alias, and so a reader who follows the interface finds a body that explains itself
 * instead of a definition with no implementation.
 */
final readonly class NoReservationsToCancel implements UpcomingReservationCanceller
{
    public function cancelUpcomingFor(PlayerProfile $profile, int $organizationId): int
    {
        return 0;
    }
}
