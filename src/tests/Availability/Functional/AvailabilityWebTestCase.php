<?php

declare(strict_types=1);

namespace App\Tests\Availability\Functional;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Availability\Entity\AvailabilitySlot;
use App\Availability\Entity\CoachAvailabilityOverride;
use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Availability\ValueObject\TimeRange;
use App\Profile\Entity\PlayerProfile;
use App\Tests\Profile\Functional\ProfileWebTestCase;

/**
 * Shared setup for the availability tests.
 *
 * Builds on the profile base because availability hangs off the things it creates: a player
 * profile, a family that spans two tenants, and a coach with an assignment. What this adds is one
 * place that knows how to seed a week and how to submit one.
 *
 * Weeks are seeded as entities rather than through `AvailabilityService`, deliberately: a test
 * that arranges its data with the same code path it is exercising cannot fail when that path
 * breaks. The service is exercised by the tests that post the form.
 */
abstract class AvailabilityWebTestCase extends ProfileWebTestCase
{
    protected const COACH_EMAIL = 'coach@example.test';

    /** The form name Symfony derives from `WeeklyAvailabilityFormType`. */
    protected const WEEK_FORM = 'weekly_availability_form';

    /** The form name Symfony derives from `CoachConflictFormType`. */
    protected const CONFLICT_FORM = 'coach_conflict_form';

    protected function createCoach(string $email = self::COACH_EMAIL, string $name = 'Casey Coach'): User
    {
        return $this->createUser($email, UserRole::Coach, name: $name);
    }

    /**
     * A coach assigned to an organization — the only kind of coach a trainer can see.
     */
    protected function createAssignedCoach(
        ?Organization $organization = null,
        string $email = self::COACH_EMAIL,
        string $name = 'Casey Coach',
    ): User {
        $coach = $this->createCoach($email, $name);
        $this->createCoachAssignment($coach, $organization ?? $this->organization);

        return $coach;
    }

    /**
     * Seeds one subject's week.
     *
     * @param array<int, list<array{int, int}>> $rangesByDay `DayOfWeek::value` => list of
     *                                                       `[startMinute, endMinute]`
     * @param list<DayOfWeek> $unavailableDays
     */
    protected function seedWeek(AvailabilitySubject $subject, array $rangesByDay, array $unavailableDays = []): void
    {
        $now = new \DateTimeImmutable();
        $entityManager = $this->currentEntityManager();

        foreach ($rangesByDay as $dayValue => $ranges) {
            foreach ($ranges as [$start, $end]) {
                $entityManager->persist(AvailabilitySlot::available(
                    $subject,
                    DayOfWeek::from($dayValue),
                    TimeRange::fromMinutes($start, $end),
                    $now,
                ));
            }
        }

        foreach ($unavailableDays as $day) {
            $entityManager->persist(AvailabilitySlot::unavailableAllDay($subject, $day, $now));
        }

        $entityManager->flush();
    }

    /**
     * Posts the weekly grid the way the browser does.
     *
     * @param array<string, array{slots?: list<string>, unavailable?: string}> $days keyed by day
     */
    protected function submitWeek(string $path, array $days): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->submitFormPayload($path, self::WEEK_FORM, $days);
    }

    /**
     * The submitted value of one grid cell: the block that starts at this hour.
     */
    protected static function hourCell(int $hour): string
    {
        return 'm' . ($hour * 60);
    }

    /**
     * The slots a subject holds, read back through a fresh manager so the assertion is about the
     * database and not about an identity map.
     *
     * @return list<AvailabilitySlot>
     */
    protected function slotsFor(AvailabilitySubject $subject): array
    {
        return $this->freshEntityManager()
            ->getRepository(AvailabilitySlot::class)
            ->findBy(
                ['subjectType' => $subject->type, 'subjectId' => $subject->id],
                ['dayOfWeek' => 'ASC', 'startMinute' => 'ASC'],
            );
    }

    /**
     * `["Monday 17:00–20:00", …]` for whatever the subject has stored, so an assertion reads like
     * the week it is checking.
     *
     * @return list<string>
     */
    protected function weekOf(AvailabilitySubject $subject): array
    {
        return array_map(
            static fn (AvailabilitySlot $slot): string => \sprintf(
                '%s %s%s',
                $slot->getDayOfWeek()->label(),
                $slot->getRange()->format(),
                $slot->isAvailable() ? '' : ' unavailable',
            ),
            $this->slotsFor($subject),
        );
    }

    /**
     * @return list<CoachAvailabilityOverride>
     */
    protected function overrides(): array
    {
        return $this->freshEntityManager()
            ->getRepository(CoachAvailabilityOverride::class)
            ->findBy([], ['id' => 'ASC']);
    }

    protected function playerSubject(PlayerProfile $profile): AvailabilitySubject
    {
        return AvailabilitySubject::playerId((int) $profile->getId());
    }

    protected function coachSubject(User $coach): AvailabilitySubject
    {
        return AvailabilitySubject::coachId((int) $coach->getId());
    }
}
