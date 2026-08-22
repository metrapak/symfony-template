<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Membership\Dto\ShareLinkResolution;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\ValueObject\ShareLinkCode;
use Symfony\Component\Clock\ClockInterface;

/**
 * Turns the `{code}` in a URL into a decision (FR-049).
 *
 * The state matrix is deliberately lossy. Four situations — the code is malformed, no row has
 * it, the row was deactivated, the row has no uses left — all produce `Unusable`, because a
 * visitor who can distinguish them can learn which codes exist. `/join/{code}` is the only
 * unauthenticated endpoint in the application that creates accounts, so it gets the same
 * treatment as a login form: one answer for every kind of "no".
 *
 * `Expired` is the single exception, required by FR-046 so the holder of a lapsed coach
 * invitation can be told to ask for a new one. It discloses that a code was once real, to
 * somebody who already had it.
 *
 * Malformed codes are rejected before any query runs. That is not only a shortcut: it means
 * the database never sees a string that did not come out of `ShareLinkCode`.
 */
final readonly class ShareLinkResolver
{
    public function __construct(
        private ShareLinkRepository $links,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(string $rawCode): ShareLinkResolution
    {
        $code = ShareLinkCode::tryParse($rawCode);

        if (null === $code) {
            return ShareLinkResolution::unusable();
        }

        $link = $this->links->findOneByCode($code);

        if (null === $link || !$link->isActive() || $link->isConsumed()) {
            return ShareLinkResolution::unusable();
        }

        if ($link->isExpired($this->clock->now())) {
            return ShareLinkResolution::expired($link);
        }

        return ShareLinkResolution::valid($link);
    }
}
