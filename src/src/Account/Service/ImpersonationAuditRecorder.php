<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\ImpersonationSession;
use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Enum\ImpersonationEndReason;
use App\Account\Repository\ImpersonationSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Opens and closes the impersonation record (FR-032).
 *
 * Called from the security layer rather than from a controller, because the switch itself
 * happens in `SwitchUserListener` — anything that only recorded the controller's intention
 * would miss a switch performed by typing the `_switch_user` parameter onto any URL.
 *
 * Unlike the rest of the module these methods flush: they are invoked from an event
 * subscriber that owns no transaction of its own, and an unflushed session row would leave
 * the expiry check with nothing to read.
 */
final readonly class ImpersonationAuditRecorder
{
    public function __construct(
        private ImpersonationSessionRepository $sessions,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Opens a session, closing any the admin had left open.
     *
     * Symfony allows switching straight from one user to another without exiting first, so
     * "already open" is a normal state, not a corruption. The previous row is closed as an
     * exit, which is what it was.
     */
    public function start(User $admin, User $target): ImpersonationSession
    {
        $now = $this->clock->now();

        return $this->entityManager->wrapInTransaction(function () use ($admin, $target, $now): ImpersonationSession {
            $this->sessions->findOpenForAdmin($admin)?->close($now, ImpersonationEndReason::Exit);

            $session = new ImpersonationSession($admin, $target, $now);
            $this->sessions->add($session);

            $this->entityManager->flush();

            $this->auditLogger->log($admin, AuditAction::ImpersonationStarted, $target, [
                'targetEmail' => $target->getEmail(),
                'targetRole' => $target->getRole()->value,
                'sessionId' => $session->getId(),
            ]);

            return $session;
        });
    }

    /**
     * Closes the admin's open session, if there is one.
     *
     * A missing row is not an error: an operator can arrive here after the row was already
     * closed by the expiry subscriber, and refusing would turn a successful exit into a 500.
     */
    public function end(User $admin, ImpersonationEndReason $reason): ?ImpersonationSession
    {
        $now = $this->clock->now();

        return $this->entityManager->wrapInTransaction(function () use ($admin, $reason, $now): ?ImpersonationSession {
            $session = $this->sessions->findOpenForAdmin($admin);

            if (null === $session) {
                return null;
            }

            $session->close($now, $reason);

            $this->auditLogger->log($admin, AuditAction::ImpersonationEnded, $session->getTargetUser(), [
                'sessionId' => $session->getId(),
                'durationSeconds' => $session->getDurationSeconds(),
                'endReason' => $reason->value,
            ]);

            return $session;
        });
    }

    public function openSessionFor(User $admin): ?ImpersonationSession
    {
        return $this->sessions->findOpenForAdmin($admin);
    }
}
