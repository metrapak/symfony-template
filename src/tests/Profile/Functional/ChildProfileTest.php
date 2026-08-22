<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Profile\Enum\PlayerGender;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FR-063 and FR-064 — creating and editing a child profile.
 *
 * The two cases worth the most attention are the ones a naive implementation gets backwards:
 *
 *  - the **duplicate-name warning is not a rejection** (FR-063). A parent with twins, or two
 *    children named after the same grandparent, must be able to proceed. What the platform owes
 *    them is the question, once.
 *  - the **age bound is enforced server-side** (BR-068). The form carries `min`/`max` attributes,
 *    which a crafted POST ignores, so the service re-checks — and this asserts the service, by
 *    submitting values the browser would have refused.
 */
final class ChildProfileTest extends ProfileWebTestCase
{
    public function testParentAddsAChildWithNoTrainers(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/children/new');
        $this->client->submitForm('Add child', [
            'create_child_form[name]' => 'Mateo Parent',
            'create_child_form[age]' => '11',
            'create_child_form[gender]' => PlayerGender::Male->value,
            'create_child_form[school]' => 'Mill Lane Primary',
        ]);

        self::assertResponseRedirects('/family/players');

        $children = $this->childrenOf($parent);
        self::assertCount(1, $children);
        self::assertSame('Mateo Parent', $children[0]->getDisplayName());
        self::assertSame('Mill Lane Primary', $children[0]->getSchool());
        self::assertSame(PlayerGender::Male, $children[0]->getGender());
        self::assertTrue($children[0]->isChild());

        // FR-063 asks for an age; the column is a birth date (Q-01.02). The age the parent typed
        // has to survive the round trip.
        self::assertSame(11, $children[0]->ageOn(new \DateTimeImmutable()));

        // FR-064's third case: a child with no association is a state the requirement allows.
        self::assertSame([], $this->associations());
    }

    public function testSingleTrainerPromptAssociatesTheChild(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $this->createAssociation($parentProfile);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/family/children/new');

        // FR-064: with exactly one trainer the question is asked by name.
        self::assertStringContainsString(
            'Will they also train with Northside Academy?',
            $crawler->filter('fieldset legend')->text(),
        );

        $this->client->submitForm('Add child', [
            'create_child_form[name]' => 'Mateo Parent',
            'create_child_form[age]' => '11',
            'create_child_form[gender]' => PlayerGender::Male->value,
            'create_child_form[organizationIds]' => [(string) $this->organization->getId()],
        ]);

        self::assertResponseRedirects('/family/players');

        // Two associations: the parent's own, and the child's new one.
        $associations = $this->associations();
        self::assertCount(2, $associations);
    }

    public function testMultipleTrainerChecklistAssociatesOnlyTheChosenOnes(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $secondOrganization = $this->createSecondOrganization();

        $this->createAssociation($parentProfile);
        $this->createAssociation($parentProfile, organization: $secondOrganization);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/family/children/new');

        self::assertStringContainsString(
            'Which of your trainers will they train with?',
            $crawler->filter('fieldset legend')->text(),
        );

        // Only the second is ticked, so only the second is written. Sent as a payload rather
        // than through `submitForm()`: DomCrawler registers only the first checkbox of an
        // expanded multiple-choice group, so the second is not tickable through it.
        $this->submitFormPayload('/family/children/new', 'create_child_form', [
            'name' => 'Maya Parent',
            'age' => '14',
            'gender' => PlayerGender::Female->value,
            'organizationIds' => [(string) $secondOrganization->getId()],
        ]);

        self::assertResponseRedirects('/family/players');

        $children = $this->childrenOf($parent);
        self::assertCount(1, $children);

        $childAssociations = array_values(array_filter(
            $this->associations(),
            fn (object $association): bool => $association->getPlayerProfile()->getId() === $children[0]->getId(),
        ));

        self::assertCount(1, $childAssociations);
        self::assertSame($secondOrganization->getId(), $childAssociations[0]->getOrganization()->getId());
    }

    /**
     * A tampered checklist value is refused outright rather than filtered out.
     *
     * Silently dropping it would look like a partial success — the parent would see the child
     * created and quietly not associated — and would hide the fact that the request was forged.
     */
    public function testSubmittingAnOrganizationTheParentDoesNotTrainWithIsRefused(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $this->createAssociation($parentProfile);

        $stranger = $this->createSecondOrganization();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->submitFormPayload('/family/children/new', 'create_child_form', [
            'name' => 'Mateo Parent',
            'age' => '11',
            'gender' => PlayerGender::Male->value,
            'organizationIds' => [(string) $stranger->getId()],
        ]);

        // The form's own Choice constraint catches it first and re-renders with an error, which is
        // the right answer for the ordinary case of a stale page. Either way, nothing is written.
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->childrenOf($parent));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidChildProvider(): iterable
    {
        yield 'no name' => ['', '11', 'Enter the child\'s name.'];
        yield 'age zero' => ['Mateo Parent', '0', 'A child profile is for ages 1 to 18.'];
        yield 'age nineteen' => ['Mateo Parent', '19', 'A child profile is for ages 1 to 18.'];
        yield 'no age' => ['Mateo Parent', '', 'Enter the child\'s age.'];
    }

    #[DataProvider('invalidChildProvider')]
    public function testChildCreationValidation(string $name, string $age, string $expectedMessage): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/children/new');
        $crawler = $this->client->submitForm('Add child', [
            'create_child_form[name]' => $name,
            'create_child_form[age]' => $age,
            'create_child_form[gender]' => PlayerGender::Male->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($expectedMessage, $crawler->filter('#error-summary')->text());
        self::assertSame([], $this->childrenOf($parent));
    }

    public function testDuplicateNameWarningIsShownAndIsNotBlocking(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $existing = $this->createChildProfile($parent, 'Mateo Parent');
        $existing->setBirthDate(new \DateTimeImmutable('-11 years'), new \DateTimeImmutable());
        $this->save($existing);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/children/new');

        // First submit: same name, same age. The warning comes back and nothing is written.
        $crawler = $this->client->submitForm('Add child', [
            'create_child_form[name]' => 'Mateo Parent',
            'create_child_form[age]' => '11',
            'create_child_form[gender]' => PlayerGender::Male->value,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Is this someone you have already added?', $crawler->filter('.warning')->text());
        self::assertCount(1, $this->childrenOf($parent));

        // Second submit, carrying the acknowledgement the warning page set. FR-063's "warning,
        // not a rejection": the parent knows their own family and gets the last word.
        $this->client->submitForm('Yes, add them anyway', [
            'create_child_form[name]' => 'Mateo Parent',
            'create_child_form[age]' => '11',
            'create_child_form[gender]' => PlayerGender::Male->value,
        ]);

        self::assertResponseRedirects('/family/players');
        self::assertCount(2, $this->childrenOf($parent));
    }

    public function testADifferentAgeDoesNotTriggerTheWarning(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $existing = $this->createChildProfile($parent, 'Mateo Parent');
        $existing->setBirthDate(new \DateTimeImmutable('-11 years'), new \DateTimeImmutable());
        $this->save($existing);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/family/children/new');

        // Same name, four years apart: siblings, not a duplicate. Matching more loosely on age
        // would fire on half the families on the platform and train parents to click through.
        $this->client->submitForm('Add child', [
            'create_child_form[name]' => 'Mateo Parent',
            'create_child_form[age]' => '7',
            'create_child_form[gender]' => PlayerGender::Male->value,
        ]);

        self::assertResponseRedirects('/family/players');
        self::assertCount(2, $this->childrenOf($parent));
    }

    public function testParentEditsAChildsProfile(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $child->setBirthDate(new \DateTimeImmutable('-11 years'), new \DateTimeImmutable());
        $this->save($child);

        $childId = (int) $child->getId();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/edit', $childId));
        $this->client->submitForm('Save', [
            'edit_child_form[name]' => 'Mateo Ruiz',
            'edit_child_form[birthDate]' => (new \DateTimeImmutable('-12 years'))->format('Y-m-d'),
            'edit_child_form[gender]' => PlayerGender::Male->value,
            'edit_child_form[school]' => 'Southside Academy',
            'edit_child_form[jerseyNumber]' => '07',
        ]);

        self::assertResponseRedirects('/family/players');

        $saved = $this->reloadProfile($childId);
        self::assertSame('Mateo Ruiz', $saved->getDisplayName());
        self::assertSame('Southside Academy', $saved->getSchool());
        // A string, not an int: "07" and "7" are different shirts.
        self::assertSame('07', $saved->getJerseyNumber());
        self::assertSame(12, $saved->ageOn(new \DateTimeImmutable()));
    }

    public function testEditRefusesABirthDateImplyingAnAgeOverEighteen(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $child->setBirthDate(new \DateTimeImmutable('-11 years'), new \DateTimeImmutable());
        $this->save($child);

        $childId = (int) $child->getId();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/edit', $childId));
        $crawler = $this->client->submitForm('Save', [
            'edit_child_form[name]' => 'Mateo Parent',
            'edit_child_form[birthDate]' => (new \DateTimeImmutable('-25 years'))->format('Y-m-d'),
            'edit_child_form[gender]' => PlayerGender::Male->value,
        ]);

        // BR-068, re-checked in the service against the *submitted* date rather than against the
        // stored one — a profile that simply aged past 18 is not what this refuses (G-22).
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A child profile must be between 1 and 18 years old', $crawler->text());
        self::assertSame(11, $this->reloadProfile($childId)->ageOn(new \DateTimeImmutable()));
    }

    public function testEditRefusesAFutureBirthDate(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $child->setBirthDate(new \DateTimeImmutable('-11 years'), new \DateTimeImmutable());
        $this->save($child);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/edit', $child->getId()));
        $crawler = $this->client->submitForm('Save', [
            'edit_child_form[name]' => 'Mateo Parent',
            'edit_child_form[birthDate]' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'edit_child_form[gender]' => PlayerGender::Male->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('cannot be in the future', $crawler->filter('#error-summary')->text());
    }

    /**
     * FR-065 / BR-060: a parent account is a player account, and the page says so even for an
     * account that has never had a profile of its own.
     */
    public function testFamilyPageCreatesTheParentsOwnProfileOnFirstVisit(): void
    {
        $parent = $this->createParent();

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/family/players');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Your training', $crawler->filter('h2')->first()->text());
        self::assertStringContainsString('Dana Parent', $crawler->filter('#self-heading')->ancestors()->first()->text());

        // Created on demand rather than left absent: FR-064's "will you train too?" needs
        // something to associate.
        $profiles = $this->profiles();
        self::assertCount(1, $profiles);
        self::assertFalse($profiles[0]->isChild());
    }
}
