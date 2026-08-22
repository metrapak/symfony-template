<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Exception\ResetTokenExpired;
use App\Account\Exception\ResetTokenInvalid;
use App\Account\Mail\AccountMailer;
use App\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ExpiredResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Owns the password reset workflow (FR-004) and the single decision that makes it safe:
 * a reset request never reveals whether an account exists.
 *
 * Wraps the reset-password bundle so controllers never touch bundle internals and bundle
 * exceptions never reach HTTP mapping.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private UserRepository $users,
        private PasswordChanger $passwordChanger,
        private AccountMailer $mailer,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Issues a reset link when the address belongs to an account that may sign in.
     *
     * Returns silently otherwise — an unknown address, a deactivated account and a throttled
     * repeat request must all be indistinguishable from success to the caller, so the
     * controller can show one confirmation message for every outcome (FR-004).
     */
    public function requestReset(string $email): void
    {
        $user = $this->users->findActiveByEmail($email);

        if (null === $user) {
            return;
        }

        try {
            $token = $this->resetPasswordHelper->generateResetToken($user);
        } catch (TooManyPasswordRequestsException) {
            // The user already has a live link in their inbox; sending another would only
            // help someone probing the throttle window.
            return;
        }

        // Dispatched after the bundle has persisted the request, and outside any transaction:
        // a mail failure must not roll back a token the user may already have received.
        $this->mailer->sendPasswordReset($user, $this->buildResetUrl($token), $this->resetPasswordHelper->getTokenLifetime());
    }

    /**
     * @throws ResetTokenInvalid
     * @throws ResetTokenExpired
     */
    public function validateToken(string $token): User
    {
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ExpiredResetPasswordTokenException $e) {
            throw ResetTokenExpired::create($e);
        } catch (ResetPasswordExceptionInterface $e) {
            // Only the bundle's own rejections are translated into "invalid link". A database
            // failure or a bug in here must not reach the user as a bad-token message, which
            // would hide a real fault behind a plausible user error.
            throw ResetTokenInvalid::create($e);
        }

        if (!$user instanceof User) {
            throw ResetTokenInvalid::create();
        }

        return $user;
    }

    /**
     * Consumes the token and writes the new password atomically. Either the token stops
     * working and the password changes, or neither happens — a partial failure must not
     * leave a spent token still spendable.
     *
     * @throws ResetTokenInvalid
     * @throws ResetTokenExpired
     */
    public function resetPassword(string $token, string $plainPassword): User
    {
        $user = $this->validateToken($token);

        try {
            return $this->entityManager->wrapInTransaction(function () use ($token, $user, $plainPassword): User {
                $this->resetPasswordHelper->removeResetRequest($token);
                $this->passwordChanger->change($user, $plainPassword);

                return $user;
            });
        } catch (ResetPasswordExceptionInterface $e) {
            // Consuming the token can still fail after validation — the same link submitted
            // twice at once, for instance. The transaction has rolled back, so mapping it
            // keeps the promise that no bundle exception reaches HTTP mapping.
            throw ResetTokenInvalid::create($e);
        }
    }

    /**
     * The bundle hands back only the token; turning it into a link is this module's concern.
     */
    private function buildResetUrl(ResetPasswordToken $token): string
    {
        return $this->urlGenerator->generate(
            'account_password_reset',
            ['token' => $token->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
