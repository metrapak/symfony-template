<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Dto\UserDirectoryFilter;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Emails are stored normalized, so logging in as `Foo@Bar.com` must find `foo@bar.com`.
     *
     * Only the *input* is normalized, never the column: `LOWER(u.email) = :identifier` would
     * make UNIQ_IDENTIFIER_EMAIL unusable and turn every login attempt — the one query an
     * attacker can trigger at will — into a sequential scan of the whole table. Comparing the
     * column directly is safe because stored addresses are already canonical: User::setEmail()
     * is the only write path and normalizes, and Version20260821160000 lowercased the rows
     * that predate it after refusing to run on any case-only collision.
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :identifier')
            ->setParameter('identifier', self::normalizeEmail($identifier))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->loadUserByIdentifier($email);
    }

    public function findActiveByEmail(string $email): ?User
    {
        $user = $this->loadUserByIdentifier($email);

        return null !== $user && UserStatus::Active === $user->getStatus() ? $user : null;
    }

    /**
     * The Super Admin user directory (FR-020).
     *
     * Returns a Query rather than rows so KnpPaginator can apply LIMIT/OFFSET to it — the
     * directory is expected to hold 10,000 users (NFR-020) and must never load them all.
     *
     * Cross-tenant on purpose: this is Super Admin tooling, and the epic's rule that
     * organization-scoped methods take a required organization id does not apply to a
     * deliberately global view.
     */
    public function directoryQuery(UserDirectoryFilter $filter): Query
    {
        $qb = $this->createQueryBuilder('u');

        if (null !== $filter->role) {
            $qb->andWhere('u.role = :role')->setParameter('role', $filter->role);
        }

        if (null !== $filter->status) {
            $qb->andWhere('u.status = :status')->setParameter('status', $filter->status);
        } else {
            // Anonymized accounts are still rows, but a directory whose default view is full
            // of deleted_17@example.com is worse at its job. They stay one filter away.
            $qb->andWhere('u.status != :hidden')->setParameter('hidden', UserStatus::Deleted);
        }

        if (null !== $filter->term && '' !== trim($filter->term)) {
            // Tool-scoped by design (FR-020): this searches users, and explicitly not the
            // rest of the platform.
            //
            // The OR is parenthesized inside the string. Doctrine's Andx does not add
            // parentheses around a raw string operand, so without them a search combined
            // with a role filter would parse as `role = :role AND email LIKE :t OR name
            // LIKE :t` and return every user whose name matched, regardless of role.
            //
            // Stored emails are already lowercase (User::setEmail normalizes), so only the
            // name needs folding.
            $qb->andWhere('(u.email LIKE :term ESCAPE \'!\' OR LOWER(u.name) LIKE :term ESCAPE \'!\')')
                ->setParameter('term', '%' . self::escapeLike(mb_strtolower(trim($filter->term))) . '%');
        }

        // Deliberately unordered. KnpPaginator applies the ORDER BY — including the default
        // one — from its own options, and a clause added here would compete with it: the
        // walker appends rather than replaces, so this one would win and the column headers
        // would silently stop working.
        return $qb->getQuery();
    }

    public function add(User $user): void
    {
        $this->getEntityManager()->persist($user);
    }

    /**
     * Counts accounts that could still sign in as a Super Admin (G-17).
     *
     * Used to refuse the removal of the last one — a platform with no reachable Super Admin
     * has no way back in, because there is no self-registration and no UI path to create one.
     */
    public function countActiveSuperAdmins(?User $excluding = null): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::SuperAdmin)
            ->setParameter('status', UserStatus::Active);

        if (null !== $excluding && null !== $excluding->getId()) {
            $qb->andWhere('u.id != :excluded')->setParameter('excluded', $excluding->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Neutralizes the LIKE wildcards. Without this, a search for "_" matches every user and
     * a search for "%" matches everything twice over — not a security hole, but a search box
     * that lies about what it found.
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
