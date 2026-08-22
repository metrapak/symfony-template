<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Entity\OrganizationBranding;
use App\Profile\Entity\PlayerProfile;
use App\Profile\ValueObject\HexColor;
use App\Tests\Membership\Functional\MembershipWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Shared setup for the profile, family, context and branding tests.
 *
 * Builds on the membership base because a training context *is* an association: FR-070 cannot be
 * tested without two tenants and a family that spans them, and that machinery already exists
 * there. What this adds is the branding row, a real PNG to upload, and the two helpers every
 * isolation test needs — `selectContext()` and `contextKeyFor()`.
 */
abstract class ProfileWebTestCase extends MembershipWebTestCase
{
    protected const PARENT_EMAIL = 'parent@example.test';
    protected const OTHER_PARENT_EMAIL = 'other-parent@example.test';
    protected const CHILD_EMAIL = 'child@children.invalid';

    protected function createParent(string $email = self::PARENT_EMAIL, string $name = 'Dana Parent'): User
    {
        return $this->createUser($email, UserRole::Player, name: $name);
    }

    /**
     * A second tenant, with its own trainer.
     *
     * Every isolation test needs one, because with a single organization a query that forgot its
     * tenant filter returns the right rows by accident and the test passes for the wrong reason.
     */
    protected function createSecondOrganization(string $name = 'Southside Skills'): Organization
    {
        return $this->createOtherOrganization($name, 'trainer-two@example.test', 'Nadia Trainer');
    }

    /**
     * A further tenant, for the case that needs a *third*: an organization the user trains with
     * nowhere, so a forged pair can name one that exists and is still none of theirs.
     */
    protected function createThirdOrganization(string $name = 'Eastside Athletics'): Organization
    {
        return $this->createOtherOrganization($name, 'trainer-three@example.test', 'Omar Trainer');
    }

    private function createOtherOrganization(string $name, string $trainerEmail, string $trainerName): Organization
    {
        $trainer = $this->createUser($trainerEmail, UserRole::Trainer, name: $trainerName);

        return $this->createOrganizationFor($trainer, $name);
    }

    protected function createBranding(Organization $organization, ?string $hex, ?string $logoPath = null): OrganizationBranding
    {
        $now = new \DateTimeImmutable();
        $branding = new OrganizationBranding($this->managed($organization, Organization::class), $now);

        if (null !== $hex) {
            $branding->setPrimaryColor(HexColor::tryParse($hex), $now);
        }

        if (null !== $logoPath) {
            $branding->setLogoPath($logoPath, $now);
        }

        return $this->save($branding);
    }

    /**
     * The value the context switcher submits for a (profile, organization) pair.
     *
     * Built here rather than in each test so a change to the wire format is one edit, and so a
     * test that forges a context is visibly forging *this* shape.
     */
    protected function contextKeyFor(PlayerProfile $profile, Organization $organization): string
    {
        return $profile->getId() . ':' . $organization->getId();
    }

    /**
     * Switches context the way a user does: a POST with the token the page carries.
     *
     * Going through the real endpoint rather than writing the session key directly is the point —
     * a test that set the session would prove nothing about the authorization on the way in.
     */
    protected function selectContext(PlayerProfile $profile, Organization $organization): void
    {
        $this->postContext($this->contextKeyFor($profile, $organization));
    }

    protected function postContext(string $contextKey): void
    {
        $this->client->request('POST', '/context/switch', [
            'context' => $contextKey,
            '_token' => $this->switcherToken(),
        ]);
    }

    /**
     * The switcher's CSRF token, read from a rendered page.
     *
     * Tokens are taken out of the HTML rather than asked of the token manager, and not by
     * preference: the manager needs a live request to reach the session, and once the client's
     * request has completed there is none. Reading the token a real browser would send is also
     * the more faithful test — it proves the page actually carries one.
     *
     * The switcher renders its form only when a viewer has more than one context, so a test that
     * needs this must have built at least two.
     */
    protected function switcherToken(): string
    {
        return $this->tokenFrom('/dashboard', '.context-switcher input[name="_token"]');
    }

    /**
     * The `submit` token from the first form on a page — what every confirmation POST carries.
     */
    protected function submitToken(string $path): string
    {
        return $this->tokenFrom($path, 'input[name="_token"]');
    }

    private function tokenFrom(string $path, string $selector): string
    {
        $crawler = $this->client->request('GET', $path);

        while ($this->client->getResponse()->isRedirect()) {
            $crawler = $this->client->followRedirect();
        }

        $field = $crawler->filter($selector);
        self::assertGreaterThan(
            0,
            $field->count(),
            \sprintf('Expected a CSRF token matching "%s" on %s.', $selector, $path),
        );

        return (string) $field->first()->attr('value');
    }

    /**
     * Posts a Symfony form's payload, carrying the CSRF token the rendered page supplied.
     *
     * Used where `submitForm()` cannot express the request: DomCrawler registers only the first
     * checkbox of an expanded multiple-choice group (they share one `name="…[]"`), so ticking the
     * second one is not reachable through it. This sends what a browser would send, with the real
     * token, so the CSRF and validation layers are still exercised.
     *
     * @param array<string, mixed> $values the form's fields, without `_token`
     *
     * @return Crawler the response to the POST, so a caller can read the errors it rendered
     */
    protected function submitFormPayload(string $path, string $formName, array $values): Crawler
    {
        $crawler = $this->client->request('GET', $path);

        $token = $crawler->filter(\sprintf('input[name="%s[_token]"]', $formName));
        self::assertGreaterThan(0, $token->count(), \sprintf('Expected a CSRF token for "%s" on %s.', $formName, $path));

        return $this->client->request('POST', $path, [
            $formName => $values + ['_token' => (string) $token->attr('value')],
        ]);
    }

    /**
     * A real, decodable PNG of the given pixel size.
     *
     * `ImageUploader` decides a file's type by parsing it (NFR-066), so an upload test cannot use
     * a text file with a `.png` name for the *accepted* cases — it would be rejected for the
     * right reason and prove nothing about the happy path. This produces bytes GD can read.
     */
    protected function createPngFile(string $filename = 'photo.png', int $size = 16): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('upload-', true) . '-' . $filename;

        $image = imagecreatetruecolor($size, $size);
        self::assertNotFalse($image, 'GD is required for the upload tests.');
        imagefill($image, 0, 0, imagecolorallocate($image, 32, 96, 160));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * A file whose *content* is not an image at all, whatever it is called.
     */
    protected function createFakeImageFile(string $filename = 'evil.png'): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('upload-', true) . '-' . $filename;
        file_put_contents($path, "<?php echo 'not an image'; ?>\n");

        return $path;
    }

    /**
     * @return list<PlayerProfile>
     */
    protected function childrenOf(User $parent): array
    {
        return $this->freshEntityManager()
            ->getRepository(PlayerProfile::class)
            ->findBy(['owner' => $parent->getId(), 'child' => true], ['id' => 'ASC']);
    }

    protected function reloadProfile(int $id): PlayerProfile
    {
        $profile = $this->freshEntityManager()->getRepository(PlayerProfile::class)->find($id);
        self::assertInstanceOf(PlayerProfile::class, $profile);

        return $profile;
    }
}
