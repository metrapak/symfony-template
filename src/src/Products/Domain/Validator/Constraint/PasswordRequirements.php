<?php

namespace App\Products\Domain\Validator\Constraint;

use Symfony\Component\Validator\Constraints as Assert;

#[\Attribute]
class PasswordRequirements extends Assert\Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(allowNull: false),
            new Assert\Length(min: 8, max: 255),
            new Assert\NotCompromisedPassword(),
            new Assert\Type('string'),
            new Assert\Regex('/[A-Z]+/'),
        ];
    }
}
