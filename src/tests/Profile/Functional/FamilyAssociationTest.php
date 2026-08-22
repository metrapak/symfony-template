<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Membership\Enum\MembershipStatus;
use App\Membership\Repository\TrainerPlayerAssociationRepository;

/**
 * FR-066 — a parent adding and removing their children's trainers.
 *
 * The removal is the half that matters most, because "remove" here does not mean delete. BR-066
 * requires the association to be deactivated with its history intact, and the test that proves it
 * is the one that counts rows *after* the removal: a row that disappeared would satisfy a naive
 * reading of the requirement and quietly destroy a trainer's attendance figures.
 */
final class FamilyAssociationTest extends ProfileWebTestCase
{
    public function testParentAddsAKnownTrainerToAChild(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        // The parent trains here; the child does not yet. FR-066's "Option B".
        $this->createAssociation($parentProfile);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/trainers', $child->getId()));
        $this->client->submitForm('Add trainer', [
            'add_trainer_form[organizationId]' => (string) $this->organization->getId(),
        ]);

        self::assertResponseRedirects('/family/players');

        $childAssociations = array_values(array_filter(
            $this->associations(),
            fn (object $association): bool => $association->getPlayerProfile()->getId() === $child->getId(),
        ));

        self::assertCount(1, $childAssociations);
        self::assertTrue($childAssociations[0]->isActive());
    }

    public function testParentAddsATrainerByShareLink(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        // A tenant the parent does not yet train with, reachable only by the code (FR-066 "A").
        $secondOrganization = $this->createSecondOrganization();
        $link = $this->createPlayerLink(organization: $secondOrganization, creator: $secondOrganization->getOwner());

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/trainers', $child->getId()));
        $this->client->submitForm('Add trainer', [
            'add_trainer_form[shareLinkCode]' => (string) $link->getCode(),
        ]);

        self::assertResponseRedirects('/family/players');

        $associations = $this->associations();
        self::assertCount(1, $associations);
        self::assertSame($secondOrganization->getId(), $associations[0]->getOrganization()->getId());
        self::assertSame($child->getId(), $associations[0]->getPlayerProfile()->getId());
    }

    public function testUnknownShareLinkCodeIsRejectedWithoutRevealingWhetherItExists(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/trainers', $child->getId()));
        $crawler = $this->client->submitForm('Add trainer', [
            'add_trainer_form[shareLinkCode]' => 'ZZZZ9999YYYY8888XXXX7777WW',
        ]);

        self::assertResponseStatusCodeSame(422);
        // FR-049's rule, inherited: a well-formed code that does not exist and a withdrawn one
        // read the same, so this field cannot be used to enumerate links.
        self::assertStringContainsString(
            'That trainer link cannot be used.',
            $crawler->filter('#error-summary')->text(),
        );
        self::assertSame([], $this->associations());
    }

    public function testOrganizationTheParentDoesNotTrainWithIsRefused(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($parentProfile);

        $stranger = $this->createSecondOrganization();

        $this->submitLogin(self::PARENT_EMAIL);
        // A forged `organizationId`: the choice list is built from the parent's own associations,
        // and this is a tenant that is not in it. The IDOR this screen is one field away from.
        $this->submitFormPayload(
            \sprintf('/family/children/%d/trainers', $child->getId()),
            'add_trainer_form',
            ['organizationId' => (string) $stranger->getId()],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->associations());
    }

    public function testSubmittingNeitherOptionIsRejected(): void
    {
        $parent = $this->createParent();
        $parentProfile = $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($parentProfile);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/family/children/%d/trainers', $child->getId()));
        $crawler = $this->client->submitForm('Add trainer', []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Paste a trainer link, or choose one of your trainers.',
            $crawler->filter('#error-summary')->text(),
        );
    }

    public function testRemovalConfirmationStatesTheConsequenceBeforeTheButton(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($child);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', \sprintf(
            '/family/children/%d/trainers/%d/remove',
            $child->getId(),
            $this->organization->getId(),
        ));

        self::assertResponseIsSuccessful();

        // FR-066's wording, and the accessibility requirement that it is read before the control:
        // the warning is an alert and precedes the form in document order.
        $warning = $crawler->filter('[role="alert"]');
        self::assertSame(1, $warning->count());
        self::assertStringContainsString('cancel all upcoming RSVPs', $warning->text());
        self::assertStringContainsString('Northside Academy', $warning->text());
        self::assertStringContainsString('history', $warning->text());
    }

    public function testRemovalDeactivatesTheAssociationAndKeepsItsHistory(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $association = $this->createAssociation($child);
        $associationId = (int) $association->getId();

        $path = \sprintf(
            '/family/children/%d/trainers/%d/remove',
            $child->getId(),
            $this->organization->getId(),
        );

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('POST', $path, ['_token' => $this->submitToken($path)]);

        self::assertResponseRedirects('/family/players');

        // BR-066: the row survives, so "trained here from March to September" stays true and the
        // trainer's historical figures do not move.
        $rows = $this->associations();
        self::assertCount(1, $rows);
        self::assertSame($associationId, $rows[0]->getId());
        self::assertSame(MembershipStatus::Inactive, $rows[0]->getStatus());
        self::assertNotNull($rows[0]->getDeactivatedAt());
    }

    public function testRemovedChildNoLongerAppearsOnTheTrainersRoster(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($child);

        $path = \sprintf(
            '/family/children/%d/trainers/%d/remove',
            $child->getId(),
            $this->organization->getId(),
        );

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('POST', $path, ['_token' => $this->submitToken($path)]);
        self::assertResponseRedirects('/family/players');

        // FR-066's fourth promise, and it follows from the deactivation rather than being a
        // separate step: every roster query filters on active status.
        $roster = static::getContainer()
            ->get(TrainerPlayerAssociationRepository::class)
            ->findActiveFor((int) $this->organization->getId());

        self::assertSame([], $roster);
    }

    public function testRemovingATrainerTheChildDoesNotTrainWithIsNotFound(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($child);

        $stranger = $this->createSecondOrganization();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf(
            '/family/children/%d/trainers/%d/remove',
            $child->getId(),
            $stranger->getId(),
        ));

        // A 404 rather than a 403 or a redirect, so the response shape does not reveal which
        // associations exist.
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemovalWithoutACsrfTokenIsRefused(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($child);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('POST', \sprintf(
            '/family/children/%d/trainers/%d/remove',
            $child->getId(),
            $this->organization->getId(),
        ));

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->associations()[0]->isActive());
    }

    public function testFamilyPageListsEachChildsTrainersWithTheirDates(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $child->setBirthDate(new \DateTimeImmutable('-11 years'), new \DateTimeImmutable());
        $this->save($child);

        $secondOrganization = $this->createSecondOrganization();
        $this->createAssociation($child);
        $this->createAssociation($child, organization: $secondOrganization);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/family/players');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('tbody tr')->first()->text();
        self::assertStringContainsString('Mateo Parent', $row);
        self::assertStringContainsString('11', $row);
        // FR-066 asks for "associated trainers and association dates".
        self::assertStringContainsString('Northside Academy', $row);
        self::assertStringContainsString('Southside Skills', $row);
        self::assertStringContainsString('since', $row);
    }
}
