<?php

declare(strict_types=1);

namespace App\Tests\Approval\Unit\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Approval\Enum\PaymentType;
use App\Approval\Service\ApprovalRequestFactory;
use App\Profile\Entity\PlayerProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decision matrix (FR-091, FR-092, BR-090, BR-091, BR-095).
 *
 * Stated exhaustively — account kind × payment type × setting — because the rule is small, the
 * consequences of getting one cell wrong are somebody's child spending real money, and the whole
 * matrix is decidable without a database. `approvalIsRequired()` is static and pure for exactly
 * this reason.
 *
 * The profiles are built with the entity's own named constructors and reflection for the ids,
 * which is the same thing `MembershipWebTestCase` does: `PlayerProfile` has no setter for its
 * account, and the rule under test is about identity.
 */
final class ApprovalRequestFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, PaymentType, bool, bool}>
     */
    public static function matrix(): iterable
    {
        // A child, buying for themselves: the only case any of this constrains.
        yield 'child, dollars, setting off' => ['child', PaymentType::Usd, false, true];
        // BR-090: no setting can waive dollars. The cell most worth having a test for.
        yield 'child, dollars, setting on' => ['child', PaymentType::Usd, true, true];
        // BR-091's default.
        yield 'child, tokens, setting off' => ['child', PaymentType::Token, false, true];
        // FR-092's waiver.
        yield 'child, tokens, setting on' => ['child', PaymentType::Token, true, false];

        // A parent buying for their child is the person the approval would be asked of.
        yield 'parent for their child, dollars' => ['parent', PaymentType::Usd, false, false];
        yield 'parent for their child, tokens' => ['parent', PaymentType::Token, false, false];

        // An adult's own profile is not managed by anybody else.
        yield 'adult for themselves, dollars' => ['adult', PaymentType::Usd, false, false];
        yield 'adult for themselves, tokens' => ['adult', PaymentType::Token, false, false];
    }

    #[DataProvider('matrix')]
    public function testTheDecisionMatrix(string $actorKind, PaymentType $paymentType, bool $waived, bool $expected): void
    {
        [$profile, $actor] = self::scenario($actorKind);

        self::assertSame(
            $expected,
            ApprovalRequestFactory::approvalIsRequired($profile, $actor, $paymentType, $waived),
        );
    }

    /**
     * BR-065's definition of a child, and the reason the rule reads the profile rather than the
     * role: a child login holds `ROLE_PLAYER` exactly like their parent.
     */
    public function testTheRuleDoesNotDependOnTheRole(): void
    {
        [$profile, $child] = self::scenario('child');

        self::assertSame(UserRole::Player, $child->getRole());
        self::assertTrue(ApprovalRequestFactory::approvalIsRequired($profile, $child, PaymentType::Usd, false));

        [$ownProfile, $adult] = self::scenario('adult');

        self::assertSame(UserRole::Player, $adult->getRole());
        self::assertFalse(ApprovalRequestFactory::approvalIsRequired($ownProfile, $adult, PaymentType::Usd, false));
    }

    /**
     * A stranger who somehow reached the decision with somebody else's profile is not that
     * profile's child, so the rule says no approval — which is correct and harmless, because the
     * voter refused them long before this (`ApprovalVoter::START`).
     */
    public function testAnUnrelatedActorIsNotTreatedAsTheChild(): void
    {
        [$profile] = self::scenario('child');
        $stranger = self::user(99, 'Stranger');

        self::assertFalse(ApprovalRequestFactory::approvalIsRequired($profile, $stranger, PaymentType::Usd, false));
    }

    /**
     * @return array{PlayerProfile, User}
     */
    private static function scenario(string $actorKind): array
    {
        $now = new \DateTimeImmutable();
        $parent = self::user(1, 'Dana Parent');

        if ('adult' === $actorKind) {
            $profile = PlayerProfile::forSelf($parent, 'Dana Parent', $now);

            return [$profile, $parent];
        }

        $childAccount = self::user(2, 'Maya Parent');
        $profile = PlayerProfile::forChildOf($parent, 'Maya Parent', $now);
        $profile->attachLogin($childAccount, $now);

        return [$profile, 'child' === $actorKind ? $childAccount : $parent];
    }

    private static function user(int $id, string $name): User
    {
        $user = new User(\sprintf('user-%d@example.test', $id), $name, UserRole::Player, new \DateTimeImmutable());

        // Ids are database-assigned and the rule under test compares them, so the test has to
        // supply them. The same reflection `MembershipWebTestCase` uses for child logins.
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
