<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Enum\UserRole;
use App\Account\Service\PasswordChanger;

class PasswordChangeTest extends AccountWebTestCase
{
    public function testAuthenticatedUserCanChangeTheirPassword(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/password');
        self::assertResponseIsSuccessful();

        $this->submitChangeForm(self::PASSWORD, 'BrandNewPass1');

        self::assertResponseRedirects('/dashboard');

        $this->assertPasswordIs('user@example.com', 'BrandNewPass1');
    }

    public function testWrongCurrentPasswordIsRejectedAndLeavesThePasswordAlone(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/password');
        $this->submitChangeForm('NotMyPassword1', 'BrandNewPass1');

        self::assertResponseIsUnprocessable();

        $this->assertPasswordIs('user@example.com', self::PASSWORD);
    }

    /**
     * The session that performed the change must keep working — being signed out of your own
     * browser by your own password change is what makes people avoid changing it.
     */
    public function testTheSessionThatChangedThePasswordStaysSignedIn(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/password');
        $this->submitChangeForm(self::PASSWORD, 'BrandNewPass1');

        $this->client->request('GET', '/trainer');

        self::assertResponseIsSuccessful();
    }

    /**
     * ...while every other session that account has open must stop working. Someone changing
     * their password because a thief has the old one has not recovered anything if the
     * thief's session survives. The reset flow reaches the same service, so it is covered by
     * the same stamp.
     */
    public function testAPasswordChangeEndsSessionsEstablishedBeforeIt(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);

        // A session established before the change — another browser, or a thief's.
        $this->client->loginUser($user);
        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful('The session should work before the password changes.');

        // The password is changed elsewhere, so this client's session never sees the write.
        $elsewhere = $this->reloadUser('user@example.com');
        static::getContainer()->get(PasswordChanger::class)->change($elsewhere, 'BrandNewPass1');

        $this->client->request('GET', '/trainer');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testWeakNewPasswordIsRejected(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/password');
        $this->submitChangeForm(self::PASSWORD, 'short');

        self::assertResponseIsUnprocessable();

        $this->assertPasswordIs('user@example.com', self::PASSWORD);
    }

    public function testMismatchedConfirmationIsRejected(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/password');
        $this->submitChangeForm(self::PASSWORD, 'BrandNewPass1', 'DifferentPass1');

        self::assertResponseIsUnprocessable();
    }

    public function testChangeFormIsRejectedWithoutAValidCsrfToken(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('POST', '/account/password', [
            'change_password_form' => [
                'currentPassword' => self::PASSWORD,
                'plainPassword' => ['first' => 'BrandNewPass1', 'second' => 'BrandNewPass1'],
                '_token' => 'not-a-valid-token',
            ],
        ]);

        self::assertResponseIsUnprocessable();

        $this->assertPasswordIs('user@example.com', self::PASSWORD);
    }

    // FR-006 — the forced-change guard.

    public function testFlaggedUserIsRedirectedAwayFromEveryOtherRoute(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer, mustChangePassword: true);
        $this->client->loginUser($user);

        foreach (['/dashboard', '/trainer'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseRedirects('/account/password', null, \sprintf('Expected %s to be blocked.', $path));
        }
    }

    public function testFlaggedUserCanReachTheChangePageWithoutLooping(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer, mustChangePassword: true);
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/password');

        self::assertResponseIsSuccessful();
    }

    public function testFlaggedUserCanStillLogOut(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer, mustChangePassword: true);
        $this->client->loginUser($user);

        // The change-password page is the only page a flagged user can reach, so the sign-out
        // link has to be on it.
        $this->clickSignOut('/account/password');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/account/password', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSuccessfulChangeClearsTheFlagAndUnblocksTheApplication(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer, mustChangePassword: true);
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/password');
        $this->submitChangeForm(self::PASSWORD, 'BrandNewPass1');

        self::assertResponseRedirects('/dashboard');

        self::assertFalse($this->reloadUser('user@example.com')->mustChangePassword());

        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();
    }

    private function submitChangeForm(string $current, string $new, ?string $confirmation = null): void
    {
        $this->client->submitForm('Save new password', [
            'change_password_form[currentPassword]' => $current,
            'change_password_form[plainPassword][first]' => $new,
            'change_password_form[plainPassword][second]' => $confirmation ?? $new,
        ]);
    }
}
