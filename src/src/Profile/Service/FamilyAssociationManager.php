<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\User;
use App\Profile\Dto\AssociationRecord;
use App\Profile\Dto\ChildSummary;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ProfileNotManaged;
use App\Profile\Exception\TrainerNotJoinable;
use App\Profile\Repository\PlayerProfileRepository;
use App\Profile\ValueObject\BirthDate;
use Symfony\Component\Clock\ClockInterface;

/**
 * Adding and removing a family member's trainers (FR-066, BR-062, BR-066).
 *
 * The removal is the part worth reading. FR-066 promises the parent four things happen when they
 * confirm, and all four happen here, in an order chosen so a partial failure leaves the safe
 * state rather than the confusing one:
 *
 *  1. Upcoming RSVPs are cancelled — through `UpcomingReservationCanceller`, which is a no-op
 *     until Epic-02 ships reservations. First, because it is the step whose absence the parent
 *     would notice, and because cancelling a reservation for a membership that then survives is
 *     recoverable while the reverse is not.
 *  2. The association is **deactivated, never deleted** (BR-066, NFR-X06). The row stays, so
 *     "this child trained here from March to September" remains true and the trainer's
 *     historical attendance figures do not move.
 *  3. The trainer stops seeing the child, which follows from 2 rather than being a separate
 *     step: every roster query filters on active status.
 *  4. The stored context selection is dropped, so a parent looking at the context they just
 *     removed is moved to one they still have on their next request instead of getting a 403 on
 *     their own dashboard.
 *
 * What this deliberately does *not* do is delete the child's data with that trainer. FR-066 says
 * "soft-deleted with history preserved", and a deactivated association *is* the soft delete —
 * every context-scoped query in the platform is filtered by an active (profile, organization)
 * pair, so ending the pair hides the data with it while leaving every row intact. Reaching into
 * later epics' tables to stamp them individually would duplicate that rule in as many places as
 * there are tables, and each copy would be one migration away from disagreeing.
 */
final readonly class FamilyAssociationManager
{
    public function __construct(
        private TrainerAssociationGateway $associations,
        private PlayerProfileRepository $profiles,
        private UpcomingReservationCanceller $reservations,
        private TrainingContextResolver $contexts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Attaches a family member to a trainer by pasted ShareLink (FR-066, "Option A").
     *
     * @throws ProfileNotManaged
     * @throws TrainerNotJoinable
     *
     * @return int the organization the code turned out to belong to
     */
    public function addTrainerByShareLink(User $parent, PlayerProfile $profile, string $code): int
    {
        $this->assertManages($parent, $profile);

        $organizationId = $this->associations->associateViaShareLink($code, $parent, $profile);
        $this->contexts->forget();

        return $organizationId;
    }

    /**
     * Attaches a family member to a trainer the parent already trains with (FR-066, "Option B").
     *
     * Entitlement is the parent's own association list, checked here: without it, an
     * organization id in a form field would let any parent enrol their child with any trainer on
     * the platform, which is the IDOR this whole screen is one field away from.
     *
     * @throws ProfileNotManaged
     * @throws TrainerNotJoinable
     */
    public function addKnownTrainer(User $parent, PlayerProfile $profile, int $organizationId): void
    {
        $this->assertManages($parent, $profile);

        if (!\in_array($organizationId, $this->trainerOrganizationIdsFor($parent), true)) {
            throw TrainerNotJoinable::code((string) $organizationId);
        }

        $this->associations->associateWithKnownTrainer($profile, $organizationId);
        $this->contexts->forget();
    }

    /**
     * Ends a family member's membership with one trainer (FR-066, BR-066).
     *
     * @throws ProfileNotManaged
     * @throws TrainerNotJoinable
     *
     * @return int how many upcoming reservations were cancelled
     */
    public function removeTrainer(User $parent, PlayerProfile $profile, int $organizationId): int
    {
        $this->assertManages($parent, $profile);

        // Refuses an organization the profile does not actively train with, so a forged id
        // cannot be used to probe which associations exist by the shape of the response.
        if (!$this->associations->hasActiveAssociation($profile, $organizationId)) {
            throw TrainerNotJoinable::code((string) $organizationId);
        }

        $cancelled = $this->reservations->cancelUpcomingFor($profile, $organizationId);

        $this->associations->deactivate($profile, $organizationId);
        $this->contexts->forget();

        return $cancelled;
    }

    /**
     * The family page's rows: every child, their age, and their trainers (FR-066).
     *
     * Assembled from one association query rather than one per child — see `ChildSummary`.
     *
     * @return list<ChildSummary>
     */
    public function familyOf(User $parent): array
    {
        $today = $this->clock->now();
        $byProfile = [];

        foreach ($this->associations->activeAssociationsForOwner($parent) as $record) {
            $byProfile[$record->playerProfileId][] = $record;
        }

        return array_map(
            function (PlayerProfile $child) use ($byProfile, $today): ChildSummary {
                $account = $child->getAccount();

                return new ChildSummary(
                    profile: $child,
                    age: $child->ageOn($today),
                    trainers: $byProfile[(int) $child->getId()] ?? [],
                    hasLogin: null !== $account,
                    // A revoked login is a deactivated account, not a deleted one — see
                    // `ChildLoginManager`. The family page shows the difference so a parent can
                    // tell "no login" from "login switched off". Asked as "could this account
                    // sign in?", which is the question the firewall itself asks.
                    loginActive: null !== $account && $account->getStatus()->canAuthenticate(),
                );
            },
            $this->profiles->findChildrenOf($parent),
        );
    }

    /**
     * The trainers this parent already trains with, deduplicated, for FR-066's "Option B" list
     * and FR-064's checklist.
     *
     * @return list<AssociationRecord> one record per organization, the earliest connection
     */
    public function trainersOf(User $parent): array
    {
        $byOrganization = [];

        foreach ($this->associations->activeAssociationsForOwner($parent) as $record) {
            $byOrganization[$record->organizationId] ??= $record;
        }

        return array_values($byOrganization);
    }

    /**
     * @return list<int>
     */
    public function trainerOrganizationIdsFor(User $parent): array
    {
        return array_map(
            static fn (AssociationRecord $record): int => $record->organizationId,
            $this->trainersOf($parent),
        );
    }

    /**
     * The organizations this profile could still be added to: the parent's trainers, minus the
     * ones this player already trains with.
     *
     * @return list<AssociationRecord>
     */
    public function addableTrainersFor(User $parent, PlayerProfile $profile): array
    {
        return array_values(array_filter(
            $this->trainersOf($parent),
            fn (AssociationRecord $record): bool => !$this->associations->hasActiveAssociation($profile, $record->organizationId),
        ));
    }

    /**
     * Whether this profile currently trains with that organization.
     *
     * Exposed so a screen can refuse an organization the player is not with *before* offering to
     * remove it, rather than discovering it at the write. The controllers go through this rather
     * than reaching for the gateway themselves, so Profile's callers keep talking to one service
     * about one subject.
     */
    public function hasActiveTrainer(PlayerProfile $profile, int $organizationId): bool
    {
        return $this->associations->hasActiveAssociation($profile, $organizationId);
    }

    /**
     * A trainer's organization name, for a message that has to name them.
     *
     * Null when the organization is gone, which a caller renders as a neutral phrase rather than
     * as an empty gap in a sentence.
     */
    public function trainerNameFor(int $organizationId): ?string
    {
        return $this->associations->organizationNameFor($organizationId);
    }

    public function isChildAgeWithinRange(PlayerProfile $profile): bool
    {
        $birthDate = $profile->getBirthDate();

        return null !== $birthDate && BirthDate::fromDate($birthDate)->isWithinChildRangeOn($this->clock->now());
    }

    /**
     * The ownership check every write here starts with.
     *
     * `ProfileVoter` makes the same decision at the controller boundary, and both are kept: the
     * voter is what produces a clean 403 and what the templates ask before rendering a button,
     * while this is what holds if a future caller reaches the service without going through a
     * controller. FR-070's isolation requirement is not something to enforce in exactly one
     * place.
     *
     * @throws ProfileNotManaged
     */
    private function assertManages(User $parent, PlayerProfile $profile): void
    {
        if ($profile->getOwner()->getId() !== $parent->getId()) {
            throw ProfileNotManaged::create();
        }
    }
}
