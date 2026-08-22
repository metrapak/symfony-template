<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Account\Entity\User;
use App\Membership\Dto\AssociationSummary;
use App\Membership\Entity\ShareLink;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Enum\RedemptionOutcome;
use App\Membership\Exception\InvalidFamilySelection;
use App\Membership\Exception\ShareLinkNotUsable;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\Repository\TrainerPlayerAssociationRepository;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Repository\PlayerProfileRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Attaches players who already have an account to another trainer (FR-043, FR-044).
 *
 * Registration is not this service's job — a brand-new account has nothing to reconcile, and
 * `PlayerRegistrationService` owns that path end to end. What lives here is the harder case:
 * an account that may already train with this organization, may be a family with several
 * profiles, and may be clicking the same link for the second time.
 *
 * Three rules hold throughout:
 *
 *  - **Idempotent.** Re-opening a link for an existing association changes nothing and is
 *    still a success (FR-043). It also does not consume a use or write a redemption row, so
 *    the usage count keeps meaning "people who joined" rather than "pages viewed".
 *  - **Selection is authorized, not trusted.** Submitted profile ids are checked against the
 *    profiles the account actually manages before anything is loaded on their behalf. An id
 *    from outside that set is refused, never silently dropped.
 *  - **All or nothing.** Consuming the link, creating the associations and recording the
 *    redemption share one transaction (NFR-041).
 */
final readonly class AssociationService
{
    public function __construct(
        private TrainerPlayerAssociationRepository $associations,
        private PlayerProfileRepository $profiles,
        private ShareLinkRepository $links,
        private RedemptionRecorder $redemptions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Attaches one profile — the ordinary case of a player with no children.
     *
     * @throws ShareLinkNotUsable
     */
    public function associate(ShareLink $link, User $account, PlayerProfile $profile): AssociationSummary
    {
        return $this->attachAll($link, $account, [$profile]);
    }

    /**
     * Attaches the family members the account holder checked (FR-044).
     *
     * @param list<int> $profileIds
     *
     * @throws InvalidFamilySelection
     * @throws ShareLinkNotUsable
     */
    public function associateFamilyMembers(ShareLink $link, User $account, array $profileIds): AssociationSummary
    {
        if ([] === $profileIds) {
            throw InvalidFamilySelection::empty();
        }

        $manageable = [];
        foreach ($this->profiles->findManagedBy($account) as $profile) {
            $manageable[(int) $profile->getId()] = $profile;
        }

        $selected = [];
        foreach ($profileIds as $profileId) {
            $selected[] = $manageable[$profileId] ?? throw InvalidFamilySelection::unknownProfile($profileId);
        }

        return $this->attachAll($link, $account, $selected);
    }

    /**
     * Ends a membership without deleting it (BR-047, NFR-X06).
     *
     * Nothing in Epic-01 calls this from a screen yet — G-15 and G-20 are the requirements
     * that would — but the row has to be endable for the history it preserves to mean
     * anything, and the reactivation path in `attachAll()` is only reachable once it is.
     */
    public function deactivate(int $organizationId, PlayerProfile $profile): void
    {
        $association = $this->associations->findOneFor($organizationId, $profile);

        if (null === $association || !$association->isActive()) {
            return;
        }

        $association->deactivate($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * @param list<PlayerProfile> $profiles
     *
     * @throws ShareLinkNotUsable
     */
    private function attachAll(ShareLink $link, User $account, array $profiles): AssociationSummary
    {
        try {
            return $this->runAttachment($link, $account, $profiles);
        } catch (UniqueConstraintViolationException) {
            // Two redemptions of the same link by the same account landed together — a double
            // submit, or a link opened in two tabs. The transaction rolled back, so the other
            // one won; running again sees its rows and reports them as already associated.
            // One retry, because a second violation would mean something other than a race.
            return $this->runAttachment($link, $account, $profiles);
        }
    }

    /**
     * @param list<PlayerProfile> $profiles
     *
     * @throws ShareLinkNotUsable
     * @throws UniqueConstraintViolationException when a concurrent redemption wrote the same
     *                                            association first
     */
    private function runAttachment(ShareLink $link, User $account, array $profiles): AssociationSummary
    {
        $organizationId = (int) $link->getOrganization()->getId();
        $now = $this->clock->now();

        /** @var list<PlayerProfile> $pending */
        $pending = [];
        /** @var list<PlayerProfile> $alreadyAssociated */
        $alreadyAssociated = [];

        foreach ($profiles as $profile) {
            $existing = $this->associations->findOneFor($organizationId, $profile);

            if (null !== $existing && $existing->isActive()) {
                $alreadyAssociated[] = $profile;

                continue;
            }

            $pending[] = $profile;
        }

        if ([] === $pending) {
            // Nothing to write, so nothing is consumed and nothing is recorded. Returning a
            // summary rather than throwing is what makes a repeat click a no-op success.
            return new AssociationSummary([], $alreadyAssociated);
        }

        $this->entityManager->wrapInTransaction(function () use ($link, $account, $pending, $organizationId, $now): void {
            if (!$this->links->consume($link, $now)) {
                // The link ran out or was withdrawn between rendering the page and this
                // submit. Indistinguishable from an unknown code by the time the controller
                // is done with it (FR-049).
                throw ShareLinkNotUsable::code($link->getCode());
            }

            foreach ($pending as $profile) {
                $existing = $this->associations->findOneFor($organizationId, $profile);

                if (null !== $existing) {
                    $existing->reactivate($link, $now);

                    continue;
                }

                $this->associations->add(new TrainerPlayerAssociation(
                    $link->getOrganization(),
                    $profile,
                    $link,
                    $now,
                ));
            }

            $this->redemptions->record($link, $account, RedemptionOutcome::Association);

            $this->entityManager->flush();
        });

        return new AssociationSummary($pending, $alreadyAssociated);
    }
}
