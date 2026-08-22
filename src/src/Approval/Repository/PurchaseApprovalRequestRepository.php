<?php

declare(strict_types=1);

namespace App\Approval\Repository;

use App\Account\Entity\User;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Enum\ApprovalStatus;
use App\Profile\Entity\PlayerProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseApprovalRequest>
 */
class PurchaseApprovalRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseApprovalRequest::class);
    }

    public function add(PurchaseApprovalRequest $request): void
    {
        $this->getEntityManager()->persist($request);
    }

    /**
     * What a parent is being asked to decide, oldest first (FR-094).
     *
     * Oldest first because the oldest is the closest to expiring, and a list that buries the
     * request about to lapse under three newer ones is a list that causes FR-096's automatic
     * denial rather than preventing it.
     *
     * Served by `IDX_APPROVAL_PARENT_STATUS`; the child profile is joined in because every row
     * renders the child's name and an N+1 across a family is a query per card.
     *
     * @return list<PurchaseApprovalRequest>
     */
    public function pendingForParent(User $parent): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c')
            ->join('r.childProfile', 'c')
            ->andWhere('r.parent = :parent')
            ->andWhere('r.status = :pending')
            ->setParameter('parent', $parent)
            ->setParameter('pending', ApprovalStatus::Pending)
            ->orderBy('r.expiresAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What a parent has already decided, most recent first — the second half of FR-094's screen.
     *
     * Bounded, because it grows forever and nobody reads the fiftieth. A parent needing more than
     * the recent history is looking for an accounting report, which Epic-05 owns.
     *
     * @return list<PurchaseApprovalRequest>
     */
    public function decidedForParent(User $parent, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c')
            ->join('r.childProfile', 'c')
            ->andWhere('r.parent = :parent')
            ->andWhere('r.status != :pending')
            ->setParameter('parent', $parent)
            ->setParameter('pending', ApprovalStatus::Pending)
            ->orderBy('r.respondedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPendingForParent(User $parent): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.parent = :parent')
            ->andWhere('r.status = :pending')
            ->setParameter('parent', $parent)
            ->setParameter('pending', ApprovalStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Everything a set of players has bought or asked to buy, most recent first (FR-090, FR-095).
     *
     * Takes profiles rather than a user, because who may see a reservation is decided by the
     * caller — a child sees their own, a parent sees their whole family — and a repository that
     * re-derived that would be a second implementation of the family rules.
     *
     * @param list<PlayerProfile> $profiles
     *
     * @return list<PurchaseApprovalRequest>
     */
    public function forProfiles(array $profiles, int $limit = 50): array
    {
        if ([] === $profiles) {
            // An empty IN() is a DQL error, and "no profiles" is a real state: a brand-new
            // account with nothing to show.
            return [];
        }

        return $this->createQueryBuilder('r')
            ->addSelect('c')
            ->join('r.childProfile', 'c')
            ->andWhere('r.childProfile IN (:profiles)')
            ->setParameter('profiles', $profiles)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The ids of every pending request whose 48 hours have run out (FR-096, NFR-091).
     *
     * Returns ids and not entities on purpose: the sweep dispatches one message per request and
     * each message handler loads its own row inside its own transaction, so hydrating a hundred
     * entities the sweep will not touch would be work thrown away — and a stale entity carried
     * from the sweep into a handler is how a request decided in between gets expired anyway.
     *
     * `expires_at <= :now` matches `PurchaseApprovalRequest::hasExpiredBy()` exactly, so the
     * sweep and the entity cannot disagree about the boundary. Served by `IDX_APPROVAL_DUE`.
     *
     * @return list<int>
     */
    public function dueForExpiry(\DateTimeImmutable $now, int $limit = 200): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.id')
            ->andWhere('r.status = :pending')
            ->andWhere('r.expiresAt <= :now')
            ->setParameter('pending', ApprovalStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('r.expiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['id'], $rows);
    }
}
