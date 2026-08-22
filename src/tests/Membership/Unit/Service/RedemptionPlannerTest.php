<?php

declare(strict_types=1);

namespace App\Tests\Membership\Unit\Service;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\RedemptionAction;
use App\Membership\Enum\ShareLinkType;
use App\Membership\Service\RedemptionPlanner;
use App\Membership\ValueObject\ShareLinkCode;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\PlayerProfileRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decision table for `/join/{code}`: who may do what with which kind of link.
 */
final class RedemptionPlannerTest extends TestCase
{
    private const NOW = '2026-08-22 12:00:00';

    public function testAnonymousVisitorsAreSentToTheMatchingRegistrationForm(): void
    {
        self::assertSame(
            RedemptionAction::RegisterPlayer,
            $this->planner()->planFor($this->link(ShareLinkType::Player), null)->action,
        );

        self::assertSame(
            RedemptionAction::RegisterCoach,
            $this->planner()->planFor($this->link(ShareLinkType::Coach), null)->action,
        );
    }

    public function testAPlayerOpeningAPlayerLinkIsOfferedTheirFamily(): void
    {
        $player = $this->user(UserRole::Player);
        $self = PlayerProfile::forSelf($player, 'Pat Player', $this->now());
        $child = PlayerProfile::forChildOf($player, 'Sam Player', $this->now());

        $plan = $this->planner($self, [$self, $child])->planFor($this->link(ShareLinkType::Player), $player);

        self::assertSame(RedemptionAction::AssociatePlayer, $plan->action);
        self::assertSame([$self, $child], $plan->profiles);
    }

    /**
     * FR-048 / BR-046. Checked before the association branch, because a child account holds
     * ROLE_PLAYER like any other and would otherwise fall straight through it.
     */
    public function testAChildIsBlockedRatherThanAssociated(): void
    {
        $parent = $this->user(UserRole::Player);
        $childAccount = $this->user(UserRole::Player, 'child@example.com');

        $child = PlayerProfile::forChildOf($parent, 'Sam Player', $this->now());
        (new \ReflectionProperty(PlayerProfile::class, 'account'))->setValue($child, $childAccount);

        $plan = $this->planner($child, [])->planFor($this->link(ShareLinkType::Player), $childAccount);

        self::assertSame(RedemptionAction::BlockChild, $plan->action);
        self::assertSame($child, $plan->childProfile);
    }

    public function testACoachOpeningACoachInvitationMayAcceptIt(): void
    {
        $plan = $this->planner()->planFor($this->link(ShareLinkType::Coach), $this->user(UserRole::Coach));

        self::assertSame(RedemptionAction::AcceptCoachInvitation, $plan->action);
    }

    #[DataProvider('mismatchedPairs')]
    public function testMismatchedRoleAndLinkTypeIsRefusedWithAReason(UserRole $role, ShareLinkType $type): void
    {
        $plan = $this->planner()->planFor($this->link($type), $this->user($role));

        self::assertSame(RedemptionAction::NotEligible, $plan->action);
        self::assertNotNull($plan->reason);
    }

    /**
     * @return iterable<string, array{UserRole, ShareLinkType}>
     */
    public static function mismatchedPairs(): iterable
    {
        yield 'player holding a coach invitation' => [UserRole::Player, ShareLinkType::Coach];
        yield 'coach holding a player link' => [UserRole::Coach, ShareLinkType::Player];
        yield 'trainer holding a player link' => [UserRole::Trainer, ShareLinkType::Player];
        yield 'trainer holding a coach invitation' => [UserRole::Trainer, ShareLinkType::Coach];
        yield 'super admin holding a player link' => [UserRole::SuperAdmin, ShareLinkType::Player];
    }

    /**
     * @param list<PlayerProfile> $managed
     */
    private function planner(?PlayerProfile $forAccount = null, array $managed = []): RedemptionPlanner
    {
        $profiles = $this->createMock(PlayerProfileRepository::class);
        $profiles->method('findProfileForAccount')->willReturn($forAccount);
        $profiles->method('findManagedBy')->willReturn($managed);

        return new RedemptionPlanner($profiles);
    }

    private function link(ShareLinkType $type): ShareLink
    {
        $trainer = $this->user(UserRole::Trainer, 'trainer@example.com');

        return new ShareLink(
            ShareLinkCode::generate(),
            $type,
            new Organization('Example Academy', $trainer, $this->now()),
            $trainer,
            $this->now(),
        );
    }

    private function user(UserRole $role, string $email = 'user@example.com'): User
    {
        return new User($email, 'Test User', $role, $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
