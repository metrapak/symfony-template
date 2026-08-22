<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Account\Entity\User;
use App\Availability\Dto\AvailabilityTally;
use App\Availability\Dto\CoachAvailabilityVerdict;
use App\Availability\Enum\AvailabilitySubjectType;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Repository\AvailabilitySlotRepository;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Availability\ValueObject\TimeRange;

/**
 * Who is available when (FR-083, FR-084, FR-085, FR-088).
 *
 * The read side of the module: counts, filters and the coach conflict verdict. It answers
 * questions and returns numbers and verdicts — it never refuses anything, which is FR-088 held
 * as a property of the class rather than as a rule each caller remembers.
 *
 * Player queries take an explicit candidate list and there is no method that omits one. The
 * candidates come from `OrganizationRosterProvider`, so BR-087's scoping is structural: a
 * trainer's filter cannot accidentally range over another academy's roster, because the query
 * has nothing to range over until somebody names a tenant.
 */
final readonly class AvailabilityMatcher implements CoachAvailabilityChecker
{
    public function __construct(
        private AvailabilityService $availability,
        private AvailabilitySlotRepository $slots,
    ) {
    }

    /**
     * Which of these players can attend the whole window (FR-084).
     *
     * Answered by the indexed coverage query rather than by loading each player's week and
     * comparing in PHP. NFR-080 asks for interactive filtering across thousands of players, and
     * the difference is one round trip against `IDX_AVAILABILITY_SLOT_LOOKUP` versus hydrating a
     * roster's worth of rows to throw most of them away.
     *
     * @param list<int> $playerProfileIds already scoped to one organization
     *
     * @return list<int>
     */
    public function playersAvailableAt(array $playerProfileIds, DayOfWeek $day, TimeRange $window): array
    {
        return $this->slots->subjectIdsAvailableAt(AvailabilitySubjectType::Player, $day, $window, $playerProfileIds);
    }

    /**
     * FR-083's "Players available at this time: 15 of 20", with the undeclared third number the
     * sentence hides — see `AvailabilityTally`.
     *
     * Two counting queries and no hydration, for the same reason as above: this number is shown
     * while a trainer is picking a time, so it is recomputed on every change.
     *
     * @param list<int> $playerProfileIds already scoped to one organization
     */
    public function tallyPlayers(array $playerProfileIds, DayOfWeek $day, TimeRange $window): AvailabilityTally
    {
        return new AvailabilityTally(
            available: \count($this->playersAvailableAt($playerProfileIds, $day, $window)),
            declared: \count($this->slots->declaredSubjectIds(AvailabilitySubjectType::Player, $playerProfileIds)),
            total: \count($playerProfileIds),
        );
    }

    public function check(User $coach, DayOfWeek $day, TimeRange $window): CoachAvailabilityVerdict
    {
        $week = $this->availability->weekFor(AvailabilitySubject::coach($coach));

        return new CoachAvailabilityVerdict(
            available: $week->covers($day, $window),
            declared: $week->isDeclared(),
            day: $day,
            window: $window,
            declaredWindows: $week->forDay($day),
        );
    }

    public function isCoachAvailableAt(User $coach, DayOfWeek $day, TimeRange $window): bool
    {
        return $this->check($coach, $day, $window)->available;
    }
}
