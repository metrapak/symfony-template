<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function findOneByOwner(User $owner): ?Organization
    {
        return $this->findOneBy(['owner' => $owner]);
    }

    public function add(Organization $organization): void
    {
        $this->getEntityManager()->persist($organization);
    }
}
