<?php

declare(strict_types=1);

namespace App\Tests\Approval\Unit\ValueObject;

use App\Approval\Enum\PaymentType;
use App\Approval\Exception\CurrencyMismatch;
use App\Approval\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Money` (FR-098's amount, and the "never a float" rule behind it).
 *
 * The tests worth reading are the ones about scale: dollars have cents and tokens do not, and the
 * whole design rests on one column pair meaning both.
 */
final class MoneyTest extends TestCase
{
    public function testDollarsAreStoredInCentsAndFormattedBack(): void
    {
        $amount = Money::usd(4500);

        self::assertSame(4500, $amount->amountMinor);
        self::assertSame('USD', $amount->currency);
        self::assertSame('45.00', $amount->decimal());
        self::assertSame('$45.00', $amount->format());
        self::assertSame('45.00 US dollars', $amount->spokenLabel());
    }

    /**
     * The cent that a float would lose.
     */
    public function testTheLastCentSurvives(): void
    {
        self::assertSame('45.10', Money::usd(4510)->decimal());
        self::assertSame('0.05', Money::usd(5)->decimal());
        self::assertSame('$0.00', Money::usd(0)->format());
    }

    public function testTokensAreWholeAndReadAsTokens(): void
    {
        self::assertSame('12 tokens', Money::tokens(12)->format());
        self::assertSame('1 token', Money::tokens(1)->format());
        self::assertSame('12', Money::tokens(12)->decimal());
        self::assertSame(0, Money::scaleOf(Money::TOKEN_CURRENCY));
    }

    public function testAnUnknownCurrencyFallsBackToTwoDecimalsAndItsCode(): void
    {
        self::assertSame('12.34 EUR', Money::of(1234, 'EUR')->format());
    }

    public function testTheCurrencyIsNormalizedToUpperCase(): void
    {
        self::assertSame('USD', Money::of(100, 'usd')->currency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCurrencies(): iterable
    {
        yield 'too short' => ['US'];
        yield 'too long' => ['USDD'];
        yield 'digits' => ['US1'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedCurrencies')]
    public function testAMalformedCurrencyIsRejected(string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(100, $currency);
    }

    /**
     * A refund is Epic-05's, and a negative purchase would silently mean "pay the child".
     */
    public function testANegativeAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::usd(-1);
    }

    public function testAmountsOfTheSameCurrencyAddUp(): void
    {
        $total = Money::usd(4500)->plus(Money::usd(2000));

        self::assertTrue($total->equals(Money::usd(6500)));
        self::assertSame('$65.00', $total->format());
    }

    public function testAddingIsImmutable(): void
    {
        $original = Money::usd(4500);
        $original->plus(Money::usd(1));

        self::assertSame(4500, $original->amountMinor);
    }

    /**
     * Twelve tokens plus forty-five dollars is not a number, and this application has no rate to
     * make it one — which is why the parent's pending list totals each currency separately.
     */
    public function testAddingDifferentCurrenciesIsRefused(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::usd(4500)->plus(Money::tokens(12));
    }

    public function testZeroIsRepresentable(): void
    {
        self::assertTrue(Money::zero('USD')->isZero());
        self::assertFalse(Money::usd(1)->isZero());
    }

    public function testEqualityConsidersTheCurrency(): void
    {
        self::assertFalse(Money::usd(12)->equals(Money::tokens(12)));
        self::assertTrue(Money::tokens(12)->equals(Money::tokens(12)));
    }

    public function testEachPaymentTypeHasItsCurrency(): void
    {
        self::assertSame('USD', Money::currencyFor(PaymentType::Usd));
        self::assertSame('TOK', Money::currencyFor(PaymentType::Token));
    }
}
