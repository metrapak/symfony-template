<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Profile\Entity\PlayerProfile;

/**
 * What a redemption by an existing account actually changed (FR-043).
 *
 * Both lists matter to the page that renders afterwards: "Mateo now trains with Northside"
 * and "you already train with Northside" are different sentences, and FR-043's idempotency
 * requirement is precisely that the second one is a success rather than an error.
 */
final readonly class AssociationSummary
{
    /**
     * @param list<PlayerProfile> $associated profiles joined (or re-joined) by this redemption
     * @param list<PlayerProfile> $alreadyAssociated profiles that were already training with the organization
     */
    public function __construct(
        public array $associated,
        public array $alreadyAssociated,
    ) {
    }

    public function changedAnything(): bool
    {
        return [] !== $this->associated;
    }

    /**
     * @return list<string>
     */
    public function associatedNames(): array
    {
        return array_map(static fn (PlayerProfile $profile): string => $profile->getDisplayName(), $this->associated);
    }
}
