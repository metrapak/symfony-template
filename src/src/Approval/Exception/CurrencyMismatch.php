<?php

declare(strict_types=1);

namespace App\Approval\Exception;

/**
 * Two `Money` values of different currencies were combined.
 *
 * Refused rather than converted: this application has no exchange rate and no business reason to
 * invent one, and twelve tokens plus forty-five dollars is not a number. The parent's pending
 * list therefore totals each currency separately, which is also the only honest way to show it.
 */
final class CurrencyMismatch extends \DomainException implements ApprovalException
{
    public static function between(string $left, string $right): self
    {
        return new self(\sprintf('Cannot combine %s with %s: they are different currencies.', $left, $right));
    }
}
