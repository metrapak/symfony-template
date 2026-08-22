<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Enum\AuditAction;
use App\Account\Enum\ImpersonationEndReason;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Symfony\Component\DomCrawler\Crawler;

/**
 * FR-028…FR-032, BR-021 — viewing the platform as another user.
 */
final class ImpersonationTest extends AdminWebTestCase
{
    private const TRAINER_EMAIL = 'tara@example.com';

    public function testImpersonationSwitchesToTheTargetsOwnView(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');

        $this->startImpersonation('Impersonate Tara Trainer');

        // The role hub sends the switched session to the trainer dashboard, not the admin one.
        self::assertResponseIsSuccessful();
        self::assertSame('/trainer', $this->client->getRequest()->getPathInfo());
        self::assertSelectorTextContains('body', self::TRAINER_EMAIL);
    }

    /**
     * FR-029: on every page, sticky, unmistakable, and not carried by colour alone.
     */
    public function testTheBannerIsPresentOnEveryPageWhileImpersonating(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');

        $this->startImpersonation('Impersonate Tara Trainer');

        self::assertSelectorExists('.impersonation-banner[role="status"]');
        self::assertSelectorTextContains('.impersonation-banner', 'Viewing as Tara Trainer');
        self::assertSelectorTextContains('.impersonation-banner', 'Exit impersonation');

        // A second, unrelated page in the same session still carries it.
        $this->client->followRedirects(true);
        $this->client->request('GET', '/dashboard');
        $this->client->followRedirects(false);
        self::assertSelectorExists('.impersonation-banner');
    }

    public function testTheBannerIsAbsentWhenNobodyIsImpersonating(): void
    {
        $this->signInAsSuperAdmin();

        $this->client->request('GET', '/admin/users');

        self::assertSelectorNotExists('.impersonation-banner');
    }

    public function testExitingReturnsTheOperatorToTheirOwnView(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');

        $crawler = $this->startImpersonation('Impersonate Tara Trainer');

        $this->client->followRedirects(true);
        $this->client->click($crawler->filter('.impersonation-banner a')->link());
        $this->client->followRedirects(false);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.impersonation-banner');
        self::assertSame('/admin', $this->client->getRequest()->getPathInfo());
    }

    /**
     * FR-030 / BR-021: refused server-side, not merely hidden.
     */
    public function testTheImpersonateControlIsNotOfferedForASuperAdmin(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('second-admin@example.com', UserRole::SuperAdmin, name: 'Second Admin');

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertCount(0, $crawler->selectButton('Impersonate Second Admin'));
        self::assertCount(0, $crawler->selectButton('Impersonate Ada Admin'));
    }

    public function testImpersonatingASuperAdminIsRefusedByThePostRoute(): void
    {
        $this->signInAsSuperAdmin();
        $other = $this->createUser('second-admin@example.com', UserRole::SuperAdmin, name: 'Second Admin');

        $this->postImpersonate((int) $other->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->impersonationSessions());
    }

    /**
     * The rule has to hold for the firewall's own entry point too: `SwitchUserListener`
     * answers `?_switch_user=` on any URL, so a check that lived only in the controller would
     * be a check anyone could skip by editing the address bar.
     */
    public function testImpersonatingASuperAdminIsRefusedWhenTheFirewallParameterIsUsedDirectly(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('second-admin@example.com', UserRole::SuperAdmin, name: 'Second Admin');

        $this->client->request('GET', '/dashboard?_switch_user=second-admin@example.com');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->impersonationSessions());
    }

    public function testANonSuperAdminCannotImpersonateAnyone(): void
    {
        $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');
        $this->createUser('coach@example.com', UserRole::Coach, name: 'Casey Coach');

        $this->submitLogin(self::TRAINER_EMAIL);

        $this->client->request('GET', '/trainer?_switch_user=coach@example.com');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->impersonationSessions());
    }

    public function testADeletedAccountCannotBeImpersonated(): void
    {
        $this->signInAsSuperAdmin();
        $deleted = $this->createUser('deleted_9@example.com', UserRole::Player, UserStatus::Deleted, name: 'Deleted User');

        $this->postImpersonate((int) $deleted->getId());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * FR-032: who, whom, when, and for how long.
     */
    public function testTheSessionIsRecordedFromStartToFinish(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $trainer = $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');

        $crawler = $this->startImpersonation('Impersonate Tara Trainer');

        $sessions = $this->impersonationSessions();
        self::assertCount(1, $sessions);
        self::assertSame($admin->getId(), $sessions[0]->getAdmin()->getId());
        self::assertSame($trainer->getId(), $sessions[0]->getTargetUser()->getId());
        self::assertTrue($sessions[0]->isOpen());

        $this->client->followRedirects(true);
        $this->client->click($crawler->filter('.impersonation-banner a')->link());
        $this->client->followRedirects(false);

        $sessions = $this->impersonationSessions();
        self::assertCount(1, $sessions);
        self::assertFalse($sessions[0]->isOpen());
        self::assertSame(ImpersonationEndReason::Exit, $sessions[0]->getEndReason());
        self::assertNotNull($sessions[0]->getDurationSeconds());
        self::assertGreaterThanOrEqual(0, $sessions[0]->getDurationSeconds());
    }

    public function testStartAndEndAreBothAudited(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');

        $crawler = $this->startImpersonation('Impersonate Tara Trainer');

        $this->client->followRedirects(true);
        $this->client->click($crawler->filter('.impersonation-banner a')->link());
        $this->client->followRedirects(false);

        $started = $this->auditEntries(AuditAction::ImpersonationStarted);
        $ended = $this->auditEntries(AuditAction::ImpersonationEnded);

        self::assertCount(1, $started);
        self::assertCount(1, $ended);
        self::assertSame($admin->getId(), $started[0]->getActor()->getId());
        self::assertSame(self::TRAINER_EMAIL, $started[0]->getPayload()['targetEmail']);
        self::assertSame(ImpersonationEndReason::Exit->value, $ended[0]->getPayload()['endReason']);

        // Neither entry claims the admin was impersonating themselves.
        self::assertNull($started[0]->getImpersonator());
        self::assertNull($ended[0]->getImpersonator());
    }

    /**
     * FR-031 / G-14: expiry returns the operator to their own view rather than logging them
     * out. Driven by IMPERSONATION_TTL=0 so the very next request is already past the limit —
     * the parameter reads its environment variable at runtime, which is what makes this
     * testable without mocking a clock the firewall does not consult.
     */
    public function testAnExpiredSessionReturnsTheOperatorToTheAdminView(): void
    {
        $previous = $_SERVER['IMPERSONATION_TTL'] ?? null;
        $_SERVER['IMPERSONATION_TTL'] = '0';
        $_ENV['IMPERSONATION_TTL'] = '0';

        try {
            $this->signInAsSuperAdmin();
            $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');

            // The switching request itself is never expired: the firewall sets its redirect
            // and stops propagation before the expiry subscriber runs. The next request in the
            // chain is already past the (zero-second) limit, so following it through lands on
            // the admin dashboard rather than on the trainer's.
            $this->startImpersonation('Impersonate Tara Trainer');

            self::assertSame('/admin', $this->client->getRequest()->getPathInfo());
            self::assertSelectorNotExists('.impersonation-banner');
            self::assertSelectorTextContains('.flash-warning', 'Impersonation ended automatically');

            $sessions = $this->impersonationSessions();
            self::assertCount(1, $sessions);
            self::assertSame(ImpersonationEndReason::Expiry, $sessions[0]->getEndReason());

            // Still signed in as themselves: expiry hands back the borrowed identity, it does
            // not end the operator's own session (G-14).
            $this->client->request('GET', '/admin/users');
            self::assertResponseIsSuccessful();
        } finally {
            if (null === $previous) {
                unset($_SERVER['IMPERSONATION_TTL'], $_ENV['IMPERSONATION_TTL']);
            } else {
                $_SERVER['IMPERSONATION_TTL'] = $previous;
                $_ENV['IMPERSONATION_TTL'] = $previous;
            }
        }
    }

    public function testTheImpersonationHistoryReportListsRecordedSessions(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');

        $crawler = $this->startImpersonation('Impersonate Tara Trainer');

        $this->client->followRedirects(true);
        $this->client->click($crawler->filter('.impersonation-banner a')->link());
        $this->client->followRedirects(false);

        $this->client->request('GET', '/admin/audit/impersonations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Ada Admin');
        self::assertSelectorTextContains('table', 'Tara Trainer');
        self::assertSelectorTextContains('table', 'Exited');
    }

    /**
     * Starts an impersonation and returns the page it lands on.
     *
     * Four hops, which is why this is a helper: the POST redirects to a URL carrying
     * `_switch_user`; the firewall performs the switch and redirects to the same URL without
     * the parameter; `/dashboard` resolves the role; the role's landing page renders.
     */
    private function startImpersonation(string $accessibleName): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $button = $crawler->selectButton($accessibleName);

        self::assertGreaterThan(0, $button->count(), \sprintf('No "%s" button in the directory.', $accessibleName));

        $this->client->followRedirects(true);
        $landed = $this->client->submit($button->form());
        $this->client->followRedirects(false);

        return $landed;
    }

    private function postImpersonate(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', \sprintf('/admin/users/%d/impersonate', $userId), ['_token' => $token]);
    }
}
