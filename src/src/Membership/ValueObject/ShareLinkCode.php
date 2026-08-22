<?php

declare(strict_types=1);

namespace App\Membership\ValueObject;

/**
 * The random string in `/join/{code}` (FR-049).
 *
 * Three properties matter, and each is enforced here rather than at a call site:
 *
 *  - **Unguessable.** 16 bytes from `random_bytes()` — the CSPRNG, never `rand()`, `uniqid()`
 *    or an id — give 128 bits of entropy. Enumerating a namespace that size is not a rate
 *    limit problem, it is arithmetic; the limiter on `/join` exists for the noisy attacker,
 *    not for this.
 *  - **Not sequential.** Nothing about the code derives from the row, the organization or the
 *    clock, so possessing one code says nothing about any other.
 *  - **URL-safe.** Base32 (Crockford's alphabet minus the ambiguous letters) survives being
 *    read aloud, typed from a WhatsApp screenshot, and copied by a mail client that would
 *    have mangled the `+` and `/` of base64. It is also case-insensitive by construction, so
 *    a link retyped in caps still resolves.
 *
 * Immutable and validated on construction: an instance is proof the string is well formed,
 * which is what lets the repository reject a malformed code before touching the database.
 */
final readonly class ShareLinkCode
{
    /**
     * No I, L, O or U: the first three are misread as 1 and 0, and excluding U keeps the
     * generator from spelling short obscenities.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 26;

    private const ENTROPY_BYTES = 16;

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        $bytes = random_bytes(self::ENTROPY_BYTES);
        $code = '';

        // Base32 by hand: 16 bytes is 128 bits, which is 25.6 base32 characters. Reading five
        // bits at a time over a big-endian bit string and padding the tail to 26 characters
        // keeps every generated code the same length, so the column can be fixed width and
        // the format check below is a single regular expression.
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $bits = str_pad($bits, self::LENGTH * 5, '0', STR_PAD_RIGHT);

        for ($offset = 0; $offset < self::LENGTH * 5; $offset += 5) {
            $code .= self::ALPHABET[bindec(substr($bits, $offset, 5))];
        }

        return new self($code);
    }

    /**
     * Parses a code that arrived from outside — a URL, a form, a console argument.
     *
     * Returns null rather than throwing: a malformed code is the ordinary case for this
     * endpoint (a truncated paste, a crawler, somebody guessing), and the caller renders the
     * same page for it as for a code that simply does not exist.
     */
    public static function tryParse(string $raw): ?self
    {
        $normalized = mb_strtoupper(trim($raw));

        if (!self::isWellFormed($normalized)) {
            return null;
        }

        return new self($normalized);
    }

    public static function isWellFormed(string $candidate): bool
    {
        return 1 === preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $candidate);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
