<?php

declare(strict_types=1);

namespace App\Approval\Service;

use App\Account\Entity\User;
use App\Account\Enum\AuditAction;
use App\Account\Service\AuditLogger;
use App\Approval\Entity\ChildSpendingSetting;
use App\Approval\Repository\ChildSpendingSettingRepository;
use App\Profile\Entity\PlayerProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reads and writes one child's token-spending permission (FR-092, BR-091, BR-096).
 *
 * **Reading never writes.** `get()` on a child nobody has decided about returns an unpersisted
 * default rather than creating a row, because a row created by a page view would claim a parent
 * made a choice they have not made and would have to invent an author for it. The absence of a
 * row *is* the default, and BR-091 says that default is off.
 *
 * **Flipping the setting does not touch requests already pending** (G-32). A parent who turns
 * token spending on while their child is waiting on an approval still has to answer that request.
 * The alternative — auto-approving in flight — would mean a setting change silently spending
 * money, and G-32 records that no requirement covers the case. Doing the conservative thing and
 * saying so is the only defensible reading; the pending request is decided by a human either way.
 *
 * Every change is audited (NFR-X02): this is the control that decides whether a child can spend
 * without asking, so who relaxed it and when is exactly the kind of fact an audit log is for.
 */
final readonly class SpendingSettingService
{
    public function __construct(
        private ChildSpendingSettingRepository $settings,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * This child's setting — the stored one, or the unpersisted default if nobody has chosen.
     */
    public function get(PlayerProfile $child): ChildSpendingSetting
    {
        return $this->settings->findForChild($child)
            ?? ChildSpendingSetting::defaultFor($child, $this->clock->now());
    }

    /**
     * The one question `ApprovalRequestFactory` asks: may this child spend tokens unasked?
     */
    public function tokenSpendingWaivedFor(PlayerProfile $child): bool
    {
        return $this->get($child)->allowsTokenSpendingWithoutApproval();
    }

    /**
     * The settings screen's read, in one query for the whole family.
     *
     * @param list<PlayerProfile> $children
     *
     * @return array<int, ChildSpendingSetting> keyed by child profile id, one entry per child
     */
    public function forFamily(array $children): array
    {
        $now = $this->clock->now();
        $stored = $this->settings->findForChildren($children);

        $byChild = [];

        foreach ($children as $child) {
            $id = (int) $child->getId();
            $byChild[$id] = $stored[$id] ?? ChildSpendingSetting::defaultFor($child, $now);
        }

        return $byChild;
    }

    /**
     * FR-092 — a parent changes the setting, at any time.
     *
     * Upserts through the entity rather than with a conditional write: the unique index on
     * `child_profile_id` is what guarantees one row per child, and a parent submitting the same
     * form twice updates the row they already have.
     */
    public function update(PlayerProfile $child, User $actor, bool $allow): ChildSpendingSetting
    {
        $now = $this->clock->now();
        $setting = $this->settings->findForChild($child);

        if (null === $setting) {
            $setting = new ChildSpendingSetting($child, $now);
            $this->settings->add($setting);
        }

        $setting->decide($allow, $actor, $now);

        return $this->entityManager->wrapInTransaction(function () use ($setting, $child, $actor, $allow): ChildSpendingSetting {
            $this->auditLogger->log(
                actor: $actor,
                action: AuditAction::ChildTokenSpendingSettingChanged,
                subject: $child,
                payload: [
                    'child' => $child->getDisplayName(),
                    'allow_token_spending_without_approval' => $allow,
                ],
            );

            $this->entityManager->flush();

            return $setting;
        });
    }
}
