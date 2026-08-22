<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Shared setup for the account functional tests: a booted client, a fresh entity manager
 * and one place that knows how to build a user. DAMA rolls the database back per test.
 */
abstract class AccountWebTestCase extends WebTestCase
{
    protected const PASSWORD = 'Password123';

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->clearRateLimiterState();
    }

    protected function tearDown(): void
    {
        $this->clearRateLimiterState();

        parent::tearDown();
    }

    /**
     * Rate limiter counters live in a cache pool, which outlives the per-test database
     * rollback. Without this, any test that trips a limit poisons the ones after it.
     */
    protected function clearRateLimiterState(): void
    {
        foreach (['cache.rate_limiter', 'cache.app'] as $poolId) {
            if (!static::getContainer()->has($poolId)) {
                continue;
            }

            $pool = static::getContainer()->get($poolId);

            if ($pool instanceof CacheItemPoolInterface) {
                $pool->clear();
            }
        }
    }

    protected function createUser(
        string $email,
        UserRole $role = UserRole::Player,
        UserStatus $status = UserStatus::Active,
        bool $verified = true,
        bool $mustChangePassword = false,
        string $password = self::PASSWORD,
        string $name = 'Test User',
    ): User {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User($email, $name, $role, new \DateTimeImmutable());
        $user->setStatus($status);
        $user->setMustChangePassword($mustChangePassword);
        $user->setPassword($hasher->hashPassword($user, $password));

        if ($verified) {
            $user->markEmailVerified(new \DateTimeImmutable());
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    protected function createOrganizationFor(User $trainer, string $name = 'Example Academy'): Organization
    {
        $organization = new Organization($name, $trainer, new \DateTimeImmutable());

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        return $organization;
    }

    /**
     * Re-reads a user from the database after a request.
     *
     * The kernel reboots between requests, so the entity from setUp() is detached by then
     * and refresh() would reject it. Going back through a freshly resolved entity manager
     * also proves the change was actually written, not just held in an identity map.
     */
    protected function reloadUser(string $email): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower($email)]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    protected function assertPasswordIs(string $email, string $expectedPlaintext): void
    {
        $user = $this->reloadUser($email);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        self::assertTrue(
            $hasher->isPasswordValid($user, $expectedPlaintext),
            \sprintf('Expected the stored password for %s to match the given plaintext.', $email),
        );
    }

    /**
     * Signs in through the real login form, so the firewall, the user checker and CSRF are
     * all exercised rather than bypassed.
     */
    protected function submitLogin(string $email, string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => $email,
            '_password' => $password,
        ]);
    }

    /**
     * Signs out by clicking the rendered link on the given page.
     *
     * Logout is CSRF-protected, so the token has to come from where a real user's click would
     * get it — `logout_path()` in the template — rather than from a hand-built URL.
     */
    protected function clickSignOut(string $fromPath): void
    {
        $crawler = $this->client->request('GET', $fromPath);

        $this->client->click($crawler->selectLink('Sign out')->link());
    }
}
