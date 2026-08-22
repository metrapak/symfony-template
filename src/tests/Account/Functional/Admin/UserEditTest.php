<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;

/**
 * FR-023 — a Super Admin editing any account, including its role.
 */
final class UserEditTest extends AdminWebTestCase
{
    private const PLAYER_EMAIL = 'pat@example.com';

    public function testEditsProfileFields(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->client->request('GET', \sprintf('/admin/users/%d/edit', $player->getId()));
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Save changes', [
            'edit_user_form[name]' => 'Patricia Player',
            'edit_user_form[email]' => 'patricia@example.com',
            'edit_user_form[phone]' => '020 7946 1234',
            'edit_user_form[role]' => UserRole::Player->value,
        ]);

        self::assertResponseRedirects('/admin/users');

        $updated = $this->reloadUser('patricia@example.com');
        self::assertSame('Patricia Player', $updated->getName());
        self::assertSame('020 7946 1234', $updated->getPhone());
    }

    public function testChangesRole(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->submitEdit((int) $player->getId(), ['edit_user_form[role]' => UserRole::Coach->value]);

        self::assertSame(UserRole::Coach, $this->reloadUser(self::PLAYER_EMAIL)->getRole());
    }

    /**
     * The same uniqueness rule as self-service editing — validated at the boundary and
     * enforced by the index behind it.
     */
    public function testAnEmailAlreadyInUseIsRejectedOnTheField(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');
        $this->createUser('taken@example.com', UserRole::Player, name: 'Someone Else');

        $this->submitEdit((int) $player->getId(), ['edit_user_form[email]' => 'taken@example.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'An account already exists for "taken@example.com".');
        self::assertSame(self::PLAYER_EMAIL, $this->reloadUser(self::PLAYER_EMAIL)->getEmail());
    }

    /**
     * Saving a user without touching their address must not trip the uniqueness check against
     * their own row.
     */
    public function testSavingWithTheSameEmailIsAllowed(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->submitEdit((int) $player->getId(), ['edit_user_form[name]' => 'Pat P']);

        self::assertResponseRedirects('/admin/users');
        self::assertSame('Pat P', $this->reloadUser(self::PLAYER_EMAIL)->getName());
    }

    public function testInvalidInputIsRejected(): void
    {
        $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->submitEdit((int) $player->getId(), [
            'edit_user_form[name]' => '',
            'edit_user_form[email]' => 'not-an-email',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error-summary', 'Enter a name.');
        self::assertSelectorTextContains('.form-error-summary', 'Enter a valid email address.');
    }

    /**
     * G-17: demoting the last Super Admin is the same lockout as deactivating them, and it is
     * easier to do by accident because the role is just another dropdown.
     */
    public function testTheLastSuperAdminCannotBeDemoted(): void
    {
        $admin = $this->signInAsSuperAdmin();

        $this->submitEdit((int) $admin->getId(), ['edit_user_form[role]' => UserRole::Trainer->value]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'last active Super Admin');
        self::assertSame(UserRole::SuperAdmin, $this->reloadUser(self::ADMIN_EMAIL)->getRole());
    }

    public function testEditingIsAuditedWithBeforeAndAfterValues(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $player = $this->createUser(self::PLAYER_EMAIL, UserRole::Player, name: 'Pat Player');

        $this->submitEdit((int) $player->getId(), [
            'edit_user_form[name]' => 'Patricia Player',
            'edit_user_form[role]' => UserRole::Coach->value,
        ]);

        $entries = $this->auditEntries(AuditAction::UserUpdated);
        self::assertCount(1, $entries);
        self::assertSame($admin->getId(), $entries[0]->getActor()->getId());

        $payload = $entries[0]->getPayload();
        self::assertSame('Pat Player', $payload['nameBefore']);
        self::assertSame('Patricia Player', $payload['nameAfter']);
        self::assertSame(UserRole::Player->value, $payload['roleBefore']);
        self::assertSame(UserRole::Coach->value, $payload['roleAfter']);
    }

    /**
     * A demotion that only took effect at session expiry would let a demoted trainer keep
     * browsing trainer pages until then. `User::isEqualTo()` compares the role, so
     * ContextListener de-authenticates the stale session on its very next request.
     *
     * The demotion is applied through the service rather than through a second browser: a
     * WebTestCase may only boot one kernel, and what is under test here is the eviction, not
     * the HTTP path that triggers it (covered by testChangesRole).
     */
    public function testChangingARoleEndsTheEditedUsersExistingSession(): void
    {
        $this->createUser('tara@example.com', UserRole::Trainer, name: 'Tara Trainer');
        $this->createUser(self::ADMIN_EMAIL, UserRole::SuperAdmin, name: 'Ada Admin');

        $this->submitLogin('tara@example.com');
        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();

        $entityManager = $this->freshEntityManager();
        $trainer = $entityManager->getRepository(\App\Account\Entity\User::class)->findOneBy(['email' => 'tara@example.com']);
        $admin = $entityManager->getRepository(\App\Account\Entity\User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        self::assertNotNull($trainer);
        self::assertNotNull($admin);

        $input = \App\Account\Dto\EditUserInput::fromUser($trainer);
        $input->role = UserRole::Coach;

        static::getContainer()->get(\App\Account\Service\UserProfileEditor::class)->apply($trainer, $input, $admin);

        $this->client->request('GET', '/trainer');
        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * @param array<string, string> $overrides
     */
    private function submitEdit(int $userId, array $overrides): void
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%d/edit', $userId));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $form->setValues($overrides);

        $this->client->submit($form);
    }
}
