<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Entity\AuditLogEntry;
use App\Account\Entity\UserDeletionRecord;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Service\UserAnonymizer;

/**
 * FR-025, FR-026, FR-027, BR-023, BR-024 — GDPR deletion by anonymization.
 */
final class UserDeletionTest extends AdminWebTestCase
{
    private const PLAYER_EMAIL = 'pat@example.com';
    private const REASON = 'Erasure request received on 2026-08-20, ticket SUP-4412.';

    public function testTheWarningPageStatesWhatIsRemovedAndThatItIsIrreversible(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->client->request('GET', \sprintf('/admin/users/%d/delete', $player->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'This cannot be undone');
        self::assertSelectorTextContains('[role="alert"]', 'Deleted User');
        self::assertSelectorTextContains('[role="alert"]', \sprintf('deleted_%d@example.com', $player->getId()));
    }

    /**
     * FR-025, field by field, against the exact values the spec names.
     */
    public function testAnonymizationOverwritesEveryPersonalFieldWithTheSpecifiedValues(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');
        $player->setPhone('020 7946 0000');
        $this->entityManager->flush();

        $userId = (int) $player->getId();
        $originalPasswordHash = $player->getPassword();

        $this->deleteUser($userId);

        $anonymized = $this->reloadUser(\sprintf('deleted_%d@example.com', $userId));

        self::assertSame('Deleted User', $anonymized->getName());
        self::assertSame(\sprintf('deleted_%d@example.com', $userId), $anonymized->getEmail());
        self::assertNull($anonymized->getPhone());
        self::assertSame(UserStatus::Deleted, $anonymized->getStatus());
        self::assertFalse($anonymized->isEmailVerified());
        self::assertNotSame($originalPasswordHash, $anonymized->getPassword());
        self::assertSame($userId, $anonymized->getId(), 'The row must be updated in place, never replaced.');
    }

    public function testTheDeletedUserCannotSignInWithTheirOldAddressOrTheNewOne(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');
        $userId = (int) $player->getId();

        $this->deleteUser($userId);
        $this->clickSignOut('/admin/users');

        $this->submitLogin(self::PLAYER_EMAIL);
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error, .form-errors, [role="alert"]');

        $this->submitLogin(\sprintf('deleted_%d@example.com', $userId));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'This account no longer exists.');
    }

    /**
     * FR-027 as narrowed by G-16: the record proves what happened without re-storing the
     * personal data the operation just removed.
     */
    public function testTheComplianceRecordIdentifiesTheDeletionWithoutStoringPersonalData(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');
        $userId = (int) $player->getId();

        $this->deleteUser($userId);

        $records = $this->freshEntityManager()->getRepository(UserDeletionRecord::class)->findAll();
        self::assertCount(1, $records);

        $record = $records[0];
        self::assertSame($userId, $record->getOriginalUserId());
        self::assertSame($admin->getId(), $record->getDeletedBy()->getId());
        self::assertSame(self::REASON, $record->getReason());
        self::assertSame(\sprintf('deleted_%d@example.com', $userId), $record->getAnonymizedEmail());

        // The address is verifiable by anyone who already has it, and unreadable to anyone
        // who does not.
        self::assertSame(UserDeletionRecord::digestFor(self::PLAYER_EMAIL), $record->getOriginalEmailDigest());
        self::assertStringNotContainsString('pat', $record->getOriginalEmailDigest());
    }

    public function testTheComplianceRecordIsFoundByTheOriginalAddressRegardlessOfCase(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->deleteUser((int) $player->getId());

        $repository = $this->freshEntityManager()->getRepository(UserDeletionRecord::class);
        self::assertNotNull($repository->findOneByEmail(' PAT@Example.COM '));
        self::assertNull($repository->findOneByEmail('someone-else@example.com'));
    }

    /**
     * FR-026 / BR-024 — the requirement most likely to be broken by a naive implementation.
     *
     * The history-bearing tables this epic ships are the audit log and the impersonation
     * sessions; attendance and payments arrive in later epics but will reference `user` the
     * same way. What is asserted here is the mechanism: rows referencing a deleted user
     * survive, the count an aggregate would report is unchanged, and each row now renders as
     * "Deleted User" without any of them checking status for themselves.
     */
    public function testHistoricalRowsSurviveDeletionAndAggregateTotalsAreUnchanged(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');
        $userId = (int) $player->getId();

        // Build some history: two status changes recorded against this user.
        $this->postToDirectory($userId, 'deactivate');
        $this->postToDirectory($userId, 'reactivate');

        $entityManager = $this->freshEntityManager();
        $historyBefore = $entityManager->getRepository(AuditLogEntry::class)->findForSubject('User', $userId);
        self::assertCount(2, $historyBefore);

        $totalBefore = (int) $entityManager->createQuery(
            'SELECT COUNT(a.id) FROM App\Account\Entity\AuditLogEntry a WHERE a.subjectId = :id',
        )->setParameter('id', $userId)->getSingleScalarResult();

        $this->deleteUser($userId);

        $entityManager = $this->freshEntityManager();
        $historyAfter = $entityManager->getRepository(AuditLogEntry::class)->findForSubject('User', $userId);

        // Three now, because the deletion itself is audited — the two originals are untouched.
        self::assertCount(3, $historyAfter);

        $totalAfter = (int) $entityManager->createQuery(
            'SELECT COUNT(a.id) FROM App\Account\Entity\AuditLogEntry a WHERE a.subjectId = :id',
        )->setParameter('id', $userId)->getSingleScalarResult();

        self::assertSame($totalBefore + 1, $totalAfter, 'Deleting a user must not remove rows that reference them.');

        // Every historical row now renders the anonymized identity, through the one accessor
        // templates use.
        foreach ($historyAfter as $entry) {
            if ('User' === $entry->getSubjectType() && $userId === $entry->getSubjectId()) {
                self::assertSame(
                    UserAnonymizer::ANONYMOUS_NAME,
                    $entityManager->getRepository(\App\Account\Entity\User::class)->find($userId)?->getDisplayName(),
                );
            }
        }
    }

    public function testDeletionIsAuditedWithoutRecordingTheAddressItErased(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');
        $userId = (int) $player->getId();

        $this->deleteUser($userId);

        $entries = $this->auditEntries(AuditAction::UserAnonymized);
        self::assertCount(1, $entries);
        self::assertSame($admin->getId(), $entries[0]->getActor()->getId());

        $payload = $entries[0]->getPayload();
        self::assertSame($userId, $payload['originalUserId']);
        self::assertSame(self::REASON, $payload['reason']);
        self::assertStringNotContainsString(self::PLAYER_EMAIL, json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    public function testAReasonIsRequired(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->client->request('GET', \sprintf('/admin/users/%d/delete', $player->getId()));
        $this->client->submitForm('Delete personal information', ['delete_user_form[reason]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error-summary', 'Enter the reason for this deletion');
        $this->assertUserStatus(self::PLAYER_EMAIL, UserStatus::Active);
    }

    public function testAThrowawayReasonIsRejected(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->client->request('GET', \sprintf('/admin/users/%d/delete', $player->getId()));
        $this->client->submitForm('Delete personal information', ['delete_user_form[reason]' => 'asdf']);

        self::assertResponseStatusCodeSame(422);
        $this->assertUserStatus(self::PLAYER_EMAIL, UserStatus::Active);
    }

    private function deleteUser(int $userId): void
    {
        $this->client->request('GET', \sprintf('/admin/users/%d/delete', $userId));
        $this->client->submitForm('Delete personal information', ['delete_user_form[reason]' => self::REASON]);

        self::assertResponseRedirects('/admin/users');
    }

    private function postToDirectory(int $userId, string $action): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', \sprintf('/admin/users/%d/%s', $userId, $action), ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');
    }
}
