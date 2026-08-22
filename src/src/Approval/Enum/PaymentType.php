<?php

declare(strict_types=1);

namespace App\Approval\Enum;

/**
 * How a child purchase is paid for (FR-091, FR-092, BR-090, BR-091).
 *
 * The distinction exists because the two are governed by different rules, not because they are
 * two ways of moving the same value: USD *always* requires a parent's approval and no setting can
 * waive it (BR-090), while token spending is waivable per child (BR-091, BR-096). Storing the
 * type on every request is what lets `ApprovalRequestFactory` answer that question later, and
 * what lets a parent reading their history tell a $45 entry fee from twelve tokens.
 */
enum PaymentType: string
{
    case Usd = 'usd';
    case Token = 'token';

    public function label(): string
    {
        return match ($this) {
            self::Usd => 'US dollars',
            self::Token => 'Tokens',
        };
    }

    /**
     * Whether a parent may waive approval for this kind of payment.
     *
     * BR-090 is the whole of it: a USD purchase is never waivable, so the setting is not merely
     * ignored for dollars — it is not offered. Asked here rather than re-derived at each call
     * site, because "which payment types can be waived" is one fact.
     */
    public function isWaivable(): bool
    {
        return self::Token === $this;
    }
}
