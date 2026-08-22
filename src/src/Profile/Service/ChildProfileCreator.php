<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\User;
use App\Profile\Dto\CreateChildInput;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ChildAgeOutOfRange;
use App\Profile\Exception\ImageRejected;
use App\Profile\Exception\TrainerNotJoinable;
use App\Profile\Repository\PlayerProfileRepository;
use App\Profile\ValueObject\BirthDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Creates a child profile and the trainer associations the parent chose (FR-063, FR-064).
 *
 * Three things are decided here rather than in the controller, because each is a rule about
 * families rather than about HTTP:
 *
 *  - **Selected trainers are authorized, not trusted.** The checklist is rendered from the
 *    organizations the parent actually trains with, and the submitted ids are checked against
 *    that same list before anything is written. An id from outside it is refused outright rather
 *    than filtered out, so a tampered submit is visible instead of looking like a partial
 *    success — the rule TASK-003 established for FR-044's family checklist, applied to FR-064's
 *    trainer checklist.
 *  - **The age bound is re-checked** (BR-068). The form checks it too; this is the check that
 *    survives a request that skipped the form.
 *  - **The profile commits before the associations.** They are separate operations because the
 *    association writes belong to Membership and carry their own transactions, and because
 *    FR-064 explicitly allows the outcome where a child exists with no trainer. A child created
 *    and then left unassociated is a state the requirement describes; an association pointing at
 *    a profile that was rolled back is not a state at all.
 *
 * `parentProfileFor()` covers FR-065: the parent's own "Self" profile is created on demand, so
 * a parent who registered before they had children — or whose account was created by an
 * administrator — can still train alongside them.
 */
final readonly class ChildProfileCreator
{
    public function __construct(
        private PlayerProfileRepository $profiles,
        private TrainerAssociationGateway $associations,
        private ImageUploader $uploader,
        private TrainingContextResolver $contexts,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int> $allowedOrganizationIds the organizations the parent may associate to
     *
     * @throws ChildAgeOutOfRange
     * @throws ImageRejected
     * @throws TrainerNotJoinable when a submitted organization is not one the parent trains with
     */
    public function create(User $parent, CreateChildInput $input, array $allowedOrganizationIds): PlayerProfile
    {
        $now = $this->clock->now();
        $age = (int) $input->age;

        if ($age < BirthDate::MIN_CHILD_AGE || $age > BirthDate::MAX_CHILD_AGE) {
            throw ChildAgeOutOfRange::forAge($age);
        }

        foreach ($input->organizationIds as $organizationId) {
            if (!\in_array($organizationId, $allowedOrganizationIds, true)) {
                throw TrainerNotJoinable::code((string) $organizationId);
            }
        }

        $birthDate = BirthDate::fromAgeOn($age, $now);
        $photo = null !== $input->photo ? $this->uploader->storeProfilePhoto($input->photo) : null;

        $child = PlayerProfile::forChildOf($parent, (string) $input->name, $now);
        $child->setBirthDate($birthDate->value, $now);
        $child->setGender($input->gender, $now);
        $child->setSchool($input->school, $now);

        if (null !== $photo) {
            $child->setPhoto($photo->path, $photo->thumbnailPath, $now);
        }

        $this->entityManager->wrapInTransaction(function () use ($child): void {
            $this->profiles->add($child);
            $this->entityManager->flush();
        });

        foreach ($input->organizationIds as $organizationId) {
            $this->associations->associateWithKnownTrainer($child, $organizationId);
        }

        // The new child's contexts did not exist when the switcher was last resolved. Dropping
        // the memo and the stored selection makes the next request re-read both, so the parent
        // sees the child they just added in the switcher rather than after a sign-out.
        $this->contexts->forget();

        return $child;
    }

    /**
     * The parent's own player profile, created if they do not have one yet (FR-065, BR-060).
     *
     * BR-060 says a parent account *is* a player account, and TASK-003's registration flow
     * always creates the self profile. This covers the accounts that predate it, and the
     * parent who first appears on the platform as somebody's parent: the profile has to exist
     * before FR-064's "will you train too?" has anything to associate.
     */
    public function parentProfileFor(User $parent): PlayerProfile
    {
        $existing = $this->profiles->findSelfProfileFor($parent);

        if (null !== $existing) {
            return $existing;
        }

        $now = $this->clock->now();
        $profile = PlayerProfile::forSelf($parent, $parent->getDisplayName(), $now);

        $this->entityManager->wrapInTransaction(function () use ($profile): void {
            $this->profiles->add($profile);
            $this->entityManager->flush();
        });

        return $profile;
    }

    /**
     * Children of this parent who already look like the one being added (FR-063).
     *
     * A warning, never a rejection: the caller shows this list and lets the parent confirm. See
     * `CreateChildInput::$acknowledgedDuplicate`.
     *
     * @return list<PlayerProfile>
     */
    public function findLookalikes(User $parent, CreateChildInput $input): array
    {
        if (null === $input->name) {
            return [];
        }

        return $this->profiles->findSimilarChildrenOf($parent, $input->name, $input->age, $this->clock->now());
    }
}
