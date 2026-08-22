<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-044 — "Who will train with {trainer}?".
 *
 * Carries profile ids rather than profile objects because that is what a checkbox list
 * submits, and because the ids must be checked against the account's own family before
 * anything is loaded on their behalf. `AssociationService` performs that check; a submitted id
 * outside the account's family is refused, never quietly skipped.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class FamilySelectionInput
{
    /**
     * @var list<int>
     */
    #[Assert\Count(min: 1, minMessage: 'Select at least one family member.')]
    public array $profileIds = [];
}
