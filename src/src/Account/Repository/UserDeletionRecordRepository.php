<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\UserDeletionRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDeletionRecord>
 */
class UserDeletionRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDeletionRecord::class);
    }

    public function add(UserDeletionRecord $record): void
    {
        $this->getEntityManager()->persist($record);
    }

    /**
     * Answers "was the account for this address deleted?" without the table ever holding the
     * address (D8). The caller supplies the address; the digest is derived here.
     */
    public function findOneByEmail(string $email): ?UserDeletionRecord
    {
        return $this->findOneBy(['originalEmailDigest' => UserDeletionRecord::digestFor($email)]);
    }
}
