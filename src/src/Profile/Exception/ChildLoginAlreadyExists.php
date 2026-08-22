<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/**
 * A parent tried to create a second login for a child that already has one (FR-067).
 *
 * Refused rather than replaced. Repointing the profile at a new account would leave the old
 * one signed in, holding the child's history and reachable by whoever knows its password,
 * while no longer being visible to the family it belongs to. Changing the password is the
 * operation the parent actually wants, and it is a different button.
 */
final class ChildLoginAlreadyExists extends \DomainException implements ProfileException
{
    public static function forChild(string $childName): self
    {
        return new self(\sprintf('%s already has a login. Reset their password instead.', $childName));
    }
}
