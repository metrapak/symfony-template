<?php

declare(strict_types=1);

namespace App\Membership\Exception;

/**
 * A submitted family-member selection named a profile the account does not manage (FR-044).
 *
 * The checklist is rendered from the account's own profiles, so this is not a mistake a form
 * can make — it is a tampered id, and it is refused rather than silently filtered so the
 * attempt is visible instead of looking like a successful partial association.
 */
final class InvalidFamilySelection extends \DomainException implements MembershipException
{
    public static function unknownProfile(int $profileId): self
    {
        return new self(\sprintf('Profile %d is not part of this account\'s family.', $profileId));
    }

    public static function empty(): self
    {
        return new self('Select at least one family member.');
    }
}
