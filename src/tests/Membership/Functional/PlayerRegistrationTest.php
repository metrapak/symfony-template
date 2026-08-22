<?php

declare(strict_types=1);

namespace App\Tests\Membership\Functional;

use App\Account\Enum\UserRole;
use App\Membership\Enum\RedemptionOutcome;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * FR-042 and Flow 1 — a stranger with a link becomes a player in a trainer's CRM.
 */
final class PlayerRegistrationTest extends MembershipWebTestCase
{
    private const NEW_PLAYER_EMAIL = 'newplayer@example.com';

    public function testTheLandingPageInvitesAnAnonymousVisitorToRegister(): void
    {
        $link = $this->createPlayerLink();

        $crawler = $this->client->request('GET', '/join/' . $link->getCode());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Northside Academy', $crawler->filter('h1')->text());
        self::assertGreaterThan(0, $crawler->filter('a[href="/join/' . $link->getCode() . '/register"]')->count());
    }

    public function testRegisteringCreatesTheAccountProfileAssociationAndRedemptionRecord(): void
    {
        $link = $this->createPlayerLink();

        $this->submitRegistration($link->getCode());

        // FR-042: verification is required for players by default (Q-01.05), so the flow ends
        // at the sign-in page rather than on a dashboard.
        self::assertResponseRedirects('/login');

        $user = $this->reloadUser(self::NEW_PLAYER_EMAIL);
        self::assertSame(UserRole::Player, $user->getRole());
        self::assertSame('+44 20 7946 0111', $user->getPhone());

        $profiles = $this->profiles();
        self::assertCount(1, $profiles);
        self::assertSame('Pat Player', $profiles[0]->getDisplayName());
        self::assertFalse($profiles[0]->isChild());
        self::assertSame('1996-04-05', $profiles[0]->getBirthDate()?->format('Y-m-d'));

        $associations = $this->associations();
        self::assertCount(1, $associations);
        self::assertSame($this->organization->getId(), $associations[0]->getOrganization()->getId());
        self::assertSame($profiles[0]->getId(), $associations[0]->getPlayerProfile()->getId());
        self::assertTrue($associations[0]->isActive());
        self::assertSame($link->getId(), $associations[0]->getViaShareLink()?->getId());

        // FR-047: the redemption is recorded and the counter moved.
        $redemptions = $this->redemptions();
        self::assertCount(1, $redemptions);
        self::assertSame(RedemptionOutcome::NewAccount, $redemptions[0]->getOutcome());
        self::assertSame(1, $this->reloadLink((int) $link->getId())->getUseCount());
    }

    public function testTheConfirmationAndVerificationEmailsAreBothSent(): void
    {
        $link = $this->createPlayerLink();

        $this->submitRegistration($link->getCode());

        self::assertEmailCount(2);

        $subjects = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            self::assertSame(self::NEW_PLAYER_EMAIL, $message->getTo()[0]->getAddress());
            $subjects[] = $message->getSubject();
        }

        // The confirmation says the registration worked; the verification link is what makes
        // the account usable while the Q-01.05 gate is on. Both, or the new player is told to
        // check an inbox that has nothing to act on.
        self::assertContains('You are registered with Northside Academy', $subjects);
        self::assertContains('Confirm your email address', $subjects);
    }

    /**
     * G-21: the form asks who is joining rather than guessing. A parent gets a profile of
     * their own as well, because US-01.03 treats them as a player too and FR-044's "Me"
     * checkbox needs it — but only the child is associated with the trainer.
     */
    public function testRegisteringAChildCreatesBothProfilesAndAssociatesOnlyTheChild(): void
    {
        $link = $this->createPlayerLink();

        $this->submitRegistration($link->getCode(), [
            'player_registration_form[registeringChild]' => 'child',
            'player_registration_form[playerName]' => 'Sam Player',
            'player_registration_form[birthDate]' => '2015-06-01',
        ]);

        self::assertResponseRedirects('/login');

        $profiles = $this->profiles();
        self::assertCount(2, $profiles);

        [$parentProfile, $childProfile] = $profiles;
        self::assertSame('Pat Player', $parentProfile->getDisplayName());
        self::assertFalse($parentProfile->isChild());
        self::assertSame('Sam Player', $childProfile->getDisplayName());
        self::assertTrue($childProfile->isChild());
        self::assertNull($childProfile->getAccount(), 'A child profile has no login of its own until TASK-004.');

        $associations = $this->associations();
        self::assertCount(1, $associations);
        self::assertSame($childProfile->getId(), $associations[0]->getPlayerProfile()->getId());
    }

    /**
     * NFR-043 / progressive enhancement: the child rules are chosen by the submitted flag, so
     * they apply even though JavaScript is what normally reveals the field.
     */
    public function testTheChildBranchRequiresAPlayerNameEvenThoughTheFieldMayBeHidden(): void
    {
        $link = $this->createPlayerLink();

        $crawler = $this->submitRegistration($link->getCode(), [
            'player_registration_form[registeringChild]' => 'child',
            'player_registration_form[playerName]' => '',
            'player_registration_form[birthDate]' => '2015-06-01',
        ]);

        // 422, not 200: AbstractController::render() reports an invalid submitted form as
        // unprocessable, which is what a rejected submit is.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString("Enter the player's name.", $crawler->filter('.form-error-summary')->text());
        self::assertCount(0, $this->profiles());
    }

    public function testAChildProfileMustBeUnderNineteen(): void
    {
        $link = $this->createPlayerLink();

        $crawler = $this->submitRegistration($link->getCode(), [
            'player_registration_form[registeringChild]' => 'child',
            'player_registration_form[playerName]' => 'Sam Player',
            'player_registration_form[birthDate]' => '1990-06-01',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('aged 18 or under', $crawler->filter('.form-error-summary')->text());
        self::assertCount(0, $this->profiles());
    }

    public function testSelfRegistrationRequiresAnAdultBirthDate(): void
    {
        $link = $this->createPlayerLink();

        $crawler = $this->submitRegistration($link->getCode(), [
            'player_registration_form[birthDate]' => (new \DateTimeImmutable('-10 years'))->format('Y-m-d'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You must be 18 or over', $crawler->filter('.form-error-summary')->text());
        self::assertCount(0, $this->profiles());
    }

    public function testAnAddressThatAlreadyHasAnAccountIsRejectedOnTheEmailField(): void
    {
        $this->createUser(self::NEW_PLAYER_EMAIL);
        $link = $this->createPlayerLink();

        $crawler = $this->submitRegistration($link->getCode());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('An account already exists', $crawler->filter('.form-error-summary')->text());
        self::assertCount(0, $this->associations());
    }

    /**
     * FR-049: the registration form is behind the same resolution as the landing page, so a
     * withdrawn link cannot be registered against by replaying its URL.
     */
    public function testRegistrationIsRefusedForADeactivatedLink(): void
    {
        $link = $this->createPlayerLink();
        $link->deactivate(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->client->request('GET', '/join/' . $link->getCode() . '/register');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function submitRegistration(string $code, array $overrides = []): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->request('GET', '/join/' . $code . '/register');
        self::assertResponseIsSuccessful();

        return $this->client->submitForm('Create account', array_merge([
            'player_registration_form[registeringChild]' => 'self',
            'player_registration_form[name]' => 'Pat Player',
            'player_registration_form[email]' => self::NEW_PLAYER_EMAIL,
            'player_registration_form[plainPassword][first]' => 'Password123',
            'player_registration_form[plainPassword][second]' => 'Password123',
            'player_registration_form[phone]' => '+44 20 7946 0111',
            'player_registration_form[birthDate]' => '1996-04-05',
            'player_registration_form[gender]' => 'undisclosed',
        ], $overrides));
    }
}
