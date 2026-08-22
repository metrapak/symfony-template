<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Account\Entity\User;
use App\Profile\Entity\PlayerProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerProfile>
 */
class PlayerProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerProfile::class);
    }

    public function add(PlayerProfile $profile): void
    {
        $this->getEntityManager()->persist($profile);
    }

    /**
     * Everybody the given account may train on behalf of: their own profile first, then their
     * children in the order they were added.
     *
     * This is the list FR-044's "Who will train with {trainer}?" checklist renders, and the
     * allow-list the submitted selection is checked against — a profile id that is not in it
     * is somebody else's family.
     *
     * @return list<PlayerProfile>
     */
    public function findManagedBy(User $owner): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.child', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findSelfProfileFor(User $account): ?PlayerProfile
    {
        return $this->findOneBy(['account' => $account, 'child' => false]);
    }

    /**
     * The profile a signed-in user *is*, if any.
     *
     * FR-048 asks "is the visitor a child account?", and the honest answer lives here rather
     * than on `User`: the role says ROLE_PLAYER either way, and only the profile knows whether
     * somebody else manages this person.
     */
    public function findProfileForAccount(User $account): ?PlayerProfile
    {
        return $this->findOneBy(['account' => $account]);
    }
}
