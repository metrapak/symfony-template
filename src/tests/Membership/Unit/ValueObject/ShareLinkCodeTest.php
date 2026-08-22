<?php

declare(strict_types=1);

namespace App\Tests\Membership\Unit\ValueObject;

use App\Membership\ValueObject\ShareLinkCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-049 — codes must be unguessable, non-sequential and URL-safe.
 */
final class ShareLinkCodeTest extends TestCase
{
    public function testGeneratedCodesAreWellFormedAndFixedLength(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $code = ShareLinkCode::generate();

            self::assertSame(26, \strlen($code->value));
            self::assertTrue(ShareLinkCode::isWellFormed($code->value));
        }
    }

    /**
     * Not a proof of randomness — that comes from `random_bytes` — but it does catch the
     * failure that matters: a generator that returns a constant, a counter, or something
     * derived from the clock.
     */
    public function testGeneratedCodesDoNotRepeat(): void
    {
        $codes = [];

        for ($i = 0; $i < 500; ++$i) {
            $codes[] = ShareLinkCode::generate()->value;
        }

        self::assertCount(500, array_unique($codes));
    }

    public function testTheAlphabetExcludesAmbiguousLetters(): void
    {
        $seen = '';

        for ($i = 0; $i < 200; ++$i) {
            $seen .= ShareLinkCode::generate()->value;
        }

        // I, L, O and U are excluded so a code read from a screenshot or over the phone does
        // not turn into a different code.
        foreach (['I', 'L', 'O', 'U'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $seen);
        }
    }

    public function testParsingIsCaseInsensitiveAndTrims(): void
    {
        $code = ShareLinkCode::generate();

        $parsed = ShareLinkCode::tryParse('  ' . mb_strtolower($code->value) . ' ');

        self::assertNotNull($parsed);
        self::assertTrue($parsed->equals($code));
    }

    #[DataProvider('malformedCodes')]
    public function testMalformedCodesAreRejectedWithoutThrowing(string $raw): void
    {
        self::assertNull(ShareLinkCode::tryParse($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['AAAA1111BBBB2222CCCC3333D'];
        yield 'too long' => ['AAAA1111BBBB2222CCCC3333DDD'];
        yield 'ambiguous letter' => ['AAAA1111BBBB2222CCCC3333DI'];
        yield 'punctuation' => ['AAAA1111BBBB2222CCCC3333D-'];
        yield 'sql fragment' => ["' OR 1=1 --                "];
        yield 'path traversal' => ['../../../../etc/passwd    '];
    }
}
