<?php

declare(strict_types=1);

namespace App\Availability\Repository;

use App\Account\Entity\User;
use App\Availability\Entity\CoachAvailabilityOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachAvailabilityOverride>
 */
class CoachAvailabilityOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachAvailabilityOverride::class);
    }

    public function add(CoachAvailabilityOverride $override): void
    {
        $this->getEntityManager()->persist($override);
    }

    /**
     * Every override recorded against one event (FR-086).
     *
     * Written for Epic-02, which will show it on the assignment it belongs to. Until events
     * exist nothing stores an event id, so this returns an empty list rather than being wrong —
     * which is why it is here now: the contract Epic-02 codes against should not change shape
     * when the column starts being filled in.
     *
     * @return list<CoachAvailabilityOverride>
     */
    public function forEvent(int $eventId): array
    {
        return $this->findBy(['eventId' => $eventId], ['createdAt' => 'ASC']);
    }

    /**
     * A coach's own overrides, most recent first (FR-087).
     *
     * Cross-tenant on purpose: this answers "when has anybody scheduled me outside my times?",
     * and a coach who moved between trainers is still entitled to their own history.
     *
     * @return list<CoachAvailabilityOverride>
     */
    public function forCoach(User $coach, int $limit = 20): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('t')
            ->join('o.overriddenBy', 't')
            ->andWhere('o.coach = :coach')
            ->setParameter('coach', $coach)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * One coach's overrides inside one organization, most recent first (BR-085, BR-087).
     *
     * Both filters, not one. Scoping by coach alone would show a trainer the overrides another
     * academy recorded against a coach who has worked for both; scoping by organization alone
     * would answer a different question than the page is asking.
     *
     * @return list<CoachAvailabilityOverride>
     */
    public function forCoachInOrganization(User $coach, int $organizationId, int $limit = 20): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('t')
            ->join('o.overriddenBy', 't')
            ->andWhere('o.coach = :coach')
            ->andWhere('o.organizationId = :organization')
            ->setParameter('coach', $coach)
            ->setParameter('organization', $organizationId)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * One organization's overrides, most recent first (BR-085, BR-087).
     *
     * Scoped by the organization stored on the row rather than by the coach's current
     * assignment: a coach who moves to another trainer must not take their previous trainer's
     * override records with them.
     *
     * @return list<CoachAvailabilityOverride>
     */
    public function forOrganization(int $organizationId, int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('c', 't')
            ->join('o.coach', 'c')
            ->join('o.overriddenBy', 't')
            ->andWhere('o.organizationId = :organization')
            ->setParameter('organization', $organizationId)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
