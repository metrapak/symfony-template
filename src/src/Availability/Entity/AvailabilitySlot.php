<?php

declare(strict_types=1);

namespace App\Availability\Entity;

use App\Availability\Enum\AvailabilitySubjectType;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Repository\AvailabilitySlotRepository;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Availability\ValueObject\TimeRange;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One declared window in somebody's week (FR-080, FR-082, spec §8 "Best Times / Availability").
 *
 * Rows are produced only by `AvailabilityService::replaceWeek()`, from a normalized
 * `WeeklySchedule`, and a subject's rows are replaced as a set inside one transaction. Nothing
 * updates a slot in place — a week is a value, and editing one window of it is how two rows end
 * up overlapping without anybody deciding they should.
 *
 * **Times are minutes since midnight**, not `TIME` columns; `TimeRange` explains why, and the
 * short version is that `24:00` has to be expressible and DST must not touch a recurring
 * pattern. They are wall-clock times in the platform's configured zone
 * (`app.availability_timezone`, G-29) — no row carries a zone of its own, because a grid that
 * mixed zones would be unreadable to the trainer comparing two players.
 *
 * **`isAvailable` is what makes "Wednesday: Not Available" a fact rather than an absence.**
 * FR-080 asks for that toggle, and without a stored negative there is no difference between a
 * family who said "never on Wednesdays" and one who has not filled the form in — a difference
 * the trainer's "15 of 20" count depends on. An unavailable day is stored as a single full-day
 * row; every matching query filters `is_available = true`, so the negative rows are inert to
 * scheduling and meaningful only to the reader.
 *
 * `subjectId` deliberately carries no foreign key: it points at `player_profile` or at `"user"`
 * depending on `subjectType`, and no column can reference two tables. `AvailabilitySubject` is
 * the only thing that constructs the pair.
 */
#[ORM\Entity(repositoryClass: AvailabilitySlotRepository::class)]
#[ORM\Table(name: 'availability_slot')]
#[ORM\Index(name: 'IDX_AVAILABILITY_SLOT_SUBJECT', columns: ['subject_type', 'subject_id', 'day_of_week'])]
#[ORM\Index(
    name: 'IDX_AVAILABILITY_SLOT_LOOKUP',
    columns: ['subject_type', 'day_of_week', 'available', 'start_minute', 'end_minute'],
)]
class AvailabilitySlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AvailabilitySubjectType::class)]
    private AvailabilitySubjectType $subjectType;

    #[ORM\Column]
    private int $subjectId;

    #[ORM\Column(type: Types::SMALLINT, enumType: DayOfWeek::class)]
    private DayOfWeek $dayOfWeek;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $startMinute;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $endMinute;

    #[ORM\Column(options: ['default' => true])]
    private bool $available = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        AvailabilitySubject $subject,
        DayOfWeek $dayOfWeek,
        TimeRange $range,
        bool $available,
        \DateTimeImmutable $now,
    ) {
        $this->subjectType = $subject->type;
        $this->subjectId = $subject->id;
        $this->dayOfWeek = $dayOfWeek;
        $this->startMinute = $range->startMinute;
        $this->endMinute = $range->endMinute;
        $this->available = $available;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function available(
        AvailabilitySubject $subject,
        DayOfWeek $dayOfWeek,
        TimeRange $range,
        \DateTimeImmutable $now,
    ): self {
        return new self($subject, $dayOfWeek, $range, true, $now);
    }

    /**
     * FR-080's "Wednesday: Not Available", as a whole-day negative row.
     *
     * The full day rather than a marker column, so the negative and the positive rows have the
     * same shape and one query returns a subject's entire week.
     */
    public static function unavailableAllDay(
        AvailabilitySubject $subject,
        DayOfWeek $dayOfWeek,
        \DateTimeImmutable $now,
    ): self {
        return new self($subject, $dayOfWeek, TimeRange::fromMinutes(0, TimeRange::DAY_END_MINUTE), false, $now);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubjectType(): AvailabilitySubjectType
    {
        return $this->subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getSubject(): AvailabilitySubject
    {
        return match ($this->subjectType) {
            AvailabilitySubjectType::Player => AvailabilitySubject::playerId($this->subjectId),
            AvailabilitySubjectType::Coach => AvailabilitySubject::coachId($this->subjectId),
        };
    }

    public function getDayOfWeek(): DayOfWeek
    {
        return $this->dayOfWeek;
    }

    public function getRange(): TimeRange
    {
        return TimeRange::fromMinutes($this->startMinute, $this->endMinute);
    }

    public function getStartMinute(): int
    {
        return $this->startMinute;
    }

    public function getEndMinute(): int
    {
        return $this->endMinute;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
