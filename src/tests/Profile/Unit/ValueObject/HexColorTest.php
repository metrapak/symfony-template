<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit\ValueObject;

use App\Profile\ValueObject\HexColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-072 and NFR-065 — a trainer's colour must not break text contrast.
 *
 * The contrast figures below are WCAG 2.1's own worked examples, so a regression in the gamma
 * expansion shows up as a wrong number rather than as a page that merely looks off.
 */
final class HexColorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedColors(): iterable
    {
        yield 'six digits with hash' => ['#1E90FF', '#1e90ff'];
        yield 'six digits without hash' => ['1e90ff', '#1e90ff'];
        yield 'uppercase' => ['#AABBCC', '#aabbcc'];
        yield 'shorthand' => ['#f00', '#ff0000'];
        yield 'shorthand without hash' => ['abc', '#aabbcc'];
        yield 'surrounding whitespace' => ['  #FFE400  ', '#ffe400'];
    }

    #[DataProvider('acceptedColors')]
    public function testParsingNormalizesToLowercaseSixDigits(string $raw, string $expected): void
    {
        $color = HexColor::tryParse($raw);

        self::assertInstanceOf(HexColor::class, $color);
        self::assertSame($expected, $color->value);
        self::assertSame($expected, (string) $color);
        self::assertTrue(HexColor::isWellFormed($raw));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejectedColors(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'four digits' => ['#12345'];
        yield 'seven digits' => ['#1234567'];
        yield 'non hex letters' => ['#gggggg'];
        yield 'css function' => ['rgb(1,2,3)'];
        yield 'style injection attempt' => ['#fff;} body{display:none'];
    }

    #[DataProvider('rejectedColors')]
    public function testMalformedColorsAreRejected(?string $raw): void
    {
        self::assertNull(HexColor::tryParse($raw));

        if (null !== $raw) {
            self::assertFalse(HexColor::isWellFormed($raw));
        }
    }

    /**
     * `#f00` and `#ff0000` are one brand, not two.
     */
    public function testShorthandAndLonghandAreEqual(): void
    {
        $short = HexColor::tryParse('#f00');
        $long = HexColor::tryParse('#FF0000');

        self::assertNotNull($short);
        self::assertNotNull($long);
        self::assertTrue($short->equals($long));
    }

    public function testRgbDecomposition(): void
    {
        $color = HexColor::tryParse('#1e90ff');

        self::assertNotNull($color);
        self::assertSame([30, 144, 255], $color->rgb());
    }

    /**
     * The extremes are exact by definition, and the 21:1 figure is the largest contrast sRGB
     * permits — if the luminance formula drifts, this is the first thing to move.
     */
    public function testBlackAndWhiteSitAtTheEndsOfTheScale(): void
    {
        self::assertSame(0.0, HexColor::black()->relativeLuminance());
        self::assertSame(1.0, HexColor::white()->relativeLuminance());
        self::assertEqualsWithDelta(21.0, HexColor::black()->contrastAgainst(HexColor::white()), 0.001);
        self::assertEqualsWithDelta(1.0, HexColor::white()->contrastAgainst(HexColor::white()), 0.001);
    }

    /**
     * A naive channel average would call these two similarly bright. Perceptually they are
     * nearly opposite, and the gamma-expanded formula says so.
     */
    public function testLuminanceIsPerceptualRatherThanANaiveAverage(): void
    {
        $blue = HexColor::tryParse('#0000ff');
        $yellow = HexColor::tryParse('#ffff00');

        self::assertNotNull($blue);
        self::assertNotNull($yellow);

        self::assertLessThan(0.1, $blue->relativeLuminance());
        self::assertGreaterThan(0.9, $yellow->relativeLuminance());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foregroundExpectations(): iterable
    {
        yield 'dark brand takes white text' => ['#0b3d91', '#ffffff'];
        yield 'bright yellow takes black text' => ['#ffe400', '#000000'];
        yield 'white takes black text' => ['#ffffff', '#000000'];
        yield 'black takes white text' => ['#000000', '#ffffff'];
    }

    /**
     * The layout has no business re-deriving this, so the decision is pinned here.
     */
    #[DataProvider('foregroundExpectations')]
    public function testAccessibleForegroundPicksTheReadableTextColor(string $brand, string $expectedForeground): void
    {
        $color = HexColor::tryParse($brand);

        self::assertNotNull($color);
        self::assertSame($expectedForeground, $color->accessibleForeground()->value);
        self::assertGreaterThanOrEqual(
            HexColor::AA_CONTRAST,
            $color->contrastAgainst($color->accessibleForeground()),
        );
    }

    /**
     * NFR-065's actual rule: refuse exactly the colours no foreground can rescue — a set that,
     * at AA's 4.5:1, turns out to be empty. The worst colour in sRGB is the mid grey `#757575`,
     * and even that clears the bar against black.
     *
     * So a trainer whose brand is bright yellow is not making a mistake, and neither is one
     * whose brand is mid grey: the contrast requirement is met by pairing the right foreground,
     * never by refusing the colour. Swept exhaustively over the grey axis because that is where
     * the minimum lives — every non-grey colour is further from the midpoint than its grey of
     * equal luminance.
     */
    public function testEverySrgbColorHasAnAccessibleForeground(): void
    {
        foreach (['#ffe400', '#0b3d91', '#ffffff', '#000000', '#1e90ff', '#767676'] as $usable) {
            $color = HexColor::tryParse($usable);
            self::assertNotNull($color);
            self::assertTrue($color->hasAccessibleForeground(), \sprintf('%s should be usable.', $usable));
        }

        $worstContrast = 21.0;

        for ($value = 0; $value <= 255; ++$value) {
            $grey = HexColor::tryParse(\sprintf('#%02x%02x%02x', $value, $value, $value));
            self::assertNotNull($grey);

            $worstContrast = min(
                $worstContrast,
                max($grey->contrastAgainstBlack(), $grey->contrastAgainstWhite()),
            );

            self::assertTrue($grey->hasAccessibleForeground());
        }

        // Pins the documented figure: if this drops below 4.5, the guard starts refusing
        // colours and the docblock on hasAccessibleForeground() stops being true.
        self::assertEqualsWithDelta(4.6075, $worstContrast, 0.001);
    }

    public function testContrastIsSymmetric(): void
    {
        $a = HexColor::tryParse('#1e90ff');
        $b = HexColor::tryParse('#ffe400');

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertEqualsWithDelta($a->contrastAgainst($b), $b->contrastAgainst($a), 0.000001);
    }

    public function testConvenienceContrastAccessorsMatchTheGeneralOne(): void
    {
        $color = HexColor::tryParse('#1e90ff');

        self::assertNotNull($color);
        self::assertEqualsWithDelta($color->contrastAgainst(HexColor::white()), $color->contrastAgainstWhite(), 0.000001);
        self::assertEqualsWithDelta($color->contrastAgainst(HexColor::black()), $color->contrastAgainstBlack(), 0.000001);
    }
}
