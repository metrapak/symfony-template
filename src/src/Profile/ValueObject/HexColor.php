<?php

declare(strict_types=1);

namespace App\Profile\ValueObject;

/**
 * A trainer's primary brand colour (FR-072, NFR-065).
 *
 * The colour is chosen by a customer and rendered behind our text, which makes it the one
 * piece of branding that can break the product for everyone in an organization. NFR-065 says
 * a chosen colour "must not break text contrast requirements — validate or constrain the
 * palette", and this class does the validating half: it can say what contrast a colour has
 * against white and against black, and therefore which foreground the layout must pair with
 * it.
 *
 * That is deliberately *not* the same as rejecting dark or light colours. A trainer whose
 * brand is `#FFE400` is not making a mistake; pairing it with white text would be. So the
 * accessible-foreground decision is computed here and applied by the layout as a CSS custom
 * property, and only a colour that fails against *both* black and white would be refused — no
 * sRGB colour does, the worst case being the mid grey `#757575` at 4.61:1 against black.
 * `hasAccessibleForeground()` states that rule instead of assuming it.
 *
 * Immutable and validated on construction. The value is normalized to `#rrggbb` lowercase so
 * the column holds one spelling and a Twig comparison against the default is a string compare.
 */
final readonly class HexColor
{
    /**
     * WCAG 2.1 AA for normal-size text. Large text is allowed 3:1, but branding accents are
     * used behind labels and buttons as well as headings, so the stricter figure is the one
     * that keeps every use of the colour compliant.
     */
    public const AA_CONTRAST = 4.5;

    private function __construct(public string $value)
    {
    }

    /**
     * Parses `#abc`, `abc`, `#aabbcc` or `AABBCC`.
     *
     * Three-digit shorthand is expanded rather than refused: it is what a designer hands over
     * and what a colour picker's text field accepts, and `#f00` and `#ff0000` are the same
     * colour — storing both spellings would only make them look like two brands.
     */
    public static function tryParse(?string $raw): ?self
    {
        if (null === $raw) {
            return null;
        }

        $candidate = mb_strtolower(ltrim(trim($raw), '#'));

        if (1 === preg_match('/^[0-9a-f]{3}$/', $candidate)) {
            $candidate = $candidate[0] . $candidate[0] . $candidate[1] . $candidate[1] . $candidate[2] . $candidate[2];
        }

        if (1 !== preg_match('/^[0-9a-f]{6}$/', $candidate)) {
            return null;
        }

        return new self('#' . $candidate);
    }

    public static function isWellFormed(string $candidate): bool
    {
        return null !== self::tryParse($candidate);
    }

    /**
     * @return array{int, int, int}
     */
    public function rgb(): array
    {
        $hex = ltrim($this->value, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Relative luminance per WCAG 2.1's definition, not a naive channel average.
     *
     * The gamma expansion matters: sRGB values are perceptually encoded, so averaging them
     * reports `#0000ff` and `#ffff00` as similarly bright when one is nearly black to the eye
     * and the other nearly white. Every contrast figure below depends on getting this right.
     */
    public function relativeLuminance(): float
    {
        $channels = array_map(static function (int $value): float {
            $sRgb = $value / 255;

            return $sRgb <= 0.04045 ? $sRgb / 12.92 : (($sRgb + 0.055) / 1.055) ** 2.4;
        }, $this->rgb());

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public function contrastAgainst(self $other): float
    {
        $lighter = max($this->relativeLuminance(), $other->relativeLuminance());
        $darker = min($this->relativeLuminance(), $other->relativeLuminance());

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public function contrastAgainstWhite(): float
    {
        return $this->contrastAgainst(self::white());
    }

    public function contrastAgainstBlack(): float
    {
        return $this->contrastAgainst(self::black());
    }

    /**
     * The text colour the layout must use on top of this one.
     *
     * Returned rather than assumed, because "dark text on light brand" is only right half the
     * time and the layout has no business re-deriving it.
     */
    public function accessibleForeground(): self
    {
        return $this->contrastAgainstBlack() >= $this->contrastAgainstWhite() ? self::black() : self::white();
    }

    /**
     * Whether *some* foreground reaches AA on this colour.
     *
     * In practice this is true for every sRGB colour: the worst case is the mid grey `#757575`,
     * which still reaches 4.61:1 against black — above the 4.5 threshold. So this guard refuses
     * nothing today, and that is the point rather than an oversight. NFR-065 is satisfied by
     * *pairing* each brand colour with the foreground `accessibleForeground()` computes, not by
     * constraining the palette, and this method is what states that as a checked rule instead
     * of an assumption. It earns its keep if the threshold is ever raised to AAA's 7:1, where
     * a wide band of colours does fail and the caller must be told.
     */
    public function hasAccessibleForeground(): bool
    {
        return max($this->contrastAgainstBlack(), $this->contrastAgainstWhite()) >= self::AA_CONTRAST;
    }

    public static function white(): self
    {
        return new self('#ffffff');
    }

    public static function black(): self
    {
        return new self('#000000');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
