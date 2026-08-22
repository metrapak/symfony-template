<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Profile\Entity\PlayerProfile;

/**
 * One row of the family page: a child, their age, and who they train with (FR-066).
 *
 * A read model assembled once per page rather than a template walking entities, which is what
 * keeps a family of three children across two trainers to a fixed number of queries — the
 * naive version asks for each child's associations inside the loop.
 *
 * `age` is computed at assembly time and carried, so every row on the page agrees about what
 * "today" is. A template calling `ageOn(now)` per row would be correct and would also be one
 * midnight away from showing two different ages on one screen.
 */
final readonly class ChildSummary
{
    /**
     * @param list<AssociationRecord> $trainers
     */
    public function __construct(
        public PlayerProfile $profile,
        public ?int $age,
        public array $trainers,
        public bool $hasLogin,
        public bool $loginActive,
    ) {
    }

    public function id(): int
    {
        return (int) $this->profile->getId();
    }

    public function name(): string
    {
        return $this->profile->getDisplayName();
    }

    public function hasTrainers(): bool
    {
        return [] !== $this->trainers;
    }
}
