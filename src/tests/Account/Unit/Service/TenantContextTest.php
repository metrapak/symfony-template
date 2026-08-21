<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Service;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Exception\NoOrganizationInContext;
use App\Account\Repository\OrganizationRepository;
use App\Account\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class TenantContextTest extends TestCase
{
    public function testTrainerResolvesToTheirOwnOrganization(): void
    {
        $trainer = $this->user(UserRole::Trainer);
        $organization = $this->organizationWithId($trainer, 42);

        $context = new TenantContext(
            $this->securityReturning($trainer),
            $this->organizationsReturning($organization),
        );

        self::assertSame(42, $context->currentOrganizationId());
        self::assertSame(42, $context->requireOrganizationId());
    }

    public function testTrainerWithoutAnOrganizationHasNoTenant(): void
    {
        $trainer = $this->user(UserRole::Trainer);

        $context = new TenantContext(
            $this->securityReturning($trainer),
            $this->organizationsReturning(null),
        );

        self::assertNull($context->currentOrganizationId());
    }

    /**
     * A player has no single organization — they have a selected training context, which a
     * later task resolves separately. This must not silently answer with something wrong.
     */
    public function testPlayerHasNoTenant(): void
    {
        $context = new TenantContext(
            $this->securityReturning($this->user(UserRole::Player)),
            $this->organizationsReturning(null),
        );

        self::assertNull($context->currentOrganizationId());
    }

    public function testSuperAdminHasNoTenant(): void
    {
        $context = new TenantContext(
            $this->securityReturning($this->user(UserRole::SuperAdmin)),
            $this->organizationsReturning(null),
        );

        self::assertNull($context->currentOrganizationId());
    }

    public function testAnonymousRequestHasNoTenant(): void
    {
        $context = new TenantContext(
            $this->securityReturning(null),
            $this->organizationsReturning(null),
        );

        self::assertNull($context->currentOrganizationId());
    }

    public function testRequireOrganizationIdThrowsWhenThereIsNoTenant(): void
    {
        $context = new TenantContext(
            $this->securityReturning($this->user(UserRole::Player)),
            $this->organizationsReturning(null),
        );

        $this->expectException(NoOrganizationInContext::class);

        $context->requireOrganizationId();
    }

    private function user(UserRole $role): User
    {
        return new User('user@example.com', $role, new \DateTimeImmutable('2026-08-21 09:00:00'));
    }

    private function organizationWithId(User $owner, int $id): Organization
    {
        $organization = new Organization('Example Academy', $owner, new \DateTimeImmutable('2026-08-21 09:00:00'));

        // Ids are assigned by the database; this stands in for a persisted row.
        $reflection = new \ReflectionProperty(Organization::class, 'id');
        $reflection->setValue($organization, $id);

        return $organization;
    }

    private function securityReturning(?User $user): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function organizationsReturning(?Organization $organization): OrganizationRepository
    {
        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('findOneByOwner')->willReturn($organization);

        return $organizations;
    }
}
