<?php

declare(strict_types=1);

namespace App\Membership\Repository;

use App\Membership\Entity\ShareLink;
use App\Membership\Enum\ShareLinkType;
use App\Membership\ValueObject\ShareLinkCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareLink>
 */
class ShareLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareLink::class);
    }

    public function add(ShareLink $link): void
    {
        $this->getEntityManager()->persist($link);
    }

    /**
     * Loads a link by code regardless of whether it can still be used.
     *
     * There is deliberately no `findActiveByCode()`. An active-only lookup returns null for
     * an expired coach invitation and for a code nobody ever issued, and FR-046 requires those
     * two to be told apart — the holder of a lapsed invitation must be offered a resend.
     * Deciding usability is `ShareLinkResolver`'s job, and it needs the row to do it.
     */
    public function findOneByCode(ShareLinkCode $code): ?ShareLink
    {
        return $this->findOneBy(['code' => $code->value]);
    }

    /**
     * Claims one use of a link, atomically (NFR-041, BR-041).
     *
     * The guard clauses live in the WHERE rather than in PHP on purpose. Read-then-write would
     * let a hundred concurrent redemptions of a single-use coach invitation each read
     * `useCount = 0` and each conclude they were the one allowed use; here the database
     * serializes the row and exactly one UPDATE reports a match.
     *
     * Callers must run this inside their own transaction and treat `false` as "somebody else
     * took the last use" — not as an error, and never as a reason to associate anyway.
     */
    public function consume(ShareLink $link, \DateTimeImmutable $now): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE share_link
                   SET use_count = use_count + 1, updated_at = :now
                 WHERE id = :id
                   AND active = true
                   AND (max_uses IS NULL OR use_count < max_uses)
                   AND (expires_at IS NULL OR expires_at > :now)
                SQL,
            [
                'id' => $link->getId(),
                'now' => $now->format('Y-m-d H:i:s'),
            ],
        );

        return 1 === $affected;
    }

    /**
     * The player links a trainer manages, newest first (FR-040).
     *
     * Takes an organization id rather than reading a tenant context, per the epic's binding
     * convention: a caller that forgets to scope this query does not compile.
     *
     * @return list<ShareLink>
     */
    public function findPlayerLinksFor(int $organizationId): array
    {
        return $this->findLinksFor($organizationId, ShareLinkType::Player);
    }

    /**
     * @return list<ShareLink>
     */
    public function findCoachInvitationsFor(int $organizationId): array
    {
        return $this->findLinksFor($organizationId, ShareLinkType::Coach);
    }

    public function findActiveCoachInvitationTo(int $organizationId, string $email): ?ShareLink
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.organization = :organization')
            ->andWhere('l.type = :type')
            ->andWhere('l.targetEmail = :email')
            ->andWhere('l.active = true')
            ->setParameter('organization', $organizationId)
            ->setParameter('type', ShareLinkType::Coach)
            ->setParameter('email', mb_strtolower(trim($email)))
            ->orderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ShareLink>
     */
    private function findLinksFor(int $organizationId, ShareLinkType $type): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.organization = :organization')
            ->andWhere('l.type = :type')
            ->setParameter('organization', $organizationId)
            ->setParameter('type', $type)
            ->orderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
