<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\ImpersonationSession;
use App\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImpersonationSession>
 */
class ImpersonationSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImpersonationSession::class);
    }

    public function add(ImpersonationSession $session): void
    {
        $this->getEntityManager()->persist($session);
    }

    /**
     * The admin's currently-open session, if any.
     *
     * Ordered by start descending and limited to one because a crashed process could in
     * principle leave an older row open; the newest is the one the live token belongs to, and
     * the expiry subscriber closes stale ones as it meets them.
     */
    public function findOpenForAdmin(User $admin): ?ImpersonationSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.admin = :admin')
            ->andWhere('s.endedAt IS NULL')
            ->setParameter('admin', $admin)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The "Impersonation History" compliance report (FR-032), newest first.
     *
     * Returns a Query rather than results so the caller can paginate it; the report is
     * unbounded by design and will outgrow a single page quickly.
     */
    public function historyQuery(): Query
    {
        return $this->createQueryBuilder('s')
            ->addSelect('admin', 'target')
            ->leftJoin('s.admin', 'admin')
            ->leftJoin('s.targetUser', 'target')
            ->orderBy('s.startedAt', 'DESC')
            ->getQuery();
    }
}
