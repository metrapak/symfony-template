<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Enum\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every `/admin` route, against every role that must not reach it.
 *
 * A matrix rather than one test per controller: the gate is a single `access_control` rule,
 * and the failure mode worth catching is a route added later that sits outside the prefix it
 * protects.
 */
final class AdminAuthorizationTest extends AdminWebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function adminRoutes(): iterable
    {
        yield 'directory' => ['GET', '/admin/users'];
        yield 'create form' => ['GET', '/admin/users/new'];
        yield 'create submit' => ['POST', '/admin/users/new'];
        yield 'edit form' => ['GET', '/admin/users/1/edit'];
        yield 'edit submit' => ['POST', '/admin/users/1/edit'];
        yield 'deactivate' => ['POST', '/admin/users/1/deactivate'];
        yield 'reactivate' => ['POST', '/admin/users/1/reactivate'];
        yield 'delete form' => ['GET', '/admin/users/1/delete'];
        yield 'delete submit' => ['POST', '/admin/users/1/delete'];
        yield 'impersonate' => ['POST', '/admin/users/1/impersonate'];
        yield 'audit report' => ['GET', '/admin/audit/impersonations'];
        yield 'admin dashboard' => ['GET', '/admin'];
    }

    #[DataProvider('adminRoutes')]
    public function testAnonymousVisitorsAreSentToLogin(string $method, string $path): void
    {
        $this->client->request($method, $path);

        self::assertResponseRedirects('http://localhost/login');
    }

    #[DataProvider('adminRoutes')]
    public function testTrainersAreRefused(string $method, string $path): void
    {
        $this->assertRoleIsRefused(UserRole::Trainer, 'tara@example.com', $method, $path);
    }

    #[DataProvider('adminRoutes')]
    public function testCoachesAreRefused(string $method, string $path): void
    {
        $this->assertRoleIsRefused(UserRole::Coach, 'casey@example.com', $method, $path);
    }

    #[DataProvider('adminRoutes')]
    public function testPlayersAreRefused(string $method, string $path): void
    {
        $this->assertRoleIsRefused(UserRole::Player, 'pat@example.com', $method, $path);
    }

    /**
     * The exit route deliberately sits outside `/admin`, because during a switch the operator
     * carries the target's roles. It must still refuse someone who is not impersonating.
     */
    public function testTheImpersonationExitRouteIsNotAWayIntoTheAdminArea(): void
    {
        $this->createUser('pat@example.com', UserRole::Player, name: 'Pat Player');
        $this->submitLogin('pat@example.com');

        $this->client->request('GET', '/impersonation/exit');

        // Sent back to their own dashboard, not into the admin area and not bounced to the
        // login page for a switch that was never active.
        self::assertResponseRedirects('/dashboard');

        $this->client->followRedirect();
        self::assertResponseRedirects('/family');
    }

    private function assertRoleIsRefused(UserRole $role, string $email, string $method, string $path): void
    {
        $this->createUser($email, $role, name: 'Signed In User');
        $this->submitLogin($email);

        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(403);
    }
}
