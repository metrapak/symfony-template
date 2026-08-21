<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The only place in the module that turns a plaintext password into a stored hash
 * (NFR-003). Setting a new password always clears the forced-change flag (FR-006) and
 * always invalidates the user's other sessions.
 */
final readonly class PasswordChanger
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Writes the new password to the managed entity. Callers inside a transaction get the
     * write flushed as part of that transaction; standalone callers get it committed here.
     *
     * Stamping the change is what ends the user's other sessions: User::isEqualTo() compares
     * the stamp, so any session still carrying the previous one is de-authenticated by
     * Symfony's ContextListener on its next request. That matters most for the reset flow —
     * a password reset that left a thief's session alive would not have recovered anything.
     */
    public function change(User $user, string $plainPassword): void
    {
        $now = $this->clock->now();

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setMustChangePassword(false);
        $user->recordPasswordChange($now);
        $user->setUpdatedAt($now);

        $this->entityManager->flush();
    }
}
