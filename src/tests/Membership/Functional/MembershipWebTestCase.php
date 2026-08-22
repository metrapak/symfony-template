<?php

declare(strict_types=1);

namespace App\Tests\Membership\Functional;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Membership\Entity\CoachAssignment;
use App\Membership\Entity\ShareLink;
use App\Membership\Entity\ShareLinkRedemption;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Enum\ShareLinkType;
use App\Membership\ValueObject\ShareLinkCode;
use App\Profile\Entity\PlayerProfile;
use App\Tests\Account\Functional\AccountWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared setup for the invitation flow tests: one place that knows how to build a trainer with
 * a tenant, a link of either kind, and a family — plus the read-backs every test makes.
 */
abstract class MembershipWebTestCase extends AccountWebTestCase
{
    protected const TRAINER_EMAIL = 'trainer@example.com';

    protected User $trainer;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trainer = $this->createUser(self::TRAINER_EMAIL, UserRole::Trainer, name: 'Tara Trainer');
        $this->organization = $this->createOrganizationFor($this->trainer, 'Northside Academy');
    }

    protected function createPlayerLink(?Organization $organization = null, ?User $creator = null): ShareLink
    {
        $link = new ShareLink(
            ShareLinkCode::generate(),
            ShareLinkType::Player,
            $this->managed($organization ?? $this->organization, Organization::class),
            $this->managed($creator ?? $this->trainer, User::class),
            new \DateTimeImmutable(),
        );

        return $this->save($link);
    }

    protected function createCoachLink(
        string $targetEmail = 'coach@example.com',
        ?\DateTimeImmutable $expiresAt = null,
        ?Organization $organization = null,
        ?User $creator = null,
        ?string $message = null,
    ): ShareLink {
        $now = new \DateTimeImmutable();

        $link = new ShareLink(
            ShareLinkCode::generate(),
            ShareLinkType::Coach,
            $this->managed($organization ?? $this->organization, Organization::class),
            $this->managed($creator ?? $this->trainer, User::class),
            $now,
        );
        $link->addressTo($targetEmail, null, $message)->expiresOn($expiresAt ?? $now->modify('+7 days'));

        return $this->save($link);
    }

    protected function createSelfProfile(User $account, ?string $name = null): PlayerProfile
    {
        $profile = PlayerProfile::forSelf(
            $this->managed($account, User::class),
            $name ?? $account->getDisplayName(),
            new \DateTimeImmutable(),
        );

        return $this->save($profile);
    }

    protected function createChildProfile(User $parent, string $name, ?User $childAccount = null): PlayerProfile
    {
        $profile = PlayerProfile::forChildOf($this->managed($parent, User::class), $name, new \DateTimeImmutable());

        if (null !== $childAccount) {
            // Child logins arrive with TASK-004; setting the column directly is how FR-048 is
            // testable before the screens that create them exist.
            (new \ReflectionProperty(PlayerProfile::class, 'account'))
                ->setValue($profile, $this->managed($childAccount, User::class));
        }

        return $this->save($profile);
    }

    protected function createAssociation(PlayerProfile $profile, ?ShareLink $via = null, ?Organization $organization = null): TrainerPlayerAssociation
    {
        $association = new TrainerPlayerAssociation(
            $this->managed($organization ?? $this->organization, Organization::class),
            $this->managed($profile, PlayerProfile::class),
            null !== $via ? $this->managed($via, ShareLink::class) : null,
            new \DateTimeImmutable(),
        );

        return $this->save($association);
    }

    protected function createCoachAssignment(User $coach, Organization $organization, ?ShareLink $via = null): CoachAssignment
    {
        $assignment = new CoachAssignment(
            $this->managed($organization, Organization::class),
            $this->managed($coach, User::class),
            null !== $via ? $this->managed($via, ShareLink::class) : null,
            new \DateTimeImmutable(),
        );

        return $this->save($assignment);
    }

    /**
     * Re-reads an entity through the *current* manager.
     *
     * The kernel reboots after every request, so an entity created in `setUp()` is detached by
     * the time a test builds more data around it, and Doctrine would treat it as a new row to
     * insert. Fixtures built between requests go through here rather than each test
     * remembering to re-fetch.
     *
     * @template T of object
     *
     * @param T $entity
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function managed(object $entity, string $class): object
    {
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        self::assertNotNull($id, 'Only persisted entities can be re-attached.');

        $managed = $this->currentEntityManager()->find($class, $id);
        self::assertInstanceOf($class, $managed);

        return $managed;
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected function save(object $entity): object
    {
        $entityManager = $this->currentEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();

        return $entity;
    }

    protected function currentEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The kernel reboots between requests, so entities loaded before one are detached
     * afterwards. Every read-back goes through a freshly resolved manager with a cleared
     * identity map, which also proves the write reached the database.
     */
    protected function freshEntityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }

    /**
     * @return list<TrainerPlayerAssociation>
     */
    protected function associations(): array
    {
        return $this->freshEntityManager()
            ->getRepository(TrainerPlayerAssociation::class)
            ->findBy([], ['id' => 'ASC']);
    }

    /**
     * @return list<ShareLinkRedemption>
     */
    protected function redemptions(): array
    {
        return $this->freshEntityManager()
            ->getRepository(ShareLinkRedemption::class)
            ->findBy([], ['id' => 'ASC']);
    }

    /**
     * @return list<CoachAssignment>
     */
    protected function coachAssignments(): array
    {
        return $this->freshEntityManager()
            ->getRepository(CoachAssignment::class)
            ->findBy([], ['id' => 'ASC']);
    }

    /**
     * @return list<PlayerProfile>
     */
    protected function profiles(): array
    {
        return $this->freshEntityManager()
            ->getRepository(PlayerProfile::class)
            ->findBy([], ['id' => 'ASC']);
    }

    protected function reloadLink(int $id): ShareLink
    {
        $link = $this->freshEntityManager()->getRepository(ShareLink::class)->find($id);
        self::assertInstanceOf(ShareLink::class, $link);

        return $link;
    }
}
