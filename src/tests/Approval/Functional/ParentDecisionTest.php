<?php

declare(strict_types=1);

namespace App\Tests\Approval\Functional;

use App\Account\Entity\AuditLogEntry;
use App\Account\Enum\AuditAction;
use App\Approval\Enum\ApprovalStatus;
use App\Approval\Enum\NotificationKind;

/**
 * Approve, deny, and the two things that must not happen twice (FR-094, FR-095, NFR-092, NFR-X02).
 */
final class ParentDecisionTest extends ApprovalWebTestCase
{
    public function testApprovingProcessesThePaymentAndConfirmsTheChild(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'approve');

        self::assertResponseRedirects('/family/approvals');

        // FR-097: the port was called, exactly once, with what it needs. Read before following
        // the redirect — the next request reboots the kernel and with it the processor's log.
        $instructions = $this->paymentInstructions();
        self::assertCount(1, $instructions);
        self::assertSame(\sprintf('approval-%d', $purchase->getId()), $instructions[0]->idempotencyKey);
        self::assertSame(4500, $instructions[0]->amount->amountMinor);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Approved.');

        $reloaded = $this->reloadPurchase((int) $purchase->getId());
        self::assertSame(ApprovalStatus::Approved, $reloaded->getStatus());
        self::assertTrue($reloaded->isPaid());
        self::assertNotNull($reloaded->getRespondedAt());
    }

    /**
     * FR-095's acceptance criterion, from the child's side.
     */
    public function testTheChildSeesTheStatusChangeFromPendingToConfirmed(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/reservations');
        self::assertSelectorTextContains('.status', 'Pending parent approval');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'approve');

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/reservations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.status', 'Confirmed');
        self::assertSelectorTextContains('.purchase-card', 'the payment went through');
    }

    public function testBR093NotesAreStoredAndShownToTheChild(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'approve', 'Yes — but this is the last one this term.');

        self::assertSame(
            'Yes — but this is the last one this term.',
            $this->reloadPurchase((int) $purchase->getId())->getParentNotes(),
        );

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/reservations');
        self::assertSelectorTextContains('.notes', 'this is the last one this term');
    }

    public function testDenyingTakesNoPaymentAndTellsTheChild(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'deny', 'You already have one.');

        self::assertResponseRedirects('/family/approvals');

        $reloaded = $this->reloadPurchase((int) $purchase->getId());
        self::assertSame(ApprovalStatus::Denied, $reloaded->getStatus());
        self::assertFalse($reloaded->isPaid());
        self::assertSame([], $this->paymentInstructions(), 'a denial never reaches the processor');

        // The child is told in-app — the only channel they have, since their address is
        // deliberately undeliverable.
        $childNotifications = $this->notifications($this->childAccount);
        self::assertCount(1, $childNotifications);
        self::assertSame(NotificationKind::Denied, $childNotifications[0]->getKind());
        self::assertStringContainsString('You already have one.', $childNotifications[0]->getBody());
    }

    /**
     * A child login's address ends in the reserved `.invalid` domain, so mailing it would bounce
     * on every decision a parent makes.
     */
    public function testNoEmailIsSentToAChildLogin(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'deny');

        self::assertEmailCount(0);
        self::assertCount(1, $this->notifications($this->childAccount));
    }

    /**
     * NFR-092, the requirement this whole design turns on: a second Approve must not pay twice.
     */
    public function testADoubleSubmittedApprovalProcessesThePaymentExactlyOnce(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);

        // The same token, sent twice, which is what a double-click actually does.
        $token = $this->decisionToken($purchase);
        $this->postDecision($purchase, 'approve', $token);
        $firstReference = $this->reloadPurchase((int) $purchase->getId())->getPaymentReference();
        self::assertNotNull($firstReference);

        $this->postDecision($purchase, 'approve', $token);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'already been Confirmed');

        $reloaded = $this->reloadPurchase((int) $purchase->getId());
        self::assertSame(ApprovalStatus::Approved, $reloaded->getStatus());
        // The payment reference is the durable proof: one payment, unchanged by the second submit.
        self::assertSame($firstReference, $reloaded->getPaymentReference());
        // And exactly one outcome notification, not two.
        self::assertCount(1, $this->notifications($this->childAccount));
    }

    /**
     * The other illegal move, from the other direction.
     */
    public function testApprovingAnAlreadyDeniedPurchaseIsRefused(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);

        $token = $this->decisionToken($purchase);
        $this->postDecision($purchase, 'deny', $token);
        $this->postDecision($purchase, 'approve', $token);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'already been Denied');

        self::assertSame(ApprovalStatus::Denied, $this->reloadPurchase((int) $purchase->getId())->getStatus());
        self::assertSame([], $this->paymentInstructions());
    }

    /**
     * NFR-X02 lists approval beside impersonation, deletion and override.
     */
    public function testADecisionIsAudited(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'approve', 'Fine by me.');

        $entries = $this->freshEntityManager()->getRepository(AuditLogEntry::class)->findBy(
            ['action' => AuditAction::ChildPurchaseApproved],
        );

        self::assertCount(1, $entries);
        self::assertSame($this->parentUser->getId(), $entries[0]->getActor()?->getId());
        self::assertSame('PurchaseApprovalRequest', $entries[0]->getSubjectType());
        self::assertSame($purchase->getId(), $entries[0]->getSubjectId());
        self::assertSame(4500, $entries[0]->getPayload()['amount_minor'] ?? null);
        self::assertSame('USD', $entries[0]->getPayload()['currency'] ?? null);
        self::assertSame('Fine by me.', $entries[0]->getPayload()['notes'] ?? null);
    }

    public function testAnOverlongNoteIsRejectedAndNothingIsDecided(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'approve', str_repeat('a', 2001));

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'Keep your note under 2000 characters.');
        self::assertSame(ApprovalStatus::Pending, $this->reloadPurchase((int) $purchase->getId())->getStatus());
        self::assertSame([], $this->paymentInstructions());
    }

    /**
     * FR-094: the review screen shows what the parent needs to decide with.
     */
    public function testTheReviewScreenShowsTheDetailsAndBothActions(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/approvals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.purchase-card', 'Maya Parent');
        self::assertSelectorTextContains('.purchase-card', 'City Cup entry fee');
        self::assertSelectorTextContains('.amount', '$45.00');
        self::assertSelectorTextContains('.expiry', 'Expires');

        $this->client->request('GET', \sprintf('/family/approvals/%d', $purchase->getId()));

        self::assertResponseIsSuccessful();
        // NFR-094: two distinct, separately labelled submits — not one control whose meaning
        // depends on a colour.
        self::assertSelectorTextContains('button[value="approve"]', 'Approve');
        self::assertSelectorExists(\sprintf(
            'button[value="deny"][formaction="/family/approvals/%d/deny"]',
            $purchase->getId(),
        ));
        self::assertSelectorExists('#approval_decision_form_notes');
    }

    /**
     * G-31, stated on the page rather than silently dropped.
     */
    public function testTheScreenExplainsWhyThereIsNoRequestMoreInfoAction(): void
    {
        $purchase = $this->seedPendingRequest();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/approvals/%d', $purchase->getId()));

        // `#main-content` rather than the section's wrapper class: both shells now render the
        // themed application shell, and what this test is about is that the page says it.
        self::assertSelectorTextContains('#main-content', 'no "request more info" here yet');
    }

    /**
     * The list orders by what is closest to lapsing, not by what arrived last.
     */
    public function testPendingRequestsAreOrderedByHowSoonTheyExpire(): void
    {
        $this->seedPendingRequest(description: 'Later', expiresInHours: 40);
        $this->seedPendingRequest(description: 'Sooner', expiresInHours: 2);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/family/approvals');

        $headings = $crawler->filter('.purchase-card h3')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => trim($node->text()),
        );

        self::assertStringContainsString('Sooner', $headings[0] ?? '');
        self::assertStringContainsString('Later', $headings[1] ?? '');
    }

    /**
     * Dollars and tokens do not add up, so the total is one line per currency.
     */
    public function testPendingTotalsAreShownPerCurrency(): void
    {
        $this->seedPendingRequest();
        $this->seedPendingRequest(paymentType: \App\Approval\Enum\PaymentType::Token, description: 'Skills session');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/approvals');

        // One total per currency, and no attempt to add them: see `Money::plus()`.
        self::assertSelectorTextContains('main', '2 requests, totalling $45.00');
        self::assertSelectorTextContains('main', '8 tokens');
    }
}
