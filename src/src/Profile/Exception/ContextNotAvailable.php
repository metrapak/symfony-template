<?php

declare(strict_types=1);

namespace App\Profile\Exception;

use App\Profile\ValueObject\TrainingContext;

/**
 * The requested (player, trainer) context is not one this user may see (FR-070, NFR-063).
 *
 * Thrown for every reason a context can be refused — the profile does not exist, it belongs to
 * another family, the association was ended, the ids are simply invented — and carrying one
 * message for all of them. FR-070 requires a forged context identifier to return 403 and never
 * data, and a message that distinguished "no such profile" from "not your profile" would leak
 * the existence of other families' children one id at a time.
 *
 * Controllers translate this to 403. It is never a 404: telling an attacker which ids exist is
 * the disclosure this class is for.
 */
final class ContextNotAvailable extends \DomainException implements ProfileException
{
    public const MESSAGE = 'That training context is not available to this account.';

    private function __construct(public readonly ?TrainingContext $attempted)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function forContext(?TrainingContext $context): self
    {
        return new self($context);
    }
}
