<?php

declare(strict_types=1);

namespace App\Approval\Enum;

/**
 * The states a child purchase can be in, and the only transitions between them (FR-095, FR-096,
 * spec §8 "Child Purchase Approvals").
 *
 * **The transition table is here rather than in a workflow definition.** The task breakdown
 * suggests considering Symfony Workflow; it is not installed, and four states with three legal
 * edges do not pay for a bundle, a configuration file and a second vocabulary for the same
 * facts — the same reasoning `confirm-dialog.js` gives for not adding Stimulus to obtain three
 * dialogs. What a workflow component would have bought is a single place that says which moves
 * are legal, and `transitions()` below is that place: `PurchaseApprovalRequest` asks it before
 * every state change, so an illegal move is refused by the entity and not only by the screen
 * that happens to call it.
 *
 * **`NotRequired` is not in the spec's list, and it is not a fifth kind of approval.** It is what
 * a purchase that never needed one looks like: FR-092's waived token spend is "processed
 * immediately, child registered instantly", and that purchase still has to appear on the child's
 * reservations, still has to carry the payment reference, and still has to be something the
 * informational notification can point at. The alternative — writing it as `Approved` — would
 * claim in the audit trail (FR-098) that a parent approved something they were only told about.
 *
 * **`info_requested` is deliberately absent.** US-01.05 lists "Request more info" as a parent
 * action, and G-31 records why it cannot be built: no recipient, no channel, no resulting state,
 * and no answer to whether it stops the 48-hour clock. A state with no defined behaviour is worse
 * than a missing one, because the requests that entered it would sit there forever.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending parent approval',
            self::Approved => 'Confirmed',
            self::Denied => 'Denied',
            self::Expired => 'Expired',
            self::NotRequired => 'Confirmed',
        };
    }

    /**
     * The one-line explanation a child reads beside the status.
     *
     * Separate from `label()` because the label is what the status *is* and this is what it
     * *means*; FR-095 asks for the child to see the change from Pending to Confirmed, and a
     * bare "Expired" tells them nothing about what happened or what to do next.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for a parent to approve this purchase.',
            self::Approved => 'A parent approved this and the payment went through.',
            self::Denied => 'A parent denied this. No payment was taken.',
            self::Expired => 'Nobody responded within 48 hours, so this was denied automatically. No payment was taken.',
            self::NotRequired => 'This went through without needing approval.',
        };
    }

    /**
     * The states this one may legally move to.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Denied, self::Expired],
            // Every other state is final. A denied request is not re-openable and an expired one
            // is not approvable after the fact: both are decisions the child was already told
            // about, and reversing one silently would change an answer somebody acted on.
            self::Approved, self::Denied, self::Expired, self::NotRequired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->transitions(), true);
    }

    public function isPending(): bool
    {
        return self::Pending === $this;
    }

    /**
     * Whether the purchase actually completed — the question the reservation list asks.
     */
    public function isConfirmed(): bool
    {
        return self::Approved === $this || self::NotRequired === $this;
    }

    public function isFinal(): bool
    {
        return [] === $this->transitions();
    }
}
