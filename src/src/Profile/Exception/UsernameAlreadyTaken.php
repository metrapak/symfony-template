<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/**
 * The username a parent chose for their child's login is already in use (FR-067, G-23).
 *
 * Usernames are global rather than per-family, because they are a login identifier and the
 * firewall resolves one without knowing which family is asking. That does leak the existence
 * of a username to anybody who tries one — the same disclosure any registration form with a
 * unique field makes — and it is accepted here for the same reason: the alternative is a
 * parent who cannot tell why their child's login will not save.
 */
final class UsernameAlreadyTaken extends \DomainException implements ProfileException
{
    public static function forUsername(string $username, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('The username "%s" is already taken. Choose another one.', $username),
            previous: $previous,
        );
    }
}
