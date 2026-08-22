<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional;

use App\Account\Enum\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-010: authorization is enforced server-side, not by hiding a link in a template.
 *
 * Every role is pointed at every role-gated route tree and must get 200 for its own and
 * 403 for the other three. Adding a fifth role or a fifth tree without updating the
 * access_control rules fails here.
 */
class AuthorizationMatrixTest extends AccountWebTestCase
{
    private const ROLE_PATHS = [
        UserRole::SuperAdmin->value => '/admin',
        UserRole::Trainer->value => '/trainer',
        UserRole::Coach->value => '/coach',
        UserRole::Player->value => '/family',
    ];

    /**
     * @return iterable<string, array{UserRole, string, int}>
     */
    public static function matrixProvider(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach (self::ROLE_PATHS as $ownerRole => $path) {
                $expected = $role->value === $ownerRole ? Response::HTTP_OK : Response::HTTP_FORBIDDEN;

                yield \sprintf('%s on %s', $role->value, $path) => [$role, $path, $expected];
            }
        }
    }

    #[DataProvider('matrixProvider')]
    public function testRoleAgainstEveryDashboardTree(UserRole $role, string $path, int $expectedStatus): void
    {
        $user = $this->createUser('user@example.com', $role);
        $this->client->loginUser($user);

        $this->client->request('GET', $path);

        self::assertResponseStatusCodeSame($expectedStatus);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedPathProvider(): iterable
    {
        yield 'admin' => ['/admin'];
        yield 'trainer' => ['/trainer'];
        yield 'coach' => ['/coach'];
        yield 'family' => ['/family'];
        yield 'dashboard hub' => ['/dashboard'];
        yield 'change password' => ['/account/password'];
        // TASK-003's trainer tools live under the same tree and inherit its rule.
        yield 'trainer player links' => ['/trainer/share-links'];
        yield 'trainer coaches' => ['/trainer/coaches'];
    }

    #[DataProvider('protectedPathProvider')]
    public function testAnonymousAccessRedirectsToLogin(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPathProvider(): iterable
    {
        yield 'login' => ['/login'];
        yield 'forgot password' => ['/password/forgot'];
        yield 'resend verification' => ['/verify/resend'];
    }

    #[DataProvider('publicPathProvider')]
    public function testPublicPathsAreReachableAnonymously(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    /**
     * FR-042 / FR-049: `/join/{code}` must answer an anonymous visitor. It answers an unknown
     * code with 404 and no hint, which is the point — what matters here is that the firewall
     * does not send them to the login page instead.
     */
    public function testTheRedemptionEndpointIsReachableAnonymously(): void
    {
        $this->client->request('GET', '/join/AAAA1111BBBB2222CCCC3333DD');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * The reset link is clicked by someone who is by definition not signed in.
     */
    public function testResetLinkIsReachableAnonymously(): void
    {
        $this->client->request('GET', '/password/reset/some-token');

        self::assertResponseRedirects('/password/reset');
    }

    /**
     * There is deliberately no public sign-up route: trainer accounts are never
     * self-registered (BR-008).
     */
    public function testThereIsNoPublicRegistrationRoute(): void
    {
        foreach (['/register', '/signup', '/sign-up', '/account/register'] as $path) {
            $this->client->request('GET', $path);

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $this->client->getResponse()->getStatusCode(),
                \sprintf('Expected no route at %s.', $path),
            );
        }
    }

    /**
     * Constraint: adding a catch-all access_control rule would lock the pre-existing public
     * pages. This is the test that notices.
     */
    public function testPreExistingPublicPagesStayPublic(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }
}
