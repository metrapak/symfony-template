<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Profile\ValueObject\TrainingContext;

/**
 * One entry in the context switcher, ready to render (FR-069).
 *
 * The switcher's shape is specified quite precisely in US-01.04 — a parent who trains sees
 * "Your Training" and "Your Children's Training" as separate groups, a parent who does not
 * train sees only the second, a child sees a flat list with no "Me" section — and all three
 * shapes are the same list grouped differently. So the grouping travels with the option rather
 * than being re-derived in the template, and `TrainingContextResolver` is what a unit test
 * points at when it checks that a family produces the right one.
 */
final readonly class TrainingContextOption
{
    public function __construct(
        public TrainingContext $context,
        public string $playerName,
        public string $organizationName,
        /**
         * True when this context belongs to the viewer's own profile rather than a child's.
         * The template groups on it; nothing authorizes on it.
         */
        public bool $own,
        public \DateTimeImmutable $connectedAt,
    ) {
    }

    public function key(): string
    {
        return $this->context->toKey();
    }

    /**
     * "Maya → Northside Academy", the label US-01.04's mock-up asks for.
     */
    public function label(): string
    {
        return \sprintf('%s → %s', $this->playerName, $this->organizationName);
    }
}
