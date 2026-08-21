<?php

namespace App\Shared\Domain\Validator\Constraint;

use Symfony\Component\Validator\Constraints as Assert;

#[\Attribute]
class PasswordRequirements extends Assert\Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(allowNull: false),
            new Assert\Length(min: 8, max: 255),
            // skipOnError: the validator calls api.pwnedpasswords.com and rethrows transport
            // failures. Without this, an outage at a third party would turn every password
            // reset, password change and super-admin creation into a 500 — locking users out
            // of account recovery to enforce a check that is a hardening measure, not a gate.
            new Assert\NotCompromisedPassword(skipOnError: true),
            new Assert\Type('string'),
            new Assert\Regex('/[A-Z]+/'),
        ];
    }
}
