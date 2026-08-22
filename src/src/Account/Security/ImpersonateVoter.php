<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether one account may be viewed as another (FR-028, FR-030, BR-021).
 *
 * A voter rather than an `access_control` rule because the decision is about a specific
 * target object, not about a URL prefix.
 *
 * **This is not the only place the rule is enforced.** `SwitchUserListener` answers any
 * request carrying `?_switch_user=`, so a check that lived only in the controller could be
 * skipped by typing a URL. `SwitchUserAuditSubscriber` applies the same rules on the security
 * event; this voter is what lets the directory decide whether to render the button and what
 * gives the controller a clean 403.
 */
final class ImpersonateVoter extends Voter
{
    public const IMPERSONATE = 'ACCOUNT_IMPERSONATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::IMPERSONATE === $attribute && $subject instanceof User;
    }

    /**
     * @param User $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actor = $token->getUser();

        if (!$actor instanceof User) {
            return false;
        }

        // Already switched: the identity in the token is the impersonated user, so granting
        // here would let a chain of switches start from someone else's account.
        if ($token instanceof SwitchUserToken) {
            return false;
        }

        if (UserRole::SuperAdmin !== $actor->getRole()) {
            return false;
        }

        // FR-030 / BR-021. Also covers the operator's own row, which is a Super Admin.
        if (UserRole::SuperAdmin === $subject->getRole()) {
            return false;
        }

        // An anonymized account has no data left to troubleshoot and no identity to borrow.
        // Inactive accounts stay impersonable on purpose: "why can I not get in" is one of
        // the support questions this feature exists to answer.
        return UserStatus::Deleted !== $subject->getStatus();
    }
}
