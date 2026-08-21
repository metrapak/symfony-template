<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Command;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateSuperAdminCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->tester = new CommandTester(
            (new Application(self::$kernel))->find('app:account:create-super-admin'),
        );
    }

    public function testItCreatesAUsableSuperAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'email' => 'Root@Example.COM',
            'password' => 'Password123',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $user = $this->findUser('root@example.com');

        self::assertSame(UserRole::SuperAdmin, $user->getRole());
        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_USER'], $user->getRoles());
        // Created verified: whoever runs this on the server need not prove mailbox control.
        self::assertTrue($user->isEmailVerified());
        self::assertFalse($user->mustChangePassword());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'Password123'));
    }

    public function testItPromptsForThePasswordWhenNotGivenAsAnArgument(): void
    {
        $this->tester->setInputs(['Password123']);

        $exitCode = $this->tester->execute(['email' => 'root@example.com']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($this->findUser('root@example.com'));
    }

    public function testItFailsOnADuplicateEmail(): void
    {
        $this->tester->execute(['email' => 'root@example.com', 'password' => 'Password123']);

        $exitCode = $this->tester->execute(['email' => 'Root@example.com', 'password' => 'Password123']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
    }

    public function testItRejectsAPasswordThatFailsTheRequirements(): void
    {
        $exitCode = $this->tester->execute(['email' => 'root@example.com', 'password' => 'short']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'root@example.com']));
    }

    public function testItRejectsAnInvalidEmailAddress(): void
    {
        $exitCode = $this->tester->execute(['email' => 'not-an-email', 'password' => 'Password123']);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    private function findUser(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
