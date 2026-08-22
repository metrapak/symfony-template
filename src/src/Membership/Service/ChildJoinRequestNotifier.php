<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Membership\Entity\ShareLink;
use App\Membership\Enum\RedemptionOutcome;
use App\Membership\Mail\MembershipMailer;
use App\Membership\Repository\ShareLinkRedemptionRepository;
use App\Profile\Entity\PlayerProfile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What happens instead of an association when a child opens a trainer's link (FR-048, BR-046).
 *
 * Two things, and the order matters. The refusal is recorded — a blocked attempt is a real
 * event in the trainer's funnel and Epic-06 asks for it — and only then is the parent emailed,
 * outside the transaction, so a mail failure cannot erase the record of the attempt.
 *
 * The link is **not** consumed. Nothing was granted, and consuming a use here would let a
 * child exhaust a single-use invitation that was never theirs to spend.
 *
 * Child accounts do not exist until TASK-004 ships them, so nothing in production reaches this
 * yet. It is implemented now because the rule belongs to the redemption flow rather than to
 * the profile screens: leaving `/join/{code}` to be patched later is how a child ends up
 * silently associated the day child logins land.
 */
final readonly class ChildJoinRequestNotifier
{
    public function __construct(
        private RedemptionRecorder $recorder,
        private ShareLinkRedemptionRepository $redemptions,
        private MembershipMailer $mailer,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether a parent notification was sent by this call
     */
    public function refuseAndNotifyParent(ShareLink $link, PlayerProfile $child): bool
    {
        $account = $child->getAccount();

        if (null === $account) {
            throw new \LogicException('A child profile without an account cannot have opened a link.');
        }

        // The refusal page is a GET, so a reload, a back button or a mail client prefetching
        // the address would otherwise mail the parent again for the same attempt. One record
        // per child and link is the event; the page says the same thing either way.
        if ($this->redemptions->hasOutcomeFor($link, $account, RedemptionOutcome::BlockedChild)) {
            return false;
        }

        $this->recorder->record($link, $account, RedemptionOutcome::BlockedChild);
        $this->entityManager->flush();

        $joinUrl = $this->urlGenerator->generate(
            'membership_join',
            ['code' => $link->getCode()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $this->mailer->sendChildJoinRequest(
                $child->getOwner(),
                $child->getDisplayName(),
                $link->getOrganization()->getName(),
                $joinUrl,
            );

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Parent notification for a blocked child link could not be sent.', [
                'shareLinkId' => $link->getId(),
                'childProfileId' => $child->getId(),
                'exception' => $e,
            ]);

            return false;
        }
    }
}
