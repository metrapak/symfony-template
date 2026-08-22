<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/**
 * Marker for every failure the Profile module raises deliberately.
 *
 * Controllers branch on these rather than on Doctrine or bundle-internal types, so the family
 * and branding screens keep their HTTP behaviour when the persistence details change. Mirrors
 * the convention TASK-003 established for `MembershipException`.
 */
interface ProfileException extends \Throwable
{
}
