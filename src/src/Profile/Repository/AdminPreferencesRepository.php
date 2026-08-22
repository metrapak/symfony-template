<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Account\Entity\User;
use App\Profile\Entity\AdminPreferences;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminPreferences>
 */
class AdminPreferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminPreferences::class);
    }

    public function add(AdminPreferences $preferences): void
    {
        $this->getEntityManager()->persist($preferences);
    }

    public function findOneForUser(User $user): ?AdminPreferences
    {
        return $this->findOneBy(['user' => $user]);
    }
}
