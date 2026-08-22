<?php

declare(strict_types=1);

namespace App\Availability\ValueObject;

use App\Account\Entity\User;
use App\Availability\Enum\AvailabilitySubjectType;
use App\Profile\Entity\PlayerProfile;

/**
 * Whose week is being read or written: a (type, id) pair (FR-080, FR-081, FR-082).
 *
 * `availability_slot.subject_id` points at two different tables depending on the type, so
 * nothing enforces the pairing in the schema (see `AvailabilitySubjectType`). This is what
 * enforces it in the code: the only ways to build one are from a `PlayerProfile` or from a
 * `User`, so a coach's id cannot be stored under the player type by a call that transposed two
 * integer arguments — the mistake the named constructors exist to make unrepresentable.
 *
 * **G-07, decided.** A subject is a *person*, not a (person, trainer) pair. US-01.03's "per
 * trainer" reading is not implemented: availability describes when somebody can attend
 * anything, a player who trains with two academies does not become free at different hours for
 * each, and a per-trainer schema would ask every family to fill the same grid once per trainer
 * and then quietly disagree with itself. The consequence is that every trainer of a player sees
 * the same declared times — which is what BR-087's organization scoping governs access to, not
 * the shape of the data.
 */
final readonly class AvailabilitySubject
{
    private function __construct(
        public AvailabilitySubjectType $type,
        public int $id,
    ) {
    }

    public static function player(PlayerProfile $profile): self
    {
        return new self(AvailabilitySubjectType::Player, (int) $profile->getId());
    }

    /**
     * A player subject from an id that has already been authorized.
     *
     * Used where the profile was loaded and checked by a voter and re-hydrating it would be a
     * second query for a number the caller is already holding. An id that has *not* been
     * authorized has no business reaching here — see `AvailabilityVoter`.
     */
    public static function playerId(int $playerProfileId): self
    {
        return new self(AvailabilitySubjectType::Player, $playerProfileId);
    }

    public static function coach(User $coach): self
    {
        return new self(AvailabilitySubjectType::Coach, (int) $coach->getId());
    }

    public static function coachId(int $coachUserId): self
    {
        return new self(AvailabilitySubjectType::Coach, $coachUserId);
    }

    public function isPlayer(): bool
    {
        return AvailabilitySubjectType::Player === $this->type;
    }

    public function isCoach(): bool
    {
        return AvailabilitySubjectType::Coach === $this->type;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function toKey(): string
    {
        return $this->type->value . ':' . $this->id;
    }
}
