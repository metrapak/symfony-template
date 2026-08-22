<?php

declare(strict_types=1);

namespace App\Profile\ValueObject;

/**
 * The (player, trainer) pair every context-scoped query is filtered by (FR-069, FR-070).
 *
 * FR-070 is a security boundary, and this is the token it is enforced with. Two ids and
 * nothing else: the pair is small enough to live in the session, cheap enough to re-authorize
 * on every request, and — critically — carries **no authority of its own**. Holding one does
 * not mean the holder may read that context's data; `TrainingContextResolver::assertAccess()`
 * decides that, every time, against the associations the *current user* actually owns.
 *
 * That is why this is a pair of ints rather than a pair of entities. An entity graph in the
 * session is a stale snapshot of a permission — an association deactivated after the switch
 * would still be sitting there, hydrated and looking valid. Ids force the check to go back to
 * the database.
 *
 * The epic's tenancy convention (MANIFEST: no Doctrine SQL filter; the organization id is a
 * *required* parameter) extends to this. A context-scoped repository method takes a
 * `TrainingContext` as a required argument, so forgetting to scope a query is an argument
 * error at compile time rather than a cross-family data leak at runtime. A Doctrine filter was
 * rejected for tenancy because it applies globally and silently and fails open when disabled;
 * the same objection holds here, more strongly — a filter that quietly stopped applying would
 * show a parent another family's calendar.
 */
final readonly class TrainingContext
{
    public function __construct(
        public int $playerProfileId,
        public int $organizationId,
    ) {
    }

    /**
     * Parses the value a switcher submitted or the session held.
     *
     * Returns null for anything malformed rather than throwing: the input is user-controlled,
     * so a bad value is an ordinary event and the caller's answer to it is the same 403 it
     * gives a well-formed pair the user has no association with.
     */
    public static function tryParse(?string $raw): ?self
    {
        if (null === $raw || 1 !== preg_match('/^(\d{1,18}):(\d{1,18})$/', trim($raw), $matches)) {
            return null;
        }

        $profileId = (int) $matches[1];
        $organizationId = (int) $matches[2];

        if ($profileId < 1 || $organizationId < 1) {
            return null;
        }

        return new self($profileId, $organizationId);
    }

    public function equals(self $other): bool
    {
        return $this->playerProfileId === $other->playerProfileId
            && $this->organizationId === $other->organizationId;
    }

    /**
     * The form value and session key for this pair.
     *
     * One format, produced in one place, so the switcher's `<option value>` and the stored
     * selection cannot drift into two spellings of the same context.
     */
    public function toKey(): string
    {
        return $this->playerProfileId . ':' . $this->organizationId;
    }

    public function __toString(): string
    {
        return $this->toKey();
    }
}
