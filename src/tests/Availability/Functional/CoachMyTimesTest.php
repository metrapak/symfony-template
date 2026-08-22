<?php

declare(strict_types=1);

namespace App\Tests\Availability\Functional;

use App\Availability\Entity\CoachAvailabilityOverride;
use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\TimeRange;

/**
 * A coach's "My Times", and what they see when a trainer overrides it (FR-082, FR-087, BR-081).
 */
final class CoachMyTimesTest extends AvailabilityWebTestCase
{
    public function testCoachSavesSeveralSlotsOnOneDay(): void
    {
        $coach = $this->createAssignedCoach();

        $this->submitLogin(self::COACH_EMAIL);

        // US-01.10's own example: "Monday 4-6pm AND 7-9pm".
        $this->submitWeek('/coach/my-times', [
            'monday' => ['slots' => [self::hourCell(16), self::hourCell(17), self::hourCell(19), self::hourCell(20)]],
            'saturday' => ['slots' => [self::hourCell(9), self::hourCell(10), self::hourCell(11)]],
        ]);

        self::assertResponseRedirects('/coach/my-times');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your times are saved.');

        self::assertSame(
            ['Monday 16:00–18:00', 'Monday 19:00–21:00', 'Saturday 09:00–12:00'],
            $this->weekOf($this->coachSubject($coach)),
            'the evening gap survives; the blocks either side of it do not',
        );

        self::assertSelectorTextContains('.best-times', 'Mon 4–6pm and 7–9pm, Sat 9am–noon');
    }

    public function testCoachMarksADayUnavailableAndReplacesTheWeekOnTheNextSave(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Friday->value => [[16 * 60, 20 * 60]]]);

        $this->submitLogin(self::COACH_EMAIL);

        $this->submitWeek('/coach/my-times', [
            'tuesday' => ['slots' => [self::hourCell(18)]],
            'friday' => ['unavailable' => '1'],
        ]);

        self::assertSame(
            ['Tuesday 18:00–19:00', 'Friday 00:00–24:00 unavailable'],
            $this->weekOf($this->coachSubject($coach)),
            'saving replaces the week rather than adding to it',
        );
    }

    public function testCoachSeesOverridesRecordedAgainstThem(): void
    {
        $coach = $this->createAssignedCoach();

        $entityManager = $this->currentEntityManager();
        $entityManager->persist(new CoachAvailabilityOverride(
            coach: $this->managed($coach, \App\Account\Entity\User::class),
            overriddenBy: $this->managed($this->trainer, \App\Account\Entity\User::class),
            organizationId: (int) $this->organization->getId(),
            dayOfWeek: DayOfWeek::Wednesday,
            window: TimeRange::fromMinutes(18 * 60, 20 * 60),
            reason: 'Regular coach is away and the squad has a fixture on Saturday.',
            now: new \DateTimeImmutable(),
        ));
        $entityManager->flush();

        $this->submitLogin(self::COACH_EMAIL);
        $this->client->request('GET', '/coach/my-times');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.overrides', 'Wednesday 18:00–20:00');
        self::assertSelectorTextContains('.overrides', 'Regular coach is away');
        self::assertSelectorTextContains('.overrides', 'Tara Trainer');
    }

    public function testMyTimesSaysNothingYetWhenThereAreNoOverrides(): void
    {
        $this->createAssignedCoach();

        $this->submitLogin(self::COACH_EMAIL);
        $this->client->request('GET', '/coach/my-times');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'When a trainer schedules you outside these times');
    }

    public function testTheCoachNavigationOffersMyTimes(): void
    {
        $this->createAssignedCoach();

        $this->submitLogin(self::COACH_EMAIL);
        $this->client->request('GET', '/coach/my-times');

        self::assertSelectorExists('nav[aria-label="Coach sections"] a[href="/coach/my-times"]');
    }

    public function testAPlayerCannotOpenACoachsTimes(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent, 'Dana Parent');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/coach/my-times');

        self::assertResponseStatusCodeSame(403);
    }

    public function testATrainerCannotOpenACoachsTimes(): void
    {
        $this->createAssignedCoach();

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/coach/my-times');

        // BR-082: a trainer reads a coach's availability through their own screens and never
        // writes it. `^/coach` is the coach's own section.
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousVisitorIsSentToTheLoginForm(): void
    {
        $this->client->request('GET', '/coach/my-times');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
