<?php

namespace App\Tests\Videos\Controller;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
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

        // This admin UI is behind ROLE_SUPER_ADMIN, so the cases below sign in first. The
        // test that it is *not* reachable anonymously is testAnonymousAccessIsRefused().
        $this->client->loginUser($this->createSuperAdmin());
    }

    public function tearDown(): void
    {
        parent::tearDown();
        unset($this->client, $this->entityManager);
    }

    public function testAnonymousAccessIsRefused(): void
    {
        // Drop the session cookie, so the super admin signed in by setUp() is not carried
        // over. Only one client (and one kernel) may exist per test.
        $this->client->getCookieJar()->clear();

        foreach (['/videos/admin', '/videos/admin/categories'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseRedirects(null, null, \sprintf('Expected %s to require signing in.', $path));
            self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        }
    }

    public function testASignedInNonAdminIsRefused(): void
    {
        $trainer = new User('trainer@example.com', 'Trainer', UserRole::Trainer, new \DateTimeImmutable());
        $trainer->setPassword('irrelevant-for-this-test');
        $this->entityManager->persist($trainer);
        $this->entityManager->flush();

        $this->client->loginUser($trainer);
        $this->client->request('GET', '/videos/admin/categories');

        self::assertResponseStatusCodeSame(403);
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

    public function testEditCategory()
    {
        $new_category = new Category();
        $new_category->setName('Category to Edit');
        $this->entityManager->persist($new_category);
        $this->entityManager->flush();

        $id = $new_category->getId();

        $crawler = $this->client->request('GET', '/videos/admin/edit-category/' . $id);
        $this->assertResponseIsSuccessful();

        $category_name = 'Updated Category Name';
        $form = $crawler->selectButton('Save')->form([
            'category[name]' => $category_name,
            'category[parent]' => 1,
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects();
        $this->client->followRedirect();

        $this->entityManager->clear();

        $category = $this->entityManager->getRepository(Category::class)->find($id);

        $this->assertNotNull($category);
        $this->assertSame($category_name, $category->getName());
    }

    public function testDeleteCategory(): void
    {
        $category = new Category();
        $category->setName('Category to Delete');
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $id = $category->getId();

        $this->client->request('GET', '/videos/admin/delete-category/' . $id);
        $this->assertResponseRedirects();
        $this->client->followRedirect();

        $this->entityManager->clear();

        $deleted = $this->entityManager->getRepository(Category::class)->find($id);
        $this->assertNull($deleted);
    }

    private function createSuperAdmin(): User
    {
        $user = new User('videos-admin@example.com', 'Videos Admin', UserRole::SuperAdmin, new \DateTimeImmutable());
        $user->setPassword('irrelevant-for-this-test');
        $user->markEmailVerified(new \DateTimeImmutable());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
