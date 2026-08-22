<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records that an account successfully authenticated (spec §8, "last login timestamp").
 *
 * `updatedAt` is deliberately left alone: it tracks changes to the account itself, and
 * bumping it on every sign-in would turn it into a second, less accurate copy of this
 * timestamp.
 */
final readonly class LoginRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function recordSuccessfulLogin(User $user): void
    {
        $user->setLastLoginAt($this->clock->now());

        $this->entityManager->flush();
    }
}
