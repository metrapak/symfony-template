<?php

declare(strict_types=1);

namespace App\Approval\ValueObject;

use App\Approval\Enum\PaymentType;
use App\Approval\Exception\CurrencyMismatch;

/**
 * An amount of money, in whole minor units, with the currency it is denominated in.
 *
 * **Integer minor units, never a float.** `0.1 + 0.2` is not `0.3` in binary floating point, and
 * a purchase approval is the last place in an application where an amount should be approximate:
 * a parent approves a number they read on a screen, and the number that reaches the processor has
 * to be that number. Storing cents as an integer makes that exact by construction rather than by
 * rounding discipline at every call site.
 *
 * **Tokens are a currency here, and that is a decision worth reading.** The alternative was to
 * store dollars in one column pair and a token count in another, and let every query and every
 * template know which one applies. Instead `TOK` is a currency whose minor unit *is* its major
 * unit — `scaleOf()` gives it a scale of zero, so twelve tokens is stored as `12` and forty-five
 * dollars as `4500`. One column pair, one type, one formatter, and `PaymentType` stays what it
 * is: how the purchase is paid for, not what the number means. Epic-05 owns real token balances
 * (G-35); this only describes an amount.
 *
 * Zero is representable and negative is not. A refund is a movement in the other direction and
 * belongs to Epic-05's ledger, not to a purchase amount; a negative purchase would silently mean
 * "pay the child", which no screen in this workflow intends. Whether a *zero* purchase may be
 * submitted is the checkout form's decision, not this type's.
 */
final readonly class Money
{
    /**
     * The currency code a token amount carries.
     *
     * Not an ISO 4217 code, and it cannot be: tokens are this platform's own unit. `TOK` is in
     * the private-use range that ISO 4217 leaves to exactly this purpose (`X`-prefixed codes are
     * the standard's own; `TOK` is ours), and it is three upper-case letters so it satisfies the
     * same column and the same validation as every real currency.
     */
    public const TOKEN_CURRENCY = 'TOK';

    public const USD = 'USD';

    private function __construct(
        public int $amountMinor,
        public string $currency,
    ) {
    }

    /**
     * @param int $amountMinor cents for USD, whole tokens for TOK
     *
     * @throws \InvalidArgumentException on a negative amount or a malformed currency code
     */
    public static function of(int $amountMinor, string $currency): self
    {
        $currency = mb_strtoupper(trim($currency));

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a three-letter currency code.', $currency));
        }

        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('An amount cannot be negative.');
        }

        return new self($amountMinor, $currency);
    }

    public static function usd(int $cents): self
    {
        return self::of($cents, self::USD);
    }

    public static function tokens(int $tokens): self
    {
        return self::of($tokens, self::TOKEN_CURRENCY);
    }

    /**
     * The zero of a currency — what a total starts from before anything is added to it.
     */
    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    /**
     * The currency a purchase of this kind is denominated in.
     *
     * Here rather than on `PaymentType` so that the enum stays free of money concerns, and so
     * there is one place to change when Epic-05 introduces a second real currency.
     */
    public static function currencyFor(PaymentType $type): string
    {
        return match ($type) {
            PaymentType::Usd => self::USD,
            PaymentType::Token => self::TOKEN_CURRENCY,
        };
    }

    /**
     * @throws CurrencyMismatch when the two amounts are in different currencies
     */
    public function plus(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return 0 === $this->amountMinor;
    }

    /**
     * What a sighted reader sees: `$45.00`, `12 tokens`, `45.00 EUR`.
     */
    public function format(): string
    {
        return match ($this->currency) {
            self::USD => '$' . $this->decimal(),
            self::TOKEN_CURRENCY => \sprintf('%d %s', $this->amountMinor, 1 === $this->amountMinor ? 'token' : 'tokens'),
            default => $this->decimal() . ' ' . $this->currency,
        };
    }

    /**
     * What a screen reader should say (NFR-094).
     *
     * `$45.00` is announced as "dollar sign forty five point zero zero" by some screen readers
     * and as "forty-five dollars" by others, and the requirement asks for amounts that "read
     * correctly" rather than for amounts that happen to. Templates render `format()` visually
     * with `aria-hidden` and this beside it in a visually-hidden span, so both readers get the
     * amount and neither gets it twice.
     */
    public function spokenLabel(): string
    {
        return match ($this->currency) {
            self::USD => \sprintf('%s US dollars', $this->decimal()),
            self::TOKEN_CURRENCY => \sprintf('%d %s', $this->amountMinor, 1 === $this->amountMinor ? 'token' : 'tokens'),
            default => \sprintf('%s %s', $this->decimal(), $this->currency),
        };
    }

    /**
     * The amount as a decimal string in major units — `4500` cents becomes `45.00`.
     *
     * String arithmetic on the integer rather than a division, so nothing here ever produces a
     * float that could round the last cent away.
     */
    public function decimal(): string
    {
        $scale = self::scaleOf($this->currency);

        if (0 === $scale) {
            return (string) $this->amountMinor;
        }

        $units = intdiv($this->amountMinor, 10 ** $scale);
        $fraction = $this->amountMinor % (10 ** $scale);

        return \sprintf('%d.%0' . $scale . 'd', $units, $fraction);
    }

    /**
     * How many minor units make one major unit, as a power of ten.
     *
     * Tokens are whole and indivisible; everything else is assumed to have cents, which is true
     * of USD and of every currency this application is likely to meet before Epic-05 makes the
     * question a real one.
     */
    public static function scaleOf(string $currency): int
    {
        return self::TOKEN_CURRENCY === $currency ? 0 : 2;
    }
}
