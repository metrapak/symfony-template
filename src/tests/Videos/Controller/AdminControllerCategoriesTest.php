<?php

namespace App\Tests\Videos\Controller;

use App\Videos\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminControllerCategoriesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    public function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        unset($this->client, $this->entityManager);
    }

    public function testTextOnPage()
    {
        $this->client->request('GET', '/videos/admin/categories');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Categories list');
        $this->assertStringContainsString('Electronics', $this->client->getResponse()->getContent());

    }

    public function testAddNewCategory()
    {

        $crawler = $this->client->request('GET', '/videos/admin/categories');

        $category_name = 'Test Category';
        $form = $crawler->selectButton('Add')->form(
            [
                'category[name]' => $category_name,
                'category[parent]' => 1,
            ],
        );

        $this->client->submit($form);

        $category = $this->entityManager->getRepository(Category::class)->findOneBy(['name' => $category_name]);
        $this->assertNotNull($category);
        $this->assertSame($category_name, $category->getName());
    }
}
