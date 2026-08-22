<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/**
 * A write was attempted against a profile the acting account does not manage.
 *
 * This is the service-layer half of `ProfileVoter`, and the two are deliberately both present.
 * The voter is what the controller checks and what a template asks before rendering a button;
 * this is what holds when a service is called from somewhere that is not a controller — a
 * console command, a message handler, a future screen written in a hurry. FR-070's isolation
 * requirement is not something to enforce in exactly one place, and reaching a service with
 * another family's profile should fail loudly rather than write.
 *
 * Carries no names or ids in its message: it is rendered as a 403, and a message naming the
 * profile would confirm that profile exists to somebody who guessed its id.
 */
final class ProfileNotManaged extends \DomainException implements ProfileException
{
    public const MESSAGE = 'That profile does not belong to this account.';

    public static function create(): self
    {
        return new self(self::MESSAGE);
    }
}
