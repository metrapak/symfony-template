<?php

declare(strict_types=1);

namespace App\Approval\Repository;

use App\Account\Entity\User;
use App\Approval\Entity\ApprovalNotification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApprovalNotification>
 */
class ApprovalNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApprovalNotification::class);
    }

    public function add(ApprovalNotification $notification): void
    {
        $this->getEntityManager()->persist($notification);
    }

    /**
     * The unread count behind the indicator (FR-093).
     *
     * A count rather than a fetch: the indicator renders on every page in the family and player
     * sections, and loading the rows to count them would put a hydration on each of them.
     */
    public function countUnreadFor(User $recipient): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One person's inbox, newest first.
     *
     * @return list<ApprovalNotification>
     */
    public function recentFor(User $recipient, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything this person has not read yet.
     *
     * Entities rather than a bulk UPDATE, so `markRead()` stays the only thing that writes the
     * column and the "already read stays at its first timestamp" rule holds. An inbox is bounded
     * by what one household generates, so the row count is not the concern here that it would be
     * on a platform-wide feed.
     *
     * @return list<ApprovalNotification>
     */
    public function unreadFor(User $recipient): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->orderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
