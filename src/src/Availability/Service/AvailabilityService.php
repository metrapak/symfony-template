<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Availability\Entity\AvailabilitySlot;
use App\Availability\Enum\AvailabilitySubjectType;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Repository\AvailabilitySlotRepository;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Availability\ValueObject\WeeklySchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reads and replaces a subject's week (FR-080, FR-081, FR-082, NFR-083).
 *
 * The module's only writer, and it writes **whole weeks**. There is deliberately no
 * `addSlot()`/`removeSlot()` pair: a week is edited by submitting a week, and the grid the user
 * sees is the grid that is stored. Incremental writes would need a merge strategy for two tabs
 * open on the same grid, and the honest answer to that is last-write-wins on the whole value,
 * which is exactly what replacement gives.
 *
 * **The replacement is atomic.** Delete-then-insert inside one transaction, so a save that fails
 * halfway cannot leave somebody with Monday cleared and Tuesday unwritten — the failure mode a
 * per-day write would have. NFR-083's one-second budget is met by the shape rather than by
 * tuning: one delete and at most a few dozen inserts, no reads.
 *
 * Rows carry no `organizationId`. That is G-07 answered — see `AvailabilitySubject`.
 */
final readonly class AvailabilityService
{
    public function __construct(
        private AvailabilitySlotRepository $slots,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function weekFor(AvailabilitySubject $subject): WeeklySchedule
    {
        return self::toSchedule($this->slots->forSubject($subject));
    }

    /**
     * Several subjects' weeks in one query (FR-083's roster view).
     *
     * A subject with no rows still gets an entry — an empty schedule, which reports
     * `isDeclared() === false` — so a caller can iterate its own list without checking for
     * missing keys and without mistaking "absent" for "unavailable".
     *
     * @param list<int> $subjectIds
     *
     * @return array<int, WeeklySchedule>
     */
    public function weeksFor(AvailabilitySubjectType $type, array $subjectIds): array
    {
        $grouped = $this->slots->forSubjects($type, $subjectIds);
        $weeks = [];

        foreach ($subjectIds as $subjectId) {
            $weeks[$subjectId] = self::toSchedule($grouped[$subjectId] ?? []);
        }

        return $weeks;
    }

    public function hasDeclared(AvailabilitySubject $subject): bool
    {
        return $this->weekFor($subject)->isDeclared();
    }

    /**
     * Replaces everything the subject had declared with this week.
     *
     * An empty schedule is a legitimate value and clears the week: somebody who unticks
     * everything and saves has withdrawn their preferences, and leaving the old rows in place
     * would show trainers times the person has explicitly removed.
     */
    public function replaceWeek(AvailabilitySubject $subject, WeeklySchedule $schedule): void
    {
        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($subject, $schedule, $now): void {
            $this->slots->deleteForSubject($subject);

            foreach ($schedule->unavailableDays() as $day) {
                $this->slots->add(AvailabilitySlot::unavailableAllDay($subject, $day, $now));
            }

            foreach ($schedule->pairs() as $pair) {
                $this->slots->add(AvailabilitySlot::available($subject, $pair['day'], $pair['range'], $now));
            }

            $this->entityManager->flush();
        });
    }

    /**
     * @param list<AvailabilitySlot> $slots
     */
    private static function toSchedule(array $slots): WeeklySchedule
    {
        $rangesByDay = [];
        $unavailableDays = [];

        foreach ($slots as $slot) {
            if (!$slot->isAvailable()) {
                // A negative row means the whole day, whatever window it happens to store; see
                // `AvailabilitySlot` on why the negative is a row at all.
                $unavailableDays[$slot->getDayOfWeek()->value] = $slot->getDayOfWeek();

                continue;
            }

            $rangesByDay[$slot->getDayOfWeek()->value][] = $slot->getRange();
        }

        return WeeklySchedule::build($rangesByDay, array_values($unavailableDays));
    }

    /**
     * The days a subject has marked unavailable, for callers that need the negative on its own.
     *
     * @return list<DayOfWeek>
     */
    public function unavailableDaysFor(AvailabilitySubject $subject): array
    {
        return $this->weekFor($subject)->unavailableDays();
    }
}
