<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\AuditLogEntry;
use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Repository\AuditLogEntryRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The one way a sensitive operation gets recorded (BR-025, NFR-X02).
 *
 * **Persists without flushing.** NFR-022 requires an audit write to be lost only if the change
 * it describes is also lost, which means both must share a transaction — and the only code
 * that knows where that transaction begins and ends is the calling service. A logger that
 * flushed on its own would produce entries for operations that later rolled back, which is a
 * worse failure than a missing entry: it is a false record.
 *
 * The impersonator is resolved here rather than passed in, so an entry written by code that
 * has never heard of impersonation still carries the admin behind it (G-18).
 */
final readonly class AuditLogger
{
    public function __construct(
        private AuditLogEntryRepository $entries,
        private ImpersonationContext $impersonationContext,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public function log(
        User $actor,
        AuditAction $action,
        ?object $subject = null,
        array $payload = [],
    ): AuditLogEntry {
        $entry = new AuditLogEntry(
            actor: $actor,
            action: $action,
            occurredAt: $this->clock->now(),
            impersonator: $this->impersonatorFor($actor),
            subjectType: null !== $subject ? self::shortName($subject) : null,
            subjectId: self::identify($subject),
            payload: $payload,
        );

        $this->entries->add($entry);

        return $entry;
    }

    /**
     * Nobody impersonates themselves, so an impersonator equal to the actor means the token
     * is mid-transition — Symfony dispatches `security.switch_user` for an exit while the
     * switched token is still in storage, so the entry recording that exit would otherwise
     * claim the admin was impersonating themselves.
     */
    private function impersonatorFor(User $actor): ?User
    {
        $impersonator = $this->impersonationContext->impersonator();

        return $impersonator?->getId() === $actor->getId() ? null : $impersonator;
    }

    private static function shortName(object $subject): string
    {
        $parts = explode('\\', $subject::class);

        return end($parts);
    }

    /**
     * Reads an id off the subject without requiring every auditable entity to implement an
     * interface. A subject that has not been flushed yet has no id, and recording null is
     * correct: the entry still names the type and carries the payload.
     */
    private static function identify(?object $subject): ?int
    {
        if (null === $subject || !method_exists($subject, 'getId')) {
            return null;
        }

        $id = $subject->getId();

        return \is_int($id) ? $id : null;
    }
}
