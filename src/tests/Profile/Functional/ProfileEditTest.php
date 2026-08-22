<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Account\Enum\UserRole;
use App\Profile\Entity\CoachProfile;
use App\Profile\Entity\TrainerProfile;

/**
 * FR-060, FR-061 and BR-067 — self-service profile editing.
 *
 * Two claims are tested here that a screenshot could not settle:
 *
 *  - **Each role sees only its own fields**, because the form does not build the others. So a
 *    trainer's POST carrying `bio` writes nothing — asserted by sending exactly that.
 *  - **The read-only fields reject modification even when POSTed directly** (BR-067). They are
 *    absent from the form and from the DTO, so a submit naming one is refused whole rather than
 *    quietly stripped — the test sends `email` and `role` and then reads the row back unchanged.
 */
final class ProfileEditTest extends ProfileWebTestCase
{
    public function testPlayerEditsTheirOwnProfile(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $profileId = (int) $profile->getId();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Ruiz',
            'update_profile_form[phone]' => '+48 22 123 45 67',
            'update_profile_form[school]' => 'Mill Lane Primary',
            'update_profile_form[jerseyNumber]' => '9',
        ]);

        self::assertResponseRedirects('/account/profile');

        $user = $this->reloadUser(self::PARENT_EMAIL);
        self::assertSame('Dana Ruiz', $user->getName());
        // Normalized on the way in: the directory holds one spelling of a number.
        self::assertSame('+48221234567', $user->getPhone());

        // A player's school and jersey live on the *profile*, not the account row.
        $saved = $this->reloadProfile($profileId);
        self::assertSame('Mill Lane Primary', $saved->getSchool());
        self::assertSame('9', $saved->getJerseyNumber());
    }

    public function testReadOnlyFieldsAreRejectedEvenWhenPostedDirectly(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $profile->setSkillLevel('advanced', new \DateTimeImmutable());
        $this->save($profile);

        $profileId = (int) $profile->getId();

        $this->submitLogin(self::PARENT_EMAIL);

        // BR-067: email, role and skill level are never self-editable. They are absent from the
        // form and from the DTO, so there is nothing for them to bind to.
        $crawler = $this->submitFormPayload('/account/profile', 'update_profile_form', [
            'name' => 'Dana Ruiz',
            'email' => 'promoted@example.test',
            'role' => UserRole::SuperAdmin->value,
            'skillLevel' => 'elite',
        ]);

        // The whole submit is refused rather than the extra fields being quietly dropped, because
        // `allow_extra_fields` is left at its default. That is the stronger outcome: a request
        // trying to change a read-only field fails visibly instead of half-succeeding.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('should not contain extra fields', $crawler->filter('#error-summary')->text());

        $user = $this->reloadUser(self::PARENT_EMAIL);
        self::assertSame(self::PARENT_EMAIL, $user->getEmail());
        self::assertSame(UserRole::Player, $user->getRole());
        // Not even the name it *was* allowed to change, since the submit was rejected whole.
        self::assertSame('Dana Parent', $user->getName());
        self::assertSame('advanced', $this->reloadProfile($profileId)->getSkillLevel());
    }

    public function testReadOnlyFieldsAreRenderedAsTextRatherThanInputs(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/account/profile');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::PARENT_EMAIL, $crawler->filter('dl')->text());

        // Not a disabled input: a disabled field is still in the DOM and still nameable by a
        // crafted request. These do not exist as fields at all.
        self::assertSame(0, $crawler->filter('input[name="update_profile_form[email]"]')->count());
        self::assertSame(0, $crawler->filter('input[name="update_profile_form[role]"]')->count());
    }

    public function testPlayerFormHasNoCoachOrTrainerFields(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $crawler = $this->client->request('GET', '/account/profile');

        // FR-061: "each role sees only its own fields", enforced by which fields exist.
        foreach (['bio', 'credentials', 'businessName', 'website', 'notifyOnTrainerCreated'] as $foreign) {
            self::assertSame(
                0,
                $crawler->filter(\sprintf('[name="update_profile_form[%s]"]', $foreign))->count(),
                \sprintf('A player\'s form must not build "%s".', $foreign),
            );
        }
    }

    public function testCoachEditsTheirBioAndPublicVisibility(): void
    {
        $coach = $this->createUser('coach@example.test', UserRole::Coach, name: 'Priya Coach');
        $this->createCoachAssignment($coach, $this->organization);

        $this->submitLogin('coach@example.test');
        $this->client->request('GET', '/account/profile');
        $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Priya Coach',
            'update_profile_form[bio]' => 'Fifteen years coaching junior squads.',
            'update_profile_form[credentials]' => 'UEFA B Licence',
            'update_profile_form[publicProfile]' => '1',
        ]);

        self::assertResponseRedirects('/account/profile');

        $profile = $this->freshEntityManager()
            ->getRepository(CoachProfile::class)
            ->findOneBy(['user' => $coach->getId(), 'organization' => $this->organization->getId()]);

        self::assertInstanceOf(CoachProfile::class, $profile);
        self::assertSame('Fifteen years coaching junior squads.', $profile->getBio());
        self::assertSame('UEFA B Licence', $profile->getCredentials());
        self::assertTrue($profile->isPublic());
    }

    public function testTrainerEditsTheirBusinessDetails(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/account/profile');
        $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Tara Trainer',
            'update_profile_form[businessName]' => 'Northside Academy Ltd',
            'update_profile_form[website]' => 'https://northside.example.com',
            'update_profile_form[description]' => 'Squad sessions for ages 8 to 16.',
        ]);

        self::assertResponseRedirects('/account/profile');

        $profile = $this->freshEntityManager()
            ->getRepository(TrainerProfile::class)
            ->findOneBy(['organization' => $this->organization->getId()]);

        self::assertInstanceOf(TrainerProfile::class, $profile);
        self::assertSame('Northside Academy Ltd', $profile->getBusinessName());
        self::assertSame('https://northside.example.com', $profile->getWebsite());

        // Created on first save rather than at registration: a trainer who never opens this screen
        // has no row, and "no row" and "cleared every field" then mean the same thing.
        self::assertSame([], $this->freshEntityManager()->getRepository(CoachProfile::class)->findAll());
    }

    /**
     * A trainer's POST carrying a coach's field writes nothing anywhere.
     *
     * Sent deliberately: hiding a field in Twig would leave it bindable, and the difference only
     * shows on a crafted request. `bio` is not a child of a trainer's form, so the form component
     * refuses the whole submit before any service is reached.
     */
    public function testTrainerCannotWriteACoachField(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);
        $crawler = $this->submitFormPayload('/account/profile', 'update_profile_form', [
            'name' => 'Tara Trainer',
            'businessName' => 'Northside Academy Ltd',
            'bio' => 'This must not be written anywhere.',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('should not contain extra fields', $crawler->filter('#error-summary')->text());

        self::assertSame([], $this->freshEntityManager()->getRepository(TrainerProfile::class)->findAll());
        self::assertSame([], $this->freshEntityManager()->getRepository(CoachProfile::class)->findAll());
    }

    public function testInvalidPhoneNumberIsRejected(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $crawler = $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            'update_profile_form[phone]' => '12345',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('7 to 15 digits', $crawler->filter('#error-summary')->text());
    }

    public function testInvalidJerseyNumberIsRejected(): void
    {
        $parent = $this->createParent();
        $this->createSelfProfile($parent);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $crawler = $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            'update_profile_form[jerseyNumber]' => 'abcd',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('up to three digits', $crawler->filter('#error-summary')->text());
    }

    public function testAnonymousVisitorCannotReachTheProfileForm(): void
    {
        $this->client->request('GET', '/account/profile');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * A child login edits their own account through this form, and only this one.
     *
     * FR-067 allows a child to "update basic profile info (photo, preferences)", and the parent's
     * child-edit form is closed to them (see `ChildProhibitionsTest`). This is the page that is
     * open, which is what keeps that prohibition from being a dead end.
     */
    public function testChildLoginCanReachTheirOwnProfileForm(): void
    {
        $parent = $this->createParent();
        $childAccount = $this->createUser(self::CHILD_EMAIL, UserRole::Player, name: 'Maya Parent');
        $this->createChildProfile($parent, 'Maya Parent', $childAccount);

        $this->submitLogin(self::CHILD_EMAIL);
        $this->client->request('GET', '/account/profile');

        self::assertResponseIsSuccessful();
    }
}
