<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Entity;

use App\Account\Entity\ImpersonationSession;
use App\Account\Entity\User;
use App\Account\Enum\ImpersonationEndReason;
use App\Account\Enum\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * FR-031, FR-032 — the record whose timestamps drive both the report and the expiry check.
 */
final class ImpersonationSessionTest extends TestCase
{
    public function testANewSessionIsOpenAndHasNoDuration(): void
    {
        $session = $this->session('2026-08-22 09:00:00');

        self::assertTrue($session->isOpen());
        self::assertNull($session->getEndedAt());
        self::assertNull($session->getDurationSeconds());
        self::assertNull($session->getEndReason());
    }

    public function testClosingRecordsTheDurationAndTheReason(): void
    {
        $session = $this->session('2026-08-22 09:00:00');

        $session->close(new \DateTimeImmutable('2026-08-22 09:12:30'), ImpersonationEndReason::Exit);

        self::assertFalse($session->isOpen());
        self::assertSame(750, $session->getDurationSeconds());
        self::assertSame(ImpersonationEndReason::Exit, $session->getEndReason());
    }

    /**
     * Two paths can race to close the same row — the operator clicking "Exit" on a request the
     * expiry subscriber has already decided is stale. The first one to arrive is the one that
     * actually happened, so a second call must not rewrite the reason.
     */
    public function testClosingIsIdempotent(): void
    {
        $session = $this->session('2026-08-22 09:00:00');

        $session->close(new \DateTimeImmutable('2026-08-22 09:05:00'), ImpersonationEndReason::Exit);
        $session->close(new \DateTimeImmutable('2026-08-22 10:00:00'), ImpersonationEndReason::Expiry);

        self::assertSame(ImpersonationEndReason::Exit, $session->getEndReason());
        self::assertSame(300, $session->getDurationSeconds());
    }

    /**
     * A clock that jumps backwards (an NTP correction mid-session) must not produce a
     * negative duration in a compliance report.
     */
    public function testDurationNeverGoesNegative(): void
    {
        $session = $this->session('2026-08-22 09:00:00');

        $session->close(new \DateTimeImmutable('2026-08-22 08:59:00'), ImpersonationEndReason::Expiry);

        self::assertSame(0, $session->getDurationSeconds());
    }

    public function testElapsedSecondsMeasuresFromTheStart(): void
    {
        $session = $this->session('2026-08-22 09:00:00');

        self::assertSame(3600, $session->elapsedSeconds(new \DateTimeImmutable('2026-08-22 10:00:00')));
        self::assertSame(0, $session->elapsedSeconds(new \DateTimeImmutable('2026-08-22 09:00:00')));
    }

    private function session(string $startedAt): ImpersonationSession
    {
        return new ImpersonationSession(
            $this->user('admin@example.com', UserRole::SuperAdmin),
            $this->user('trainer@example.com', UserRole::Trainer),
            new \DateTimeImmutable($startedAt),
        );
    }

    private function user(string $email, UserRole $role): User
    {
        return new User($email, ucfirst(strstr($email, '@', true) ?: $email), $role, new \DateTimeImmutable('2026-08-01'));
    }
}
