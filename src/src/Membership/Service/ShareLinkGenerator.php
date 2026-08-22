<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Membership\Entity\ShareLink;
use App\Membership\Enum\ShareLinkType;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\ValueObject\ShareLinkCode;
use Symfony\Component\Clock\ClockInterface;

/**
 * Mints ShareLinks (FR-040, FR-041).
 *
 * The two factories differ only in the terms they stamp on the row, and those terms are
 * BR-040 and BR-041 written once: a player link is unlimited and never expires, a coach
 * invitation is single-use and lapses after seven days.
 *
 * Neither method flushes. Creating a coach invitation is one step of a workflow that also
 * writes an email and must commit or roll back as a unit, and only the calling service knows
 * where that transaction begins.
 */
final readonly class ShareLinkGenerator
{
    public const COACH_LINK_LIFETIME = 'P7D';

    /**
     * A collision at 128 bits of entropy is not something that happens; the bound exists so a
     * misconfigured or exhausted random source fails loudly instead of looping forever.
     */
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private ShareLinkRepository $links,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A static link a trainer can hand to a whole squad (BR-040).
     *
     * Several may be active at once. The spec neither allows nor forbids it, and one link per
     * organization would mean a trainer who prints a code on a flyer can never issue a second
     * one for a summer camp without breaking the flyer. Each link is tracked separately, which
     * is also what Epic-06 needs to compare campaigns.
     */
    public function createPlayerLink(Organization $organization, User $creator): ShareLink
    {
        $link = new ShareLink(
            $this->uniqueCode(),
            ShareLinkType::Player,
            $organization,
            $creator,
            $this->clock->now(),
        );

        $this->links->add($link);

        return $link;
    }

    /**
     * A single-use invitation addressed to one coach, valid for seven days (BR-041).
     */
    public function createCoachLink(
        Organization $organization,
        User $creator,
        string $email,
        ?string $name = null,
        ?string $message = null,
    ): ShareLink {
        $now = $this->clock->now();

        $link = new ShareLink(
            $this->uniqueCode(),
            ShareLinkType::Coach,
            $organization,
            $creator,
            $now,
        );
        $link->addressTo($email, $name, $message)->expiresOn($this->expiryFrom($now));

        $this->links->add($link);

        return $link;
    }

    /**
     * Puts a new code and a new seven-day window on an existing invitation (FR-046).
     */
    public function reissue(ShareLink $link): ShareLink
    {
        $now = $this->clock->now();

        return $link->reissue($this->uniqueCode(), $this->expiryFrom($now), $now);
    }

    public function expiryFrom(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->add(new \DateInterval(self::COACH_LINK_LIFETIME));
    }

    /**
     * Checks for an existing row rather than catching the unique violation.
     *
     * A violation caught inside a PostgreSQL transaction aborts that transaction, so the
     * retry would have nothing left to write into — and coach invitations are created inside
     * one.
     */
    private function uniqueCode(): ShareLinkCode
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; ++$attempt) {
            $code = ShareLinkCode::generate();

            if (null === $this->links->findOneByCode($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate an unused share link code.');
    }
}
