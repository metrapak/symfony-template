<?php

declare(strict_types=1);

namespace App\Membership\Mail;

use App\Account\Entity\User;
use App\Membership\Entity\ShareLink;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The three messages the invitation flow sends (Q-01.04, still unanswered by the client — the
 * content here is the shipping default, and changing it is a template change).
 *
 * Same shape as `AccountMailer`: a thin adapter that renders a template pair and hands the
 * result to Symfony, with a plain-text alternative on every message so no recipient depends on
 * HTML. Mail is synchronous — no async transport is configured — so a slow SMTP server slows
 * the request that triggered it, which is why every caller dispatches after its transaction
 * has committed.
 *
 * Recipients are passed as plain strings rather than as entities. A template that can reach
 * through an entity is a template that can render a field nobody meant to email.
 */
final readonly class MembershipMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private string $senderAddress,
        private string $senderName,
    ) {
    }

    /**
     * FR-041 / US-01.08 — the invitation a coach receives, carrying the trainer's own message.
     *
     * Addressed to the invitation's target email, which may not belong to any account yet:
     * that is the point of an invitation.
     */
    public function sendCoachInvitation(ShareLink $link, string $trainerName, string $joinUrl): void
    {
        $targetEmail = $link->getTargetEmail();

        if (null === $targetEmail) {
            throw new \LogicException('A coach invitation must carry a target email address.');
        }

        $this->send(
            new Address($targetEmail, $link->getTargetName() ?? ''),
            \sprintf('%s has invited you to coach', $trainerName),
            'coach_invitation',
            [
                'coachName' => $link->getTargetName(),
                'trainerName' => $trainerName,
                'organizationName' => $link->getOrganization()->getName(),
                'personalMessage' => $link->getMessage(),
                'joinUrl' => $joinUrl,
                'expiresAt' => $link->getExpiresAt(),
            ],
        );
    }

    /**
     * FR-042 — "confirmation email sent" after a registration through a player link.
     */
    public function sendRegistrationConfirmation(
        User $user,
        string $organizationName,
        string $playerName,
        bool $verificationRequired,
    ): void {
        $this->send(
            new Address($user->getEmail(), $user->getDisplayName()),
            \sprintf('You are registered with %s', $organizationName),
            'registration_confirmation',
            [
                'name' => $user->getDisplayName(),
                'playerName' => $playerName,
                'organizationName' => $organizationName,
                // Says whether a second email with a confirmation link is on its way, so the
                // two messages do not read as duplicates of each other.
                'verificationRequired' => $verificationRequired,
            ],
        );
    }

    /**
     * FR-048 — a child clicked a trainer's link, so their parent is asked to finish it.
     *
     * The link travels in the body deliberately: BR-046 says the child may not add a trainer,
     * not that the trainer must be hidden from the family. The parent following this link
     * lands on the same `/join/{code}` page, where they are the account that may accept it.
     */
    public function sendChildJoinRequest(
        User $parent,
        string $childName,
        string $organizationName,
        string $joinUrl,
    ): void {
        $this->send(
            new Address($parent->getEmail(), $parent->getDisplayName()),
            \sprintf('%s wants to join %s\'s program', $childName, $organizationName),
            'child_join_request',
            [
                'parentName' => $parent->getDisplayName(),
                'childName' => $childName,
                'organizationName' => $organizationName,
                'joinUrl' => $joinUrl,
            ],
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(Address $to, string $subject, string $template, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderAddress, $this->senderName))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate(\sprintf('membership/email/%s.html.twig', $template))
            ->textTemplate(\sprintf('membership/email/%s.txt.twig', $template))
            ->context($context);

        $this->mailer->send($email);
    }
}
