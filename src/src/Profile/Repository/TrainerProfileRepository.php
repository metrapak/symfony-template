<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Profile\Entity\TrainerProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainerProfile>
 */
class TrainerProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainerProfile::class);
    }

    public function add(TrainerProfile $profile): void
    {
        $this->getEntityManager()->persist($profile);
    }

    public function findOneForOrganization(int $organizationId): ?TrainerProfile
    {
        return $this->findOneBy(['organization' => $organizationId]);
    }
}
