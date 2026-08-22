<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
// The directory's default view filters on both columns (FR-020, NFR-020). Declared on the
// entity as well as created in the migration, or doctrine:schema:update would propose
// dropping it on every future diff.
#[ORM\Index(name: 'IDX_USER_ROLE_STATUS', columns: ['role', 'status'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /**
     * The human label for this account: a trainer's own name, a coach's name, a player or
     * parent's name. Required for every user (spec §9, "Required fields enforced (name,
     * email for all users)") and set to "Deleted User" by anonymization (FR-025).
     */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $mustChangePassword = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * When the stored password was last replaced. Compared in isEqualTo() so that changing
     * a password de-authenticates every session except the one that changed it.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $name, UserRole $role, \DateTimeImmutable $now)
    {
        $this->setEmail($email);
        $this->name = $name;
        $this->role = $role;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Emails are normalized on write so the unique index and the user provider
     * agree on one canonical form (BR-001).
     */
    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * The single label every template asks for when it needs to show who a user is.
     *
     * Routing all of them through one accessor is what makes FR-026 hold: anonymization
     * writes "Deleted User" into `name`, and every roster, payment row and analytics table
     * that renders a user picks that up without each of them remembering to check status.
     */
    public function getDisplayName(): string
    {
        return $this->name;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = null === $phone || '' === trim($phone) ? null : trim($phone);

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [$this->role->value, 'ROLE_USER'];
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function markEmailVerified(\DateTimeImmutable $at): static
    {
        $this->emailVerifiedAt = $at;

        return $this;
    }

    /**
     * Clears the verification stamp. Used by anonymization (FR-025): the address that was
     * verified no longer exists on this row, so the claim that it was verified is false.
     */
    public function markEmailUnverified(): static
    {
        $this->emailVerifiedAt = null;

        return $this;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function setMustChangePassword(bool $mustChangePassword): static
    {
        $this->mustChangePassword = $mustChangePassword;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getPasswordChangedAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    /**
     * Stamps a password replacement, truncated to whole seconds.
     *
     * The truncation is not cosmetic and the only writer of this field lives here so it
     * cannot be bypassed: the column is TIMESTAMP(0), so a value carrying microseconds
     * would come back from the database as a different instant than the one held in the
     * session, isEqualTo() would report inequality on the very next request, and the user
     * who just changed their password would be signed out along with everyone else.
     */
    public function recordPasswordChange(\DateTimeImmutable $at): static
    {
        $this->passwordChangedAt = $at->setTime(
            (int) $at->format('H'),
            (int) $at->format('i'),
            (int) $at->format('s'),
        );

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Symfony's ContextListener refreshes the session user on every request but does
     * not re-run the user checker. Reporting inequality when status or role changed
     * forces de-authentication, so deactivating or demoting a user takes effect on
     * their existing session instead of at session expiry.
     *
     * The password-change stamp is compared for the same reason: the usual reason to
     * change a password is that somebody else has the old one, so every other session
     * must stop working. The session that performed the change keeps its access, because
     * ContextListener re-serializes its own token — carrying the new stamp — into the
     * session on the way out of that request.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->id === $user->id
            && $this->status === $user->status
            && $this->role === $user->role
            && $this->email === $user->email
            && $this->passwordChangedAt?->getTimestamp() === $user->passwordChangedAt?->getTimestamp();
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }
}
