<?php

declare(strict_types=1);

namespace App\Membership\Repository;

use App\Account\Entity\User;
use App\Membership\Entity\CoachAssignment;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\MembershipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachAssignment>
 */
class CoachAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachAssignment::class);
    }

    public function add(CoachAssignment $assignment): void
    {
        $this->getEntityManager()->persist($assignment);
    }

    /**
     * The one organization a coach currently works for, or null (BR-044).
     *
     * Cross-tenant by design and therefore not scoped by an organization id: the question this
     * answers is "is this coach already taken, anywhere?", which is exactly the question the
     * single-trainer rule turns on.
     */
    public function findActiveForCoach(User $coach): ?CoachAssignment
    {
        return $this->findOneBy(['coach' => $coach, 'status' => MembershipStatus::Active]);
    }

    public function findOneByShareLink(ShareLink $link): ?CoachAssignment
    {
        return $this->findOneBy(['viaShareLink' => $link]);
    }

    /**
     * The organization's coaches, accepted invitations first (US-01.08).
     *
     * @return list<CoachAssignment>
     */
    public function findFor(int $organizationId): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('c')
            ->join('a.coach', 'c')
            ->andWhere('a.organization = :organization')
            ->setParameter('organization', $organizationId)
            ->orderBy('a.status', 'ASC')
            ->addOrderBy('a.joinedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
