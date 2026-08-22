<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Enum\UserRole;

/**
 * FR-003: repeated failed logins are throttled.
 *
 * The limiter state lives in cache.app, which outlives a database rollback, so it has to be
 * cleared explicitly or this test only passes once.
 */
class LoginThrottlingTest extends AccountWebTestCase
{
    public function testRepeatedFailedLoginsAreEventuallyThrottled(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        // max_attempts is 5, so the first five are refused as bad credentials.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submitLogin('user@example.com', 'WrongPassword123');
            $body = $this->client->followRedirect()->filter('body')->text();

            self::assertStringContainsString(
                'Invalid credentials',
                $body,
                \sprintf('Attempt %d should still report bad credentials.', $attempt),
            );
        }

        $this->submitLogin('user@example.com', 'WrongPassword123');
        $body = $this->client->followRedirect()->filter('body')->text();

        self::assertStringNotContainsString('Invalid credentials', $body);
        self::assertMatchesRegularExpression('/too many|later|attempt/i', $body);
    }

    /**
     * Throttling must not lock out a user who then supplies the right password within the
     * allowance — only once the limit is actually reached.
     */
    public function testCorrectPasswordStillWorksBelowTheLimit(): void
    {
        $this->createUser('user@example.com', UserRole::Trainer);

        $this->submitLogin('user@example.com', 'WrongPassword123');
        $this->client->followRedirect();

        $this->submitLogin('user@example.com');

        self::assertResponseRedirects('/dashboard');
    }
}
