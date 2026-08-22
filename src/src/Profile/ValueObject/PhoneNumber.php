<?php

declare(strict_types=1);

namespace App\Profile\ValueObject;

/**
 * A contact telephone number as the platform stores it (FR-060, "phone format validated").
 *
 * The spec never names a country, and guessing one is how a valid number gets rejected: a
 * parent in Warsaw, a trainer in Chicago and a coach in Lagos write theirs three different
 * ways, and none of them is wrong. So this validates *shape* rather than dialling plan —
 * enough digits to be a number, no letters, no SQL — and normalizes presentation so the
 * directory does not hold `(555) 010-1234` and `555.010.1234` as two different people.
 *
 * Normalization keeps a leading `+` and discards every other separator. That is lossy on
 * purpose: the punctuation carries no information the platform uses, while `+` distinguishes
 * an international number from a national one and cannot be recovered once dropped.
 *
 * Immutable and validated on construction, so holding an instance is proof the string is
 * storable — which is what lets `ProfileUpdater` write it without re-checking.
 */
final readonly class PhoneNumber
{
    /**
     * E.164 allows 15 digits; 7 is the shortest number in use anywhere that is not a short
     * code. Short codes are not personal contact numbers, so refusing them is correct.
     */
    private const MIN_DIGITS = 7;
    private const MAX_DIGITS = 15;

    private function __construct(public string $value)
    {
    }

    /**
     * Parses a number that arrived from a form.
     *
     * Returns null for both "absent" and "malformed" because the caller treats them the same
     * way — the constraint on the DTO is what produces the message a user reads, and it is
     * checked before this runs.
     */
    public static function tryParse(?string $raw): ?self
    {
        if (null === $raw || '' === trim($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        if (!self::isWellFormed($trimmed)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        $prefix = str_starts_with($trimmed, '+') ? '+' : '';

        return new self($prefix . $digits);
    }

    public static function isWellFormed(string $candidate): bool
    {
        $trimmed = trim($candidate);

        // The `+` is only meaningful in front. Anywhere else it is a typo, and accepting it
        // would let two spellings of one number normalize to the same digits with different
        // meanings.
        if (1 !== preg_match('/^\+?[0-9 ().\-]+$/', $trimmed)) {
            return false;
        }

        $digitCount = \strlen(preg_replace('/\D+/', '', $trimmed) ?? '');

        return $digitCount >= self::MIN_DIGITS && $digitCount <= self::MAX_DIGITS;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
