<?php

declare(strict_types=1);

namespace App\Membership\Repository;

use App\Account\Entity\User;
use App\Membership\Entity\ShareLink;
use App\Membership\Entity\ShareLinkRedemption;
use App\Membership\Enum\RedemptionOutcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareLinkRedemption>
 */
class ShareLinkRedemptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareLinkRedemption::class);
    }

    public function add(ShareLinkRedemption $redemption): void
    {
        $this->getEntityManager()->persist($redemption);
    }

    public function hasOutcomeFor(ShareLink $link, User $user, RedemptionOutcome $outcome): bool
    {
        return null !== $this->findOneBy(['shareLink' => $link, 'user' => $user, 'outcome' => $outcome]);
    }

    /**
     * @return list<ShareLinkRedemption>
     */
    public function findFor(ShareLink $link): array
    {
        return $this->findBy(['shareLink' => $link], ['redeemedAt' => 'DESC', 'id' => 'DESC']);
    }
}
