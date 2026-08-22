<?php

declare(strict_types=1);

namespace App\Profile\Exception;

use App\Profile\ValueObject\BirthDate;

/**
 * A child profile was given an age outside 1-18 (FR-063, BR-068).
 *
 * The form's constraint is what a parent normally meets first; this is the service refusing
 * the same thing, so a request that skipped the form is refused too. Both exist deliberately —
 * BR-068 is a business rule, and a rule enforced only by a `<input min>` is not enforced.
 *
 * **This is not what happens when an existing child turns 19** (G-22). The bound applies to
 * the age being *entered*, not to a profile the clock moved past it: nobody's account is
 * broken by a birthday. What should happen at 19 — conversion to an independent account,
 * continued parent management, or a blocked state — is unspecified, and until it is specified
 * an aged-out profile keeps working exactly as it did the day before.
 */
final class ChildAgeOutOfRange extends \DomainException implements ProfileException
{
    public static function forAge(int $age): self
    {
        return new self(\sprintf(
            'A child profile must be between %d and %d years old; %d was given. An adult should use their own account.',
            BirthDate::MIN_CHILD_AGE,
            BirthDate::MAX_CHILD_AGE,
            $age,
        ));
    }
}
