<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Profile\Entity\OrganizationBranding;
use App\Profile\ValueObject\HexColor;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for FR-071 and FR-072 — a trainer's logo and brand colour.
 *
 * The colour is a text field carrying a hex string, and the native colour picker writes into
 * the same field. NFR-064 requires a text hex input beside the picker, and this is why: one
 * field, two ways to fill it, so a keyboard user and a mouse user submit the same value and
 * there is no second source of truth to reconcile.
 *
 * Contrast is validated here rather than in the service, because it is a property of the
 * submitted value and the person who can fix it is the one looking at the form. What is refused
 * is narrow — see `HexColor::hasAccessibleForeground()` — because a trainer's brand is not
 * wrong for being dark or light, only for being unreadable against both black and white.
 *
 * Mutable because Symfony Forms writes into it.
 */
final class BrandingInput
{
    /**
     * Validated by `ImageUploader` on content rather than by an `Assert\Image` on the
     * client-supplied extension — same reasoning as `UpdateProfileInput::$photo`. SVG is
     * refused there (G-24) even though FR-071 lists it.
     */
    public ?UploadedFile $logo = null;

    public bool $removeLogo = false;

    #[Assert\Length(max: 7)]
    public ?string $primaryColorHex = null;

    #[Assert\Callback]
    public function validateColor(ExecutionContextInterface $context): void
    {
        if (null === $this->primaryColorHex || '' === trim($this->primaryColorHex)) {
            return;
        }

        $color = HexColor::tryParse($this->primaryColorHex);

        if (null === $color) {
            $context->buildViolation('Enter a colour as a hex code, for example #006600.')
                ->atPath('primaryColorHex')
                ->addViolation();

            return;
        }

        // NFR-065. Only a mid grey can fail this, and a trainer who lands on one needs to be
        // told why rather than having their brand silently replaced with the default.
        if (!$color->hasAccessibleForeground()) {
            $context->buildViolation('That colour cannot be paired with readable text. Choose a slightly lighter or darker shade.')
                ->atPath('primaryColorHex')
                ->addViolation();
        }
    }

    public function resolvedColor(): ?HexColor
    {
        return HexColor::tryParse($this->primaryColorHex);
    }

    public static function fromBranding(?OrganizationBranding $branding): self
    {
        $input = new self();
        // The chosen colour, not the resolved one: an organization on the default must see the
        // field empty, so that saving without touching it does not silently pin the current
        // default as an explicit choice.
        $input->primaryColorHex = $branding?->getPrimaryColor()?->value;

        return $input;
    }
}
