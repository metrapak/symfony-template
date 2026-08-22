<?php

declare(strict_types=1);

namespace App\Profile\Dto;

use App\Profile\Entity\OrganizationBranding;
use App\Profile\ValueObject\HexColor;

/**
 * The branding a single page should render (FR-071, FR-072, G-26).
 *
 * Always answerable — there is no "unbranded" state a template has to handle, because an
 * organization with no row and no colour resolves to the platform default. A nullable branding
 * object would put a conditional in every layout that uses it, and one of them would eventually
 * be wrong.
 *
 * `foreground` travels with `primaryColor` rather than being derived in the template. NFR-065
 * requires the trainer's colour not to break text contrast, and the layout paints both as CSS
 * custom properties: if the template picked the text colour it would be re-deriving a WCAG
 * calculation in Twig, and a mistake there is unreadable text for everyone in that tenant.
 */
final readonly class ResolvedBranding
{
    public function __construct(
        public ?int $organizationId,
        public ?string $organizationName,
        public ?string $logoPath,
        public HexColor $primaryColor,
        public HexColor $foreground,
    ) {
    }

    /**
     * The platform's own look: what a Super Admin, a signed-out visitor, or a player who has
     * not selected a context sees.
     */
    public static function platformDefault(): self
    {
        $primary = OrganizationBranding::defaultPrimaryColor();

        return new self(
            organizationId: null,
            organizationName: null,
            logoPath: null,
            primaryColor: $primary,
            foreground: $primary->accessibleForeground(),
        );
    }

    public function hasLogo(): bool
    {
        return null !== $this->logoPath;
    }

    public function isCustomised(): bool
    {
        return null !== $this->organizationId;
    }
}
