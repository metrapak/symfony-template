<?php

declare(strict_types=1);

namespace App\Approval\Notification;

use App\Account\Entity\User;
use App\Approval\Entity\ApprovalNotification;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Enum\ApprovalStatus;
use App\Approval\Enum\NotificationKind;
use App\Approval\Mail\ApprovalMailer;
use App\Approval\Repository\ApprovalNotificationRepository;
use App\Profile\Service\ChildLoginManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Who gets told what, on which channel, when a purchase changes state (FR-093, FR-095, FR-096).
 *
 * Three rules, each of which is a requirement rather than a preference:
 *
 *  - **The in-app notification is written first, inside its own transaction, and the email is
 *    attempted afterwards.** NFR-093 says an approval request must not be silently lost when mail
 *    delivery fails, so the durable record is committed before anything talks to SMTP, and a
 *    transport failure is logged and swallowed. A parent whose mail server is down still finds
 *    the request waiting on their dashboard; a workflow that let the exception escape would have
 *    rolled back the decision that caused it.
 *  - **A child is notified in-app and never by email.** A child login's address is derived and
 *    deliberately undeliverable (`ChildLoginManager::DERIVED_EMAIL_DOMAIN`, RFC 2606), so mailing
 *    it would produce a bounce for every decision a parent makes. This is the concrete reason the
 *    in-app store exists at all — see `ApprovalNotification` and G-33.
 *  - **Nobody is notified about their own action.** A parent who just clicked Approve does not
 *    need an email saying they approved it, and an adult buying for themselves is not their own
 *    guardian. The workflow passes the actor in and it is skipped.
 *
 * URLs are generated here, absolutely, because the expiry sweep runs from the console where there
 * is no request to borrow a host from. That works because `router.default_uri` is configured;
 * without it, links in mail sent from the sweep would be relative and useless.
 */
final readonly class ApprovalNotifier
{
    public function __construct(
        private ApprovalNotificationRepository $notifications,
        private ApprovalMailer $mailer,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * FR-093 — the parent is asked to decide, by email and in-app.
     */
    public function notifyParentApprovalNeeded(PurchaseApprovalRequest $request): void
    {
        $parent = $request->getParent();

        $this->store(
            $parent,
            NotificationKind::ApprovalNeeded,
            \sprintf(
                '%s needs your approval to spend %s',
                $request->getChildProfile()->getDisplayName(),
                $request->getAmount()->format(),
            ),
            \sprintf(
                '%s asked to buy "%s" for %s, paid in %s. Approve or deny it before %s.',
                $request->getChildProfile()->getDisplayName(),
                $request->getPurchaseDescription(),
                $request->getAmount()->format(),
                mb_strtolower($request->getPaymentType()->label()),
                $request->getExpiresAt()?->format('j M Y, H:i') ?? 'it expires',
            ),
            $request,
        );

        $this->mail(
            $parent,
            fn () => $this->mailer->sendApprovalNeeded($parent, $request, $this->reviewUrl($request)),
        );
    }

    /**
     * FR-092 — the parent waived approval for this child's tokens, so they are told, not asked.
     */
    public function notifyParentTokenSpend(PurchaseApprovalRequest $request): void
    {
        $parent = $request->getParent();

        $this->store(
            $parent,
            NotificationKind::TokenSpendNotice,
            \sprintf(
                '%s spent %s',
                $request->getChildProfile()->getDisplayName(),
                $request->getAmount()->format(),
            ),
            \sprintf(
                '%s bought "%s" for %s. You allow this child to spend tokens without approval, so no approval was requested.',
                $request->getChildProfile()->getDisplayName(),
                $request->getPurchaseDescription(),
                $request->getAmount()->format(),
            ),
            $request,
        );

        $this->mail(
            $parent,
            fn () => $this->mailer->sendTokenSpendNotice($parent, $request, $this->settingsUrl($request)),
        );
    }

    /**
     * FR-095, FR-096 — the outcome, to the child who asked and to the parent who did not act.
     *
     * @param User|null $actor whoever made the decision, so they are not told what they just did;
     *                         null for the expiry sweep, which nobody performed
     */
    public function notifyOutcome(PurchaseApprovalRequest $request, ?User $actor): void
    {
        $child = $request->getChildProfile()->getAccount();
        $parent = $request->getParent();

        if (null !== $child && $child->getId() !== $actor?->getId()) {
            $this->store($child, $this->outcomeKind($request), $this->outcomeSummary($request), $this->outcomeBody($request), $request);
            $this->mail($child, fn () => $this->mailOutcome($child, $request));
        }

        // The parent hears about an outcome they did not cause — which in practice means an
        // expiry. FR-096 asks for a notification on expiry and does not say to whom; the parent
        // is the one who was asked and did not answer, so they are told what happened in their
        // silence.
        if ($parent->getId() !== $actor?->getId() && $parent->getId() !== $child?->getId()) {
            $this->store($parent, $this->outcomeKind($request), $this->outcomeSummary($request), $this->outcomeBody($request), $request);
            $this->mail($parent, fn () => $this->mailOutcome($parent, $request));
        }
    }

    /**
     * Writes and commits the durable half of a notification.
     *
     * Its own transaction, deliberately: the caller has already committed the decision, and this
     * must not be able to roll that back.
     */
    private function store(
        User $recipient,
        NotificationKind $kind,
        string $summary,
        string $body,
        PurchaseApprovalRequest $request,
    ): void {
        $this->notifications->add(new ApprovalNotification(
            $recipient,
            $kind,
            $summary,
            $body,
            $this->clock->now(),
            $request->getId(),
        ));

        $this->entityManager->flush();
    }

    /**
     * Sends, unless the recipient cannot receive mail — and never lets a transport failure
     * escape (NFR-093).
     *
     * @param callable():void $send
     */
    private function mail(User $recipient, callable $send): void
    {
        if (!self::canReceiveMail($recipient)) {
            return;
        }

        try {
            $send();
        } catch (TransportExceptionInterface $e) {
            // Logged at error level rather than rethrown: the notification the requirement cares
            // about is already committed, and failing the parent's Approve click because SMTP is
            // down would undo a decision they made for a reason unrelated to it.
            $this->logger->error('Could not send an approval email; the in-app notification was still stored.', [
                'recipient_id' => $recipient->getId(),
                'exception' => $e,
            ]);
        }
    }

    private function mailOutcome(User $recipient, PurchaseApprovalRequest $request): void
    {
        match ($this->outcomeKind($request)) {
            NotificationKind::Approved => $this->mailer->sendApproved($recipient, $request),
            NotificationKind::Denied => $this->mailer->sendDenied($recipient, $request),
            default => $this->mailer->sendExpired($recipient, $request),
        };
    }

    private function outcomeKind(PurchaseApprovalRequest $request): NotificationKind
    {
        return match ($request->getStatus()) {
            ApprovalStatus::Approved => NotificationKind::Approved,
            ApprovalStatus::Denied => NotificationKind::Denied,
            default => NotificationKind::Expired,
        };
    }

    private function outcomeSummary(PurchaseApprovalRequest $request): string
    {
        return \sprintf('%s: %s', $request->getStatus()->label(), $request->getPurchaseDescription());
    }

    private function outcomeBody(PurchaseApprovalRequest $request): string
    {
        $body = \sprintf(
            '%s — %s for %s. %s',
            $request->getPurchaseDescription(),
            $request->getChildProfile()->getDisplayName(),
            $request->getAmount()->format(),
            $request->getStatus()->explanation(),
        );

        $notes = $request->getParentNotes();

        return null === $notes ? $body : $body . ' Note from your parent: ' . $notes;
    }

    private function reviewUrl(PurchaseApprovalRequest $request): string
    {
        return $this->urls->generate(
            'family_approval_show',
            ['id' => $request->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function settingsUrl(PurchaseApprovalRequest $request): string
    {
        return $this->urls->generate(
            'family_child_spending',
            ['id' => $request->getChildProfile()->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * Whether an address is one mail can actually reach.
     *
     * A child login's address ends in the reserved `.invalid` domain by construction, so this is
     * a property of the account rather than a guess about it.
     */
    private static function canReceiveMail(User $recipient): bool
    {
        return !str_ends_with(mb_strtolower($recipient->getEmail()), '@' . ChildLoginManager::DERIVED_EMAIL_DOMAIN);
    }
}
