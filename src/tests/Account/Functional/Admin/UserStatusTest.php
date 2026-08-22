<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Security\AccountStatusChecker;

/**
 * FR-024, BR-022 — deactivation and reactivation, the reversible half of the removal model.
 */
final class UserStatusTest extends AdminWebTestCase
{
    private const PLAYER_EMAIL = 'player@example.com';

    public function testDeactivationStopsTheUserSigningIn(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->submitStatusForm('Deactivate Pat Player');

        self::assertResponseRedirects('/admin/users');
        $this->assertUserStatus(self::PLAYER_EMAIL, UserStatus::Inactive);

        $this->clickSignOut('/admin/users');
        $this->submitLogin(self::PLAYER_EMAIL);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', AccountStatusChecker::INACTIVE_MESSAGE);
        self::assertNotNull($player->getId());
    }

    public function testReactivationRestoresAccess(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::PLAYER_EMAIL, UserRole::Player, UserStatus::Inactive, name: 'Pat Player');

        $this->submitStatusForm('Reactivate Pat Player');

        $this->assertUserStatus(self::PLAYER_EMAIL, UserStatus::Active);

        $this->clickSignOut('/admin/users');
        $this->submitLogin(self::PLAYER_EMAIL);

        self::assertResponseRedirects('/dashboard');
    }

    /**
     * FR-024's confirmation copy is the one place the operator is told that history survives.
     */
    public function testTheDeactivateConfirmationExplainsThatHistoryIsPreserved(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[data-confirm]')->reduce(
            static fn ($node): bool => str_contains((string) $node->attr('action'), 'deactivate'),
        );

        self::assertStringContainsString('All historical data will be preserved', (string) $form->attr('data-confirm'));
    }

    public function testDeactivationIsAudited(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->submitStatusForm('Deactivate Pat Player');

        $entries = $this->auditEntries(AuditAction::UserDeactivated);
        self::assertCount(1, $entries);
        self::assertSame($admin->getId(), $entries[0]->getActor()->getId());
        self::assertSame(UserStatus::Inactive->value, $entries[0]->getPayload()['status']);
    }

    /**
     * A state change reachable by GET is a state change any other site can trigger. The route
     * is POST-only, and the token is checked rather than merely rendered.
     */
    public function testStatusChangesRequireAPostWithAValidCsrfToken(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->client->request('GET', \sprintf('/admin/users/%d/deactivate', $player->getId()));
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', \sprintf('/admin/users/%d/deactivate', $player->getId()), ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $this->assertUserStatus(self::PLAYER_EMAIL, UserStatus::Active);
    }

    /**
     * G-17: an operator who deactivates themselves cannot undo it.
     */
    public function testASuperAdminCannotDeactivateThemselves(): void
    {
        $admin = $this->signInAsSuperAdmin();
        // A second Super Admin, so the refusal is about self-modification and not about the
        // last-admin rule.
        $this->createUser('second-admin@example.com', UserRole::SuperAdmin, name: 'Second Admin');

        $this->postStatusChange($admin->getId(), 'deactivate');

        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'You cannot deactivate your own account.');
        $this->assertUserStatus(self::ADMIN_EMAIL, UserStatus::Active);
    }

    /**
     * BR-023: `Deleted` is terminal, so an anonymized account cannot be brought back.
     */
    public function testAnAnonymizedAccountCannotBeReactivated(): void
    {
        $this->signInAsSuperAdmin();
        $deleted = $this->createUser('gone@example.com', UserRole::Player, UserStatus::Deleted, name: 'Deleted User');

        $this->postStatusChange($deleted->getId(), 'reactivate');

        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'cannot move from "deleted"');
        $this->assertUserStatus('gone@example.com', UserStatus::Deleted);
    }

    private function submitStatusForm(string $accessibleName): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $button = $crawler->selectButton($accessibleName);

        self::assertGreaterThan(0, $button->count(), \sprintf('No "%s" button in the directory.', $accessibleName));

        $this->client->submit($button->form());
    }

    private function postStatusChange(?int $userId, string $action): void
    {
        self::assertNotNull($userId);

        $crawler = $this->client->request('GET', '/admin/users');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', \sprintf('/admin/users/%d/%s', $userId, $action), ['_token' => $token]);
    }
}
