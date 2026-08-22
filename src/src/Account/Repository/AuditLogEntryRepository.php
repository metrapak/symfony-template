<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\AuditLogEntry;
use App\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /**
     * Persists without flushing, on purpose (NFR-022).
     *
     * The caller's transaction decides whether the audited change and its record commit
     * together. A repository that flushed here would write an entry for an operation that
     * later rolled back.
     */
    public function add(AuditLogEntry $entry): void
    {
        $this->getEntityManager()->persist($entry);
    }

    /**
     * @return list<AuditLogEntry>
     */
    public function findForSubject(string $subjectType, int $subjectId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.subjectType = :type')
            ->andWhere('a.subjectId = :id')
            ->setParameter('type', $subjectType)
            ->setParameter('id', $subjectId)
            ->orderBy('a.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Entries written while the actor was being impersonated by the given admin (G-18): the
     * "what did they actually do in there" half of the compliance answer.
     *
     * @return list<AuditLogEntry>
     */
    public function findWrittenWhileImpersonating(User $admin, \DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.impersonator = :admin')
            ->andWhere('a.occurredAt >= :from')
            ->setParameter('admin', $admin)
            ->setParameter('from', $from)
            ->orderBy('a.occurredAt', 'ASC');

        if (null !== $to) {
            $qb->andWhere('a.occurredAt <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }
}
