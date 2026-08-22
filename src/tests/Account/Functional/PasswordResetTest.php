<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;

class PasswordResetTest extends AccountWebTestCase
{
    use ClockSensitiveTrait;

    private const CONFIRMATION = 'a password reset link is on its way';

    public function testFullResetFlowLetsTheUserSignInWithTheNewPassword(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->requestReset('user@example.com');
        self::assertEmailCount(1);

        $this->client->request('GET', $this->extractResetPath($this->getMailerMessage()));
        self::assertResponseRedirects('/password/reset');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->submitNewPassword('BrandNewPass1');
        self::assertResponseRedirects('/login');

        $this->assertPasswordIs('user@example.com', 'BrandNewPass1');

        $this->submitLogin('user@example.com', 'BrandNewPass1');
        self::assertResponseRedirects('/dashboard');
    }

    public function testUnknownEmailProducesTheSameResponseAndSendsNoMail(): void
    {
        $this->createUser('known@example.com', UserRole::Trainer);

        // Mailer assertions describe the most recent request, so they must run before the
        // redirect is followed.
        $this->requestReset('known@example.com');
        self::assertEmailCount(1);
        $knownBody = $this->client->followRedirect()->filter('body')->text();

        $this->requestReset('nobody@example.com');
        self::assertEmailCount(0);
        $unknownBody = $this->client->followRedirect()->filter('body')->text();

        self::assertStringContainsString(self::CONFIRMATION, $knownBody);
        self::assertSame($knownBody, $unknownBody);
    }

    public function testInactiveAccountGetsTheSameNeutralResponseAndNoMail(): void
    {
        $this->createUser('inactive@example.com', UserRole::Player, UserStatus::Inactive);

        $this->requestReset('inactive@example.com');
        self::assertEmailCount(0);

        self::assertStringContainsString(self::CONFIRMATION, $this->client->followRedirect()->filter('body')->text());
    }

    public function testASecondRequestInsideTheThrottleWindowSendsNoFurtherMail(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->requestReset('user@example.com');
        self::assertEmailCount(1);

        $this->requestReset('user@example.com');
        self::assertEmailCount(0);

        // Still the same confirmation — the throttle must not be observable from outside.
        self::assertStringContainsString(self::CONFIRMATION, $this->client->followRedirect()->filter('body')->text());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->requestReset('user@example.com');
        $resetPath = $this->extractResetPath($this->getMailerMessage());

        // BR-003: the link is valid for one hour.
        static::mockTime('+3601 seconds');

        $this->client->request('GET', $resetPath);
        $this->client->followRedirect();

        self::assertResponseRedirects('/password/forgot');
        self::assertStringContainsString('expired', $this->client->followRedirect()->filter('body')->text());
    }

    public function testTokenCannotBeUsedTwice(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->requestReset('user@example.com');
        $resetPath = $this->extractResetPath($this->getMailerMessage());

        $this->client->request('GET', $resetPath);
        $this->client->followRedirect();
        $this->submitNewPassword('BrandNewPass1');
        self::assertResponseRedirects('/login');

        // Replaying the same link must not offer the form again.
        $this->client->request('GET', $resetPath);
        $this->client->followRedirect();
        self::assertResponseRedirects('/password/forgot');

        // And the password set by the first use is untouched.
        $this->assertPasswordIs('user@example.com', 'BrandNewPass1');
    }

    public function testResetFormWithoutATokenInSessionSendsTheUserBack(): void
    {
        $this->client->request('GET', '/password/reset');

        self::assertResponseRedirects('/password/forgot');
    }

    public function testWeakNewPasswordIsRejectedAndTheTokenStaysUsable(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->requestReset('user@example.com');
        $this->client->request('GET', $this->extractResetPath($this->getMailerMessage()));
        $this->client->followRedirect();

        $this->submitNewPassword('short');
        self::assertResponseIsUnprocessable();

        $this->assertPasswordIs('user@example.com', self::PASSWORD);

        $this->submitNewPassword('BrandNewPass1');
        self::assertResponseRedirects('/login');
    }

    public function testTheEmailCarriesAPlainTextAlternative(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->requestReset('user@example.com');
        $email = $this->getMailerMessage();

        self::assertEmailAddressContains($email, 'to', 'user@example.com');
        self::assertEmailTextBodyContains($email, '/password/reset/');
        self::assertEmailHtmlBodyContains($email, '/password/reset/');
    }

    private function requestReset(string $email): void
    {
        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send reset link', ['forgot_password_form[email]' => $email]);
    }

    private function submitNewPassword(string $password): void
    {
        $this->client->submitForm('Save new password', [
            'reset_password_form[plainPassword][first]' => $password,
            'reset_password_form[plainPassword][second]' => $password,
        ]);
    }

    private function extractResetPath(Email $email): string
    {
        self::assertSame(1, preg_match('#/password/reset/[^"\s<]+#', $email->getTextBody(), $matches));

        return $matches[0];
    }
}
