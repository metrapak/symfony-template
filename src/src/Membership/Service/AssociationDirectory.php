<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\User;
use App\Account\Repository\OrganizationRepository;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Enum\ShareLinkType;
use App\Membership\Exception\ShareLinkNotUsable;
use App\Membership\Repository\TrainerPlayerAssociationRepository;
use App\Profile\Dto\AssociationRecord;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\TrainerNotJoinable;
use App\Profile\Service\TrainerAssociationGateway;

/**
 * Membership's answer to the questions the Profile module asks about associations.
 *
 * The inversion is explained on `TrainerAssociationGateway`: Membership already depends on
 * Profile through `TrainerPlayerAssociation`'s foreign key, so the family screens must not
 * depend back on Membership's repository. This adapter is where the two meet, and it is the
 * only class in either module that knows both vocabularies.
 *
 * It holds no rules of its own. Reads become read models; writes delegate to
 * `AssociationService`, which owns the idempotency, the unique index and the link accounting.
 * The one piece of judgement here is translation: a `ShareLinkNotUsable` from Membership
 * becomes a `TrainerNotJoinable` the family page knows how to render, without the family page
 * learning what a ShareLink state is.
 */
final readonly class AssociationDirectory implements TrainerAssociationGateway
{
    public function __construct(
        private TrainerPlayerAssociationRepository $associations,
        private OrganizationRepository $organizations,
        private ShareLinkResolver $resolver,
        private AssociationService $associationService,
    ) {
    }

    public function activeAssociationsForOwner(User $owner): array
    {
        return array_map(
            static fn (TrainerPlayerAssociation $association): AssociationRecord => self::toRecord($association, own: !$association->getPlayerProfile()->isChild()),
            $this->associations->findActiveForOwner($owner),
        );
    }

    public function activeAssociationsForProfile(PlayerProfile $profile): array
    {
        return array_map(
            // A child login is looking at its own training, so every row is "own" from where
            // it is standing — the switcher it gets is a flat list with no "Me" section, which
            // is exactly what a single group produces (FR-069).
            static fn (TrainerPlayerAssociation $association): AssociationRecord => self::toRecord($association, own: true),
            $this->associations->findActiveWithOrganizationsForProfile($profile),
        );
    }

    public function hasActiveAssociation(PlayerProfile $profile, int $organizationId): bool
    {
        return null !== $this->associations->findOneActiveFor($organizationId, $profile);
    }

    public function associateWithKnownTrainer(PlayerProfile $profile, int $organizationId): void
    {
        $organization = $this->organizations->find($organizationId);

        if (null === $organization) {
            // The caller checked entitlement against its own association list, so a missing
            // organization here means the tenant was removed between that check and this
            // write. Refused with the same message a bad link gets: nothing was granted.
            throw TrainerNotJoinable::code((string) $organizationId);
        }

        $this->associationService->attachWithoutLink($organization, $profile);
    }

    public function associateViaShareLink(string $code, User $actor, PlayerProfile $profile): int
    {
        $resolution = $this->resolver->resolve($code);

        if (!$resolution->isValid()) {
            throw TrainerNotJoinable::code($code);
        }

        $link = $resolution->requireLink();

        // A coach invitation is not a way to enrol a child, however valid the code is. Checked
        // here rather than trusted from the resolver, because `resolve()` answers "is this
        // code usable?" and not "usable for what?".
        if (ShareLinkType::Player !== $link->getType()) {
            throw TrainerNotJoinable::code($code);
        }

        try {
            $this->associationService->associate($link, $actor, $profile);
        } catch (ShareLinkNotUsable) {
            // The link was withdrawn or exhausted between resolving and consuming it.
            throw TrainerNotJoinable::code($code);
        }

        return (int) $link->getOrganization()->getId();
    }

    public function deactivate(PlayerProfile $profile, int $organizationId): void
    {
        $this->associationService->deactivate($organizationId, $profile);
    }

    public function organizationNameFor(int $organizationId): ?string
    {
        return $this->organizations->find($organizationId)?->getName();
    }

    private static function toRecord(TrainerPlayerAssociation $association, bool $own): AssociationRecord
    {
        $profile = $association->getPlayerProfile();
        $organization = $association->getOrganization();

        return new AssociationRecord(
            playerProfileId: (int) $profile->getId(),
            playerName: $profile->getDisplayName(),
            ownProfile: $own,
            organizationId: (int) $organization->getId(),
            organizationName: $organization->getName(),
            connectedAt: $association->getConnectedAt(),
        );
    }
}
