<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Account\Entity\User;
use App\Profile\Entity\EmergencyContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmergencyContact>
 */
class EmergencyContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmergencyContact::class);
    }

    public function add(EmergencyContact $contact): void
    {
        $this->getEntityManager()->persist($contact);
    }

    public function remove(EmergencyContact $contact): void
    {
        $this->getEntityManager()->remove($contact);
    }

    /**
     * One of this parent's contacts, by id.
     *
     * The parent is part of the *query*, not a check applied afterwards, which is what makes
     * another family's id a 404 rather than a row that has to be rejected. There is no code path
     * here on which the wrong contact is loaded at all.
     */
    public function findOneForParent(int $id, User $parent): ?EmergencyContact
    {
        return $this->findOneBy(['id' => $id, 'parent' => $parent]);
    }

    /**
     * In the order the parent arranged them, so "call this one first" survives a page reload.
     *
     * @return list<EmergencyContact>
     */
    public function findForParent(User $parent): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.parent = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
