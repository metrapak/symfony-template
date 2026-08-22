<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for FR-066's "Add Trainer" — either option (FR-066 A and B).
 *
 * The two options are one form with two ways to fill it in, because that is how the screen
 * works: a parent either pastes a code their trainer sent them or picks from the trainers they
 * already train with. Exactly one must be provided, which is a rule about the pair rather than
 * about either field, so it is a callback rather than two `NotBlank`s that would each fire on
 * the other option's submit.
 *
 * The ShareLink code's *format* is checked by `ShareLinkCode::tryParse()` inside the gateway,
 * not here. A well-formed code that does not exist and a malformed one get the same answer
 * (FR-049), and putting a format constraint on this field would tell a parent which of the two
 * they had — the distinction the redemption flow deliberately refuses to make.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class AddTrainerInput
{
    #[Assert\Length(max: 64)]
    public ?string $shareLinkCode = null;

    public ?int $organizationId = null;

    #[Assert\Callback]
    public function validateOneOptionChosen(ExecutionContextInterface $context): void
    {
        $hasCode = null !== $this->shareLinkCode && '' !== trim($this->shareLinkCode);
        $hasOrganization = null !== $this->organizationId;

        if (!$hasCode && !$hasOrganization) {
            $context->buildViolation('Paste a trainer link, or choose one of your trainers.')
                ->atPath('shareLinkCode')
                ->addViolation();

            return;
        }

        if ($hasCode && $hasOrganization) {
            $context->buildViolation('Choose one: paste a link, or pick a trainer you already train with.')
                ->atPath('shareLinkCode')
                ->addViolation();
        }
    }

    public function usesShareLink(): bool
    {
        return null !== $this->shareLinkCode && '' !== trim($this->shareLinkCode);
    }
}
