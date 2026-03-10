<?php

namespace App\Tests\Videos\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminControllerCategoriesTest extends WebTestCase
{
    private KernelBrowser $client;

    public function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

    }

    public function testTextOnPage()
    {
        $crawler = $this->client->request('GET', '/videos/admin/categories');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Categories list');
        $this->assertStringContainsString('Electronics', $this->client->getResponse()->getContent());

    }
}
