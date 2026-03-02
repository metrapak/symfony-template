<?php

namespace App\Tests\Shared\Functional\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MainControllerTest extends WebTestCase
{
    public function testHomepage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        $cookies = $response->headers->getCookies();

        $hasVisitedCookie = false;
        foreach ($cookies as $cookie) {
            if ('visited_homepage' === $cookie->getName()) {
                $hasVisitedCookie = true;
                $this->assertEquals('visited_homepage', $cookie->getValue());
                break;
            }
        }
        $this->assertTrue($hasVisitedCookie, 'Cookie "visited_homepage" was not found in response.');
        $link = $crawler->selectLink('Homepage')->link();
        $client->click($link);
        $this->assertStringContainsString('Homepage', $client->getResponse()->getContent());
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
