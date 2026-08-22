<?php

declare(strict_types=1);

namespace App\Tests\Approval\Functional;

use App\Approval\Enum\ApprovalStatus;
use App\Approval\Enum\NotificationKind;
use App\Approval\Message\ExpireApprovalRequest;
use App\Approval\Service\ApprovalExpiryHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The 48-hour expiry (FR-096, NFR-091, and the idempotency NFR-092 asks of its message).
 *
 * **The boundary is tested with data, not with a clock.** Two requests are seeded, one a minute
 * past its mark and one a minute short of it, and the real sweep is run against the real time.
 * That keeps the comparison under test — a mocked clock would prove the mock agrees with itself.
 */
final class ApprovalExpiryTest extends ApprovalWebTestCase
{
    public function testARequestPastItsWindowIsExpiredAndOneShortOfItIsNot(): void
    {
        $due = $this->seedPendingRequest(description: 'Past the mark', expiresInHours: -1);
        $notDue = $this->seedPendingRequest(description: 'Not yet', expiresInHours: 1);

        $this->runSweep();

        self::assertSame(ApprovalStatus::Expired, $this->reloadPurchase((int) $due->getId())->getStatus());
        self::assertSame(ApprovalStatus::Pending, $this->reloadPurchase((int) $notDue->getId())->getStatus());
    }

    /**
     * FR-096: an expiry is an automatic *denial*, so nothing is charged.
     */
    public function testExpiryTakesNoPayment(): void
    {
        $due = $this->seedPendingRequest(expiresInHours: -1);

        $this->runSweep();

        $reloaded = $this->reloadPurchase((int) $due->getId());
        self::assertFalse($reloaded->isPaid());
        self::assertNull($reloaded->getParentNotes(), 'nobody wrote a note, so none is invented');
        self::assertNotNull($reloaded->getRespondedAt());
        self::assertSame([], $this->paymentProcessor()->recordedInstructions());
    }

    /**
     * FR-096 asks for a notification and does not say to whom. Both: the child who is waiting,
     * and the parent who was asked and did not answer.
     */
    public function testExpiryNotifiesBothSides(): void
    {
        $this->seedPendingRequest(expiresInHours: -1);

        $this->runSweep();

        $childNotifications = $this->notifications($this->childAccount);
        self::assertCount(1, $childNotifications);
        self::assertSame(NotificationKind::Expired, $childNotifications[0]->getKind());
        self::assertStringContainsString('denied automatically', $childNotifications[0]->getBody());

        $parentNotifications = $this->notifications($this->parentUser);
        self::assertCount(1, $parentNotifications);
        self::assertSame(NotificationKind::Expired, $parentNotifications[0]->getKind());

        // The parent can receive mail; the child's derived address cannot, so exactly one message.
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'to', self::PARENT_EMAIL);
    }

    /**
     * At-least-once delivery is the norm for any real transport, so a redelivered expiry must not
     * notify twice.
     */
    public function testARedeliveredExpiryMessageChangesNothing(): void
    {
        $due = $this->seedPendingRequest(expiresInHours: -1);

        $this->runSweep();
        $expiredAt = $this->reloadPurchase((int) $due->getId())->getRespondedAt();

        // The same message again, as a queue would deliver it after a worker restart.
        $this->dispatchExpiry((int) $due->getId());

        $reloaded = $this->reloadPurchase((int) $due->getId());
        self::assertSame(ApprovalStatus::Expired, $reloaded->getStatus());
        self::assertEquals($expiredAt, $reloaded->getRespondedAt(), 'the first expiry stands');
        self::assertCount(1, $this->notifications($this->childAccount));
        self::assertCount(1, $this->notifications($this->parentUser));
    }

    /**
     * A parent who answers while the sweep is in flight wins: their decision is not overwritten.
     */
    public function testAnAlreadyDecidedRequestIsLeftAloneBytheSweep(): void
    {
        $purchase = $this->seedPendingRequest(expiresInHours: -1);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitDecision($purchase, 'approve');

        $this->runSweep();

        self::assertSame(ApprovalStatus::Approved, $this->reloadPurchase((int) $purchase->getId())->getStatus());
    }

    /**
     * A purchase that never needed approval has no window to run out of.
     */
    public function testPurchasesThatNeededNoApprovalAreNeverSwept(): void
    {
        $this->seedSpendingSetting($this->child, true);

        $this->submitLogin(self::CHILD_EMAIL);
        $this->submitCheckout($this->child, [
            'purchaseDescription' => 'Extra skills session',
            'paymentType' => 'token',
            'amount' => '3',
        ]);

        $this->runSweep();

        self::assertSame(ApprovalStatus::NotRequired, $this->purchases()[0]->getStatus());
    }

    /**
     * The command is the deployment contract — a cron entry calls it, so it has to work from the
     * console and report what it did.
     */
    public function testTheConsoleCommandExpiresDueRequests(): void
    {
        $due = $this->seedPendingRequest(expiresInHours: -1);

        $tester = $this->commandTester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Expired 1 purchase request', $tester->getDisplay());
        self::assertSame(ApprovalStatus::Expired, $this->reloadPurchase((int) $due->getId())->getStatus());
    }

    /**
     * The ordinary case for a cron entry running every fifteen minutes: nothing to do, said
     * quietly and successfully.
     */
    public function testTheCommandSucceedsQuietlyWhenNothingIsDue(): void
    {
        $this->seedPendingRequest(expiresInHours: 4);

        $tester = $this->commandTester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('Expired', $tester->getDisplay());
    }

    public function testTheCommandRejectsAMeaninglessLimit(): void
    {
        $tester = $this->commandTester();

        self::assertSame(2, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('at least 1', $tester->getDisplay());
    }

    /**
     * A full batch is a backlog, and an operator should hear about it rather than discover it
     * through a parent.
     */
    public function testAFullBatchSaysThereMayBeMore(): void
    {
        $this->seedPendingRequest(description: 'One', expiresInHours: -2);
        $this->seedPendingRequest(description: 'Two', expiresInHours: -1);

        $tester = $this->commandTester();
        $tester->execute(['--limit' => '2']);

        self::assertStringContainsString('there may be more still due', $tester->getDisplay());
    }

    private function runSweep(): void
    {
        $handler = static::getContainer()->get(ApprovalExpiryHandler::class);
        self::assertInstanceOf(ApprovalExpiryHandler::class, $handler);

        $handler->expireDue();
    }

    private function dispatchExpiry(int $requestId): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new ExpireApprovalRequest($requestId));
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(static::createKernel());
        $application->setAutoExit(false);

        return new CommandTester($application->find('app:approvals:expire'));
    }
}
