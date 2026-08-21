<?php

declare(strict_types=1);

namespace App\Account\Exception;

/**
 * Marker for every failure the Account module raises deliberately.
 *
 * Controllers and exception listeners can branch on this instead of on bundle-internal
 * or Doctrine exception types, which keeps HTTP status mapping stable when the underlying
 * implementation changes.
 */
interface AccountException extends \Throwable
{
}
