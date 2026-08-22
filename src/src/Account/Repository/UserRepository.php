<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\User;
use App\Account\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function add(User $user): void
    {
        $this->getEntityManager()->persist($user);
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
