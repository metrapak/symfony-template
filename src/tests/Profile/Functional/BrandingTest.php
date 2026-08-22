<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Profile\Entity\OrganizationBranding;

/**
 * FR-071, FR-072 and BR-069 — trainer portal branding, and who sees it.
 *
 * The requirement that carries the risk is BR-069's scoping: branding belongs to one
 * organization and is visible to *its* members. A player who trains with two branded trainers is
 * the ordinary case, not an edge one, and G-26 resolves it by following the active training
 * context — so the tests below check the colour on the page a viewer actually loads rather than
 * the row in the database, because the row being right proves nothing about what was rendered.
 *
 * The upload half of FR-071 (type, size, SVG) lives in ImageUploadTest; what is here is
 * visibility, the colour lifecycle, and authorization.
 */
final class BrandingTest extends ProfileWebTestCase
{
    private const FIRST_COLOR = '#7c3aed';
    private const SECOND_COLOR = '#ffe400';

    public function testTrainerSetsAColourAndItIsStoredNormalized(): void
    {
        $this->submitLogin($this->trainerEmail());

        // Shorthand, uppercase, no hash: three spellings a colour picker's text field accepts.
        $this->submitFormPayload('/trainer/branding', 'branding_form', [
            'primaryColorHex' => 'F0A',
        ]);

        self::assertResponseRedirects('/trainer/branding');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-success');

        self::assertSame('#ff00aa', $this->storedColorFor($this->organization->getName()));
    }

    public function testMembersOfTheOrganizationSeeTheTrainersColour(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR);

        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);

        // One association, so this is their only context and the resolver selects it for them.
        $this->submitLogin(self::PARENT_EMAIL);

        self::assertStringContainsString(self::FIRST_COLOR, $this->renderedStyleBlock());
    }

    /**
     * BR-069 in its negative form, which is the half worth testing: a member of one tenant must
     * not be wearing another tenant's colours.
     */
    public function testMembersOfAnotherOrganizationDoNotSeeIt(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR);
        $secondOrganization = $this->createSecondOrganization();
        $this->createBranding($secondOrganization, self::SECOND_COLOR);

        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile, organization: $secondOrganization);

        // Associated with the second organization only, so that is the context they get.
        $this->submitLogin(self::PARENT_EMAIL);

        $style = $this->renderedStyleBlock();

        self::assertStringContainsString(self::SECOND_COLOR, $style);
        self::assertStringNotContainsString(self::FIRST_COLOR, $style);
    }

    /**
     * G-26: a player who trains with two branded trainers wears whichever one they are currently
     * looking at. Switching context has to repaint the page, or "no combined view anywhere in the
     * platform" (FR-070) would be untrue of the chrome around the data.
     */
    public function testBrandingFollowsTheActiveContextForAMultiTrainerPlayer(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR);
        $secondOrganization = $this->createSecondOrganization();
        $this->createBranding($secondOrganization, self::SECOND_COLOR);

        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);
        $this->createAssociation($profile, organization: $secondOrganization);

        $this->submitLogin(self::PARENT_EMAIL);

        $this->selectContext($profile, $this->organization);
        self::assertStringContainsString(self::FIRST_COLOR, $this->renderedStyleBlock());

        $this->selectContext($profile, $secondOrganization);
        $switched = $this->renderedStyleBlock();

        self::assertStringContainsString(self::SECOND_COLOR, $switched);
        self::assertStringNotContainsString(self::FIRST_COLOR, $switched);
    }

    /**
     * FR-072 — "visible immediately to everyone in the organization". Branding is resolved per
     * request, so there is nothing to invalidate; this proves that rather than assuming it.
     */
    public function testAColourChangeIsVisibleToAMemberOnTheirNextPageLoad(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);

        $this->submitLogin($this->trainerEmail());
        $this->submitFormPayload('/trainer/branding', 'branding_form', [
            'primaryColorHex' => self::FIRST_COLOR,
        ]);
        self::assertResponseRedirects('/trainer/branding');

        $this->submitLogin(self::PARENT_EMAIL);

        self::assertStringContainsString(self::FIRST_COLOR, $this->renderedStyleBlock());
    }

    /**
     * FR-072's reset-to-default, and its documented asymmetry: the logo survives it.
     */
    public function testResettingTheColourRestoresTheDefaultAndKeepsTheLogo(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR, 'logos/2026/08/existing.png');

        $this->submitLogin($this->trainerEmail());

        $this->client->request('POST', '/trainer/branding/reset', [
            '_token' => $this->submitToken('/trainer/branding'),
        ]);

        self::assertResponseRedirects('/trainer/branding');

        $branding = $this->reloadBranding();
        self::assertNull($branding->getPrimaryColor());
        self::assertSame(OrganizationBranding::DEFAULT_PRIMARY_COLOR, $branding->resolvePrimaryColor()->value);
        self::assertSame('logos/2026/08/existing.png', $branding->getLogoPath());
    }

    public function testResetWithoutACsrfTokenIsRefused(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR);

        $this->submitLogin($this->trainerEmail());
        $this->client->request('POST', '/trainer/branding/reset', ['_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(self::FIRST_COLOR, $this->reloadBranding()->resolvePrimaryColor()->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedColors(): iterable
    {
        yield 'not hex' => ['chartreuse'];
        yield 'too short' => ['#12'];
        yield 'too long' => ['#1234567'];
        yield 'css injection attempt' => ['#fff;} body{display:none;}'];
    }

    /**
     * The colour lands inside a `<style>` block, so a value that could close the declaration is
     * the one input on this form that must never be stored.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedColors')]
    public function testAMalformedColourIsRejectedAndNothingIsStored(string $raw): void
    {
        $this->submitLogin($this->trainerEmail());

        $crawler = $this->submitFormPayload('/trainer/branding', 'branding_form', [
            'primaryColorHex' => $raw,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->storedColorFor($this->organization->getName()));
        self::assertStringNotContainsString('display:none', $crawler->filter('style')->text(''));
    }

    /**
     * NFR-065 — a colour no foreground could rescue would be refused, but at AA none exists, so
     * what this actually pins is that even the worst case is paired with a readable foreground
     * rather than rejected or left to the browser.
     */
    public function testALowContrastColourIsPairedWithAReadableForeground(): void
    {
        $this->createBranding($this->organization, '#757575');

        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);

        $this->submitLogin(self::PARENT_EMAIL);

        $style = $this->renderedStyleBlock();

        self::assertStringContainsString('--brand: #757575', $style);
        self::assertMatchesRegularExpression('/--brand-fg:\s*#(000000|ffffff)/', $style);
    }

    /**
     * The colour must reach the browser as a hash token, not as a CSS escape sequence.
     *
     * Guards a regression that was invisible in every other test: escaping the value with
     * Twig's `|e('css')` rewrites `#7c3aed` as `\23 7c3aed`, which is well-formed CSS but
     * tokenizes as an *identifier* rather than a hash. `color: var(--brand)` then fails to
     * parse and is dropped, so the page renders in black with no focus outline — the whole of
     * FR-071 and FR-072 silently inert, and the row in the database perfectly correct.
     *
     * Asserting on the absence of a backslash rather than on a rendered colour because that is
     * the difference the browser reacts to and the only part a template edit can reintroduce.
     */
    public function testTheColourIsEmittedAsAHashTokenRatherThanACssEscape(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR);

        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $this->createAssociation($profile);

        $this->submitLogin(self::PARENT_EMAIL);
        $style = $this->renderedStyleBlock();

        self::assertStringContainsString('--brand: ' . self::FIRST_COLOR . ';', $style);
        self::assertStringNotContainsString('\\', $style);
        self::assertMatchesRegularExpression('/--brand:\s*#[0-9a-f]{6};/', $style);
        self::assertMatchesRegularExpression('/--brand-fg:\s*#[0-9a-f]{6};/', $style);
    }

    /**
     * A viewer with no context at all still gets a page, in the platform's colours rather than
     * in nobody's.
     */
    public function testAViewerWithNoContextGetsThePlatformDefault(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR);

        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');

        $style = $this->renderedStyleBlock(request: false);

        self::assertStringContainsString(OrganizationBranding::DEFAULT_PRIMARY_COLOR, $style);
        self::assertStringNotContainsString(self::FIRST_COLOR, $style);
    }

    public function testAPlayerCannotOpenTheBrandingScreen(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/trainer/branding');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The organization is taken from the tenant context rather than the URL, so a second trainer
     * has no way to name somebody else's — they get their own screen, and the first trainer's
     * colour is untouched.
     */
    public function testASecondTrainerEditsTheirOwnOrganizationAndNotAnothers(): void
    {
        $this->createBranding($this->organization, self::FIRST_COLOR);
        $secondOrganization = $this->createSecondOrganization();

        $this->submitLogin('trainer-two@example.test');
        $this->submitFormPayload('/trainer/branding', 'branding_form', [
            'primaryColorHex' => self::SECOND_COLOR,
        ]);

        self::assertResponseRedirects('/trainer/branding');

        self::assertSame(self::FIRST_COLOR, $this->storedColorFor($this->organization->getName()));
        self::assertSame(self::SECOND_COLOR, $this->storedColorFor($secondOrganization->getName()));
    }

    public function testAnAnonymousVisitorCannotReachTheBrandingScreen(): void
    {
        $this->client->request('GET', '/trainer/branding');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * The `<style>` block the layout renders for the *current* viewer.
     *
     * Read from a page rather than from the service, because the thing under test is what a
     * browser receives — a correct `ResolvedBranding` that never reaches the layout would still
     * be a bug for every requirement on this page.
     */
    private function renderedStyleBlock(bool $request = true): string
    {
        if ($request) {
            $this->client->request('GET', '/account/profile');
        }

        return $this->client->getCrawler()->filter('style')->text('');
    }

    private function trainerEmail(): string
    {
        return $this->organization->getOwner()->getEmail();
    }

    private function reloadBranding(): OrganizationBranding
    {
        $branding = $this->freshEntityManager()
            ->getRepository(OrganizationBranding::class)
            ->findOneBy(['organization' => $this->organization->getId()]);

        self::assertInstanceOf(OrganizationBranding::class, $branding);

        return $branding;
    }

    private function storedColorFor(string $organizationName): ?string
    {
        $rows = $this->freshEntityManager()
            ->getRepository(OrganizationBranding::class)
            ->findAll();

        foreach ($rows as $row) {
            if ($row->getOrganization()->getName() === $organizationName) {
                return $row->getPrimaryColor()?->value;
            }
        }

        return null;
    }
}
