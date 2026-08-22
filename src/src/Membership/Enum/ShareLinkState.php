<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * The answer `ShareLinkResolver` gives about a code taken from a URL.
 *
 * There are deliberately only three values for four situations. Unknown, deactivated and
 * fully-consumed codes all collapse into `Unusable` (FR-049): a visitor who can tell a
 * deactivated code from an invented one can enumerate which codes exist, and `/join/{code}`
 * is the one unauthenticated, account-creating endpoint in the application.
 *
 * `Expired` stays separate because FR-046 requires the holder of a lapsed coach invitation to
 * be told why it does not work and offered a resend. That is a deliberate, bounded
 * disclosure: it reveals that a code was once valid, to somebody who already had it.
 */
enum ShareLinkState: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Unusable = 'unusable';
}
