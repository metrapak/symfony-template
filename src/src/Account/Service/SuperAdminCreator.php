<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the first Super Admin. There is no UI path to this account — every other user is
 * created by someone who is already signed in, so the first one has to come from the CLI.
 *
 * The account is created verified: whoever runs this command on the server does not need to
 * prove control of the mailbox.
 */
final readonly class SuperAdminCreator
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EmailAlreadyRegistered
     */
    public function create(string $email, string $name, string $plainPassword): User
    {
        if (null !== $this->users->findOneByEmail($email)) {
            throw EmailAlreadyRegistered::forEmail($email);
        }

        $now = $this->clock->now();

        $user = new User($email, $name, UserRole::SuperAdmin, $now);
        $user->setStatus(UserStatus::Active);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->markEmailVerified($now);

        try {
            return $this->entityManager->wrapInTransaction(function () use ($user): User {
                $this->users->add($user);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The lookup above cannot rule out a concurrent insert; the unique index can.
            throw EmailAlreadyRegistered::forEmail($email, $e);
        }
    }
}
