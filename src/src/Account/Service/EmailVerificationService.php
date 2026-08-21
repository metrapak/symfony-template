<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Exception\VerificationLinkExpired;
use App\Account\Exception\VerificationLinkInvalid;
use App\Account\Mail\AccountMailer;
use App\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\Exception\ExpiredSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Owns email verification (FR-005) on top of a signed URL: the user id and email are
 * carried in the signature and expiry lives inside it, so there is no token table and
 * nothing to garbage-collect.
 *
 * Wraps the verify-email bundle so controllers never touch bundle internals.
 */
final readonly class EmailVerificationService
{
    public const VERIFY_ROUTE = 'account_verify_email';

    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private UserRepository $users,
        private AccountMailer $mailer,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function sendVerification(User $user): void
    {
        $components = $this->verifyEmailHelper->generateSignature(
            self::VERIFY_ROUTE,
            (string) $user->getId(),
            $user->getEmail(),
            // The id travels as a signed query parameter so the verify route can load the
            // user while anonymous. It is covered by the signature, so it cannot be swapped
            // for another account's id without invalidating the link.
            ['id' => $user->getId()],
        );

        $this->mailer->sendEmailVerification($user, $components->getSignedUrl(), $components->getExpiresAt());
    }

    /**
     * Marks the account verified.
     *
     * Idempotent by design: a signed URL stays valid until it expires even after it has been
     * used, so a second click — a mail client prefetching the link, a user pressing back —
     * must be a no-op rather than an error.
     *
     * @param string $signedUrl the absolute URL the user clicked, signature included
     *
     * @throws VerificationLinkInvalid
     * @throws VerificationLinkExpired
     */
    public function verify(string $signedUrl, User $user): void
    {
        try {
            // The bundle validates against a Request rather than a URL string; its
            // URL-string API is deprecated. Rebuilding a Request from the signed URL keeps
            // the HTTP request object out of this service's contract.
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
                Request::create($signedUrl),
                (string) $user->getId(),
                $user->getEmail(),
            );
        } catch (ExpiredSignatureException $e) {
            throw VerificationLinkExpired::create($e);
        } catch (VerifyEmailExceptionInterface $e) {
            // Every way the bundle can reject a link — bad signature, missing signature,
            // signature for a different address — implements this interface. Anything else
            // (the bundle's misconfiguration RuntimeException, a database failure, a bug
            // here) is deliberately left to propagate: reporting an outage to the user as
            // "this link is not valid" would send them off to request a new link forever
            // while the real fault stayed invisible in monitoring.
            throw VerificationLinkInvalid::create($e);
        }

        // Signature checked first, then the no-op: short-circuiting before validation would
        // let anyone confirm which accounts are already verified just by guessing an id.
        if ($user->isEmailVerified()) {
            return;
        }

        $user->markEmailVerified($this->clock->now());
        $user->setUpdatedAt($this->clock->now());

        $this->entityManager->flush();
    }

    /**
     * Re-issues a link for the given address.
     *
     * Returns silently for an unknown address and for one that is already verified, so the
     * caller can show one confirmation message either way. Without that, this endpoint would
     * be a registration oracle — and it has to stay reachable while anonymous, because a
     * user who cannot sign in until they verify is exactly who needs it.
     */
    public function resendFor(string $email): void
    {
        $user = $this->users->findOneByEmail($email);

        if (null === $user || $user->isEmailVerified()) {
            return;
        }

        $this->sendVerification($user);
    }
}
