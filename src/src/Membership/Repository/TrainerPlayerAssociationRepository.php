<?php

declare(strict_types=1);

namespace App\Membership\Repository;

use App\Account\Entity\User;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Enum\MembershipStatus;
use App\Profile\Entity\PlayerProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainerPlayerAssociation>
 */
class TrainerPlayerAssociationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainerPlayerAssociation::class);
    }

    public function add(TrainerPlayerAssociation $association): void
    {
        $this->getEntityManager()->persist($association);
    }

    public function findOneFor(int $organizationId, PlayerProfile $profile): ?TrainerPlayerAssociation
    {
        return $this->findOneBy(['organization' => $organizationId, 'playerProfile' => $profile]);
    }

    /**
     * Whether this player already trains with this organization, in any state.
     *
     * "In any state" is intentional: an inactive association still occupies the unique index,
     * so a caller that treats it as absent and inserts is the caller that hits a constraint
     * violation.
     */
    public function existsFor(int $organizationId, PlayerProfile $profile): bool
    {
        return null !== $this->findOneFor($organizationId, $profile);
    }

    /**
     * The organizations one player currently trains with, for the context switcher TASK-004
     * builds on top of this task's associations.
     *
     * @return list<TrainerPlayerAssociation>
     */
    public function findActiveForProfile(PlayerProfile $profile): array
    {
        return $this->findBy(
            ['playerProfile' => $profile, 'status' => MembershipStatus::Active],
            ['connectedAt' => 'ASC'],
        );
    }

    /**
     * Every active association across the profiles one account manages — their own and their
     * children's — with the organization and profile already joined.
     *
     * This is the query behind the context switcher (FR-069) and the family page (FR-066), and
     * the joins are the point: a family with three children across two trainers renders in one
     * query instead of one per row.
     *
     * @return list<TrainerPlayerAssociation>
     */
    public function findActiveForOwner(User $owner): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('p', 'o')
            ->join('a.playerProfile', 'p')
            ->join('a.organization', 'o')
            ->andWhere('p.owner = :owner')
            ->andWhere('a.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', MembershipStatus::Active)
            ->orderBy('p.child', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->addOrderBy('a.connectedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One profile's active associations, with the organization joined.
     *
     * `findActiveForProfile()` above answers the same question without the join; this exists
     * because a child login needs the organization *names* and must not be given a route to
     * anything its parent owns (FR-068), so it cannot go through `findActiveForOwner()`.
     *
     * @return list<TrainerPlayerAssociation>
     */
    public function findActiveWithOrganizationsForProfile(PlayerProfile $profile): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->join('a.organization', 'o')
            ->andWhere('a.playerProfile = :profile')
            ->andWhere('a.status = :status')
            ->setParameter('profile', $profile)
            ->setParameter('status', MembershipStatus::Active)
            ->orderBy('a.connectedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveFor(int $organizationId, PlayerProfile $profile): ?TrainerPlayerAssociation
    {
        return $this->findOneBy([
            'organization' => $organizationId,
            'playerProfile' => $profile,
            'status' => MembershipStatus::Active,
        ]);
    }

    /**
     * The trainer's roster (the "player appears in the trainer's CRM" half of FR-042).
     *
     * @return list<TrainerPlayerAssociation>
     */
    public function findActiveFor(int $organizationId): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('p')
            ->join('a.playerProfile', 'p')
            ->andWhere('a.organization = :organization')
            ->andWhere('a.status = :status')
            ->setParameter('organization', $organizationId)
            ->setParameter('status', MembershipStatus::Active)
            ->orderBy('a.connectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
