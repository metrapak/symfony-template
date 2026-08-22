<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Account\Entity\User;
use App\Profile\Repository\AdminPreferencesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Super Admin's notification settings (FR-061, "Super Admin — admin notification settings").
 *
 * The spec asks for the screen and names no settings beyond "email notifications, etc.", so
 * this holds the two events an administrator of *this* platform would actually want to hear
 * about — a new trainer appearing, and an account erasure — and nothing invented beyond them.
 *
 * **Nothing reads these yet, and that is stated rather than hidden.** No requirement in
 * Epic-01 sends mail to a Super Admin; Q-01.04 ("which automated emails are required?") is
 * still open. The settings are stored so the screen FR-061 requires is real and a preference
 * survives, and the senders that consult them arrive with the answer to Q-01.04. A toggle that
 * silently did nothing while looking live would be worse than one documented as pending.
 */
#[ORM\Entity(repositoryClass: AdminPreferencesRepository::class)]
#[ORM\Table(name: 'admin_preferences')]
#[ORM\UniqueConstraint(name: 'UNIQ_ADMIN_PREFERENCES_USER', fields: ['user'])]
class AdminPreferences
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyOnTrainerCreated = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyOnAccountErasure = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function notifiesOnTrainerCreated(): bool
    {
        return $this->notifyOnTrainerCreated;
    }

    public function notifiesOnAccountErasure(): bool
    {
        return $this->notifyOnAccountErasure;
    }

    public function update(bool $notifyOnTrainerCreated, bool $notifyOnAccountErasure, \DateTimeImmutable $now): static
    {
        $this->notifyOnTrainerCreated = $notifyOnTrainerCreated;
        $this->notifyOnAccountErasure = $notifyOnAccountErasure;
        $this->updatedAt = $now;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
