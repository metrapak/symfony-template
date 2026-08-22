<?php

declare(strict_types=1);

namespace App\Tests\Approval\Functional;

/**
 * The in-app half of FR-093, and the indicator that points at it (G-33).
 *
 * Notifications are produced by acting on real purchases rather than seeded directly: what these
 * tests are for is the claim that the workflow actually tells people things, and a hand-written
 * row would prove only that the inbox can render one.
 */
final class NotificationInboxTest extends ApprovalWebTestCase
{
    public function testAParentSeesTheirNotificationsAndTheUnreadCount(): void
    {
        $this->createRequestFromTheChild();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '1 unread of 1');
        self::assertSelectorTextContains('main', 'needs your approval to spend');
        self::assertSelectorTextContains('.status', 'Unread');
        // FR-093's notification links to the thing it is about.
        self::assertSelectorExists('a[href^="/family/approvals/"]');
    }

    /**
     * The count is in the accessible text and not only in a coloured badge (NFR-094).
     */
    public function testTheIndicatorSaysWhatItIsCounting(): void
    {
        $this->createRequestFromTheChild();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/approvals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav', '1 unread');
        self::assertSelectorTextContains('nav', '1 purchase waiting for your approval');
    }

    /**
     * Reading the page must not clear the badge — a prefetch would otherwise silently answer for
     * the parent.
     */
    public function testViewingTheInboxDoesNotMarkAnythingRead(): void
    {
        $this->createRequestFromTheChild();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/notifications');
        $this->client->request('GET', '/notifications');

        self::assertSelectorTextContains('main', '1 unread of 1');
        self::assertTrue($this->notifications($this->parentUser)[0]->isUnread());
    }

    public function testMarkingAllReadClearsTheCount(): void
    {
        $this->createRequestFromTheChild();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('POST', '/notifications/read', [
            '_token' => $this->submitToken('/notifications'),
        ]);

        self::assertResponseRedirects('/notifications');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Marked 1 notification as read.');
        self::assertSelectorTextContains('main', '0 unread of 1');
        self::assertFalse($this->notifications($this->parentUser)[0]->isUnread());
    }

    /**
     * NFR-X03. Without this, any page on the internet could clear the badge that tells a parent
     * somebody is waiting on them.
     */
    public function testMarkingAllReadWithoutAValidTokenIsRefused(): void
    {
        $this->createRequestFromTheChild();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('POST', '/notifications/read', ['_token' => 'not-a-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->notifications($this->parentUser)[0]->isUnread());
    }

    /**
     * The child's only channel. Their address is undeliverable by construction, so what a parent
     * gets by email, a child gets here or not at all.
     */
    public function testAChildReadsTheirOutcomeInTheInbox(): void
    {
        $this->createRequestFromTheChild();
        $purchase = $this->purchases()[0];

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'deny', 'Not this month.');

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Denied: City Cup entry fee');
        self::assertSelectorTextContains('main', 'Not this month.');
    }

    /**
     * A purchase the child made, so the parent has a real notification to read.
     */
    private function createRequestFromTheChild(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'City Cup entry fee',
            'paymentType' => 'usd',
            'amount' => '45.00',
        ]);
    }
}
