<?php

declare(strict_types=1);

namespace App\Account\Dto;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for FR-023 — a Super Admin editing any account, including its role.
 *
 * Validated identically to a self-service edit, which is why the field constraints match
 * CreateTrainerInput rather than being relaxed for an administrator. The one asymmetry is
 * `role`: nothing else in the platform may change it.
 *
 * Phone is optional here and required at creation: an account that arrived through a route
 * that never asked for a phone number (a CLI-created Super Admin) must still be editable.
 */
final class EditUserInput
{
    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Enter an email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 32)]
    #[Assert\Regex(
        pattern: '/^[0-9+().\- ]+$/',
        message: 'Use digits, spaces and the characters + ( ) - . only.',
    )]
    public ?string $phone = null;

    #[Assert\NotNull]
    public ?UserRole $role = null;

    public static function fromUser(User $user): self
    {
        $input = new self();
        $input->name = $user->getName();
        $input->email = $user->getEmail();
        $input->phone = $user->getPhone();
        $input->role = $user->getRole();

        return $input;
    }
}
