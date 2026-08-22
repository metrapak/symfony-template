<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Account\Entity\User;
use App\Availability\Dto\CoachAvailabilityVerdict;
use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\TimeRange;

/**
 * The contract Epic-02's coach-assignment flow calls (FR-085, FR-086, FR-088).
 *
 * This interface is the deliverable. Coach assignment belongs to Epic-02 and events do not exist
 * yet, so what this task can honestly ship is the *decision* — is this coach available then, and
 * what must happen if not — behind a signature that will not move when the assignment screen
 * arrives. An interface here is justified on those grounds and not on testability: it is a
 * published boundary between two epics, and the module that will consume it does not exist to be
 * refactored alongside.
 *
 * **Return shape.** `CoachAvailabilityVerdict`, with three states rather than a boolean:
 *
 *  | verdict                            | what the caller must do                              |
 *  |------------------------------------|------------------------------------------------------|
 *  | `available === true`               | assign, silently                                     |
 *  | `conflict() === true`              | show FR-085's warning; a reason is required to go on  |
 *  | `declared === false`               | assign, silently — nothing has been declared to clash |
 *
 * **The caller may never refuse.** FR-088 and BR-083 make availability advisory everywhere:
 * a conflict produces a warning and an override record, never a rejected assignment. Nothing in
 * this interface returns a reason to stop, and that omission is deliberate — an implementation
 * that threw on a conflict would make availability a constraint in every consumer at once.
 */
interface CoachAvailabilityChecker
{
    /**
     * Whether the coach's declared week covers this window, and what the caller should say.
     */
    public function check(User $coach, DayOfWeek $day, TimeRange $window): CoachAvailabilityVerdict;

    /**
     * The same question when only the answer matters.
     *
     * True means "declared and covers it". A coach who has declared nothing is `false` here and
     * is **not** in conflict — `check()` is the method that can tell those apart, and the one to
     * use before warning anybody.
     */
    public function isCoachAvailableAt(User $coach, DayOfWeek $day, TimeRange $window): bool;
}
