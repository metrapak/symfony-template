<?php

declare(strict_types=1);

namespace App\Membership\DataFixtures;

use App\Account\DataFixtures\AccountFixtures;
use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Membership\Entity\ShareLink;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Enum\ShareLinkType;
use App\Membership\ValueObject\ShareLinkCode;
use App\Profile\Entity\PlayerProfile;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One row per branch the redemption flow has to handle, so the whole of TASK-003 can be
 * exercised by hand without first inventing data: a usable player link, a pending coach
 * invitation, one that lapsed yesterday, a player who has already joined, and a parent with a
 * child so the family checklist has something to show.
 *
 * The codes are fixed strings rather than generated ones. Fixtures are for typing URLs into a
 * browser, and a code that changes on every `db-seed` cannot be written in a README — the
 * randomness that matters is in `ShareLinkCode::generate()`, which is what production uses and
 * what the unit tests cover.
 */
class MembershipFixtures extends Fixture implements DependentFixtureInterface
{
    public const PARENT_EMAIL = 'parent@example.com';

    public const PLAYER_LINK_CODE = 'AAAA1111BBBB2222CCCC3333DD';
    public const PENDING_COACH_LINK_CODE = 'BBBB1111CCCC2222DDDD3333EE';
    public const EXPIRED_COACH_LINK_CODE = 'CCCC1111DDDD2222EEEE3333FF';

    public const PENDING_COACH_EMAIL = 'pending-coach@example.com';
    public const EXPIRED_COACH_EMAIL = 'lapsed-coach@example.com';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var Organization $organization */
        $organization = $this->getReference(AccountFixtures::ORGANIZATION_REFERENCE, Organization::class);
        /** @var User $trainer */
        $trainer = $this->getReference(AccountFixtures::TRAINER_REFERENCE, User::class);
        /** @var User $player */
        $player = $this->getReference(AccountFixtures::PLAYER_REFERENCE, User::class);

        $playerLink = new ShareLink(
            $this->code(self::PLAYER_LINK_CODE),
            ShareLinkType::Player,
            $organization,
            $trainer,
            $now,
        );
        $manager->persist($playerLink);

        $pendingInvite = new ShareLink(
            $this->code(self::PENDING_COACH_LINK_CODE),
            ShareLinkType::Coach,
            $organization,
            $trainer,
            $now,
        );
        $pendingInvite
            ->addressTo(self::PENDING_COACH_EMAIL, 'Priya Coach', 'Looking forward to working with you.')
            ->expiresOn($now->modify('+7 days'));
        $manager->persist($pendingInvite);

        $expiredInvite = new ShareLink(
            $this->code(self::EXPIRED_COACH_LINK_CODE),
            ShareLinkType::Coach,
            $organization,
            $trainer,
            $now->modify('-8 days'),
        );
        $expiredInvite
            ->addressTo(self::EXPIRED_COACH_EMAIL, null, null)
            ->expiresOn($now->modify('-1 day'));
        $manager->persist($expiredInvite);

        // An adult who already trains with this organization: the idempotent branch of FR-043
        // is reachable by opening the player link while signed in as them.
        $playerProfile = PlayerProfile::forSelf($player, $player->getDisplayName(), $now);
        $playerProfile->setBirthDate($now->modify('-24 years'), $now);
        $manager->persist($playerProfile);
        $manager->persist(new TrainerPlayerAssociation($organization, $playerProfile, $playerLink, $now));

        // A parent with one child and no association yet, so the "Who will train with…?"
        // checklist (FR-044) has two boxes the first time they open the link.
        $parent = new User(self::PARENT_EMAIL, 'Parent Example', UserRole::Player, $now);
        $parent->setPassword($this->passwordHasher->hashPassword($parent, AccountFixtures::PASSWORD));
        $parent->setPhone('+44 20 7946 0100');
        $parent->markEmailVerified($now);
        $manager->persist($parent);

        $parentProfile = PlayerProfile::forSelf($parent, 'Parent Example', $now);
        $parentProfile->setBirthDate($now->modify('-41 years'), $now);
        $manager->persist($parentProfile);

        $childProfile = PlayerProfile::forChildOf($parent, 'Sam Example', $now);
        $childProfile->setBirthDate($now->modify('-11 years'), $now);
        $manager->persist($childProfile);

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [AccountFixtures::class];
    }

    private function code(string $raw): ShareLinkCode
    {
        return ShareLinkCode::tryParse($raw)
            ?? throw new \LogicException(\sprintf('Fixture share link code "%s" is not well formed.', $raw));
    }
}
