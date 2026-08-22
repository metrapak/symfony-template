<?php

declare(strict_types=1);

namespace App\Tests\Approval\Functional;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Enum\ApprovalStatus;
use App\Profile\Entity\PlayerProfile;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FR-099 and BR-094 — every way of deciding somebody else's purchase, attempted directly.
 *
 * The requirement's own sentence is what makes this file necessary rather than paranoid: a child
 * "cannot approve, deny, or expire any request, including their own", and a direct attempt
 * "returns 403". A child login holds `ROLE_PLAYER` exactly like their parent, so `access_control`
 * on `^/family` admits them and every route here would be open without the voters. Nothing below
 * clicks a button; every test sends the request a hidden link would have sent.
 */
final class ApprovalAuthorizationTest extends ApprovalWebTestCase
{
    private User $otherParent;
    private PlayerProfile $theirChild;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otherParent = $this->createParent(self::OTHER_PARENT_EMAIL, 'Sam Other');
        $this->theirChild = $this->createChildProfile($this->otherParent, 'Their Child');
    }

    /**
     * FR-099, the central case: not even their own.
     */
    public function testAChildCannotApproveTheirOwnRequest(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('POST', \sprintf('/family/approvals/%d/approve', $purchase->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ApprovalStatus::Pending, $this->reloadPurchase((int) $purchase->getId())->getStatus());
        self::assertSame([], $this->paymentInstructions());
    }

    public function testAChildCannotDenyTheirOwnRequest(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('POST', \sprintf('/family/approvals/%d/deny', $purchase->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ApprovalStatus::Pending, $this->reloadPurchase((int) $purchase->getId())->getStatus());
    }

    /**
     * The approvals list is a parent's screen, and the capability is what says so.
     */
    public function testAChildCannotReachTheApprovalsList(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/family/approvals');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * FR-099's second half: the setting is the parent's, and a child cannot widen their own
     * allowance.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenSpendingRoutes(): iterable
    {
        yield 'the family spending list' => ['GET', '/family/spending'];
    }

    #[DataProvider('forbiddenSpendingRoutes')]
    public function testAChildCannotReachTheSpendingSettings(string $method, string $path): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAChildCannotChangeTheirOwnSpendingSetting(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/spending', $this->child->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * BR-094: only *this* child's parent. Holding the capability says nothing about whose family
     * it applies to, which is the IDOR the id in the URL would otherwise open.
     */
    public function testAnotherParentCannotDecideThisFamilysRequest(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::OTHER_PARENT_EMAIL);
        $this->client->request('POST', \sprintf('/family/approvals/%d/approve', $purchase->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ApprovalStatus::Pending, $this->reloadPurchase((int) $purchase->getId())->getStatus());
    }

    public function testAnotherParentCannotEvenViewThisFamilysRequest(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::OTHER_PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/approvals/%d', $purchase->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A Super Admin has impersonation, which is audited (FR-032). A silent cross-family approval
     * from the admin role would not be.
     */
    public function testASuperAdminCannotDecideAFamilysRequest(): void
    {
        $purchase = $this->seedPendingRequest();
        $this->createUser('admin@example.test', UserRole::SuperAdmin, name: 'Ada Admin');

        $this->submitLogin('admin@example.test');
        $this->client->request('POST', \sprintf('/family/approvals/%d/approve', $purchase->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * FR-095 requires the child to see their own purchase change state, so VIEW is granted where
     * DECIDE is not — and the page comes back without the buttons.
     */
    public function testAChildMayViewTheirOwnRequestWithoutTheDecisionForm(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', \sprintf('/family/approvals/%d', $purchase->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.status', 'Pending parent approval');
        self::assertSelectorNotExists('button[value="approve"]');
        self::assertSelectorNotExists('button[value="deny"]');
    }

    /**
     * The reservations list is scoped server-side, so another family's purchases are not in it
     * even though the route is open to every player.
     */
    public function testReservationsShowOnlyTheViewersOwnFamily(): void
    {
        $this->seedPendingRequest(description: 'Ours');
        $this->seedPendingRequest($this->theirChild, description: 'Theirs', parent: $this->otherParent);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/reservations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Ours');
        self::assertSelectorTextNotContains('main', 'Theirs');
    }

    /**
     * An anonymous visitor gets the login form, not a purchase.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function guardedRoutes(): iterable
    {
        yield 'approvals' => ['GET', '/family/approvals'];
        yield 'reservations' => ['GET', '/reservations'];
        yield 'notifications' => ['GET', '/notifications'];
        yield 'spending' => ['GET', '/family/spending'];
    }

    #[DataProvider('guardedRoutes')]
    public function testAnonymousVisitorsAreSentToLogin(string $method, string $path): void
    {
        $this->client->request($method, $path);

        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * A trainer is not a family member. `^/reservations` is `ROLE_PLAYER`, so this is
     * `access_control`'s answer rather than a voter's, and it is worth pinning: the section was
     * added to the rule list in this task.
     */
    public function testATrainerHasNoReservationsSection(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/reservations');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A notification is addressed to one account, and no route takes an id — so this is the only
     * way the question can be asked at all, and the answer must be "your own".
     */
    public function testTheInboxShowsOnlyTheViewersOwnNotifications(): void
    {
        $purchase = $this->seedPendingRequest();

        // Produce a real notification for the parent by having the child ask.
        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'deny', 'Not this time.');

        $this->submitLogin(self::OTHER_PARENT_EMAIL);
        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'You have no notifications.');
    }

    /**
     * The seeded row belongs to the family it names, whichever way it was built.
     */
    public function testSeededRequestsBelongToTheirOwnParent(): void
    {
        $ours = $this->seedPendingRequest();
        $theirs = $this->seedPendingRequest($this->theirChild, parent: $this->otherParent);

        self::assertSame($this->parentUser->getId(), $ours->getParent()->getId());
        self::assertSame($this->otherParent->getId(), $theirs->getParent()->getId());
        self::assertContainsOnlyInstancesOf(PurchaseApprovalRequest::class, $this->purchases());
    }
}
