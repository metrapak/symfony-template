<?php

declare(strict_types=1);

namespace App\Membership\Dto;

use App\Membership\Entity\ShareLink;

/**
 * The result of issuing or re-issuing a coach invitation (FR-041, FR-046).
 *
 * `invitationSent` is false when the transport refused the message. The invitation still
 * exists — it is created before the mail is dispatched — so the trainer is told to resend
 * rather than left believing a coach was contacted.
 */
final readonly class CoachInvited
{
    public function __construct(
        public ShareLink $link,
        public bool $invitationSent,
    ) {
    }
}
