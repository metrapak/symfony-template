<?php

declare(strict_types=1);

namespace App\Membership\Entity;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Repository\CoachAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A coach working for one organization (US-01.08, BR-044).
 *
 * "A coach may be active under only one trainer at a time" is enforced by a **partial unique
 * index** on `coach_id WHERE status = 'active'`, created in the migration. A plain unique
 * index cannot express it: a coach who legitimately left one organization for another must
 * keep the ended row, and a full unique index would forbid the second one forever.
 *
 * FR-045 requires that rule to survive a caller that never heard of it, which is why the
 * index exists at all — `CoachInvitationService` checks first for the error message, and the
 * database is what makes the check true under concurrency and for fixtures, console commands
 * and future code paths alike.
 *
 * The status also drives tenancy: `CoachAssignmentOrganizationProvider` reads the active row
 * to answer what `TenantContext` could not answer before this task existed.
 */
#[ORM\Entity(repositoryClass: CoachAssignmentRepository::class)]
#[ORM\Table(name: 'coach_assignment')]
#[ORM\Index(name: 'IDX_COACH_ASSIGNMENT_ORG_STATUS', columns: ['organization_id', 'status'])]
// The partial index BR-044 rests on, declared here as well as created in the migration so
// mapping and database agree and `doctrine:schema:validate` stays green. PostgreSQL is the
// only platform this project targets, and it is the one that supports the predicate.
//
// The predicate is written the way PostgreSQL stores it, casts and all, rather than the way
// a person would write it. Doctrine compares this string against `pg_get_indexdef` output; a
// tidier `status = 'active'` is the same index but a different string, and every future
// `doctrine:migrations:diff` would then propose dropping and recreating it. TASK-001 hit the
// same wall with functional indexes and resolved it by leaving them out of the mapping — here
// the constraint is a correctness guarantee rather than a performance one, so it is worth
// carrying the ugly string to keep the mapping honest about it.
#[ORM\UniqueConstraint(
    name: 'UNIQ_COACH_ASSIGNMENT_ACTIVE_COACH',
    columns: ['coach_id'],
    options: ['where' => "((status)::text = 'active'::text)"],
)]
class CoachAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'coach_id', nullable: false, onDelete: 'RESTRICT')]
    private User $coach;

    #[ORM\ManyToOne(targetEntity: ShareLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ShareLink $viaShareLink = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: MembershipStatus::class)]
    private MembershipStatus $status = MembershipStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(
        Organization $organization,
        User $coach,
        ?ShareLink $viaShareLink,
        \DateTimeImmutable $joinedAt,
    ) {
        $this->organization = $organization;
        $this->coach = $coach;
        $this->viaShareLink = $viaShareLink;
        $this->joinedAt = $joinedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getCoach(): User
    {
        return $this->coach;
    }

    public function getViaShareLink(): ?ShareLink
    {
        return $this->viaShareLink;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return MembershipStatus::Active === $this->status;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /**
     * Ends the assignment, releasing the coach to be invited elsewhere.
     *
     * G-20 asks who performs this transition when a coach moves between trainers; nothing in
     * Epic-01 answers it, so no UI calls this yet. The method exists because the schema's
     * partial index is meaningless without it, and because the migration and the tests both
     * need to prove that an ended assignment stops blocking a new one.
     */
    public function end(\DateTimeImmutable $now): static
    {
        $this->status = MembershipStatus::Inactive;
        $this->endedAt = $now;

        return $this;
    }
}
