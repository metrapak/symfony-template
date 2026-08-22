<?php

declare(strict_types=1);

namespace App\Membership\Exception;

/**
 * Marker for every failure the Membership module raises deliberately.
 *
 * Controllers branch on these rather than on Doctrine or bundle-internal exception types, so
 * the redemption flow's HTTP behaviour stays stable when the persistence details change.
 */
interface MembershipException extends \Throwable
{
}
