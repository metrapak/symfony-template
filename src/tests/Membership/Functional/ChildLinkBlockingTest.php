<?php

declare(strict_types=1);

namespace App\Tests\Membership\Functional;

use App\Membership\Enum\RedemptionOutcome;
use Symfony\Component\Mime\Email;

/**
 * FR-048 / BR-046 — a child may not add a trainer; their parent is asked to.
 *
 * Child logins arrive with TASK-004, so the account/profile pairing is built directly here.
 * The rule is implemented now because it belongs to the redemption flow: leaving `/join` to be
 * patched later is how a child ends up silently associated the day child logins land.
 */
final class ChildLinkBlockingTest extends MembershipWebTestCase
{
    private const PARENT_EMAIL = 'parent@example.com';
    private const CHILD_EMAIL = 'sam@example.com';

    public function testAChildIsRefusedAndTheParentIsEmailed(): void
    {
        $parent = $this->createUser(self::PARENT_EMAIL, name: 'Pat Parent');
        $childAccount = $this->createUser(self::CHILD_EMAIL, name: 'Sam Parent');
        $this->createSelfProfile($parent);
        $this->createChildProfile($parent, 'Sam Parent', $childAccount);

        $link = $this->createPlayerLink();

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ask your parent to register you');

        // Nothing was granted, and nothing was spent.
        self::assertCount(0, $this->associations());
        self::assertSame(0, $this->reloadLink((int) $link->getId())->getUseCount());

        // FR-047: the refusal is still a recorded event in the trainer's funnel.
        $redemptions = $this->redemptions();
        self::assertCount(1, $redemptions);
        self::assertSame(RedemptionOutcome::BlockedChild, $redemptions[0]->getOutcome());
        self::assertSame($childAccount->getId(), $redemptions[0]->getUser()->getId());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame("Sam Parent wants to join Northside Academy's program", $email->getSubject());
        self::assertSame(self::PARENT_EMAIL, $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Review Registration', $email->getTextBody());
        self::assertStringContainsString('/join/' . $link->getCode(), $email->getTextBody());
    }

    /**
     * The refusal page is a GET, so a reload — or a mail client prefetching the address — must
     * not mail the parent again for the same attempt.
     */
    public function testReloadingTheRefusalDoesNotEmailTheParentAgain(): void
    {
        $parent = $this->createUser(self::PARENT_EMAIL, name: 'Pat Parent');
        $childAccount = $this->createUser(self::CHILD_EMAIL, name: 'Sam Parent');
        $this->createChildProfile($parent, 'Sam Parent', $childAccount);

        $link = $this->createPlayerLink();

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());
        $this->client->request('GET', '/join/' . $link->getCode());

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0, 'The second view is the same event, not a new one.');
        self::assertCount(1, $this->redemptions());
    }

    /**
     * The parent, following the same link from their inbox, can complete what the child could
     * not — which is what makes the notification useful rather than merely polite.
     */
    public function testTheParentCanCompleteTheRegistrationFromTheSameLink(): void
    {
        $parent = $this->createUser(self::PARENT_EMAIL, name: 'Pat Parent');
        $childAccount = $this->createUser(self::CHILD_EMAIL, name: 'Sam Parent');
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Sam Parent', $childAccount);

        $link = $this->createPlayerLink();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('legend', 'Who will train with Northside Academy?');

        $token = $this->client->getCrawler()->filter('input[name="family_selection_form[_token]"]');
        $this->client->request('POST', '/join/' . $link->getCode() . '/associate', [
            'family_selection_form' => [
                'profileIds' => [(string) $child->getId()],
                '_token' => (string) $token->attr('value'),
            ],
        ]);

        self::assertResponseRedirects('/family');

        $associations = $this->associations();
        self::assertCount(1, $associations);
        self::assertSame($child->getId(), $associations[0]->getPlayerProfile()->getId());
    }
}
