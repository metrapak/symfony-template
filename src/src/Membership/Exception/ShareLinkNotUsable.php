<?php

declare(strict_types=1);

namespace App\Membership\Exception;

/**
 * Raised when a redemption is attempted against a link that cannot be used (FR-049).
 *
 * Reaching this from the web flow means the link lapsed between the page being rendered and
 * the form being submitted — the controller resolves the code before showing anything — or
 * that somebody replayed a POST. Both get the same response as an unknown code.
 */
final class ShareLinkNotUsable extends \DomainException implements MembershipException
{
    public static function code(string $code): self
    {
        return new self(\sprintf('Share link "%s" can no longer be used.', $code));
    }
}
