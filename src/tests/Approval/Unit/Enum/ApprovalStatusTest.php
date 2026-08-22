<?php

declare(strict_types=1);

namespace App\Tests\Approval\Unit\Enum;

use App\Approval\Enum\ApprovalStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The transition table (FR-095, FR-096).
 *
 * The table is small enough to state exhaustively, and that is the point: every pair is asserted,
 * so a state added later without a decision about where it may go fails here rather than in
 * production.
 */
final class ApprovalStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{ApprovalStatus, ApprovalStatus, bool}>
     */
    public static function transitions(): iterable
    {
        $legal = [
            'pending to approved' => [ApprovalStatus::Pending, ApprovalStatus::Approved],
            'pending to denied' => [ApprovalStatus::Pending, ApprovalStatus::Denied],
            'pending to expired' => [ApprovalStatus::Pending, ApprovalStatus::Expired],
        ];

        foreach ($legal as $name => [$from, $to]) {
            yield $name => [$from, $to, true];
        }

        $legalPairs = array_map(
            static fn (array $pair): string => $pair[0]->value . '>' . $pair[1]->value,
            array_values($legal),
        );

        foreach (ApprovalStatus::cases() as $from) {
            foreach (ApprovalStatus::cases() as $to) {
                if (\in_array($from->value . '>' . $to->value, $legalPairs, true)) {
                    continue;
                }

                yield \sprintf('%s to %s is refused', $from->value, $to->value) => [$from, $to, false];
            }
        }
    }

    #[DataProvider('transitions')]
    public function testTheTransitionTable(ApprovalStatus $from, ApprovalStatus $to, bool $legal): void
    {
        self::assertSame($legal, $from->canTransitionTo($to));
    }

    public function testOnlyPendingHasAnywhereToGo(): void
    {
        self::assertFalse(ApprovalStatus::Pending->isFinal());

        foreach ([ApprovalStatus::Approved, ApprovalStatus::Denied, ApprovalStatus::Expired, ApprovalStatus::NotRequired] as $status) {
            self::assertTrue($status->isFinal(), $status->value . ' should be final');
        }
    }

    /**
     * "Confirmed" is the child's word for a purchase that happened, and two states are it: one a
     * parent approved, one that never needed approving (FR-092).
     */
    public function testTheConfirmedStatesAreTheOnesThatWereActuallyPaidFor(): void
    {
        self::assertTrue(ApprovalStatus::Approved->isConfirmed());
        self::assertTrue(ApprovalStatus::NotRequired->isConfirmed());
        self::assertFalse(ApprovalStatus::Pending->isConfirmed());
        self::assertFalse(ApprovalStatus::Denied->isConfirmed());
        self::assertFalse(ApprovalStatus::Expired->isConfirmed());
    }

    /**
     * Both confirmed states read as "Confirmed" to the child, because the difference between them
     * is the parent's, not theirs.
     */
    public function testBothConfirmedStatesReadTheSameToAChild(): void
    {
        self::assertSame('Confirmed', ApprovalStatus::Approved->label());
        self::assertSame('Confirmed', ApprovalStatus::NotRequired->label());
    }

    public function testEveryStatusExplainsItself(): void
    {
        foreach (ApprovalStatus::cases() as $status) {
            self::assertNotSame('', $status->explanation(), $status->value . ' needs an explanation');
        }
    }

    /**
     * G-31: `info_requested` has no defined behaviour and is deliberately not a state. Pinned so
     * that adding it is a decision somebody makes on purpose.
     */
    public function testThereIsNoInfoRequestedState(): void
    {
        self::assertNull(ApprovalStatus::tryFrom('info_requested'));
    }
}
