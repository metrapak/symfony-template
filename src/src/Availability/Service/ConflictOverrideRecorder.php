<?php

declare(strict_types=1);

namespace App\Availability\Service;

use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Service\AuditLogger;
use App\Availability\Entity\CoachAvailabilityOverride;
use App\Availability\Enum\DayOfWeek;
use App\Availability\Repository\CoachAvailabilityOverrideRepository;
use App\Availability\ValueObject\TimeRange;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records a trainer's decision to schedule a coach outside their stated times (FR-086, BR-085,
 * NFR-X02).
 *
 * Two writes, one transaction: the override row BR-085 asks for, and an audit entry, because
 * NFR-X02 lists an override among the operations that must be auditable alongside impersonation
 * and deletion. `AuditLogger` persists without flushing precisely so that both land together —
 * an audit entry for an override that rolled back would be a false record, which is worse than a
 * missing one.
 *
 * The blank-reason guard is duplicated here on purpose. `CoachConflictInput` rejects an empty
 * reason at the boundary and that is where a trainer sees the error; this refuses to write the
 * row at all. FR-086 makes the reason the entire point of the record, so the invariant belongs
 * with the writer and not only with the form that happens to be the current caller — Epic-02
 * will be the next one.
 */
final readonly class ConflictOverrideRecorder
{
    public function __construct(
        private CoachAvailabilityOverrideRepository $overrides,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param int $organizationId the trainer's own tenant, resolved by the caller from context
     * @param int|null $eventId Epic-02's event; null until events exist
     *
     * @throws \InvalidArgumentException when the reason is blank
     */
    public function record(
        User $coach,
        User $trainer,
        int $organizationId,
        DayOfWeek $day,
        TimeRange $window,
        string $reason,
        ?int $eventId = null,
    ): CoachAvailabilityOverride {
        $reason = trim($reason);

        if ('' === $reason) {
            throw new \InvalidArgumentException('An availability override cannot be recorded without a reason.');
        }

        $override = new CoachAvailabilityOverride(
            coach: $coach,
            overriddenBy: $trainer,
            organizationId: $organizationId,
            dayOfWeek: $day,
            window: $window,
            reason: $reason,
            now: $this->clock->now(),
            eventId: $eventId,
        );

        return $this->entityManager->wrapInTransaction(function () use ($override, $trainer, $coach, $day, $window, $eventId, $reason): CoachAvailabilityOverride {
            $this->overrides->add($override);

            $this->auditLogger->log(
                actor: $trainer,
                action: AuditAction::CoachAvailabilityOverridden,
                subject: $coach,
                payload: [
                    'day' => $day->label(),
                    'window' => $window->format(),
                    'event_id' => $eventId,
                    // The reason is the record. Truncated in the audit payload because the full
                    // text lives on the override row, and the audit log is read as a list.
                    'reason' => mb_substr($reason, 0, 255),
                ],
            );

            $this->entityManager->flush();

            return $override;
        });
    }
}
