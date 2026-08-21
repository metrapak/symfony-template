<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses authentication for accounts that exist but may not sign in (FR-009, FR-005).
 *
 * Wired as the `main` firewall's `user_checker`, so it runs on every authentication —
 * form login as well as a session restored from a cookie. A controller-level check would
 * only cover the routes someone remembered to guard.
 */
final readonly class AccountStatusChecker implements UserCheckerInterface
{
    public const INACTIVE_MESSAGE = 'Account deactivated. Contact support.';
    public const DELETED_MESSAGE = 'This account no longer exists.';
    public const UNVERIFIED_MESSAGE = 'Please verify your email address before signing in. Check your inbox or request a new link.';

    public function __construct(
        private bool $emailVerificationRequired,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // FR-009 asks for distinct wording per status, which does confirm an account exists
        // to someone who already holds the password. Accepted trade-off, spec'd explicitly.
        match ($user->getStatus()) {
            UserStatus::Inactive => throw new CustomUserMessageAccountStatusException(self::INACTIVE_MESSAGE),
            UserStatus::Deleted => throw new CustomUserMessageAccountStatusException(self::DELETED_MESSAGE),
            UserStatus::Active => null,
        };
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$this->requiresVerifiedEmail($user)) {
            return;
        }

        if (!$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException(self::UNVERIFIED_MESSAGE);
        }
    }

    /**
     * Q-01.05 is unresolved: the client has not said whether verification must precede the
     * first login. The whole gate is switched by `EMAIL_VERIFICATION_REQUIRED`, and which
     * roles it covers is decided here — one place to change when the answer arrives.
     */
    private function requiresVerifiedEmail(User $user): bool
    {
        if (!$this->emailVerificationRequired) {
            return false;
        }

        return match ($user->getRole()) {
            UserRole::Player, UserRole::Coach => true,
            UserRole::Trainer, UserRole::SuperAdmin => false,
        };
    }
}
