<?php

declare(strict_types=1);

namespace App\Tests\Membership\Functional;

use App\Account\Enum\UserRole;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\MembershipStatus;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * FR-041, FR-045 and FR-046 — inviting a coach, accepting, and the single-trainer rule.
 */
final class CoachInvitationTest extends MembershipWebTestCase
{
    private const COACH_EMAIL = 'coach@example.com';

    public function testInvitingACoachCreatesASingleUseSevenDayLinkAndEmailsIt(): void
    {
        $this->submitLogin(self::TRAINER_EMAIL);

        $this->client->request('GET', '/trainer/coaches/invite');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Send invitation', [
            'coach_invite_form[email]' => self::COACH_EMAIL,
            'coach_invite_form[name]' => 'Casey Coach',
            'coach_invite_form[message]' => 'Sessions are on Tuesdays.',
        ]);

        self::assertResponseRedirects('/trainer/coaches');

        $link = $this->onlyCoachLink();
        self::assertSame(self::COACH_EMAIL, $link->getTargetEmail());
        self::assertSame('Casey Coach', $link->getTargetName());
        self::assertSame(1, $link->getMaxUses(), 'BR-041: a coach invitation is single-use.');
        self::assertNotNull($link->getExpiresAt());
        self::assertSame(7, $link->getCreatedAt()->diff($link->getExpiresAt())->days);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Tara Trainer has invited you to coach', $email->getSubject());
        self::assertSame(self::COACH_EMAIL, $email->getTo()[0]->getAddress());
        self::assertStringContainsString($link->getCode(), $email->getTextBody());
        self::assertStringContainsString('Sessions are on Tuesdays.', $email->getTextBody());
    }

    public function testThePendingInvitationIsListedUntilItIsAccepted(): void
    {
        $link = $this->createCoachLink(self::COACH_EMAIL);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/coaches');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('td.status-pending', 'Pending');

        $coach = $this->createUser(self::COACH_EMAIL, UserRole::Coach, name: 'Casey Coach');
        $this->createCoachAssignment($coach, $this->organization, $link);

        $this->client->request('GET', '/trainer/coaches');
        self::assertSelectorTextContains('td.status-accepted', 'Accepted');
    }

    public function testAnAnonymousVisitorSeesTheInvitationTermsBeforeRegistering(): void
    {
        $link = $this->createCoachLink(self::COACH_EMAIL, null, message: 'Sessions are on Tuesdays.');

        $crawler = $this->client->request('GET', '/join/' . $link->getCode());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Join Northside Academy');
        self::assertStringContainsString('invited to coach for Northside Academy', $crawler->filter('main')->text());
        self::assertStringContainsString('single-use', $crawler->filter('main')->text());
        self::assertStringContainsString('Sessions are on Tuesdays.', $crawler->filter('blockquote')->text());
        self::assertGreaterThan(0, $crawler->filter('a[href="/join/' . $link->getCode() . '/register"]')->count());
    }

    /**
     * FR-045 — a coach with no account yet: register, be attached, consume the invitation.
     */
    public function testACoachRegistersThroughTheInvitationAndIsAttached(): void
    {
        $link = $this->createCoachLink(self::COACH_EMAIL);

        $this->client->request('GET', '/join/' . $link->getCode() . '/register');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Create account', [
            'coach_registration_form[name]' => 'Casey Coach',
            'coach_registration_form[plainPassword][first]' => 'Password123',
            'coach_registration_form[plainPassword][second]' => 'Password123',
        ]);

        self::assertResponseRedirects('/login');

        $coach = $this->reloadUser(self::COACH_EMAIL);
        self::assertSame(UserRole::Coach, $coach->getRole());

        $assignments = $this->coachAssignments();
        self::assertCount(1, $assignments);
        self::assertSame($this->organization->getId(), $assignments[0]->getOrganization()->getId());
        self::assertSame(MembershipStatus::Active, $assignments[0]->getStatus());

        // Single-use: the link is spent, and spent links are indistinguishable from unknown
        // ones (FR-049).
        self::assertSame(1, $this->reloadLink((int) $link->getId())->getUseCount());
        $this->client->request('GET', '/join/' . $link->getCode());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnExistingCoachAcceptsTheInvitationBySigningIn(): void
    {
        $this->createUser(self::COACH_EMAIL, UserRole::Coach, name: 'Casey Coach');
        $link = $this->createCoachLink(self::COACH_EMAIL);

        $this->submitLogin(self::COACH_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Join Northside Academy');

        self::assertResponseRedirects('/coach');
        self::assertCount(1, $this->coachAssignments());
    }

    /**
     * FR-045 / BR-044. Refused at the invitation, before a coach wastes time registering.
     */
    public function testACoachActiveUnderAnotherTrainerCannotBeInvited(): void
    {
        $coach = $this->createUser(self::COACH_EMAIL, UserRole::Coach, name: 'Casey Coach');
        $otherTrainer = $this->createUser('other-trainer@example.com', UserRole::Trainer, name: 'Otto Trainer');
        $this->createCoachAssignment($coach, $this->createOrganizationFor($otherTrainer, 'Southside Sports'));

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/coaches/invite');
        $crawler = $this->client->submitForm('Send invitation', [
            'coach_invite_form[email]' => self::COACH_EMAIL,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already an active coach for another trainer', $crawler->filter('.form-error-summary')->text());
        // The message must not name the other organization: that is another tenant's roster.
        self::assertStringNotContainsString('Southside Sports', $crawler->filter('.form-error-summary')->text());
        self::assertCount(0, $this->freshEntityManager()->getRepository(ShareLink::class)->findAll());
    }

    /**
     * And again at acceptance, which is the moment the rule is actually about — the coach may
     * have been hired elsewhere between the invitation and the click.
     */
    public function testACoachActiveElsewhereCannotAcceptAnInvitationTheyAlreadyHold(): void
    {
        $coach = $this->createUser(self::COACH_EMAIL, UserRole::Coach, name: 'Casey Coach');
        $link = $this->createCoachLink(self::COACH_EMAIL);

        $otherTrainer = $this->createUser('other-trainer@example.com', UserRole::Trainer, name: 'Otto Trainer');
        $this->createCoachAssignment($coach, $this->createOrganizationFor($otherTrainer, 'Southside Sports'));

        $this->submitLogin(self::COACH_EMAIL);
        $this->client->request('GET', '/join/' . $link->getCode());
        $this->client->submitForm('Join Northside Academy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'This link is not for this account');
        self::assertCount(1, $this->coachAssignments());
        self::assertSame(0, $this->reloadLink((int) $link->getId())->getUseCount(), 'A refused acceptance does not spend the invitation.');
    }

    /**
     * FR-046 — an expired invitation says so, and the trainer can issue a fresh one.
     */
    public function testAnExpiredInvitationExplainsItselfAndCanBeResent(): void
    {
        $link = $this->createCoachLink(self::COACH_EMAIL, new \DateTimeImmutable('-1 hour'));
        $originalCode = $link->getCode();

        $this->client->request('GET', '/join/' . $originalCode);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorTextContains('h1', 'This invitation has expired');

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/coaches');
        self::assertSelectorTextContains('td.status-expired', 'Expired');

        $this->client->submitForm('Resend');
        self::assertResponseRedirects('/trainer/coaches');

        $reissued = $this->reloadLink((int) $link->getId());
        self::assertNotSame($originalCode, $reissued->getCode(), 'A resend issues a fresh code.');
        self::assertTrue($reissued->isActive());
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $reissued->getExpiresAt());

        // The old URL stops working the moment the new one is issued.
        $this->client->request('GET', '/join/' . $originalCode);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', '/join/' . $reissued->getCode());
        self::assertResponseIsSuccessful();
    }

    /**
     * Multi-tenancy at the object level (NFR-X01): the role gate on `/trainer` says nothing
     * about *whose* invitation an id names.
     */
    public function testATrainerCannotResendAnotherTrainersInvitation(): void
    {
        $otherTrainer = $this->createUser('other-trainer@example.com', UserRole::Trainer, name: 'Otto Trainer');
        $otherOrganization = $this->createOrganizationFor($otherTrainer, 'Southside Sports');
        $foreignLink = $this->createCoachLink(self::COACH_EMAIL, null, $otherOrganization, $otherTrainer);

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('POST', '/trainer/coaches/invite/' . $foreignLink->getId() . '/resend', [
            '_token' => $this->csrfToken(),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($foreignLink->getCode(), $this->reloadLink((int) $foreignLink->getId())->getCode());
    }

    private function onlyCoachLink(): ShareLink
    {
        $links = $this->freshEntityManager()->getRepository(ShareLink::class)->findAll();
        self::assertCount(1, $links);

        return $links[0];
    }

    /**
     * A hand-built POST still has to carry the stateless `submit` token the templates emit.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/trainer/coaches');
        $token = $crawler->filter('input[name="_token"]')->first();

        return $token->count() > 0 ? (string) $token->attr('value') : '';
    }
}
