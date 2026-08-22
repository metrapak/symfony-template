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
     * The children a parent manages, newest last (FR-066's family list).
     *
     * Separate from `findManagedBy()` rather than a filter over it, because the family page
     * lists children *and* handles a parent with none — and "no rows" has to mean "no
     * children", not "the parent's own profile was filtered out".
     *
     * @return list<PlayerProfile>
     */
    public function findChildrenOf(User $parent): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')
            ->andWhere('p.child = true')
            ->setParameter('owner', $parent)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A child of this parent whose name and age already look like the one being added (FR-063).
     *
     * The requirement asks for a *warning*, not a rejection — twins with rhyming names and a
     * parent who genuinely has two children called after the same grandparent are both real —
     * so this answers "is there something the parent should look at?" and the caller decides
     * what to do with the answer.
     *
     * Matching is deliberately loose in one direction and exact in the other: names are
     * compared case-insensitively and trimmed, ages must be equal. A looser age window would
     * fire on every pair of siblings and train parents to click through the warning.
     *
     * @return list<PlayerProfile>
     */
    public function findSimilarChildrenOf(User $parent, string $displayName, ?int $age, \DateTimeImmutable $today): array
    {
        $candidates = $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')
            ->andWhere('p.child = true')
            ->andWhere('LOWER(p.displayName) = :name')
            ->setParameter('owner', $parent)
            ->setParameter('name', mb_strtolower(trim($displayName)))
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        if (null === $age) {
            return $candidates;
        }

        // Age is derived rather than stored, so it cannot be part of the SQL predicate
        // without duplicating the birth-date arithmetic in two dialects. The candidate set is
        // already narrowed to one family and one name, so it is at most a handful of rows.
        return array_values(array_filter(
            $candidates,
            static fn (PlayerProfile $profile): bool => $profile->ageOn($today) === $age,
        ));
    }

    /**
     * The profile behind a context identifier, or null.
     *
     * Loading it says nothing about whether the caller may see it — `TrainingContextResolver`
     * checks that against the association. This exists so the check has something to check.
     */
    public function findOneById(int $profileId): ?PlayerProfile
    {
        return $this->find($profileId);
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
