<?php

declare(strict_types=1);

namespace App\Availability\Entity;

use App\Account\Entity\User;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Repository\CoachAvailabilityOverrideRepository;
use App\Availability\ValueObject\TimeRange;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A trainer's record of scheduling a coach outside the coach's stated times (FR-086, BR-085,
 * spec §8 "Coach Availability Overrides").
 *
 * Append-only: there is no setter, no status and no delete path. The record exists because
 * BR-085 requires every override to be answerable for later — who, when, why — and a row that
 * can be edited afterwards answers a different question than the one it was written for.
 *
 * **`eventId` is a plain nullable integer with no foreign key, and that is a known debt.**
 * Epic-02 owns events and does not exist yet, so there is no table to reference; creating the
 * column now with the constraint deferred keeps Epic-02's assignment flow from having to migrate
 * this table on arrival. Until then the only writer is the trainer's pre-assignment check, which
 * has no event to name and stores null. The follow-up is to add the foreign key with Epic-02's
 * first migration — noted in the epic index rather than in a comment nobody greps for.
 *
 * The conflicting window is stored alongside, which the spec's field list does not ask for. It
 * earns its place precisely *because* `eventId` is null today: without it the row would say a
 * trainer overrode something, some time, for a reason — and not what the conflict was. A record
 * that cannot be read back is not an audit trail.
 */
#[ORM\Entity(repositoryClass: CoachAvailabilityOverrideRepository::class)]
#[ORM\Table(name: 'coach_availability_override')]
#[ORM\Index(name: 'IDX_COACH_OVERRIDE_COACH', columns: ['coach_id', 'created_at'])]
#[ORM\Index(name: 'IDX_COACH_OVERRIDE_EVENT', columns: ['event_id'])]
class CoachAvailabilityOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Epic-02's event, once there is one. See the class note. */
    #[ORM\Column(nullable: true)]
    private ?int $eventId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $coach;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $overriddenBy;

    /**
     * The organization the override was made in.
     *
     * Not in the spec's field list, and required anyway: without it, listing "the overrides my
     * organization recorded" would mean joining through a coach's *current* assignment, which
     * changes when a coach moves to another trainer (BR-044) and would silently move their
     * history with them.
     */
    #[ORM\Column]
    private int $organizationId;

    #[ORM\Column(type: Types::SMALLINT, enumType: DayOfWeek::class)]
    private DayOfWeek $dayOfWeek;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $startMinute;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $endMinute;

    /** Required and non-blank (FR-086). The validator enforces it before this is constructed. */
    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $coach,
        User $overriddenBy,
        int $organizationId,
        DayOfWeek $dayOfWeek,
        TimeRange $window,
        string $reason,
        \DateTimeImmutable $now,
        ?int $eventId = null,
    ) {
        $this->coach = $coach;
        $this->overriddenBy = $overriddenBy;
        $this->organizationId = $organizationId;
        $this->dayOfWeek = $dayOfWeek;
        $this->startMinute = $window->startMinute;
        $this->endMinute = $window->endMinute;
        $this->reason = $reason;
        $this->createdAt = $now;
        $this->eventId = $eventId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): ?int
    {
        return $this->eventId;
    }

    public function getCoach(): User
    {
        return $this->coach;
    }

    public function getOverriddenBy(): User
    {
        return $this->overriddenBy;
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getDayOfWeek(): DayOfWeek
    {
        return $this->dayOfWeek;
    }

    public function getWindow(): TimeRange
    {
        return TimeRange::fromMinutes($this->startMinute, $this->endMinute);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
