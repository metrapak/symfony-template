<?php

declare(strict_types=1);

namespace App\Account\Enum;

/**
 * The catalogue of auditable operations (BR-025, NFR-X02).
 *
 * A backed enum rather than free-form strings so the compliance report can group and filter
 * without a `LIKE`, and so a typo in an action name is a compile-time error rather than a row
 * nobody ever finds again.
 */
enum AuditAction: string
{
    case TrainerCreated = 'trainer.created';
    case UserUpdated = 'user.updated';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case UserAnonymized = 'user.anonymized';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationEnded = 'impersonation.ended';

    /**
     * A trainer scheduled a coach outside their stated availability (FR-086, BR-085).
     *
     * Audited as well as recorded on `coach_availability_override`, because NFR-X02 lists an
     * override beside impersonation and deletion: the override row is the trainer's explanation,
     * the audit entry is the platform's independent trace of who did it and when.
     */
    case CoachAvailabilityOverridden = 'coach_availability.overridden';

    /**
     * A parent decided a child's purchase, or changed what a child may spend without asking
     * (FR-095, FR-092, NFR-X02).
     *
     * NFR-X02 lists approval beside impersonation, deletion and override, and these are the
     * three writes that spend or authorize spending somebody else's money. Expiry has no case of
     * its own on purpose: nobody performs it, and an audit entry has to name an actor — the
     * request row carries its own `expired` status and response timestamp instead.
     */
    case ChildPurchaseApproved = 'child_purchase.approved';
    case ChildPurchaseDenied = 'child_purchase.denied';
    case ChildTokenSpendingSettingChanged = 'child_spending_setting.changed';

    public function label(): string
    {
        return match ($this) {
            self::TrainerCreated => 'Trainer created',
            self::UserUpdated => 'User updated',
            self::UserDeactivated => 'User deactivated',
            self::UserReactivated => 'User reactivated',
            self::UserAnonymized => 'User deleted (anonymized)',
            self::ImpersonationStarted => 'Impersonation started',
            self::ImpersonationEnded => 'Impersonation ended',
            self::CoachAvailabilityOverridden => 'Coach availability overridden',
            self::ChildPurchaseApproved => 'Child purchase approved',
            self::ChildPurchaseDenied => 'Child purchase denied',
            self::ChildTokenSpendingSettingChanged => 'Child token spending setting changed',
        };
    }
}
