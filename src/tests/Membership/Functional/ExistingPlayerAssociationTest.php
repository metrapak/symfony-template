<?php

declare(strict_types=1);

namespace App\Tests\Membership\Functional;

use App\Account\Enum\UserRole;
use App\Membership\Enum\RedemptionOutcome;

/**
 * FR-043 and FR-044 — an account that already exists joining another trainer.
 */
final class ExistingPlayerAssociationTest extends MembershipWebTestCase
{
    private const PLAYER_EMAIL = 'pat@example.com';

    public function testAPlayerWithOneProfileJoinsWithASingleConfirmation(): void
    {
        $player = $this->createUser(self::PLAYER_EMAIL, name: 'Pat Player');
        $profile = $this->createSelfProfile($player);
        $link = $this->createPlayerLink();

        $this->submitLogin(self::PLAYER_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Join Northside Academy');

        self::assertResponseRedirects('/family');

        $associations = $this->associations();
        self::assertCount(1, $associations);
        self::assertSame($profile->getId(), $associations[0]->getPlayerProfile()->getId());
        self::assertSame(RedemptionOutcome::Association, $this->redemptions()[0]->getOutcome());
        self::assertSame(1, $this->reloadLink((int) $link->getId())->getUseCount());
    }

    /**
     * FR-043 / BR-043 — the multi-trainer case: a second association, never a second account.
     */
    public function testJoiningASecondTrainerAddsAnAssociationWithoutASecondAccount(): void
    {
        $player = $this->createUser(self::PLAYER_EMAIL, name: 'Pat Player');
        $profile = $this->createSelfProfile($player);

        $firstLink = $this->createPlayerLink();
        $this->createAssociation($profile, $firstLink);

        $otherTrainer = $this->createUser('other-trainer@example.com', UserRole::Trainer, name: 'Otto Trainer');
        $otherOrganization = $this->createOrganizationFor($otherTrainer, 'Southside Sports');
        $secondLink = $this->createPlayerLink($otherOrganization, $otherTrainer);

        $this->submitLogin(self::PLAYER_EMAIL);
        $this->client->request('GET', '/join/' . $secondLink->getCode());
        $this->client->submitForm('Join Southside Sports');

        self::assertResponseRedirects('/family');

        $associations = $this->associations();
        self::assertCount(2, $associations);
        self::assertSame(
            [$this->organization->getId(), $otherOrganization->getId()],
            array_map(static fn ($association) => $association->getOrganization()->getId(), $associations),
        );

        // One account throughout: the player row count is unchanged by the second redemption.
        self::assertCount(1, $this->freshEntityManager()->getRepository(\App\Account\Entity\User::class)->findBy(['email' => self::PLAYER_EMAIL]));
    }

    /**
     * FR-043's idempotency: re-opening a link you already accepted is a success that changes
     * nothing — no duplicate row, no error page, and no use consumed.
     */
    public function testRedeemingTheSameLinkTwiceIsANoOpSuccess(): void
    {
        $player = $this->createUser(self::PLAYER_EMAIL, name: 'Pat Player');
        $this->createSelfProfile($player);
        $link = $this->createPlayerLink();

        $this->submitLogin(self::PLAYER_EMAIL);

        $this->client->request('GET', '/join/' . $link->getCode());
        $this->client->submitForm('Join Northside Academy');
        self::assertResponseRedirects('/family');

        $this->client->request('GET', '/join/' . $link->getCode());
        $this->client->submitForm('Join Northside Academy');
        self::assertResponseRedirects('/family');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'You already train with Northside Academy.');

        self::assertCount(1, $this->associations());
        self::assertCount(1, $this->redemptions(), 'A repeat visit is not a second redemption.');
        self::assertSame(1, $this->reloadLink((int) $link->getId())->getUseCount());
    }

    /**
     * FR-044 — "Who will train with {trainer}?", and only the checked members join.
     */
    public function testAParentSeesTheFamilyChecklistAndOnlyCheckedMembersAreAssociated(): void
    {
        $parent = $this->createUser(self::PLAYER_EMAIL, name: 'Pat Parent');
        $parentProfile = $this->createSelfProfile($parent);
        $firstChild = $this->createChildProfile($parent, 'Sam Parent');
        $secondChild = $this->createChildProfile($parent, 'Alex Parent');
        $link = $this->createPlayerLink();

        $this->submitLogin(self::PLAYER_EMAIL);
        $crawler = $this->client->request('GET', '/join/' . $link->getCode());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('legend', 'Who will train with Northside Academy?');
        self::assertCount(3, $crawler->filter('input[type="checkbox"]'));
        // The account holder is offered as "Me", the children by name (FR-044).
        self::assertStringContainsString('Pat Parent (Me)', $crawler->filter('fieldset')->text());

        $this->submitFamilySelection($link->getCode(), [(int) $firstChild->getId()]);

        self::assertResponseRedirects('/family');

        $associations = $this->associations();
        self::assertCount(1, $associations);
        self::assertSame($firstChild->getId(), $associations[0]->getPlayerProfile()->getId());

        $associatedIds = array_map(static fn ($association) => $association->getPlayerProfile()->getId(), $associations);
        self::assertNotContains($parentProfile->getId(), $associatedIds);
        self::assertNotContains($secondChild->getId(), $associatedIds);
    }

    public function testAnEmptyFamilySelectionAssociatesNobody(): void
    {
        $parent = $this->createUser(self::PLAYER_EMAIL, name: 'Pat Parent');
        $this->createSelfProfile($parent);
        $this->createChildProfile($parent, 'Sam Parent');
        $link = $this->createPlayerLink();

        $this->submitLogin(self::PLAYER_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());
        $this->submitFamilySelection($link->getCode(), []);

        self::assertResponseRedirects('/join/' . $link->getCode());
        self::assertCount(0, $this->associations());
    }

    /**
     * The checklist is rendered from the account's own family, so a submitted id from outside
     * it is tampering. It is refused rather than filtered, and nobody is associated.
     */
    public function testAProfileFromAnotherFamilyCannotBeAssociated(): void
    {
        $parent = $this->createUser(self::PLAYER_EMAIL, name: 'Pat Parent');
        $this->createSelfProfile($parent);
        $this->createChildProfile($parent, 'Sam Parent');

        $stranger = $this->createUser('stranger@example.com', name: 'Sid Stranger');
        $strangerProfile = $this->createSelfProfile($stranger);

        $link = $this->createPlayerLink();

        $this->submitLogin(self::PLAYER_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());

        $this->submitFamilySelection($link->getCode(), [(int) $strangerProfile->getId()]);

        self::assertResponseRedirects('/join/' . $link->getCode());
        self::assertCount(0, $this->associations());
    }

    /**
     * Posts the checklist by hand rather than through `submitForm()`.
     *
     * DomCrawler indexes same-named checkboxes positionally, so ticking one by profile id
     * through the form object means translating ids into positions in the test — which would
     * pass just as happily if the server started associating the wrong person. The token
     * still comes from the rendered form, so CSRF is exercised.
     *
     * @param list<int> $profileIds
     */
    private function submitFamilySelection(string $code, array $profileIds): void
    {
        $token = $this->client->getCrawler()->filter('input[name="family_selection_form[_token]"]');

        $this->client->request('POST', '/join/' . $code . '/associate', [
            'family_selection_form' => [
                'profileIds' => array_map(strval(...), $profileIds),
                '_token' => $token->count() > 0 ? (string) $token->attr('value') : '',
            ],
        ]);
    }
}
