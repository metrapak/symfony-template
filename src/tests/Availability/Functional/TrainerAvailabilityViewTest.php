<?php

declare(strict_types=1);

namespace App\Tests\Availability\Functional;

use App\Availability\Enum\DayOfWeek;

/**
 * The trainer's view of player availability, its filter, and its tenancy (FR-083, FR-084,
 * FR-088, BR-082, BR-087).
 */
final class TrainerAvailabilityViewTest extends AvailabilityWebTestCase
{
    public function testTrainerSeesBestTimesForTheirOwnPlayers(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent, 'Dana Parent');
        $this->createAssociation($profile);
        $this->seedWeek($this->playerSubject($profile), [
            DayOfWeek::Monday->value => [[17 * 60, 20 * 60]],
            DayOfWeek::Wednesday->value => [[18 * 60, 21 * 60]],
        ]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/players/availability');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Dana Parent');
        // The spec's own player-card wording.
        self::assertSelectorTextContains('tbody', 'Mon 5–8pm, Wed 6–9pm');
    }

    /**
     * BR-087, with a second tenant present so a missing filter cannot pass by accident.
     */
    public function testTrainerSeesNoOtherOrganizationsPlayers(): void
    {
        $otherOrganization = $this->createSecondOrganization();

        $mine = $this->createSelfProfile($this->createParent('mine@example.test', 'My Player'), 'My Player');
        $this->createAssociation($mine);
        $this->seedWeek($this->playerSubject($mine), [DayOfWeek::Monday->value => [[17 * 60, 20 * 60]]]);

        $theirs = $this->createSelfProfile($this->createParent('theirs@example.test', 'Their Player'), 'Their Player');
        $this->createAssociation($theirs, null, $otherOrganization);
        $this->seedWeek($this->playerSubject($theirs), [DayOfWeek::Monday->value => [[17 * 60, 20 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->client->request('GET', '/trainer/players/availability?availability_filter_form[day]=1&availability_filter_form[startMinute]=1020&availability_filter_form[endMinute]=1080');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('My Player', $crawler->filter('tbody')->text());
        self::assertStringNotContainsString('Their Player', $crawler->filter('tbody')->text());
        // Both players are available; only one of them is this trainer's.
        self::assertSelectorTextContains('.tally', 'Players available at this time: 1 of 1');
    }

    public function testFilterReturnsOnlyPlayersWhoseTimesCoverTheWholeWindow(): void
    {
        // Available for the whole Monday evening.
        $covers = $this->createSelfProfile($this->createParent('covers@example.test', 'Cora Covers'), 'Cora Covers');
        $this->createAssociation($covers);
        $this->seedWeek($this->playerSubject($covers), [DayOfWeek::Monday->value => [[17 * 60, 20 * 60]]]);

        // Available on Monday, but only until 18:00 — a partial overlap is not availability.
        $partial = $this->createSelfProfile($this->createParent('partial@example.test', 'Pat Partial'), 'Pat Partial');
        $this->createAssociation($partial);
        $this->seedWeek($this->playerSubject($partial), [DayOfWeek::Monday->value => [[16 * 60, 18 * 60]]]);

        // Declared a week, and Monday is not in it.
        $elsewhere = $this->createSelfProfile($this->createParent('elsewhere@example.test', 'Eli Elsewhere'), 'Eli Elsewhere');
        $this->createAssociation($elsewhere);
        $this->seedWeek($this->playerSubject($elsewhere), [DayOfWeek::Thursday->value => [[17 * 60, 20 * 60]]]);

        // Has never filled the grid in: neither available nor unavailable.
        $silent = $this->createSelfProfile($this->createParent('silent@example.test', 'Sam Silent'), 'Sam Silent');
        $this->createAssociation($silent);

        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->client->request('GET', '/trainer/players/availability?availability_filter_form[day]=1&availability_filter_form[startMinute]=1020&availability_filter_form[endMinute]=1140');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tally', 'Players available at this time: 1 of 4');
        // FR-083's hidden third number: silence is reported as silence.
        self::assertSelectorTextContains('.tally', '1 player has not set their times yet');

        $rows = $crawler->filter('tbody tr')->each(static fn ($row): string => preg_replace('/\s+/', ' ', trim($row->text())) ?? '');

        self::assertContains('Cora Covers Mon 5–8pm Yes', $rows);
        self::assertContains('Pat Partial Mon 4–6pm No', $rows);
        self::assertContains('Eli Elsewhere Thu 5–8pm No', $rows);
        self::assertContains('Sam Silent No preferred times set Not set', $rows);
    }

    /**
     * A window that exactly equals a declared range counts, which is the boundary case the
     * repository test pins down in SQL and this one pins down through the page.
     */
    public function testAWindowEqualToTheDeclaredRangeMatches(): void
    {
        $profile = $this->createSelfProfile($this->createParent('exact@example.test', 'Exa Ct'), 'Exa Ct');
        $this->createAssociation($profile);
        $this->seedWeek($this->playerSubject($profile), [DayOfWeek::Monday->value => [[17 * 60, 20 * 60]]]);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/players/availability?availability_filter_form[day]=1&availability_filter_form[startMinute]=1020&availability_filter_form[endMinute]=1200');

        self::assertSelectorTextContains('.tally', 'Players available at this time: 1 of 1');
    }

    public function testTheUnfilteredPageListsEverybody(): void
    {
        $first = $this->createSelfProfile($this->createParent('first@example.test', 'First Player'), 'First Player');
        $second = $this->createSelfProfile($this->createParent('second@example.test', 'Second Player'), 'Second Player');
        $this->createAssociation($first);
        $this->createAssociation($second);

        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->client->request('GET', '/trainer/players/availability');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorNotExists('.tally', 'no filter, no count');
    }

    public function testAnEndBeforeItsStartIsRejected(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/players/availability?availability_filter_form[day]=1&availability_filter_form[startMinute]=1140&availability_filter_form[endMinute]=1020');

        // Symfony answers a rejected form with 422, which is as true of a filter in the query
        // string as of a posted form: the page renders, and the query is not processable.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#error-summary', 'The end time has to be after the start time.');
        self::assertSelectorNotExists('.tally');
    }

    public function testAnEndedAssociationDropsThePlayerFromTheView(): void
    {
        $profile = $this->createSelfProfile($this->createParent('left@example.test', 'Lee Left'), 'Lee Left');
        $association = $this->createAssociation($profile);
        $this->seedWeek($this->playerSubject($profile), [DayOfWeek::Monday->value => [[17 * 60, 20 * 60]]]);

        $association->deactivate(new \DateTimeImmutable());
        $this->currentEntityManager()->flush();

        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->client->request('GET', '/trainer/players/availability');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Lee Left', $crawler->filter('tbody')->text());
    }

    public function testAPlayerCannotOpenTheTrainersView(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent, 'Dana Parent');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/trainer/players/availability');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheTrainerNavigationOffersTheView(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/players/availability');

        self::assertSelectorExists('nav[aria-label="Trainer sections"] a[href="/trainer/players/availability"]');
    }
}
