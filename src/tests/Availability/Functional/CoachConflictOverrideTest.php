<?php

declare(strict_types=1);

namespace App\Tests\Availability\Functional;

use App\Account\Entity\AuditLogEntry;
use App\Account\Enum\AuditAction;
use App\Availability\Enum\DayOfWeek;

/**
 * The conflict warning, the required reason, and the record (FR-085, FR-086, FR-087, FR-088,
 * BR-084, BR-085, BR-087, NFR-X02).
 */
final class CoachConflictOverrideTest extends AvailabilityWebTestCase
{
    /** Monday 18:00–20:00, in the form's own vocabulary. */
    private const MONDAY_EVENING = [
        'day' => '1',
        'startMinute' => '1080',
        'endMinute' => '1200',
    ];

    public function testWarningIsShownForATimeOutsideTheCoachsStatedHours(): void
    {
        $coach = $this->createAssignedCoach();
        // Free on Monday, but only until 18:00.
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 18 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitCheck($coach, self::MONDAY_EVENING);

        self::assertResponseIsSuccessful();
        // FR-085's sentence, verbatim.
        self::assertSelectorTextContains(
            '.conflict',
            'Coach Casey Coach is not available at this time per their schedule. Continue anyway?',
        );
        // What they *did* say, so the trainer can reschedule instead of overriding.
        self::assertSelectorTextContains('.conflict', 'Monday: 4–6pm');
        // FR-086: the reason field and the way past the warning are both on the page.
        self::assertSelectorExists('#coach_conflict_form_reason');
        self::assertSelectorExists('button[name="coach_conflict_form[confirm]"]');
        self::assertSame([], $this->overrides(), 'checking records nothing');
    }

    public function testNoWarningWhenTheCoachIsAvailable(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 21 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitCheck($coach, self::MONDAY_EVENING);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.conflict');
        self::assertSelectorTextContains('.no-conflict', 'is available Monday 18:00–20:00');
        self::assertSelectorNotExists('button[name="coach_conflict_form[confirm]"]');
    }

    /**
     * A coach who has declared nothing is not in conflict. Warning here would demand an
     * explanation for the absence of data, which is how a warning stops meaning anything.
     */
    public function testNoWarningWhenTheCoachHasDeclaredNothing(): void
    {
        $coach = $this->createAssignedCoach();

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitCheck($coach, self::MONDAY_EVENING);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.conflict');
        self::assertSelectorTextContains('.no-conflict', 'has not set their times yet');
        self::assertSame([], $this->overrides());
    }

    /**
     * A declared "not available on Wednesdays" *is* a conflict — the other half of the same
     * distinction.
     */
    public function testAnExplicitlyUnavailableDayIsAConflict(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [], [DayOfWeek::Wednesday]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitCheck($coach, ['day' => '3', 'startMinute' => '1080', 'endMinute' => '1200']);

        self::assertSelectorTextContains('.conflict', 'is not available at this time');
        self::assertSelectorTextContains('.conflict', 'Wednesday: not available');
    }

    public function testAnEmptyReasonBlocksTheOverride(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 18 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitConfirm($coach, self::MONDAY_EVENING + ['reason' => '   ']);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'Enter a reason for scheduling this coach outside their stated times.');
        self::assertSame([], $this->overrides(), 'nothing is recorded without a reason');
    }

    public function testAnOverrideWithAReasonIsRecordedInFull(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 18 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitConfirm($coach, self::MONDAY_EVENING + [
            'reason' => 'Only coach available for the tournament squad.',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Override recorded.');

        $overrides = $this->overrides();
        self::assertCount(1, $overrides);

        // FR-086's five fields, plus the window that makes the record readable before Epic-02.
        $override = $overrides[0];
        self::assertSame($coach->getId(), $override->getCoach()->getId());
        self::assertSame($this->trainer->getId(), $override->getOverriddenBy()->getId());
        self::assertSame($this->organization->getId(), $override->getOrganizationId());
        self::assertSame('Only coach available for the tournament squad.', $override->getReason());
        self::assertSame(DayOfWeek::Monday, $override->getDayOfWeek());
        self::assertSame('18:00–20:00', $override->getWindow()->format());
        self::assertNull($override->getEventId(), 'Epic-02 supplies the event; nothing here invents one');
        self::assertEqualsWithDelta(time(), $override->getCreatedAt()->getTimestamp(), 60);
    }

    /**
     * NFR-X02 lists an override beside impersonation and deletion.
     */
    public function testAnOverrideIsAudited(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 18 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitConfirm($coach, self::MONDAY_EVENING + ['reason' => 'Cover for illness.']);

        $entries = $this->freshEntityManager()->getRepository(AuditLogEntry::class)->findBy(
            ['action' => AuditAction::CoachAvailabilityOverridden],
        );

        self::assertCount(1, $entries);
        self::assertSame($this->trainer->getId(), $entries[0]->getActor()?->getId());
        self::assertSame('User', $entries[0]->getSubjectType());
        self::assertSame($coach->getId(), $entries[0]->getSubjectId());
        self::assertSame('Monday', $entries[0]->getPayload()['day'] ?? null);
        self::assertSame('18:00–20:00', $entries[0]->getPayload()['window'] ?? null);
    }

    /**
     * FR-087: the coach sees it. Not a notification — Q-01.06 is unanswered and G-30 leaves
     * "request a change" undefined — but the record is on their own page.
     */
    public function testTheCoachSeesTheOverrideOnTheirOwnPage(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 18 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitConfirm($coach, self::MONDAY_EVENING + ['reason' => 'Squad has a fixture on Saturday.']);

        $this->submitLogin(self::COACH_EMAIL);
        $this->client->request('GET', '/coach/my-times');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.overrides', 'Squad has a fixture on Saturday.');
        self::assertSelectorTextContains('.overrides', 'Monday 18:00–20:00');
    }

    /**
     * FR-088: nothing in the flow refuses anything. The override succeeds, and a second one for
     * the same coach and window is recorded rather than deduplicated away — two decisions are two
     * records.
     */
    public function testAvailabilityNeverBlocksTheFlow(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 18 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitConfirm($coach, self::MONDAY_EVENING + ['reason' => 'First session.']);
        $this->submitConfirm($coach, self::MONDAY_EVENING + ['reason' => 'Second session, same slot.']);

        self::assertCount(2, $this->overrides());
    }

    /**
     * The verdict is recomputed on the confirming submit, so a coach who opened their times up in
     * the meantime does not get an override recorded against a conflict that no longer exists.
     */
    public function testConfirmingAConflictThatHasGoneRecordsNothing(): void
    {
        $coach = $this->createAssignedCoach();
        $this->seedWeek($this->coachSubject($coach), [DayOfWeek::Monday->value => [[16 * 60, 21 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitConfirm($coach, self::MONDAY_EVENING + ['reason' => 'Stale warning.']);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'has no conflict at that time any more');
        self::assertSame([], $this->overrides());
    }

    public function testTheWindowMustBeComplete(): void
    {
        $coach = $this->createAssignedCoach();

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitCheck($coach, ['day' => '', 'startMinute' => '', 'endMinute' => '']);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'Choose a day.');
    }

    public function testAnEndBeforeItsStartIsRejected(): void
    {
        $coach = $this->createAssignedCoach();

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->submitCheck($coach, ['day' => '1', 'startMinute' => '1200', 'endMinute' => '1080']);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'The end time has to be after the start time.');
    }

    /**
     * BR-087: a coach of another organization is indistinguishable from one that does not exist.
     */
    public function testACoachOfAnotherOrganizationIsNotFound(): void
    {
        $otherOrganization = $this->createSecondOrganization();
        $theirCoach = $this->createAssignedCoach($otherOrganization, 'their-coach@example.test', 'Their Coach');

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', \sprintf('/trainer/coaches/%d/availability-check', $theirCoach->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testANonExistentCoachIsNotFound(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/coaches/987654/availability-check');

        self::assertResponseStatusCodeSame(404);
    }

    public function testACoachCannotRunTheCheck(): void
    {
        $coach = $this->createAssignedCoach();

        $this->submitLogin(self::COACH_EMAIL);
        $this->client->request('GET', \sprintf('/trainer/coaches/%d/availability-check', $coach->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheCoachesListLinksToTheCheck(): void
    {
        // The list is built from invitations and the assignments they produced, so the assignment
        // has to be attached to the link a real acceptance would have consumed.
        $link = $this->createCoachLink(self::COACH_EMAIL);
        $coach = $this->createCoach();
        $this->createCoachAssignment($coach, $this->organization, $link);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/coaches');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('a[href="/trainer/coaches/%d/availability-check"]', $coach->getId()));
    }

    /**
     * @param array<string, string> $values
     */
    private function submitCheck(\App\Account\Entity\User $coach, array $values): void
    {
        $this->submitFormPayload(
            \sprintf('/trainer/coaches/%d/availability-check', $coach->getId()),
            self::CONFLICT_FORM,
            $values + ['check' => ''],
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function submitConfirm(\App\Account\Entity\User $coach, array $values): void
    {
        $this->submitFormPayload(
            \sprintf('/trainer/coaches/%d/availability-check', $coach->getId()),
            self::CONFLICT_FORM,
            $values + ['confirm' => ''],
        );
    }
}
