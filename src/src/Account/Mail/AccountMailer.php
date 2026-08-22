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
