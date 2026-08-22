<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Entity\EmergencyContact;
use App\Profile\Entity\PlayerProfile;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FR-068 — every prohibition, attempted directly.
 *
 * The requirement's acceptance criterion is the whole point of this file: each prohibition
 * "returns 403 when attempted directly, not merely a hidden link". So nothing here clicks a
 * button; every test sends the request a hidden link would have sent.
 *
 * What makes these tests necessary rather than paranoid: a child account holds `ROLE_PLAYER`,
 * exactly like their parent. `access_control` on `^/family` therefore admits them, and every
 * route in the section would be open to them if the voters were not there. A test that only
 * checked the templates would pass with the security removed.
 */
final class ChildProhibitionsTest extends ProfileWebTestCase
{
    private User $parent;
    private PlayerProfile $child;
    private PlayerProfile $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parent = $this->createParent();
        $this->createSelfProfile($this->parent);

        $childAccount = $this->createUser(self::CHILD_EMAIL, UserRole::Player, name: 'Maya Parent');
        $this->child = $this->createChildProfile($this->parent, 'Maya Parent', $childAccount);
        $this->sibling = $this->createChildProfile($this->parent, 'Mateo Parent');

        $this->createAssociation($this->child);
        $this->createAssociation($this->sibling);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenGetProvider(): iterable
    {
        yield 'the family page' => ['GET', '/family/players'];
        yield 'adding a child' => ['GET', '/family/children/new'];
        yield 'adding an emergency contact' => ['GET', '/family/contacts/new'];
    }

    #[DataProvider('forbiddenGetProvider')]
    public function testChildCannotReachFamilyManagementPages(string $method, string $path): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(403);
    }

    public function testChildCannotEditTheirSiblingsProfile(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/edit', $this->sibling->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Not even their own, through this route.
     *
     * `ProfileVoter` grants a child `EDIT_OWN_BASICS` and withholds `EDIT`, which is what the
     * parent's form requires — G-25 leaves undefined which fields a child may change, and name
     * and birth date are the two a parent would be astonished to find editable. So a child edits
     * their photo through `/account/profile` and never touches this form.
     */
    public function testChildCannotUseTheParentsEditFormOnTheirOwnProfile(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/edit', $this->child->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testChildCannotAddATrainerForThemselves(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);

        // BR-065: children cannot self-associate with trainers. GET and POST alike.
        $this->client->request('GET', \sprintf('/family/children/%d/trainers', $this->child->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', \sprintf('/family/children/%d/trainers', $this->child->getId()), [
            'add_trainer_form' => ['shareLinkCode' => 'AAAA1111BBBB2222CCCC3333DD'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testChildCannotRemoveATrainerAssociation(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);

        $path = \sprintf(
            '/family/children/%d/trainers/%d/remove',
            $this->child->getId(),
            $this->organization->getId(),
        );

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        // Refused before the CSRF check is even reached, so a child who somehow held a token
        // still gets nowhere.
        $this->client->request('POST', $path, ['_token' => 'anything']);
        self::assertResponseStatusCodeSame(403);

        // And the association is untouched.
        self::assertCount(2, array_filter(
            $this->associations(),
            static fn (object $association): bool => $association->isActive(),
        ));
    }

    public function testChildCannotCreateOrRevokeALogin(): void
    {
        $this->submitLogin(self::CHILD_EMAIL);

        $this->client->request('GET', \sprintf('/family/children/%d/login', $this->sibling->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', \sprintf('/family/children/%d/login/revoke', $this->child->getId()), [
            '_token' => 'anything',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testChildCannotChangeTheFamilysEmergencyContacts(): void
    {
        // BR-064: the parent owns the family's contact information. A child changing the number a
        // trainer would ring in an emergency is a safety-relevant write, not a preference.
        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('POST', '/family/contacts/new', [
            'emergency_contact_form' => [
                'name' => 'Not A Grandparent',
                'relationship' => 'Stranger',
                'phone' => '+48221234567',
            ],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The other half of the rule: everything above is *allowed* to the parent.
     *
     * Without this, the tests above would pass just as well if the whole section were broken for
     * everybody — a 403 for a child proves nothing unless somebody gets a 200.
     */
    public function testParentReachesEverythingTheChildIsRefused(): void
    {
        $this->submitLogin(self::PARENT_EMAIL);

        foreach ([
            '/family/players',
            '/family/children/new',
            '/family/contacts/new',
            \sprintf('/family/children/%d/edit', $this->child->getId()),
            \sprintf('/family/children/%d/trainers', $this->child->getId()),
            \sprintf('/family/children/%d/login', $this->sibling->getId()),
        ] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful(\sprintf('Expected the parent to reach %s.', $path));
        }
    }

    public function testParentCannotTouchAnotherFamilysChild(): void
    {
        $stranger = $this->createParent(self::OTHER_PARENT_EMAIL, 'Sam Stranger');
        $strangersChild = $this->createChildProfile($stranger, 'Ines Stranger');

        $this->submitLogin(self::PARENT_EMAIL);

        // Holding `MANAGE_CHILDREN` says nothing about *which* family it applies to — that is
        // `ProfileVoter`, and this is the IDOR it exists to close.
        foreach ([
            \sprintf('/family/children/%d/edit', $strangersChild->getId()),
            \sprintf('/family/children/%d/trainers', $strangersChild->getId()),
            \sprintf('/family/children/%d/login', $strangersChild->getId()),
        ] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseStatusCodeSame(403, \sprintf('Expected 403 on %s.', $path));
        }
    }

    public function testParentCannotEditAnotherFamilysEmergencyContact(): void
    {
        $stranger = $this->createParent(self::OTHER_PARENT_EMAIL, 'Sam Stranger');

        // Submitted through the rendered form, so the form's own CSRF token travels with it. A
        // hand-built POST would come back 422 and the test would pass without proving anything.
        $this->submitLogin(self::OTHER_PARENT_EMAIL);
        $this->client->request('GET', '/family/contacts/new');
        $this->client->submitForm('Add contact', [
            'emergency_contact_form[name]' => 'Rosa Stranger',
            'emergency_contact_form[relationship]' => 'Grandmother',
            'emergency_contact_form[phone]' => '+48221234567',
        ]);
        self::assertResponseRedirects('/family/players');

        $contacts = $this->contactsOf($stranger);
        self::assertCount(1, $contacts);

        // The other parent's id, from an account that is not its owner. Loaded *by owner*, so
        // this is a 404 — the row is never fetched, let alone rejected after the fact.
        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/contacts/%d/edit', $contacts[0]->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<EmergencyContact>
     */
    private function contactsOf(User $parent): array
    {
        return $this->freshEntityManager()
            ->getRepository(EmergencyContact::class)
            ->findBy(['parent' => $parent->getId()], ['id' => 'ASC']);
    }
}
