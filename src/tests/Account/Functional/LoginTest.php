<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Security\AccountStatusChecker;
use PHPUnit\Framework\Attributes\DataProvider;

class LoginTest extends AccountWebTestCase
{
    /**
     * @return iterable<string, array{UserRole, string}>
     */
    public static function roleLandingProvider(): iterable
    {
        yield 'super admin' => [UserRole::SuperAdmin, '/admin'];
        yield 'trainer' => [UserRole::Trainer, '/trainer'];
        yield 'coach' => [UserRole::Coach, '/coach'];
        yield 'player' => [UserRole::Player, '/family'];
    }

    #[DataProvider('roleLandingProvider')]
    public function testEachRoleLandsOnItsOwnDashboard(UserRole $role, string $expectedPath): void
    {
        $this->createUser('user@example.com', $role);

        $this->submitLogin('user@example.com');

        // form_login sends the user to /dashboard, which redirects on to the role's own URL.
        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertResponseRedirects($expectedPath);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testLoginIsCaseInsensitiveOnTheEmailAddress(): void
    {
        $this->createUser('mixed.case@example.com', UserRole::Trainer);

        $this->submitLogin('Mixed.Case@Example.COM');

        self::assertResponseRedirects('/dashboard');
    }

    public function testWrongPasswordShowsAGenericError(): void
    {
        $this->createUser('user@example.com');

        $this->submitLogin('user@example.com', 'WrongPassword123');

        $crawler = $this->client->followRedirect();
        $body = $crawler->filter('body')->text();

        self::assertStringContainsString('Invalid credentials', $body);
        // No hint that the account exists — the same wording an unknown address gets.
        self::assertStringNotContainsString('user@example.com does not exist', $body);
    }

    public function testUnknownEmailIsIndistinguishableFromAWrongPassword(): void
    {
        $this->createUser('known@example.com');

        $this->submitLogin('known@example.com', 'WrongPassword123');
        $knownBody = $this->client->followRedirect()->filter('.form-error-summary')->text();

        $this->submitLogin('nobody@example.com', 'WrongPassword123');
        $unknownBody = $this->client->followRedirect()->filter('.form-error-summary')->text();

        self::assertSame(trim($knownBody), trim($unknownBody));
    }

    public function testInactiveAccountIsRefusedWithTheSpecifiedMessage(): void
    {
        $this->createUser('inactive@example.com', UserRole::Player, UserStatus::Inactive);

        $this->submitLogin('inactive@example.com');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(AccountStatusChecker::INACTIVE_MESSAGE, $crawler->filter('body')->text());
    }

    public function testDeletedAccountIsRefusedAndCannotReachADashboard(): void
    {
        $this->createUser('deleted@example.com', UserRole::Player, UserStatus::Deleted);

        $this->submitLogin('deleted@example.com');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(AccountStatusChecker::DELETED_MESSAGE, $crawler->filter('body')->text());

        $this->client->request('GET', '/family');
        self::assertResponseRedirects();
    }

    public function testUnverifiedPlayerCannotSignInWhileTheGateIsOn(): void
    {
        $this->createUser('unverified@example.com', UserRole::Player, verified: false);

        $this->submitLogin('unverified@example.com');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(AccountStatusChecker::UNVERIFIED_MESSAGE, $crawler->filter('body')->text());
    }

    public function testUnverifiedTrainerCanSignIn(): void
    {
        $this->createUser('trainer@example.com', UserRole::Trainer, verified: false);

        $this->submitLogin('trainer@example.com');

        self::assertResponseRedirects('/dashboard');
    }

    public function testSuccessfulLoginRecordsTheLastLoginTime(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);
        self::assertNull($this->reloadUser('user@example.com')->getLastLoginAt());

        $this->submitLogin('user@example.com');
        self::assertResponseRedirects('/dashboard');

        $lastLoginAt = $this->reloadUser('user@example.com')->getLastLoginAt();

        self::assertNotNull($lastLoginAt, 'A successful login must be stamped on the account.');
        self::assertLessThan(60, abs(time() - $lastLoginAt->getTimestamp()));
    }

    public function testRefusedLoginIsNotRecordedAsALogin(): void
    {
        $this->createUser('inactive@example.com', UserRole::Player, UserStatus::Inactive);

        $this->submitLogin('inactive@example.com');

        // The user checker refused the account, so nothing about this was a login.
        self::assertNull($this->reloadUser('inactive@example.com')->getLastLoginAt());
    }

    public function testWrongPasswordIsNotRecordedAsALogin(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->submitLogin('user@example.com', 'WrongPassword123');

        self::assertNull($this->reloadUser('user@example.com')->getLastLoginAt());
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);
        $this->submitLogin('user@example.com');

        $this->clickSignOut('/trainer');
        $this->client->request('GET', '/trainer');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Otherwise any third-party page could sign a visitor out with an <img src="/logout">.
     */
    public function testLogoutWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);
        $this->submitLogin('user@example.com');

        $this->client->request('GET', '/logout?_csrf_token=not-a-valid-token');
        self::assertResponseStatusCodeSame(403);

        // And the session it tried to end is still usable.
        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();
    }

    public function testLoginFormIsRejectedWithoutAValidCsrfToken(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->client->request('POST', '/login', [
            '_username' => 'user@example.com',
            '_password' => self::PASSWORD,
            '_csrf_token' => 'not-a-valid-token',
        ]);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Invalid CSRF token', $crawler->filter('body')->text());
    }

    public function testAnonymousUserIsSentToLoginFromTheDashboardHub(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
