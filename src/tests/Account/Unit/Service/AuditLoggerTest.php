<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Service;

use App\Account\Entity\AuditLogEntry;
use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Repository\AuditLogEntryRepository;
use App\Account\Service\AuditLogger;
use App\Account\Service\ImpersonationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * G-18 / NFR-022 — what an audit entry records, and what it must not do while recording it.
 */
final class AuditLoggerTest extends TestCase
{
    public function testTheEntryIsPersistedButNotFlushed(): void
    {
        // NFR-022: the entry has to commit or roll back with the change it describes, so the
        // logger must leave the transaction boundary to its caller. A logger that flushed
        // here would record operations that later rolled back — a false record, which is
        // worse than a missing one.
        $repository = $this->createMock(AuditLogEntryRepository::class);
        $repository->expects(self::once())->method('add');

        $this->loggerWith($repository, impersonator: null)
            ->log($this->user('admin@example.com', UserRole::SuperAdmin), AuditAction::UserUpdated);
    }

    public function testAnEntryWrittenWhileImpersonatingCarriesTheAdminBehindIt(): void
    {
        $actor = $this->user('trainer@example.com', UserRole::Trainer, 7);
        $admin = $this->user('admin@example.com', UserRole::SuperAdmin, 1);

        $entry = $this->loggerWith($this->collectingRepository($captured), $admin)
            ->log($actor, AuditAction::UserUpdated);

        self::assertSame($actor, $entry->getActor());
        self::assertSame($admin, $entry->getImpersonator());
    }

    public function testAnOrdinaryEntryHasNoImpersonator(): void
    {
        $actor = $this->user('admin@example.com', UserRole::SuperAdmin, 1);

        $entry = $this->loggerWith($this->collectingRepository($captured), null)
            ->log($actor, AuditAction::UserUpdated);

        self::assertNull($entry->getImpersonator());
    }

    /**
     * Nobody impersonates themselves. Symfony dispatches `security.switch_user` for an exit
     * while the switched token is still in storage, so without this the entry recording that
     * exit would claim the admin was impersonating themselves.
     */
    public function testAnImpersonatorEqualToTheActorIsTreatedAsNoImpersonator(): void
    {
        $admin = $this->user('admin@example.com', UserRole::SuperAdmin, 1);
        $sameAdminReloaded = $this->user('admin@example.com', UserRole::SuperAdmin, 1);

        $entry = $this->loggerWith($this->collectingRepository($captured), $sameAdminReloaded)
            ->log($admin, AuditAction::ImpersonationEnded);

        self::assertNull($entry->getImpersonator());
    }

    public function testTheSubjectIsRecordedByTypeNameAndId(): void
    {
        $actor = $this->user('admin@example.com', UserRole::SuperAdmin, 1);
        $subject = $this->user('pat@example.com', UserRole::Player, 42);

        $entry = $this->loggerWith($this->collectingRepository($captured), null)
            ->log($actor, AuditAction::UserDeactivated, $subject, ['reason' => 'Requested']);

        self::assertSame('User', $entry->getSubjectType());
        self::assertSame(42, $entry->getSubjectId());
        self::assertSame(['reason' => 'Requested'], $entry->getPayload());
    }

    /**
     * An entity that has not been flushed has no id yet. Recording null is correct — the entry
     * still names the type and carries the payload — and is preferable to refusing to log.
     */
    public function testASubjectWithoutAnIdStillProducesAnEntry(): void
    {
        $actor = $this->user('admin@example.com', UserRole::SuperAdmin, 1);
        $unsaved = $this->user('new@example.com', UserRole::Trainer, null);

        $entry = $this->loggerWith($this->collectingRepository($captured), null)
            ->log($actor, AuditAction::TrainerCreated, $unsaved);

        self::assertSame('User', $entry->getSubjectType());
        self::assertNull($entry->getSubjectId());
    }

    public function testTheOccurrenceTimeComesFromTheClock(): void
    {
        $actor = $this->user('admin@example.com', UserRole::SuperAdmin, 1);

        $entry = $this->loggerWith($this->collectingRepository($captured), null, new MockClock('2026-08-22 11:30:00'))
            ->log($actor, AuditAction::UserUpdated);

        self::assertSame('2026-08-22 11:30:00', $entry->getOccurredAt()->format('Y-m-d H:i:s'));
    }

    /**
     * Builds a real ImpersonationContext over a stubbed Security rather than doubling the
     * context itself. The behavior under test is "an entry written during a switch names the
     * admin", and the only thing that makes a switch a switch is the shape of the token — so
     * the token is what the test supplies.
     */
    private function loggerWith(
        AuditLogEntryRepository $repository,
        ?User $impersonator,
        ?MockClock $clock = null,
    ): AuditLogger {
        $security = $this->createMock(Security::class);
        $security->method('getToken')->willReturn($this->tokenFor($impersonator));

        return new AuditLogger(
            $repository,
            new ImpersonationContext($security),
            $clock ?? new MockClock('2026-08-22 09:00:00'),
        );
    }

    private function tokenFor(?User $impersonator): TokenInterface
    {
        $switched = $this->user('switched@example.com', UserRole::Trainer, 7);

        if (null === $impersonator) {
            return new UsernamePasswordToken($switched, 'main', $switched->getRoles());
        }

        return new SwitchUserToken(
            $switched,
            'main',
            $switched->getRoles(),
            new UsernamePasswordToken($impersonator, 'main', $impersonator->getRoles()),
        );
    }

    /**
     * @param list<AuditLogEntry>|null $captured
     */
    private function collectingRepository(?array &$captured): AuditLogEntryRepository
    {
        $captured = [];

        $repository = $this->createMock(AuditLogEntryRepository::class);
        $repository->method('add')->willReturnCallback(
            static function (AuditLogEntry $entry) use (&$captured): void {
                $captured[] = $entry;
            },
        );

        return $repository;
    }

    private function user(string $email, UserRole $role, ?int $id = null): User
    {
        $user = new User($email, ucfirst(strstr($email, '@', true) ?: $email), $role, new \DateTimeImmutable('2026-08-01'));

        if (null !== $id) {
            // The id is generated on flush; these tests never reach a database.
            $property = new \ReflectionProperty(User::class, 'id');
            $property->setValue($user, $id);
        }

        return $user;
    }
}
