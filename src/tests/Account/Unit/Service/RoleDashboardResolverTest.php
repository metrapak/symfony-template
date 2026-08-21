<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Service;

use App\Account\Enum\UserRole;
use App\Account\Service\RoleDashboardResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleDashboardResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{UserRole, string}>
     */
    public static function roleRouteProvider(): iterable
    {
        yield 'super admin' => [UserRole::SuperAdmin, 'admin_dashboard'];
        yield 'trainer' => [UserRole::Trainer, 'trainer_dashboard'];
        yield 'coach' => [UserRole::Coach, 'coach_dashboard'];
        yield 'player' => [UserRole::Player, 'family_dashboard'];
    }

    #[DataProvider('roleRouteProvider')]
    public function testEveryRoleResolvesToItsOwnDashboardRoute(UserRole $role, string $expected): void
    {
        self::assertSame($expected, (new RoleDashboardResolver())->resolveRouteName($role));
    }

    public function testEveryRoleHasADistinctDashboard(): void
    {
        $resolver = new RoleDashboardResolver();

        $routes = array_map(
            static fn (UserRole $role): string => $resolver->resolveRouteName($role),
            UserRole::cases(),
        );

        self::assertSame($routes, array_unique($routes));
    }
}
