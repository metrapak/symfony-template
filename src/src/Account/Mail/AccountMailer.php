<?php

declare(strict_types=1);

namespace App\Account\Mail;

use App\Account\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Thin adapter over the mailer: renders account templates and hands them to Symfony.
 *
 * Every message carries a plain-text alternative, so no recipient depends on HTML being
 * rendered. Mail is dispatched synchronously (no async Messenger transport is configured),
 * which means a slow SMTP server slows the request that triggered it.
 */
final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private string $senderAddress,
        private string $senderName,
    ) {
    }

    public function sendPasswordReset(User $user, string $resetUrl, int $tokenLifetimeSeconds): void
    {
        $this->send($user, 'Reset your password', 'password_reset', [
            'resetUrl' => $resetUrl,
            'expiresInMinutes' => intdiv($tokenLifetimeSeconds, 60),
        ]);
    }

    public function sendEmailVerification(User $user, string $verificationUrl, \DateTimeInterface $expiresAt): void
    {
        $this->send($user, 'Confirm your email address', 'email_verification', [
            'verificationUrl' => $verificationUrl,
            'expiresAt' => $expiresAt,
        ]);
    }

    /**
     * The invitation a Super Admin's newly created trainer receives (FR-022, US-01.01).
     *
     * The temporary password travels in the message body. That is a deliberate, bounded
     * exposure: the credential is single-use in practice — `mustChangePassword` forces a
     * change at first login — and the alternative the spec offers ("OR sends invite email
     * with setup link") is the password-reset flow, which this account cannot use until it
     * has a password to reset. Q-01.04 has not answered which of the two the client wants;
     * switching to a setup link is a change to this method and its template, not to the
     * creation workflow.
     */
    public function sendTrainerInvitation(User $user, string $temporaryPassword, string $loginUrl, string $businessName): void
    {
        // The recipient is passed into the context as plain strings rather than as the
        // entity: the other templates in this directory take no `user` variable, and a
        // template that could reach through an entity is a template that can render a field
        // nobody meant to email.
        $this->send($user, 'Your trainer account is ready', 'trainer_invitation', [
            'name' => $user->getDisplayName(),
            // Not `email`: Symfony's BodyRenderer overwrites that key with its own
            // WrappedTemplatedEmail, so a context entry by that name never reaches the
            // template.
            'recipientEmail' => $user->getEmail(),
            'temporaryPassword' => $temporaryPassword,
            'loginUrl' => $loginUrl,
            'businessName' => $businessName,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(User $user, string $subject, string $template, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderAddress, $this->senderName))
            ->to(new Address($user->getEmail()))
            ->subject($subject)
            ->htmlTemplate(\sprintf('account/email/%s.html.twig', $template))
            ->textTemplate(\sprintf('account/email/%s.txt.twig', $template))
            ->context($context);

        $this->mailer->send($email);
    }
}
