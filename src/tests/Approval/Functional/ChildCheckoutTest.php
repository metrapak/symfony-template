<?php

declare(strict_types=1);

namespace App\Tests\Approval\Functional;

use App\Approval\Enum\ApprovalStatus;
use App\Approval\Enum\NotificationKind;
use App\Approval\Enum\PaymentType;

/**
 * The checkout branch (FR-090, FR-091, FR-092, BR-090, BR-091).
 *
 * The three cases that matter are the three rows of the decision matrix a real family hits:
 * dollars from a child, tokens from a child whose parent has not waived approval, and tokens from
 * one whose parent has. The fourth — an adult buying for themselves — is here too, because a rule
 * that asked *everyone* for approval would pass all three of the others.
 */
final class ChildCheckoutTest extends ApprovalWebTestCase
{
    public function testUsdCheckoutByAChildCreatesAPendingRequestAndTakesNoPayment(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'City Cup entry fee',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '45.00',
        ]);

        self::assertResponseRedirects('/reservations');
        // FR-090's acceptance criterion, in two halves: the request exists…
        $purchases = $this->purchases();
        self::assertCount(1, $purchases);
        self::assertSame(ApprovalStatus::Pending, $purchases[0]->getStatus());
        self::assertSame(4500, $purchases[0]->getAmount()->amountMinor);
        self::assertSame('USD', $purchases[0]->getAmount()->currency);
        self::assertSame($this->parentUser->getId(), $purchases[0]->getParent()->getId());
        // …and no payment was attempted.
        self::assertNull($purchases[0]->getPaymentReference());
        self::assertSame([], $this->paymentInstructions());
    }

    /**
     * FR-096: the window is 48 hours from the request, not from anything else.
     */
    public function testAPendingRequestExpiresFortyEightHoursAfterItIsMade(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'City Cup entry fee',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '45.00',
        ]);

        $purchase = $this->purchases()[0];
        $expiresAt = $purchase->getExpiresAt();

        self::assertNotNull($expiresAt);
        self::assertSame(
            $purchase->getRequestedAt()->modify('+48 hours')->format('Y-m-d H:i'),
            $expiresAt->format('Y-m-d H:i'),
        );
    }

    /**
     * FR-093: both channels, and the email carries what the parent needs to decide.
     */
    public function testTheParentIsNotifiedInAppAndByEmail(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'City Cup entry fee',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '45.00',
        ]);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', self::PARENT_EMAIL);
        self::assertStringContainsString('needs your approval', (string) $email->getSubject());
        self::assertStringContainsString('$45.00', (string) $email->getSubject());

        $notifications = $this->notifications($this->parentUser);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationKind::ApprovalNeeded, $notifications[0]->getKind());
        self::assertTrue($notifications[0]->isUnread());
        // The four facts FR-093 asks the notification to identify.
        self::assertStringContainsString('Maya Parent', $notifications[0]->getBody());
        self::assertStringContainsString('City Cup entry fee', $notifications[0]->getBody());
        self::assertStringContainsString('$45.00', $notifications[0]->getBody());
        self::assertStringContainsString('us dollars', $notifications[0]->getBody());
    }

    /**
     * BR-091's default, exercised through the absence of any setting row at all.
     */
    public function testTokenCheckoutWithTheSettingOffCreatesAPendingRequest(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Five-a-side league',
            'paymentType' => PaymentType::Token->value,
            'amount' => '8',
        ]);

        $purchases = $this->purchases();
        self::assertCount(1, $purchases);
        self::assertSame(ApprovalStatus::Pending, $purchases[0]->getStatus());
        self::assertSame('TOK', $purchases[0]->getAmount()->currency);
        self::assertSame(8, $purchases[0]->getAmount()->amountMinor);
        self::assertSame([], $this->paymentInstructions());
    }

    /**
     * FR-092's other branch: processed immediately, parent told rather than asked.
     */
    public function testTokenCheckoutWithTheSettingOnIsProcessedImmediately(): void
    {
        $this->seedSpendingSetting($this->child, true);

        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Extra skills session',
            'paymentType' => PaymentType::Token->value,
            'amount' => '3',
        ]);

        $purchases = $this->purchases();
        self::assertCount(1, $purchases);
        self::assertSame(ApprovalStatus::NotRequired, $purchases[0]->getStatus());
        self::assertTrue($purchases[0]->getStatus()->isConfirmed());
        self::assertNotNull($purchases[0]->getPaymentReference(), 'the payment was taken');
        self::assertCount(1, $this->paymentInstructions());

        // Informational, not an approval request — the distinction FR-092 draws.
        $notifications = $this->notifications($this->parentUser);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationKind::TokenSpendNotice, $notifications[0]->getKind());
        self::assertFalse($notifications[0]->getKind()->needsAction());

        self::assertEmailCount(1);
        self::assertStringContainsString('For your information', (string) self::getMailerMessage()?->getSubject());
    }

    /**
     * BR-090: no setting can waive a dollar purchase, including one turned on for this child.
     */
    public function testUsdStillNeedsApprovalEvenWhenTokenSpendingIsWaived(): void
    {
        $this->seedSpendingSetting($this->child, true);

        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Winter camp',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '120.00',
        ]);

        self::assertSame(ApprovalStatus::Pending, $this->purchases()[0]->getStatus());
        self::assertSame([], $this->paymentInstructions());
    }

    /**
     * An adult is not a child. A rule that asked everyone for approval would pass every test
     * above and fail this one.
     */
    public function testAnAdultBuyingForThemselvesNeedsNoApproval(): void
    {
        $self = $this->createSelfProfile($this->parentUser);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitCheckout($self, [
            'purchaseDescription' => 'Adult league fee',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '30.00',
        ]);

        $purchases = $this->purchases();
        self::assertCount(1, $purchases);
        self::assertSame(ApprovalStatus::NotRequired, $purchases[0]->getStatus());
        self::assertCount(1, $this->paymentInstructions());
        // Nobody to inform: they are their own guardian.
        self::assertSame([], $this->notifications());
        self::assertEmailCount(0);
    }

    /**
     * A parent buying for their child is the person the approval would be asked of.
     */
    public function testAParentBuyingForTheirChildNeedsNoApproval(): void
    {
        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Winter camp',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '120.00',
        ]);

        $purchases = $this->purchases();
        self::assertCount(1, $purchases);
        self::assertSame(ApprovalStatus::NotRequired, $purchases[0]->getStatus());
        self::assertCount(1, $this->paymentInstructions());
        self::assertSame([], $this->notifications());
    }

    public function testTheAmountMustBeANumber(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'City Cup entry fee',
            'paymentType' => PaymentType::Usd->value,
            'amount' => 'forty five',
        ]);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'Enter an amount as a number');
        self::assertSame([], $this->purchases());
    }

    /**
     * Tokens are whole and dollars are not, and the same field means both.
     */
    public function testFractionalTokensAreRejected(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Five-a-side league',
            'paymentType' => PaymentType::Token->value,
            'amount' => '12.50',
        ]);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'Tokens are whole numbers');
        self::assertSame([], $this->purchases());
    }

    public function testAZeroAmountIsRejected(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Nothing at all',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '0.00',
        ]);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'A purchase has to cost something');
        self::assertSame([], $this->purchases());
    }

    /**
     * Cents survive the trip. `45.10` through a float would arrive as 4509.
     */
    public function testCentsAreStoredExactly(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Club socks',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '45.10',
        ]);

        self::assertSame(4510, $this->purchases()[0]->getAmount()->amountMinor);
    }

    /**
     * FR-090: the child sees the status on the reservation.
     */
    public function testTheChildSeesTheirRequestAsPending(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'City Cup entry fee',
            'paymentType' => PaymentType::Usd->value,
            'amount' => '45.00',
        ]);

        $this->client->request('GET', '/reservations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.status', 'Pending parent approval');
        self::assertSelectorTextContains('.purchase-card', 'City Cup entry fee');
        self::assertSelectorTextContains('.purchase-card', 'Waiting for a parent to approve');
    }

    /**
     * BR-094 and the IDOR the id in the URL would otherwise open: another family's child is not
     * yours to spend for.
     */
    public function testAStrangerCannotStartACheckoutForSomebodyElsesChild(): void
    {
        $otherParent = $this->createParent(self::OTHER_PARENT_EMAIL, 'Sam Other');
        $theirChild = $this->createChildProfile($otherParent, 'Their Child');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/reservations/checkout/%d', $theirChild->getId()));

        self::assertResponseStatusCodeSame(403);
    }
}
