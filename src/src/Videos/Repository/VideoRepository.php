<?php

namespace App\Videos\Repository;

use App\Videos\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<Video>
 */
class VideoRepository extends ServiceEntityRepository
{
    public function __construct(public ManagerRegistry $registry, public PaginatorInterface $paginator)
    {
        parent::__construct($registry, Video::class);
    }

    public function findAllPaginated(int $page)
    {
        $queryBuilder = $this->createQueryBuilder('v')
            ->orderBy('v.id', 'ASC');

        return $this->paginator->paginate(
            $queryBuilder,
            $page,
            5,
        );
    }

    //    /**
    //     * @return Video[] Returns an array of Video objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('v.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Video
    //    {
    //        return $this->createQueryBuilder('v')
    //            ->andWhere('v.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
