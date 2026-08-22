<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Account\Enum\UserRole;
use App\Profile\Service\TrainingContextResolver;

/**
 * FR-069, FR-070 and NFR-063 — the epic's security boundary.
 *
 * NFR-063 asks for "0 cross-context leaks; verified by an explicit isolation test suite", and
 * this is that suite. Every test here is about one question: can a request name a context its
 * sender does not hold, and get anything other than a 403?
 *
 * The forged-context cases matter more than the happy path. A switcher that lists the right
 * options proves only that the *template* is right; what protects a family's data is that the
 * endpoint refuses a pair the user has no association with, however it arrives.
 */
final class ContextIsolationTest extends ProfileWebTestCase
{
    public function testSwitcherGroupsOwnContextsSeparatelyFromChildren(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        $this->createAssociation($parentProfile);
        $this->createAssociation($child);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/dashboard');
        $crawler = $this->client->followRedirect();

        // FR-069's two groups, by the labels the requirement itself uses.
        self::assertSame(1, $crawler->filter('optgroup[label="Your Training"]')->count());
        self::assertSame(1, $crawler->filter('optgroup[label="Your Children\'s Training"]')->count());
        self::assertSame(2, $crawler->filter('#context-select option')->count());
    }

    public function testParentWhoDoesNotTrainSeesOnlyTheirChildrensContexts(): void
    {
        $parent = $this->createParent();
        // A self profile with no association: the parent exists as a player (BR-060) but does
        // not train, which is FR-069's second shape.
        $this->createSelfProfile($parent);
        $firstChild = $this->createChildProfile($parent, 'Mateo Parent');
        $secondChild = $this->createChildProfile($parent, 'Maya Parent');

        $this->createAssociation($firstChild);
        $this->createAssociation($secondChild);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/dashboard');
        $crawler = $this->client->followRedirect();

        self::assertSame(0, $crawler->filter('optgroup[label="Your Training"]')->count());
        // Rendered flat rather than inside a single group — a group of one is noise.
        self::assertSame(0, $crawler->filter('optgroup')->count());
        self::assertSame(2, $crawler->filter('#context-select option')->count());
    }

    public function testChildSeesOnlyTheirOwnTrainersAndNoneOfTheirParents(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $sibling = $this->createChildProfile($parent, 'Mateo Parent');

        $childAccount = $this->createUser(self::CHILD_EMAIL, UserRole::Player, name: 'Maya Parent');
        $child = $this->createChildProfile($parent, 'Maya Parent', $childAccount);

        $secondOrganization = $this->createSecondOrganization();

        // The parent and the sibling train with the first organization; the child with the
        // second only. A resolver that answered from the *owner* would hand the child all three.
        $this->createAssociation($parentProfile);
        $this->createAssociation($sibling);
        $this->createAssociation($child, organization: $secondOrganization);

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/dashboard');
        $crawler = $this->client->followRedirect();

        // FR-069: a child sees a flat list with no "Me" section.
        self::assertSame(0, $crawler->filter('optgroup')->count());

        // One context, so the switcher renders as text rather than a select with one option.
        self::assertStringContainsString('Southside Skills', $crawler->filter('.context-switcher')->text());
        self::assertStringNotContainsString('Northside Academy', $crawler->filter('.context-switcher')->text());
    }

    public function testChildCannotSelectAContextBelongingToTheirParent(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $this->createAssociation($parentProfile);

        $childAccount = $this->createUser(self::CHILD_EMAIL, UserRole::Player, name: 'Maya Parent');
        $child = $this->createChildProfile($parent, 'Maya Parent', $childAccount);

        // The child holds two contexts of their own, so the switcher renders the form its token
        // lives on and the 403 below is the isolation rule rather than a missing token.
        $this->createAssociation($child, organization: $this->createSecondOrganization());
        $this->createAssociation($child, organization: $this->createThirdOrganization());

        $forged = $this->contextKeyFor($parentProfile, $this->organization);

        $this->submitLogin(self::CHILD_EMAIL);
        $this->postContext($forged);

        // FR-068's "cannot view the parent's training data", enforced where the dataset is chosen
        // rather than in each screen that renders one.
        self::assertResponseStatusCodeSame(403);
    }

    public function testForgedContextNamingAnotherFamilyIsRefused(): void
    {
        $parent = $this->createParent();
        $ownProfile = $this->createSelfProfile($parent);
        $this->createAssociation($ownProfile);

        // A second context for the parent, so the switcher form (and its token) is rendered.
        $ownChild = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($ownChild, organization: $this->createSecondOrganization());

        $stranger = $this->createParent(self::OTHER_PARENT_EMAIL, 'Sam Stranger');
        $strangerProfile = $this->createSelfProfile($stranger);
        $this->createAssociation($strangerProfile);

        $forged = $this->contextKeyFor($strangerProfile, $this->organization);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->postContext($forged);

        self::assertResponseStatusCodeSame(403);
    }

    public function testContextForAnOrganizationTheProfileDoesNotTrainWithIsRefused(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);

        // A second context, so the switcher renders its form and supplies a token.
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($child, organization: $this->createSecondOrganization());

        // A profile the user genuinely owns, paired with a tenant it has no association with.
        // Owning half of the pair must not be enough.
        $forged = $this->contextKeyFor($profile, $this->createThirdOrganization());

        $this->submitLogin(self::PARENT_EMAIL);
        $this->postContext($forged);

        self::assertResponseStatusCodeSame(403);
    }

    public function testMalformedContextIsRefusedTheSameWayAsAnUnauthorizedOne(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        // Two contexts, so the switcher renders the form its CSRF token lives on. With one, the
        // partial deliberately renders static text and there would be no token to send — and a
        // request rejected for a missing token would pass this test for the wrong reason.
        $this->createAssociation($profile);
        $this->createAssociation($child, organization: $this->createSecondOrganization());

        $this->submitLogin(self::PARENT_EMAIL);

        foreach (['', 'nonsense', '0:0', '1', '1:2:3', '-1:-1'] as $malformed) {
            $this->postContext($malformed);

            // One answer for malformed and unauthorized alike, so the endpoint cannot be used to
            // discover which contexts exist.
            self::assertResponseStatusCodeSame(403, \sprintf('Expected 403 for context "%s".', $malformed));
        }
    }

    public function testSwitchingWithoutACsrfTokenIsRefused(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('POST', '/context/switch', [
            'context' => $this->contextKeyFor($profile, $this->organization),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSelectedContextPersistsAcrossRequests(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        $secondOrganization = $this->createSecondOrganization();
        $this->createAssociation($parentProfile);
        $this->createAssociation($child, organization: $secondOrganization);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->selectContext($child, $secondOrganization);

        self::assertResponseRedirects();
        self::assertSame(
            $child->getId() . ':' . $secondOrganization->getId(),
            $this->client->getRequest()->getSession()->get(TrainingContextResolver::SESSION_KEY),
        );

        // FR-069: "the selected context persists across the session". Read on a later request,
        // through the page rather than the session, because that is what a user sees.
        $crawler = $this->client->request('GET', '/family/players');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $child->getId() . ':' . $secondOrganization->getId(),
            $crawler->filter('#context-select option[selected]')->attr('value'),
        );
    }

    public function testRemovingATrainerDropsAContextTheUserNoLongerHolds(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        $secondOrganization = $this->createSecondOrganization();
        $this->createAssociation($parentProfile);
        $this->createAssociation($child, organization: $secondOrganization);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->selectContext($child, $secondOrganization);

        $removalPath = \sprintf(
            '/family/children/%d/trainers/%d/remove',
            $child->getId(),
            $secondOrganization->getId(),
        );

        // The token comes from the confirmation page the parent would have read first (FR-066).
        $this->client->request('POST', $removalPath, ['_token' => $this->submitToken($removalPath)]);

        self::assertResponseRedirects('/family/players');

        // The stored selection is gone rather than being a 403 the parent meets on their own
        // dashboard: the resolver re-reads and falls back to a context they still hold.
        $crawler = $this->client->request('GET', '/family/players');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            (string) $secondOrganization->getName(),
            $crawler->filter('.context-switcher')->text(),
        );
    }

    public function testContextSwitchIsUnavailableToAnAnonymousVisitor(): void
    {
        $this->client->request('POST', '/context/switch', ['context' => '1:1']);

        // `access_control` on `^/context`, before any of the resolver's reasoning runs.
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * A trainer has an organization, not a training context — and the switcher must not offer one.
     *
     * Only the rendering is asserted here. A trainer's *forged* switch cannot be tested through
     * this endpoint honestly: they have no switcher, so no CSRF token, and the 403 that came back
     * would be the token check rather than the isolation rule. What a trainer may see is asserted
     * where it belongs — on the voters, in `BrandingVisibilityTest` and `ChildProhibitionsTest`.
     */
    public function testTrainerIsOfferedNoTrainingContexts(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);

        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->client->request('GET', '/trainer');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#context-select')->count());
        self::assertSame(0, $crawler->filter('.context-switcher')->count());
    }
}
