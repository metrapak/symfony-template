<?php

declare(strict_types=1);

namespace App\Tests\Approval\Functional;

use App\Account\Entity\User;
use App\Approval\Entity\ApprovalNotification;
use App\Approval\Entity\ChildSpendingSetting;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Enum\PaymentType;
use App\Approval\Payment\FakePaymentProcessor;
use App\Approval\ValueObject\Money;
use App\Profile\Entity\PlayerProfile;
use App\Tests\Profile\Functional\ProfileWebTestCase;

/**
 * Shared setup for the purchase approval tests.
 *
 * Builds on the profile base because approval hangs off what it creates: a parent, a child with a
 * login of their own, and a second family to be isolated from. What this adds is one place that
 * knows how to seed a purchase in any state, and how to read the fake processor's call log.
 *
 * **Purchases are seeded as entities, not through the checkout screen.** A test that arranges its
 * data with the same code path it is exercising cannot fail when that path breaks. The checkout is
 * exercised by the tests that actually post the form.
 *
 * **Expiry is tested by data, not by a clock.** Seeding a request whose `expiresAt` is already
 * behind us — and one whose is not quite — makes the 48-hour boundary a property of the rows
 * rather than of a mocked time source, which keeps the sweep's real comparison under test.
 */
abstract class ApprovalWebTestCase extends ProfileWebTestCase
{
    /** The form name Symfony derives from `ApprovalDecisionFormType`. */
    protected const DECISION_FORM = 'approval_decision_form';

    /** The form name Symfony derives from `CheckoutFormType`. */
    protected const CHECKOUT_FORM = 'checkout_form';

    /** The form name Symfony derives from `SpendingSettingFormType`. */
    protected const SPENDING_FORM = 'spending_setting_form';

    protected User $parentUser;
    protected User $childAccount;
    protected PlayerProfile $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentUser = $this->createParent();
        $this->childAccount = $this->createUser(self::CHILD_EMAIL, name: 'Maya Parent');
        $this->child = $this->createChildProfile($this->parentUser, 'Maya Parent', $this->childAccount);
    }

    /**
     * A purchase waiting on a parent.
     *
     * @param int|null $expiresInHours null means "the standard window"; a negative value seeds a
     *                                 request the sweep should already consider due
     */
    protected function seedPendingRequest(
        ?PlayerProfile $child = null,
        PaymentType $paymentType = PaymentType::Usd,
        ?Money $amount = null,
        string $description = 'City Cup entry fee',
        ?int $expiresInHours = null,
        ?User $parent = null,
    ): PurchaseApprovalRequest {
        $child = $this->managed($child ?? $this->child, PlayerProfile::class);
        $now = new \DateTimeImmutable();

        $request = PurchaseApprovalRequest::awaitingApproval(
            $child,
            $this->managed($parent ?? $child->getOwner(), User::class),
            'stand-in:test-' . bin2hex(random_bytes(4)),
            $description,
            $amount ?? self::defaultAmountFor($paymentType),
            $paymentType,
            $now,
            $now->modify(\sprintf('%+d hours', $expiresInHours ?? 48)),
        );

        return $this->save($request);
    }

    protected function seedSpendingSetting(PlayerProfile $child, bool $allow): ChildSpendingSetting
    {
        $now = new \DateTimeImmutable();
        $managed = $this->managed($child, PlayerProfile::class);

        $setting = new ChildSpendingSetting($managed, $now);
        $setting->decide($allow, $this->managed($managed->getOwner(), User::class), $now);

        return $this->save($setting);
    }

    /**
     * Every purchase in the database, oldest first.
     *
     * @return list<PurchaseApprovalRequest>
     */
    protected function purchases(): array
    {
        return $this->freshEntityManager()
            ->getRepository(PurchaseApprovalRequest::class)
            ->findBy([], ['id' => 'ASC']);
    }

    protected function reloadPurchase(int $id): PurchaseApprovalRequest
    {
        $purchase = $this->freshEntityManager()->getRepository(PurchaseApprovalRequest::class)->find($id);
        self::assertInstanceOf(PurchaseApprovalRequest::class, $purchase);

        return $purchase;
    }

    /**
     * @return list<ApprovalNotification>
     */
    protected function notifications(?User $recipient = null): array
    {
        $criteria = null !== $recipient ? ['recipient' => $recipient->getId()] : [];

        return $this->freshEntityManager()
            ->getRepository(ApprovalNotification::class)
            ->findBy($criteria, ['id' => 'ASC']);
    }

    /**
     * The instructions the stand-in processor was given during the request that just ran.
     *
     * Read from the container rather than from a spy injected by the test, because the point of
     * the assertion is that the *wired* processor was called — once — through the real workflow.
     *
     * **Read it before making another request.** The kernel is rebooted at the start of each one,
     * which builds a fresh processor with an empty log; `followRedirect()` counts. Where an
     * assertion has to survive that, use the payment reference on the row instead — it is the
     * durable half of the same fact.
     *
     * @return list<\App\Approval\Payment\PaymentInstruction>
     */
    protected function paymentInstructions(): array
    {
        return $this->paymentProcessor()->recordedInstructions();
    }

    protected function paymentProcessor(): FakePaymentProcessor
    {
        $processor = static::getContainer()->get(FakePaymentProcessor::class);
        self::assertInstanceOf(FakePaymentProcessor::class, $processor);

        return $processor;
    }

    /**
     * Posts a decision the way the browser does, carrying the page's own CSRF token.
     */
    protected function submitDecision(PurchaseApprovalRequest $purchase, string $action, ?string $notes = null): void
    {
        $this->postDecision($purchase, $action, $this->decisionToken($purchase), $notes);
    }

    /**
     * The decision form's CSRF token, taken from the rendered page.
     *
     * Separate from the post so a test can re-send the *same* token — which is what a parent
     * double-clicking actually does, and what NFR-092's test has to reproduce. Fetching a second
     * token would be impossible anyway: the form is not rendered once the purchase is decided.
     */
    protected function decisionToken(PurchaseApprovalRequest $purchase): string
    {
        $crawler = $this->client->request('GET', \sprintf('/family/approvals/%d', $purchase->getId()));

        $token = $crawler->filter(\sprintf('input[name="%s[_token]"]', self::DECISION_FORM));
        self::assertGreaterThan(0, $token->count(), 'Expected the decision form to be rendered with a CSRF token.');

        return (string) $token->attr('value');
    }

    protected function postDecision(PurchaseApprovalRequest $purchase, string $action, string $token, ?string $notes = null): void
    {
        $this->client->request('POST', \sprintf('/family/approvals/%d/%s', $purchase->getId(), $action), [
            self::DECISION_FORM => (null === $notes ? [] : ['notes' => $notes]) + ['_token' => $token],
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function submitCheckout(PlayerProfile $player, array $values): void
    {
        $this->submitFormPayload(
            \sprintf('/reservations/checkout/%d', $player->getId()),
            self::CHECKOUT_FORM,
            $values,
        );
    }

    private static function defaultAmountFor(PaymentType $paymentType): Money
    {
        return PaymentType::Token === $paymentType ? Money::tokens(8) : Money::usd(4500);
    }
}
