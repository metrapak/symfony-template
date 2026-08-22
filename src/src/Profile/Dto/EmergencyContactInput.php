<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Profile\Entity\EmergencyContact;
use App\Profile\ValueObject\PhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for FR-061's parent emergency contact.
 *
 * The spec lists "emergency contact info (if children)" and defines no fields, cardinality or
 * requiredness — one of the task's open items. Three fields are the minimum that makes the
 * record usable at all: a trainer standing on a court needs to know *who* to call, *what* their
 * relationship to the child is, and the *number*. Anything less is a phone number with no
 * context; anything more is invention.
 *
 * All three are required, because a partially filled emergency contact is the kind of record
 * that looks present and fails when it is needed.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class EmergencyContactInput
{
    #[Assert\NotBlank(message: 'Enter the contact\'s name.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Say how this person is related to your child — for example "Grandmother".')]
    #[Assert\Length(max: 64)]
    public ?string $relationship = null;

    #[Assert\NotBlank(message: 'Enter a phone number.')]
    #[Assert\Length(max: 32)]
    public ?string $phone = null;

    #[Assert\Callback]
    public function validatePhone(ExecutionContextInterface $context): void
    {
        if (null === $this->phone || '' === trim($this->phone)) {
            return;
        }

        if (null === PhoneNumber::tryParse($this->phone)) {
            $context->buildViolation('Enter a phone number with 7 to 15 digits — for example +48 22 123 45 67.')
                ->atPath('phone')
                ->addViolation();
        }
    }

    public static function fromContact(EmergencyContact $contact): self
    {
        $input = new self();
        $input->name = $contact->getName();
        $input->relationship = $contact->getRelationship();
        $input->phone = $contact->getPhone();

        return $input;
    }
}
