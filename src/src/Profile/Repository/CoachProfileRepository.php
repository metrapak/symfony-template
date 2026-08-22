<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Account\Entity\User;
use App\Profile\Entity\CoachProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachProfile>
 */
class CoachProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachProfile::class);
    }

    public function add(CoachProfile $profile): void
    {
        $this->getEntityManager()->persist($profile);
    }

    /**
     * The coach's profile within one organization.
     *
     * Organization-scoped and the id is required, per the epic's tenancy convention: a coach
     * who has worked for two trainers has two of these, and a lookup by user alone would
     * return whichever the database felt like.
     */
    public function findOneFor(User $coach, int $organizationId): ?CoachProfile
    {
        return $this->findOneBy(['user' => $coach, 'organization' => $organizationId]);
    }

    /**
     * Every profile this coach has ever written, so an erasure can clear all of them (FR-025).
     *
     * Cross-tenant on purpose, and the only method here that is: an erasure is not an
     * organization's operation, and leaving a bio behind in the one tenant nobody thought of
     * is exactly the failure FR-025 is about.
     *
     * @return list<CoachProfile>
     */
    public function findAllForUser(User $coach): array
    {
        return $this->findBy(['user' => $coach], ['id' => 'ASC']);
    }

    /**
     * The organization's coaches who chose to be publicly visible (FR-061).
     *
     * @return list<CoachProfile>
     */
    public function findPublicFor(int $organizationId): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('u')
            ->join('c.user', 'u')
            ->andWhere('c.organization = :organization')
            ->andWhere('c.public = true')
            ->setParameter('organization', $organizationId)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
