<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Profile\Entity\OrganizationBranding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationBranding>
 */
class OrganizationBrandingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationBranding::class);
    }

    public function add(OrganizationBranding $branding): void
    {
        $this->getEntityManager()->persist($branding);
    }

    public function findOneForOrganization(int $organizationId): ?OrganizationBranding
    {
        return $this->findOneBy(['organization' => $organizationId]);
    }
}
