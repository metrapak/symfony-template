<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Membership\Entity\ShareLink;
use App\Membership\Enum\ShareLinkState;

/**
 * What `ShareLinkResolver` found behind a code from a URL.
 *
 * The link is exposed only for the states that may see it. An `Unusable` resolution carries
 * no link at all, so a controller cannot accidentally render "invited by Northside Academy"
 * on a page that is supposed to be indistinguishable from a 404 (FR-049) — the type makes the
 * leak impossible rather than asking every template to remember.
 */
final readonly class ShareLinkResolution
{
    private function __construct(
        public ShareLinkState $state,
        public ?ShareLink $link,
    ) {
    }

    public static function valid(ShareLink $link): self
    {
        return new self(ShareLinkState::Valid, $link);
    }

    public static function expired(ShareLink $link): self
    {
        return new self(ShareLinkState::Expired, $link);
    }

    public static function unusable(): self
    {
        return new self(ShareLinkState::Unusable, null);
    }

    public function isValid(): bool
    {
        return ShareLinkState::Valid === $this->state;
    }

    /**
     * @throws \LogicException when the resolution is not usable
     */
    public function requireLink(): ShareLink
    {
        return $this->link ?? throw new \LogicException('This share link resolution carries no link.');
    }
}
