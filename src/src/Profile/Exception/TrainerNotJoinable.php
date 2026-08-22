<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/**
 * A ShareLink a parent pasted into the family page cannot be used (FR-066, "Option A").
 *
 * One exception for every reason, carrying one message, on purpose: FR-049's uniform-failure
 * rule applies here as much as it does on `/join`. Telling a parent apart "that code does not
 * exist" from "that code is exhausted" would turn the family page into the code oracle the
 * public flow deliberately is not — and this endpoint is authenticated, which makes it a
 * *better* place to enumerate from, not a worse one.
 *
 * The offending code is carried as a property rather than interpolated into the message, so it
 * reaches the log without reaching the parent.
 */
final class TrainerNotJoinable extends \DomainException implements ProfileException
{
    public const MESSAGE = 'That trainer link cannot be used. Check the code with your trainer and try again.';

    private function __construct(public readonly string $attemptedCode)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function code(string $code): self
    {
        return new self($code);
    }
}
