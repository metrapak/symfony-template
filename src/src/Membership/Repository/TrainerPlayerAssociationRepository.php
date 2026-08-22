<?php

declare(strict_types=1);

namespace App\Membership\Repository;

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
