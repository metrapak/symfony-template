<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit\ValueObject;

use App\Profile\ValueObject\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-060 — "phone format validated".
 *
 * The rule under test is deliberately shape-based rather than dialling-plan based, so these
 * cases pin down two things: that numbers written the way people from different countries
 * actually write them are accepted, and that normalization collapses spelling differences
 * without collapsing meaning.
 */
final class PhoneNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedNumbers(): iterable
    {
        yield 'plain digits' => ['5550101234', '5550101234'];
        yield 'us punctuation' => ['(555) 010-1234', '5550101234'];
        yield 'dot separators' => ['555.010.1234', '5550101234'];
        yield 'international' => ['+48 501 234 567', '+48501234567'];
        yield 'international with parens' => ['+1 (312) 555-0148', '+13125550148'];
        yield 'surrounding whitespace' => ['  +44 20 7946 0958  ', '+442079460958'];
        yield 'shortest allowed' => ['1234567', '1234567'];
        yield 'longest allowed' => ['+123456789012345', '+123456789012345'];
    }

    #[DataProvider('acceptedNumbers')]
    public function testAcceptedNumbersNormalizeToOneSpelling(string $raw, string $expected): void
    {
        $number = PhoneNumber::tryParse($raw);

        self::assertInstanceOf(PhoneNumber::class, $number);
        self::assertSame($expected, $number->value);
        self::assertSame($expected, (string) $number);
        self::assertTrue(PhoneNumber::isWellFormed($raw));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejectedNumbers(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'letters' => ['555-CALL-ME'];
        yield 'too few digits' => ['12345'];
        yield 'too many digits' => ['+1234567890123456'];
        yield 'sql injection attempt' => ["555' OR 1=1--"];
        yield 'plus in the middle' => ['555+0101234'];
        yield 'trailing plus' => ['5550101234+'];
    }

    #[DataProvider('rejectedNumbers')]
    public function testMalformedNumbersAreRejected(?string $raw): void
    {
        self::assertNull(PhoneNumber::tryParse($raw));
    }

    /**
     * The two spellings are one person in the directory; the `+` prefix is the one separator
     * that survives normalization because it is the only one carrying meaning.
     */
    public function testPunctuationIsLostButTheInternationalPrefixIsNot(): void
    {
        $national = PhoneNumber::tryParse('(555) 010-1234');
        $alsoNational = PhoneNumber::tryParse('555.010.1234');
        $international = PhoneNumber::tryParse('+5550101234');

        self::assertNotNull($national);
        self::assertNotNull($alsoNational);
        self::assertNotNull($international);

        self::assertSame($national->value, $alsoNational->value);
        self::assertNotSame($national->value, $international->value);
    }

    public function testIsWellFormedAgreesWithTryParse(): void
    {
        foreach (['5550101234', '+48501234567', '12345', 'nonsense', '555 010 1234'] as $candidate) {
            self::assertSame(
                PhoneNumber::isWellFormed($candidate),
                null !== PhoneNumber::tryParse($candidate),
                \sprintf('Disagreement on "%s".', $candidate),
            );
        }
    }
}
