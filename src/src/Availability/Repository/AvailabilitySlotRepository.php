<?php

declare(strict_types=1);

namespace App\Availability\Repository;

use App\Availability\Entity\AvailabilitySlot;
use App\Availability\Enum\AvailabilitySubjectType;
use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Availability\ValueObject\TimeRange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AvailabilitySlot>
 */
class AvailabilitySlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvailabilitySlot::class);
    }

    public function add(AvailabilitySlot $slot): void
    {
        $this->getEntityManager()->persist($slot);
    }

    /**
     * One subject's whole week, Monday first.
     *
     * @return list<AvailabilitySlot>
     */
    public function forSubject(AvailabilitySubject $subject): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.subjectType = :type')
            ->andWhere('s.subjectId = :id')
            ->setParameter('type', $subject->type)
            ->setParameter('id', $subject->id)
            ->orderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startMinute', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Many subjects' weeks in one query, grouped by subject id.
     *
     * This is what keeps the trainer's roster off the N+1 path: a page showing "Best Times" for
     * forty players reads one result set rather than forty (NFR-080). Subjects with no rows are
     * absent from the returned map, which is the caller's signal that they have declared nothing
     * — not that they are unavailable.
     *
     * @param list<int> $subjectIds
     *
     * @return array<int, list<AvailabilitySlot>>
     */
    public function forSubjects(AvailabilitySubjectType $type, array $subjectIds): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        $slots = $this->createQueryBuilder('s')
            ->andWhere('s.subjectType = :type')
            ->andWhere('s.subjectId IN (:ids)')
            ->setParameter('type', $type)
            ->setParameter('ids', $subjectIds)
            ->orderBy('s.subjectId', 'ASC')
            ->addOrderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startMinute', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($slots as $slot) {
            $grouped[$slot->getSubjectId()][] = $slot;
        }

        return $grouped;
    }

    /**
     * Which of these subjects are available for the whole of `$window` on `$day` (FR-083,
     * FR-084).
     *
     * **Coverage, not intersection.** A stored window has to contain the queried one, which is
     * the difference between "some of this session suits them" and "they can attend it"; see
     * `TimeRange::covers()`. Saving merges adjacent ranges, so a subject free 16:00-18:00 and
     * 18:00-21:00 is stored as one row and is correctly found for a 17:00-19:00 session — the
     * reason the predicate can stay a single-row comparison the index serves.
     *
     * Boundaries are inclusive on both sides: a declared 17:00-20:00 covers a queried
     * 17:00-20:00. Negative rows are excluded by `isAvailable`, so an explicitly unavailable day
     * cannot satisfy the query by covering it.
     *
     * The candidate list is required rather than optional. Every caller in this task is a
     * trainer looking at their own organization (BR-087), and a method that could be called
     * without a scope is a method that will be.
     *
     * @param list<int> $subjectIds the already-scoped candidates
     *
     * @return list<int> the ids among them that are available, in ascending order
     */
    public function subjectIdsAvailableAt(
        AvailabilitySubjectType $type,
        DayOfWeek $day,
        TimeRange $window,
        array $subjectIds,
    ): array {
        if ([] === $subjectIds) {
            return [];
        }

        /** @var list<array{subjectId: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.subjectId AS subjectId')
            ->andWhere('s.subjectType = :type')
            ->andWhere('s.subjectId IN (:ids)')
            ->andWhere('s.dayOfWeek = :day')
            ->andWhere('s.available = true')
            ->andWhere('s.startMinute <= :start')
            ->andWhere('s.endMinute >= :end')
            ->setParameter('type', $type)
            ->setParameter('ids', $subjectIds)
            ->setParameter('day', $day)
            ->setParameter('start', $window->startMinute)
            ->setParameter('end', $window->endMinute)
            ->orderBy('s.subjectId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => $row['subjectId'], $rows);
    }

    /**
     * Which of these subjects have said anything at all about their week.
     *
     * The denominator's other half: FR-083's "15 of 20" needs to distinguish a player who
     * declared they are busy from one who has never opened the form, and existence of any row —
     * positive or negative — is that distinction.
     *
     * @param list<int> $subjectIds
     *
     * @return list<int>
     */
    public function declaredSubjectIds(AvailabilitySubjectType $type, array $subjectIds): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        /** @var list<array{subjectId: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.subjectId AS subjectId')
            ->andWhere('s.subjectType = :type')
            ->andWhere('s.subjectId IN (:ids)')
            ->setParameter('type', $type)
            ->setParameter('ids', $subjectIds)
            ->orderBy('s.subjectId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => $row['subjectId'], $rows);
    }

    /**
     * Clears a subject's week, ready for the replacement rows.
     *
     * A bulk DQL delete rather than loading and removing entities: a week is at most a few dozen
     * rows, but hydrating them to throw them away is work with no reader, and the delete has to
     * be a single statement for `replaceWeek()`'s transaction to be as short as it can be.
     *
     * The identity map is not cleared here. Callers write through `AvailabilityService`, which
     * owns the transaction and does not hold the old rows afterwards.
     */
    public function deleteForSubject(AvailabilitySubject $subject): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.subjectType = :type')
            ->andWhere('s.subjectId = :id')
            ->setParameter('type', $subject->type)
            ->setParameter('id', $subject->id)
            ->getQuery()
            ->execute();
    }
}
