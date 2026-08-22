<?php

declare(strict_types=1);

namespace App\Tests\Availability\Integration\Repository;

use App\Availability\Entity\AvailabilitySlot;
use App\Availability\Enum\AvailabilitySubjectType;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Repository\AvailabilitySlotRepository;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Availability\ValueObject\TimeRange;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The coverage query, against the real database.
 *
 * `WeeklyScheduleTest` pins the same rules down in PHP; this proves the SQL agrees with them,
 * which is the half that would otherwise drift — `start_minute <= :start AND end_minute >= :end`
 * and `TimeRange::covers()` are two statements of one rule in two languages.
 *
 * No user or profile rows are created. `subject_id` carries no foreign key (see the migration),
 * so a subject in these tests is just a number — which is also a small proof that the queries
 * never join to find out who they belong to.
 */
class AvailabilitySlotRepositoryTest extends KernelTestCase
{
    private const ALICE = 101;
    private const BEN = 102;
    private const CHLOE = 103;
    private const DIYA = 104;

    private EntityManagerInterface $entityManager;
    private AvailabilitySlotRepository $slots;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $slots = $this->entityManager->getRepository(AvailabilitySlot::class);
        self::assertInstanceOf(AvailabilitySlotRepository::class, $slots);
        $this->slots = $slots;
    }

    public function testFindsSubjectsWhoseDeclaredRangeCoversTheWindow(): void
    {
        // Alice is free all Monday evening; Ben only until 18:00; Chloe on Tuesday instead.
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60);
        $this->persistAvailable(self::BEN, DayOfWeek::Monday, 16 * 60, 18 * 60);
        $this->persistAvailable(self::CHLOE, DayOfWeek::Tuesday, 17 * 60, 20 * 60);

        $available = $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Player,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(17 * 60, 19 * 60),
            [self::ALICE, self::BEN, self::CHLOE],
        );

        self::assertSame([self::ALICE], $available);
    }

    /**
     * Boundary-touching windows are included; a merely adjacent range is not.
     */
    public function testBoundariesAreInclusiveAndAdjacencyIsNot(): void
    {
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60);
        $this->persistAvailable(self::BEN, DayOfWeek::Monday, 20 * 60, 22 * 60);

        $exactly = $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Player,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(17 * 60, 20 * 60),
            [self::ALICE, self::BEN],
        );
        self::assertSame([self::ALICE], $exactly, 'a window equal to the declared range is covered');

        $atTheStart = $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Player,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(17 * 60, 18 * 60),
            [self::ALICE, self::BEN],
        );
        self::assertSame([self::ALICE], $atTheStart, 'sharing the start boundary still counts');

        $justAfter = $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Player,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(19 * 60, 21 * 60),
            [self::ALICE, self::BEN],
        );
        self::assertSame([], $justAfter, 'neither range covers a window straddling them');
    }

    public function testANegativeRowNeverSatisfiesTheQuery(): void
    {
        // A whole-day "not available" row covers every window arithmetically. Only the
        // `available = true` predicate stops it being the most available row in the table.
        $this->persistUnavailable(self::DIYA, DayOfWeek::Monday);

        $available = $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Player,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(17 * 60, 19 * 60),
            [self::DIYA],
        );

        self::assertSame([], $available);
        self::assertSame([self::DIYA], $this->slots->declaredSubjectIds(AvailabilitySubjectType::Player, [self::DIYA]));
    }

    public function testSubjectTypesDoNotSeeEachOther(): void
    {
        // A coach and a player can share an id — the column points at two different tables.
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60, AvailabilitySubjectType::Coach);

        $players = $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Player,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(17 * 60, 19 * 60),
            [self::ALICE],
        );
        $coaches = $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Coach,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(17 * 60, 19 * 60),
            [self::ALICE],
        );

        self::assertSame([], $players);
        self::assertSame([self::ALICE], $coaches);
    }

    public function testAnEmptyCandidateListQueriesNothing(): void
    {
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60);

        self::assertSame([], $this->slots->subjectIdsAvailableAt(
            AvailabilitySubjectType::Player,
            DayOfWeek::Monday,
            TimeRange::fromMinutes(17 * 60, 19 * 60),
            [],
        ));
        self::assertSame([], $this->slots->declaredSubjectIds(AvailabilitySubjectType::Player, []));
        self::assertSame([], $this->slots->forSubjects(AvailabilitySubjectType::Player, []));
    }

    public function testDeclaredSubjectIdsSeparatesSilenceFromRefusal(): void
    {
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60);
        $this->persistUnavailable(self::BEN, DayOfWeek::Monday);
        // Chloe has no rows at all.

        $declared = $this->slots->declaredSubjectIds(
            AvailabilitySubjectType::Player,
            [self::ALICE, self::BEN, self::CHLOE],
        );

        self::assertSame([self::ALICE, self::BEN], $declared);
    }

    public function testForSubjectsGroupsByIdAndOmitsSubjectsWithNoRows(): void
    {
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60);
        $this->persistAvailable(self::ALICE, DayOfWeek::Wednesday, 18 * 60, 21 * 60);
        $this->persistAvailable(self::BEN, DayOfWeek::Monday, 16 * 60, 18 * 60);

        $grouped = $this->slots->forSubjects(AvailabilitySubjectType::Player, [self::ALICE, self::BEN, self::CHLOE]);

        self::assertCount(2, $grouped);
        self::assertCount(2, $grouped[self::ALICE]);
        self::assertCount(1, $grouped[self::BEN]);
        self::assertArrayNotHasKey(self::CHLOE, $grouped);
    }

    public function testDeleteForSubjectTouchesOnlyThatSubject(): void
    {
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60);
        $this->persistAvailable(self::BEN, DayOfWeek::Monday, 16 * 60, 18 * 60);
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 17 * 60, 20 * 60, AvailabilitySubjectType::Coach);

        $this->slots->deleteForSubject(AvailabilitySubject::playerId(self::ALICE));

        self::assertSame([], $this->slots->forSubject(AvailabilitySubject::playerId(self::ALICE)));
        self::assertCount(1, $this->slots->forSubject(AvailabilitySubject::playerId(self::BEN)));
        self::assertCount(1, $this->slots->forSubject(AvailabilitySubject::coachId(self::ALICE)));
    }

    public function testForSubjectIsOrderedByDayThenStart(): void
    {
        $this->persistAvailable(self::ALICE, DayOfWeek::Wednesday, 18 * 60, 21 * 60);
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 19 * 60, 21 * 60);
        $this->persistAvailable(self::ALICE, DayOfWeek::Monday, 16 * 60, 18 * 60);

        $week = array_map(
            static fn (AvailabilitySlot $slot): string => $slot->getDayOfWeek()->shortLabel() . ' ' . $slot->getRange()->format(),
            $this->slots->forSubject(AvailabilitySubject::playerId(self::ALICE)),
        );

        self::assertSame(['Mon 16:00–18:00', 'Mon 19:00–21:00', 'Wed 18:00–21:00'], $week);
    }

    private function persistAvailable(
        int $subjectId,
        DayOfWeek $day,
        int $startMinute,
        int $endMinute,
        AvailabilitySubjectType $type = AvailabilitySubjectType::Player,
    ): void {
        $subject = AvailabilitySubjectType::Player === $type
            ? AvailabilitySubject::playerId($subjectId)
            : AvailabilitySubject::coachId($subjectId);

        $this->entityManager->persist(AvailabilitySlot::available(
            $subject,
            $day,
            TimeRange::fromMinutes($startMinute, $endMinute),
            new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();
    }

    private function persistUnavailable(int $subjectId, DayOfWeek $day): void
    {
        $this->entityManager->persist(AvailabilitySlot::unavailableAllDay(
            AvailabilitySubject::playerId($subjectId),
            $day,
            new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();
    }
}
