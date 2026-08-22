<?php

declare(strict_types=1);

namespace App\Tests\Availability\Functional;

use App\Account\Enum\UserRole;
use App\Availability\Enum\DayOfWeek;

/**
 * "Best Times" for a player and for each child (FR-080, FR-081, FR-088, BR-082, NFR-081).
 */
final class PlayerAvailabilityTest extends AvailabilityWebTestCase
{
    public function testPlayerSavesAWeekAndItSurvivesAReload(): void
    {
        $player = $this->createParent('solo-player@example.test', 'Sam Player');
        $profile = $this->createSelfProfile($player, 'Sam Player');
        $this->createAssociation($profile);

        $this->submitLogin('solo-player@example.test');

        $this->submitWeek(\sprintf('/availability/player/%d', $profile->getId()), [
            // Three adjacent evening blocks, which is how a family says "17:00 to 20:00".
            'monday' => ['slots' => [self::hourCell(17), self::hourCell(18), self::hourCell(19)]],
            'wednesday' => ['slots' => [self::hourCell(18), self::hourCell(19), self::hourCell(20)]],
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        // FR-080's confirmation, which is also what tells a family why the grid exists.
        self::assertSelectorTextContains(
            '.flash-success',
            'Availability saved. Trainers can see these preferences when planning sessions.',
        );

        self::assertSame(
            ['Monday 17:00–20:00', 'Wednesday 18:00–21:00'],
            $this->weekOf($this->playerSubject($profile)),
            'adjacent blocks are stored as one range per day',
        );

        // The reloaded grid re-ticks what was saved.
        $crawler = $this->client->request('GET', \sprintf('/availability/player/%d', $profile->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.best-times', 'Mon 5–8pm, Wed 6–9pm');
        self::assertCount(6, $crawler->filter('input[data-availability-cell][checked]'));
    }

    /**
     * NFR-081, checked structurally: the grid is a table with associated headers, and every cell
     * is a labelled checkbox. This is the requirement the epic calls its hardest, and these
     * assertions are what stop a later redesign quietly turning the grid back into a div of
     * clickable spans.
     */
    public function testTheGridIsATableOfLabelledCheckboxes(): void
    {
        $player = $this->createParent('grid-player@example.test', 'Gina Player');
        $profile = $this->createSelfProfile($player, 'Gina Player');

        $this->submitLogin('grid-player@example.test');
        $crawler = $this->client->request('GET', \sprintf('/availability/player/%d', $profile->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.availability-grid'));
        self::assertCount(25, $crawler->filter('table.availability-grid thead th[scope="col"]'), '24 hours plus the day column');
        self::assertCount(7, $crawler->filter('table.availability-grid tbody th[scope="row"]'), 'one row header per day');
        self::assertCount(168, $crawler->filter('input[data-availability-cell]'), '7 days of 24 hourly blocks');

        // A cell's accessible name is complete on its own, because a screen reader landing
        // mid-table announces the label rather than the headers above it.
        $firstCell = $crawler->filter('td.cell input')->first();
        $label = $crawler->filter(\sprintf('label[for="%s"]', $firstCell->attr('id')));

        self::assertSame('Monday midnight to 1:00 AM, available', trim($label->text()));

        // Every day offers the explicit refusal FR-080 asks for.
        self::assertCount(7, $crawler->filter('input[data-availability-unavailable]'));

        // The enhancement's controls ship hidden, so the page is honest with JavaScript off.
        self::assertNotNull($crawler->filter('[data-availability-copy-weekdays]')->attr('hidden'));
    }

    public function testParentSetsSeparateAvailabilityForEachChild(): void
    {
        $parent = $this->createParent();
        $mateo = $this->createChildProfile($parent, 'Mateo');
        $maya = $this->createChildProfile($parent, 'Maya');

        $this->submitLogin(self::PARENT_EMAIL);

        $this->submitWeek(\sprintf('/availability/player/%d', $mateo->getId()), [
            'monday' => ['slots' => [self::hourCell(16), self::hourCell(17)]],
        ]);
        $this->submitWeek(\sprintf('/availability/player/%d', $maya->getId()), [
            'thursday' => ['slots' => [self::hourCell(9)]],
        ]);

        self::assertSame(['Monday 16:00–18:00'], $this->weekOf($this->playerSubject($mateo)));
        self::assertSame(['Thursday 09:00–10:00'], $this->weekOf($this->playerSubject($maya)));
    }

    public function testTheSwitcherOffersEveryProfileTheAccountManages(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent, 'Dana Parent');
        $child = $this->createChildProfile($parent, 'Mateo');

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', \sprintf('/availability/player/%d', $parentProfile->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('a[href="/availability/player/%d"]', $child->getId()));
        // The profile being edited is named but not linked to itself.
        self::assertSelectorTextContains('nav[aria-label="Choose whose availability to set"] strong', 'Dana Parent');
    }

    public function testIndexOpensTheAccountsOwnProfile(): void
    {
        $parent = $this->createParent();
        $this->createChildProfile($parent, 'Mateo');
        $parentProfile = $this->createSelfProfile($parent, 'Dana Parent');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/availability');

        self::assertResponseRedirects(\sprintf('/availability/player/%d', $parentProfile->getId()));
    }

    public function testIndexFallsBackToTheFirstChildWhenTheParentIsNotAPlayer(): void
    {
        $parent = $this->createParent();
        $child = $this->createChildProfile($parent, 'Mateo');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/availability');

        self::assertResponseRedirects(\sprintf('/availability/player/%d', $child->getId()));
    }

    public function testAnAccountWithNoProfileIsSentToTheFamilyPage(): void
    {
        $this->createParent('childless@example.test', 'New Parent');

        $this->submitLogin('childless@example.test');
        $this->client->request('GET', '/availability');

        self::assertResponseRedirects('/family/players');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'Add a player profile first');
    }

    public function testMarkingADayNotAvailableStoresTheRefusal(): void
    {
        $player = $this->createParent('refuser@example.test', 'Rita Player');
        $profile = $this->createSelfProfile($player, 'Rita Player');

        $this->submitLogin('refuser@example.test');

        $this->submitWeek(\sprintf('/availability/player/%d', $profile->getId()), [
            'monday' => ['slots' => [self::hourCell(17)]],
            'wednesday' => ['unavailable' => '1'],
        ]);

        self::assertSame(
            ['Monday 17:00–18:00', 'Wednesday 00:00–24:00 unavailable'],
            $this->weekOf($this->playerSubject($profile)),
        );

        // A refusal is information, so the summary says so rather than staying silent.
        $this->client->request('GET', \sprintf('/availability/player/%d', $profile->getId()));
        self::assertSelectorTextContains('.week-lines', 'Wed not available');
    }

    public function testADayCannotBeBothNotAvailableAndSelected(): void
    {
        $player = $this->createParent('contradictor@example.test', 'Cass Player');
        $profile = $this->createSelfProfile($player, 'Cass Player');

        $this->submitLogin('contradictor@example.test');

        $this->submitWeek(\sprintf('/availability/player/%d', $profile->getId()), [
            'wednesday' => ['slots' => [self::hourCell(18)], 'unavailable' => '1'],
        ]);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('#error-summary', 'Wednesday is marked as not available');
        self::assertSame([], $this->slotsFor($this->playerSubject($profile)), 'nothing was written');
    }

    /**
     * Out-of-grid times are refused by the choice list rather than by a range check, which is why
     * a forged value fails as a rejected submit and not as an error.
     */
    public function testAForgedTimeValueIsRejected(): void
    {
        $player = $this->createParent('forger@example.test', 'Fred Player');
        $profile = $this->createSelfProfile($player, 'Fred Player');

        $this->submitLogin('forger@example.test');

        $this->submitWeek(\sprintf('/availability/player/%d', $profile->getId()), [
            'monday' => ['slots' => ['m1500']], // 25:00, which is not a time of day
        ]);

        self::assertResponseIsUnprocessable();
        self::assertSame([], $this->slotsFor($this->playerSubject($profile)));
    }

    public function testSavingAnEmptyWeekClearsWhatWasThere(): void
    {
        $player = $this->createParent('clearer@example.test', 'Cleo Player');
        $profile = $this->createSelfProfile($player, 'Cleo Player');
        $this->seedWeek($this->playerSubject($profile), [DayOfWeek::Monday->value => [[17 * 60, 20 * 60]]]);

        $this->submitLogin('clearer@example.test');
        $this->submitWeek(\sprintf('/availability/player/%d', $profile->getId()), []);

        self::assertSame([], $this->slotsFor($this->playerSubject($profile)));
    }

    public function testAnotherFamilysChildIsRefused(): void
    {
        $parent = $this->createParent();
        $otherParent = $this->createParent(self::OTHER_PARENT_EMAIL, 'Other Parent');
        $theirChild = $this->createChildProfile($otherParent, 'Not Yours');

        $this->createSelfProfile($parent, 'Dana Parent');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/availability/player/%d', $theirChild->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAChildLoginEditsTheirOwnWeekAndNotASiblings(): void
    {
        $parent = $this->createParent();
        $childAccount = $this->createUser(self::CHILD_EMAIL, UserRole::Player, name: 'Maya Child');
        $maya = $this->createChildProfile($parent, 'Maya', $childAccount);
        $sibling = $this->createChildProfile($parent, 'Mateo');

        $this->submitLogin(self::CHILD_EMAIL);

        // FR-068 lists preferences among the things a child account may change.
        $this->submitWeek(\sprintf('/availability/player/%d', $maya->getId()), [
            'friday' => ['slots' => [self::hourCell(16)]],
        ]);
        self::assertSame(['Friday 16:00–17:00'], $this->weekOf($this->playerSubject($maya)));

        // Their sibling's week is not theirs, even inside the same family.
        $this->client->request('GET', \sprintf('/availability/player/%d', $sibling->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * BR-082: a trainer views availability and never writes it, and the refusal is a 403 rather
     * than an absent link.
     */
    public function testATrainerCannotOpenAPlayersAvailabilityForm(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent, 'Dana Parent');
        $this->createAssociation($profile);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', \sprintf('/availability/player/%d', $profile->getId()));

        // The trainer holds ROLE_TRAINER, so `access_control` refuses `^/availability` outright.
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousVisitorIsSentToTheLoginForm(): void
    {
        $this->client->request('GET', '/availability');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testANonExistentProfileIsNotFound(): void
    {
        $player = $this->createParent('curious@example.test', 'Curious Player');
        $this->createSelfProfile($player, 'Curious Player');

        $this->submitLogin('curious@example.test');
        $this->client->request('GET', '/availability/player/987654');

        self::assertResponseStatusCodeSame(404);
    }
}
