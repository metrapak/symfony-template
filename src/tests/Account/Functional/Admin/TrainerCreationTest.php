<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Entity\Organization;
use App\Account\Enum\AuditAction;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Symfony\Component\Mime\Email;

/**
 * FR-021, FR-022, FR-033, BR-020 — the only way a trainer account comes into existence.
 */
final class TrainerCreationTest extends AdminWebTestCase
{
    private const TRAINER_EMAIL = 'tara@example.com';

    public function testCreatesTheAccountItsOrganizationAndItsInvitation(): void
    {
        $this->signInAsSuperAdmin();

        $this->client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Create trainer', [
            'create_trainer_form[businessName]' => 'Northside Academy',
            'create_trainer_form[name]' => 'Tara Trainer',
            'create_trainer_form[email]' => self::TRAINER_EMAIL,
            'create_trainer_form[phone]' => '+44 20 7946 0000',
        ]);

        self::assertResponseRedirects('/admin/users');

        $trainer = $this->reloadUser(self::TRAINER_EMAIL);
        self::assertSame('Tara Trainer', $trainer->getName());
        self::assertSame('+44 20 7946 0000', $trainer->getPhone());
        self::assertSame(UserRole::Trainer, $trainer->getRole());
        self::assertSame(UserStatus::Active, $trainer->getStatus());
        // FR-022: the temporary credential must be replaced at the first sign-in.
        self::assertTrue($trainer->mustChangePassword());

        $organization = $this->freshEntityManager()->getRepository(Organization::class)->findOneBy(['name' => 'Northside Academy']);
        self::assertNotNull($organization);
        self::assertSame($trainer->getId(), $organization->getOwner()->getId());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Your trainer account is ready', $email->getSubject());
        self::assertSame(self::TRAINER_EMAIL, $email->getTo()[0]->getAddress());
    }

    public function testTheNewTrainerAppearsInTheDirectoryImmediately(): void
    {
        $this->signInAsSuperAdmin();
        $this->createTrainer();

        $this->client->request('GET', '/admin/users');

        self::assertSelectorTextContains('table', 'Tara Trainer');
        self::assertSelectorTextContains('table', 'Active');
    }

    /**
     * FR-033: creation is auditable — who created the account, when, and what it was.
     */
    public function testCreationIsAudited(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $this->createTrainer();

        $entries = $this->auditEntries(AuditAction::TrainerCreated);

        self::assertCount(1, $entries);
        self::assertSame($admin->getId(), $entries[0]->getActor()->getId());
        self::assertNull($entries[0]->getImpersonator());
        self::assertSame('User', $entries[0]->getSubjectType());
        self::assertSame(self::TRAINER_EMAIL, $entries[0]->getPayload()['email']);
        self::assertSame('Northside Academy', $entries[0]->getPayload()['businessName']);
    }

    /**
     * FR-022 end to end: the emailed credential works, and it lands the trainer on the
     * forced change-password page rather than on their dashboard.
     */
    public function testTheTrainerCanSignInWithTheEmailedPasswordAndIsForcedToChangeIt(): void
    {
        $this->signInAsSuperAdmin();
        $this->createTrainer();

        $temporaryPassword = $this->temporaryPasswordFromInvitation();

        $this->clickSignOut('/admin/users');
        $this->submitLogin(self::TRAINER_EMAIL, $temporaryPassword);
        $this->client->followRedirect();

        // RequirePasswordChangeSubscriber sends every other route here until it is done.
        self::assertResponseRedirects('/account/password');
    }

    public function testDuplicateEmailIsReportedOnTheEmailFieldRatherThanFailing(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::TRAINER_EMAIL, UserRole::Player, name: 'Existing Person');

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create trainer', [
            'create_trainer_form[businessName]' => 'Northside Academy',
            'create_trainer_form[name]' => 'Tara Trainer',
            'create_trainer_form[email]' => self::TRAINER_EMAIL,
            'create_trainer_form[phone]' => '020 7946 0000',
        ]);

        // 422, not 200: AbstractController::render() reports an invalid submitted form as
        // Unprocessable Content.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'An account already exists for "tara@example.com".');
        self::assertEmailCount(0);
    }

    /**
     * The uniqueness check normalizes, so a differently-cased address is the same address.
     */
    public function testDuplicateEmailIsDetectedRegardlessOfCase(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::TRAINER_EMAIL, UserRole::Player, name: 'Existing Person');

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create trainer', [
            'create_trainer_form[businessName]' => 'Northside Academy',
            'create_trainer_form[name]' => 'Tara Trainer',
            'create_trainer_form[email]' => 'Tara@Example.COM',
            'create_trainer_form[phone]' => '020 7946 0000',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error-summary');
    }

    public function testRequiredFieldsAreEnforced(): void
    {
        $this->signInAsSuperAdmin();

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create trainer', [
            'create_trainer_form[businessName]' => '',
            'create_trainer_form[name]' => '',
            'create_trainer_form[email]' => '',
            'create_trainer_form[phone]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error-summary', 'Enter the business name.');
        self::assertSelectorTextContains('.form-error-summary', 'Enter the trainer\'s name.');
        self::assertSelectorTextContains('.form-error-summary', 'Enter an email address.');
        self::assertSelectorTextContains('.form-error-summary', 'Enter a phone number.');
        self::assertNull($this->freshEntityManager()->getRepository(\App\Account\Entity\User::class)->findOneBy(['name' => 'Tara Trainer']));
    }

    public function testMalformedEmailIsRejected(): void
    {
        $this->signInAsSuperAdmin();

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create trainer', [
            'create_trainer_form[businessName]' => 'Northside Academy',
            'create_trainer_form[name]' => 'Tara Trainer',
            'create_trainer_form[email]' => 'not-an-email',
            'create_trainer_form[phone]' => '020 7946 0000',
        ]);

        self::assertSelectorTextContains('.form-error-summary', 'Enter a valid email address.');
    }

    /**
     * BR-020: creating a trainer is reachable only as a Super Admin. There is no
     * self-registration, and the page that creates one is not anonymously reachable.
     *
     * (`/videos/register` exists and is public, but it renders a static marketing template
     * from the pre-existing Videos demo module and creates nothing.)
     */
    public function testTrainerCreationIsNotReachableAnonymously(): void
    {
        $this->client->request('GET', '/admin/users/new');
        self::assertResponseRedirects('http://localhost/login');

        $this->client->request('POST', '/admin/users/new');
        self::assertResponseRedirects('http://localhost/login');
    }

    private function createTrainer(): void
    {
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create trainer', [
            'create_trainer_form[businessName]' => 'Northside Academy',
            'create_trainer_form[name]' => 'Tara Trainer',
            'create_trainer_form[email]' => self::TRAINER_EMAIL,
            'create_trainer_form[phone]' => '020 7946 0000',
        ]);
    }

    /**
     * Reads the credential out of the message that was actually sent, rather than generating
     * one in the test — which is what proves the emailed password is the stored password.
     */
    private function temporaryPasswordFromInvitation(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        preg_match('/Temporary password: (\S+)/', (string) $email->getTextBody(), $matches);
        self::assertArrayHasKey(1, $matches, 'The invitation did not contain a temporary password.');

        return $matches[1];
    }
}
