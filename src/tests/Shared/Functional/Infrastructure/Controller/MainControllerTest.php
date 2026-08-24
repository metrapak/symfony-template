<?php

namespace App\Tests\Shared\Functional\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MainControllerTest extends WebTestCase
{
    /**
     * The front page is reachable without a session and offers the two things it exists for:
     * a way in, and the list of places a visitor may go.
     */
    public function testHomepageOffersSignInAndPublicSections(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // The card posts to the firewall's own check path — not to a homepage-specific action —
        // so a change to `check_path` cannot leave this form quietly posting into a 404.
        $form = $crawler->filter('form[action="/login"]')->form();
        $this->assertTrue($form->has('_username'));
        $this->assertTrue($form->has('_password'));
        $this->assertTrue($form->has('_csrf_token'));

        $this->assertSelectorExists('[data-home-panel]');
        $this->assertSelectorTextContains('[data-home-panel-label="Demo pages"]', 'Starships');
    }

    /**
     * FR-010: the page must not advertise a destination the visitor would be refused. The role
     * panels are the only place on a public page that lists them, so an anonymous request is the
     * cheapest check that the `is_granted` gates around them are actually doing something.
     */
    public function testHomepageHidesRoleSectionsFromAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-home-panel-label="Administration"]');
        $this->assertSelectorNotExists('[data-home-panel-label="Family"]');
        $this->assertSelectorNotExists('[data-home-panel-label="Your account"]');
    }

    /**
     * The sign-in card posts to the firewall, so a rejected attempt is redirected by Symfony's
     * failure handler rather than rendered by this controller. `_failure_path` is what brings it
     * back to the page the visitor actually used — and the handler ignores anything that is not a
     * path, falling back to `/login` without complaining, so the behaviour is asserted rather
     * than assumed.
     */
    public function testRejectedSignInComesBackToTheFrontPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $client->submit($crawler->filter('form[action="/login"]')->form([
            '_username' => 'parent@example.com',
            '_password' => 'not-the-password',
        ]));

        $this->assertResponseRedirects('/');

        $crawler = $client->followRedirect();
        $this->assertSelectorExists('.form-error-summary');
        // FR-001: the wording must not say whether the address exists.
        $this->assertSelectorTextContains('.form-error-summary', 'Invalid credentials');
    }

    public function testStarships(): void
    {
        $client = static::createClient();

        $client->request('GET', '/starships');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'My ship');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }
}
