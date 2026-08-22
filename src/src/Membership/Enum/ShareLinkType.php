<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * The two kinds of invitation link a trainer can hand out (BR-040, BR-041).
 *
 * The distinction is not cosmetic: it decides the default use limit and expiry a
 * ShareLinkGenerator applies, and which redemption flow `/join/{code}` runs. A link never
 * changes type, so the flow a code resolves to is fixed the moment it is created.
 */
enum ShareLinkType: string
{
    /** Static: unlimited uses, no expiry, handed to a whole squad at once. */
    case Player = 'player';

    /** Unique: one use, seven days, addressed to a single coach's email. */
    case Coach = 'coach';

    public function label(): string
    {
        return match ($this) {
            self::Player => 'Player link',
            self::Coach => 'Coach invitation',
        };
    }
}
