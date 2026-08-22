<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Security\SessionIdleTimeoutSubscriber;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * What happens to an already-authenticated session when the world changes underneath it.
 *
 * The firewall's user checker never sees these requests — ContextListener reloads the user
 * from the database but does not re-run user checkers — so everything here is carried by
 * User::isEqualTo() and by SessionIdleTimeoutSubscriber. Without tests at this level, both
 * look like dead code to the next person reading them.
 */
class SessionLifecycleTest extends AccountWebTestCase
{
    use ClockSensitiveTrait;

    public function testDeactivatingAnAccountEndsItsLiveSession(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful('The session should work while the account is active.');

        $this->reloadUser('user@example.com')->setStatus(UserStatus::Inactive);
        $this->entityManager->flush();

        $this->client->request('GET', '/trainer');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testDeletingAnAccountEndsItsLiveSession(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->reloadUser('user@example.com')->setStatus(UserStatus::Deleted);
        $this->entityManager->flush();

        $this->client->request('GET', '/trainer');

        self::assertResponseRedirects();
    }

    /**
     * A demotion must not leave the old role's access alive until the session expires.
     */
    public function testChangingAnAccountsRoleEndsItsLiveSession(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->reloadUser('user@example.com')->setRole(UserRole::Player);
        $this->entityManager->flush();

        $this->client->request('GET', '/trainer');

        self::assertResponseRedirects();
    }

    public function testASessionIdleBeyondTheConfiguredWindowIsSignedOut(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        // First request stamps the activity time.
        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();

        // The default window is 7 days (app.session_idle_ttl).
        static::mockTime('+604801 seconds');

        $this->client->request('GET', '/trainer');
        self::assertResponseRedirects('/login');

        $body = $this->client->followRedirect()->filter('body')->text();
        self::assertStringContainsString(SessionIdleTimeoutSubscriber::EXPIRED_MESSAGE, $body);

        // The session was invalidated, not merely redirected away from: going back to a
        // protected page still bounces.
        $this->client->request('GET', '/trainer');
        self::assertResponseRedirects();
    }

    public function testActivityInsideTheWindowKeepsTheSessionAlive(): void
    {
        $user = $this->createUser('user@example.com', UserRole::Trainer);
        $this->client->loginUser($user);

        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();

        // Six days idle, then a request: still inside the window, and it re-stamps activity.
        static::mockTime('+518400 seconds');
        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();

        // Six more days (mockTime is relative to the mocked now, so twelve days in total).
        // An absolute seven-day cap would have cut this off; an idle window slides.
        static::mockTime('+518400 seconds');
        $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();
    }

    /**
     * The subscriber must not start a session for visitors who do not have one, or the
     * public pages pay for session storage and a firewall wake-up on every hit.
     */
    public function testAnonymousRequestsToPublicPagesGetNoSessionCookie(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getCookieJar()->get('MOCKSESSID'));
    }
}
