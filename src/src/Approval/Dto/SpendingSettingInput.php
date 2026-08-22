<?php

declare(strict_types=1);

namespace App\Approval\Dto;

/**
 * The one switch on the child spending screen (FR-092).
 *
 * A plain bool with no constraint: an unchecked checkbox submits nothing, which Symfony maps to
 * false, and false is a valid answer — indeed the default one (BR-091). There is nothing here for
 * a validator to reject.
 */
final class SpendingSettingInput
{
    public function __construct(
        public bool $allowTokenSpendingWithoutApproval = false,
    ) {
    }
}
