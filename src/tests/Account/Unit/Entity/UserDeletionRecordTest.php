<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Entity;

use App\Account\Entity\UserDeletionRecord;
use PHPUnit\Framework\TestCase;

/**
 * FR-027 as narrowed by G-16 — the compliance record holds a verification token, not an
 * address.
 */
final class UserDeletionRecordTest extends TestCase
{
    /**
     * The digest is derived from the normalized form, because that is the form the address
     * was stored in — otherwise a lookup by the address as the user typed it would miss.
     */
    public function testTheDigestIsIndependentOfCaseAndSurroundingWhitespace(): void
    {
        $canonical = UserDeletionRecord::digestFor('pat@example.com');

        self::assertSame($canonical, UserDeletionRecord::digestFor('PAT@Example.COM'));
        self::assertSame($canonical, UserDeletionRecord::digestFor('  pat@example.com  '));
    }

    public function testDifferentAddressesProduceDifferentDigests(): void
    {
        self::assertNotSame(
            UserDeletionRecord::digestFor('pat@example.com'),
            UserDeletionRecord::digestFor('sam@example.com'),
        );
    }

    /**
     * The point of the design: the record can confirm an address somebody already has, and
     * cannot be read back into one.
     */
    public function testTheDigestDoesNotContainTheAddress(): void
    {
        $digest = UserDeletionRecord::digestFor('pat@example.com');

        self::assertSame(64, \strlen($digest));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        self::assertStringNotContainsString('pat', $digest);
        self::assertStringNotContainsString('example', $digest);
    }
}
