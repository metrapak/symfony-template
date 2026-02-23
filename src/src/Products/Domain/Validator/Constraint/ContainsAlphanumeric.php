<?php

namespace App\Products\Domain\Validator\Constraint;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ContainsAlphanumeric extends Constraint
{
    public string $message = 'The string "{{ string }}" contains an illegal character: it can only contain letters or numbers.';

    #[HasNamedArguments]
    public function __construct(
        public string $mode,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
