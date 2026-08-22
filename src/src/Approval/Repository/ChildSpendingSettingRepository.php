<?php

declare(strict_types=1);

namespace App\Approval\Repository;

use App\Approval\Entity\ChildSpendingSetting;
use App\Profile\Entity\PlayerProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChildSpendingSetting>
 */
class ChildSpendingSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChildSpendingSetting::class);
    }

    public function add(ChildSpendingSetting $setting): void
    {
        $this->getEntityManager()->persist($setting);
    }

    /**
     * The stored setting for one child, or null when nobody has ever changed it.
     *
     * Null is a meaningful answer and not a missing row to paper over: BR-091 makes "no decision"
     * mean "approval required", and `SpendingSettingService` is the one place that turns the null
     * into that default.
     */
    public function findForChild(PlayerProfile $child): ?ChildSpendingSetting
    {
        return $this->findOneBy(['childProfile' => $child]);
    }

    /**
     * Every stored setting for a family, keyed by child profile id.
     *
     * One query for the whole settings screen. Looking each child up in turn would be a query
     * per row on a page whose entire purpose is to compare children against each other.
     *
     * @param list<PlayerProfile> $children
     *
     * @return array<int, ChildSpendingSetting>
     */
    public function findForChildren(array $children): array
    {
        if ([] === $children) {
            return [];
        }

        /** @var list<ChildSpendingSetting> $settings */
        $settings = $this->createQueryBuilder('s')
            ->andWhere('s.childProfile IN (:children)')
            ->setParameter('children', $children)
            ->getQuery()
            ->getResult();

        $byChild = [];

        foreach ($settings as $setting) {
            $byChild[(int) $setting->getChildProfile()->getId()] = $setting;
        }

        return $byChild;
    }
}
