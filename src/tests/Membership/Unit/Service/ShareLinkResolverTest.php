<?php

declare(strict_types=1);

namespace App\Tests\Membership\Unit\Service;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\ShareLinkState;
use App\Membership\Enum\ShareLinkType;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\Service\ShareLinkResolver;
use App\Membership\ValueObject\ShareLinkCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The state matrix behind FR-046 and FR-049.
 *
 * The property under test is not only "does it say expired when it is expired" but the
 * collapsing: unknown, deactivated and consumed must be one answer, carrying no link, so that
 * no caller can leak the difference.
 */
final class ShareLinkResolverTest extends TestCase
{
    private const NOW = '2026-08-22 12:00:00';

    public function testAUsableLinkResolvesToValid(): void
    {
        $link = $this->playerLink();

        $resolution = $this->resolver($link)->resolve($link->getCode());

        self::assertSame(ShareLinkState::Valid, $resolution->state);
        self::assertSame($link, $resolution->link);
    }

    public function testAnUnknownCodeIsUnusableAndCarriesNoLink(): void
    {
        $resolution = $this->resolver(null)->resolve(ShareLinkCode::generate()->value);

        self::assertSame(ShareLinkState::Unusable, $resolution->state);
        self::assertNull($resolution->link);
    }

    public function testAMalformedCodeIsUnusableWithoutQueryingTheDatabase(): void
    {
        $links = $this->createMock(ShareLinkRepository::class);
        $links->expects(self::never())->method('findOneByCode');

        $resolver = new ShareLinkResolver($links, new MockClock(new \DateTimeImmutable(self::NOW)));

        self::assertSame(ShareLinkState::Unusable, $resolver->resolve('not-a-code')->state);
    }

    public function testADeactivatedLinkIsIndistinguishableFromAnUnknownOne(): void
    {
        $link = $this->playerLink();
        $link->deactivate(new \DateTimeImmutable(self::NOW));

        $resolution = $this->resolver($link)->resolve($link->getCode());

        self::assertSame(ShareLinkState::Unusable, $resolution->state);
        self::assertNull($resolution->link);
    }

    public function testAConsumedCoachLinkIsIndistinguishableFromAnUnknownOne(): void
    {
        $link = $this->coachLink();
        $this->setUseCount($link, 1);

        $resolution = $this->resolver($link)->resolve($link->getCode());

        self::assertSame(ShareLinkState::Unusable, $resolution->state);
        self::assertNull($resolution->link);
    }

    /**
     * FR-046 needs the holder of a lapsed invitation to be told so, which is the one failure
     * that is allowed to be distinguishable.
     */
    public function testAnExpiredLinkIsReportedSeparatelyAndKeepsItsLink(): void
    {
        $link = $this->coachLink();

        $resolution = $this->resolver($link, '2026-08-30 12:00:01')->resolve($link->getCode());

        self::assertSame(ShareLinkState::Expired, $resolution->state);
        self::assertSame($link, $resolution->link);
    }

    /**
     * BR-041's seven days, at the boundary: the last instant inside the window still works,
     * the first instant outside it does not.
     */
    public function testTheSevenDayWindowIsClosedAtItsEnd(): void
    {
        $link = $this->coachLink();

        self::assertSame(
            ShareLinkState::Valid,
            $this->resolver($link, '2026-08-29 11:59:59')->resolve($link->getCode())->state,
        );

        self::assertSame(
            ShareLinkState::Expired,
            $this->resolver($link, '2026-08-29 12:00:00')->resolve($link->getCode())->state,
        );
    }

    /**
     * A consumed link that is also expired must not reveal that it ever existed: the
     * unusable check runs first for exactly that reason.
     */
    public function testAConsumedAndExpiredLinkStaysUnusableRatherThanExpired(): void
    {
        $link = $this->coachLink();
        $this->setUseCount($link, 1);

        $resolution = $this->resolver($link, '2026-09-30 12:00:00')->resolve($link->getCode());

        self::assertSame(ShareLinkState::Unusable, $resolution->state);
    }

    private function resolver(?ShareLink $link, string $now = self::NOW): ShareLinkResolver
    {
        $links = $this->createMock(ShareLinkRepository::class);
        $links->method('findOneByCode')->willReturn($link);

        return new ShareLinkResolver($links, new MockClock(new \DateTimeImmutable($now)));
    }

    private function playerLink(): ShareLink
    {
        return new ShareLink(
            ShareLinkCode::generate(),
            ShareLinkType::Player,
            $this->organization(),
            $this->trainer(),
            new \DateTimeImmutable(self::NOW),
        );
    }

    private function coachLink(): ShareLink
    {
        $now = new \DateTimeImmutable(self::NOW);

        $link = new ShareLink(
            ShareLinkCode::generate(),
            ShareLinkType::Coach,
            $this->organization(),
            $this->trainer(),
            $now,
        );

        return $link->addressTo('coach@example.com', null, null)->expiresOn($now->modify('+7 days'));
    }

    private function organization(): Organization
    {
        return new Organization('Example Academy', $this->trainer(), new \DateTimeImmutable(self::NOW));
    }

    private function trainer(): User
    {
        return new User('trainer@example.com', 'Tara Trainer', UserRole::Trainer, new \DateTimeImmutable(self::NOW));
    }

    private function setUseCount(ShareLink $link, int $count): void
    {
        // The counter is only ever incremented by an atomic UPDATE in the repository, so there
        // is no setter to call; reflection stands in for a row the database already changed.
        (new \ReflectionProperty(ShareLink::class, 'useCount'))->setValue($link, $count);
    }
}
