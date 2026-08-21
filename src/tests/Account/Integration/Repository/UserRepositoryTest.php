<?php

declare(strict_types=1);

namespace App\Tests\Account\Integration\Repository;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // The repository is only ever autowired into services, so the test container has
        // inlined it. Going through the entity manager exercises the same instance.
        $users = $this->entityManager->getRepository(User::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    public function testLoadUserByIdentifierIsCaseInsensitive(): void
    {
        $this->persistUser('foo@bar.com');

        $found = $this->users->loadUserByIdentifier('Foo@Bar.COM');

        self::assertNotNull($found);
        self::assertSame('foo@bar.com', $found->getEmail());
    }

    public function testLoadUserByIdentifierTrimsSurroundingWhitespace(): void
    {
        $this->persistUser('trimmed@example.com');

        self::assertNotNull($this->users->loadUserByIdentifier('  trimmed@example.com  '));
    }

    public function testLoadUserByIdentifierReturnsNullForUnknownEmail(): void
    {
        self::assertNull($this->users->loadUserByIdentifier('nobody@example.com'));
    }

    public function testFindActiveByEmailIgnoresNonActiveAccounts(): void
    {
        $inactive = $this->persistUser('inactive@example.com');
        $inactive->setStatus(UserStatus::Inactive);
        $this->entityManager->flush();

        self::assertNull($this->users->findActiveByEmail('inactive@example.com'));
        self::assertNotNull($this->users->findOneByEmail('inactive@example.com'));
    }

    /**
     * The Validator cannot close the race between two concurrent registrations; only the
     * database constraint can. This asserts the constraint is actually there.
     */
    public function testDuplicateEmailIsRejectedByTheDatabase(): void
    {
        $this->persistUser('duplicate@example.com');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->persistUser('Duplicate@Example.com');
    }

    private function persistUser(string $email): User
    {
        $user = new User($email, UserRole::Player, new \DateTimeImmutable());
        $user->setPassword('irrelevant-for-this-test');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
