<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Answers "is the current request being made by an impersonated identity, and if so, who is
 * behind it?" (G-18).
 *
 * The single reader of `SwitchUserToken` outside the security layer. Everything that needs to
 * know about impersonation — the audit logger, the banner, the expiry subscriber — asks here,
 * so the knowledge that Symfony models impersonation with a nested token does not spread.
 */
final readonly class ImpersonationContext
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function isImpersonating(): bool
    {
        return null !== $this->impersonator();
    }

    /**
     * The Super Admin behind the current identity, or null when nobody is impersonating.
     */
    public function impersonator(): ?User
    {
        $token = $this->security->getToken();

        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        $original = $token->getOriginalToken()->getUser();

        return $original instanceof User ? $original : null;
    }

    /**
     * The identity the request is being made as — the impersonated user during a switch, the
     * signed-in user otherwise.
     */
    public function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
