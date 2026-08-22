<?php

declare(strict_types=1);

namespace App\Approval\Mail;

use App\Account\Entity\User;
use App\Approval\Entity\PurchaseApprovalRequest;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The five messages the approval workflow sends (FR-093, FR-095, FR-096; Q-01.04 is still
 * unanswered, so the content here is the shipping default and changing it is a template change).
 *
 * Same shape as `AccountMailer` and `MembershipMailer`: a thin adapter that renders a template
 * pair and hands the result to Symfony, with a plain-text alternative on every message. Mail is
 * synchronous — no async transport is configured — which is why `ApprovalNotifier` sends only
 * after its transaction has committed, and why a failure here must not be allowed to take the
 * decision with it (NFR-093).
 *
 * Every message carries the four facts FR-093 names — which child, which purchase, how much, and
 * paid how — because a parent reading "approval needed" on a phone should not have to sign in to
 * find out what they are being asked about.
 *
 * The review URL is built by the caller. This class never generates one: a mailer that knew the
 * routing would be a mailer that behaves differently under the console (the expiry sweep) than
 * under a request.
 */
final readonly class ApprovalMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private string $senderAddress,
        private string $senderName,
    ) {
    }

    /**
     * FR-093 — a parent is asked to decide, and told when the question stops being open.
     */
    public function sendApprovalNeeded(User $parent, PurchaseApprovalRequest $request, string $reviewUrl): void
    {
        $this->send(
            $parent,
            \sprintf('%s needs your approval to spend %s', $request->getChildProfile()->getDisplayName(), $request->getAmount()->format()),
            'approval_needed',
            $request,
            ['reviewUrl' => $reviewUrl],
        );
    }

    /**
     * FR-092 — the parent waived approval for this child's tokens, so this is news, not a request.
     *
     * The subject line says so in the first three words. A parent who receives both kinds must be
     * able to tell them apart in a notification list without opening either.
     */
    public function sendTokenSpendNotice(User $parent, PurchaseApprovalRequest $request, string $settingsUrl): void
    {
        $this->send(
            $parent,
            \sprintf('For your information: %s spent %s', $request->getChildProfile()->getDisplayName(), $request->getAmount()->format()),
            'token_spend_notice',
            $request,
            ['settingsUrl' => $settingsUrl],
        );
    }

    public function sendApproved(User $recipient, PurchaseApprovalRequest $request): void
    {
        $this->send(
            $recipient,
            \sprintf('Approved: %s', $request->getPurchaseDescription()),
            'approved',
            $request,
            [],
        );
    }

    public function sendDenied(User $recipient, PurchaseApprovalRequest $request): void
    {
        $this->send(
            $recipient,
            \sprintf('Not approved: %s', $request->getPurchaseDescription()),
            'denied',
            $request,
            [],
        );
    }

    /**
     * FR-096 — nobody answered, so the platform denied it and says so to both sides.
     */
    public function sendExpired(User $recipient, PurchaseApprovalRequest $request): void
    {
        $this->send(
            $recipient,
            \sprintf('Expired: %s', $request->getPurchaseDescription()),
            'expired',
            $request,
            [],
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function send(User $recipient, string $subject, string $template, PurchaseApprovalRequest $request, array $extra): void
    {
        // Scalars, not the entity. A template that can reach through a purchase can reach through
        // the child profile hanging off it and render a field nobody meant to email — the same
        // rule `MembershipMailer` states.
        $context = [
            'recipientName' => $recipient->getDisplayName(),
            'childName' => $request->getChildProfile()->getDisplayName(),
            'purchaseDescription' => $request->getPurchaseDescription(),
            'amount' => $request->getAmount()->format(),
            'spokenAmount' => $request->getAmount()->spokenLabel(),
            'paymentType' => $request->getPaymentType()->label(),
            'requestedAt' => $request->getRequestedAt(),
            'expiresAt' => $request->getExpiresAt(),
            'statusLabel' => $request->getStatus()->label(),
            'parentNotes' => $request->getParentNotes(),
        ];

        $email = (new TemplatedEmail())
            ->from(new Address($this->senderAddress, $this->senderName))
            ->to(new Address($recipient->getEmail(), $recipient->getDisplayName()))
            ->subject($subject)
            ->htmlTemplate(\sprintf('approval/email/%s.html.twig', $template))
            ->textTemplate(\sprintf('approval/email/%s.txt.twig', $template))
            ->context($context + $extra);

        $this->mailer->send($email);
    }
}
