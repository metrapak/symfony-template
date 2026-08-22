<?php

declare(strict_types=1);

namespace App\Account\Service;

/**
 * Produces the one-time credential a new trainer receives (FR-022).
 *
 * Two properties matter and both are enforced by construction rather than by retrying until
 * a random string happens to qualify:
 *
 * 1. **Unguessable.** Every character comes from `random_int()`, which is cryptographically
 *    secure. `rand()`, `uniqid()` and `str_shuffle()` are not, and a predictable temporary
 *    password is a full account takeover on an account that has not been used yet.
 * 2. **Always acceptable to `PasswordRequirements`.** One character of each required class is
 *    placed first, then the rest is filled and the whole thing shuffled with the same secure
 *    source. A generator that could emit a password the forced-change form then rejects would
 *    strand the trainer at their first login with no way forward.
 *
 * The alphabet omits characters that are read back wrongly over the phone or from a printout
 * (0/O, 1/l/I), because this credential is frequently transcribed by hand.
 */
final readonly class TemporaryPasswordGenerator
{
    private const UPPERCASE = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    private const LOWERCASE = 'abcdefghijkmnpqrstuvwxyz';
    private const DIGITS = '23456789';

    public const LENGTH = 16;

    public function generate(): string
    {
        $alphabet = self::UPPERCASE . self::LOWERCASE . self::DIGITS;

        $characters = [
            self::pick(self::UPPERCASE),
            self::pick(self::LOWERCASE),
            self::pick(self::DIGITS),
        ];

        for ($i = \count($characters); $i < self::LENGTH; ++$i) {
            $characters[] = self::pick($alphabet);
        }

        // Fisher-Yates driven by random_int(), not str_shuffle(): str_shuffle() uses the
        // non-cryptographic generator, which would undo the point of the loop above.
        for ($i = \count($characters) - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, \strlen($alphabet) - 1)];
    }
}
