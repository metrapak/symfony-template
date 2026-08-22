<?php

declare(strict_types=1);

namespace App\Profile\DataFixtures;

use App\Account\DataFixtures\AccountFixtures;
use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Membership\DataFixtures\MembershipFixtures;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Profile\Entity\CoachProfile;
use App\Profile\Entity\EmergencyContact;
use App\Profile\Entity\OrganizationBranding;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Entity\TrainerProfile;
use App\Profile\Enum\PlayerGender;
use App\Profile\ValueObject\HexColor;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The family, context and branding shapes TASK-004 has to be exercised against.
 *
 * The centrepiece is **a parent with three children across two trainers**, because that is the
 * smallest family that makes every part of FR-069 and FR-070 visible at once: the switcher has
 * both of its groups, one child trains with both organizations while another trains with one, and
 * the two brandings differ so switching context visibly changes the page.
 *
 * A **second organization** is created here rather than reused from `MembershipFixtures`, and it
 * is the reason this file exists at all. With one tenant, every isolation bug looks like correct
 * behaviour: a query that forgot its organization filter returns the right rows by accident. Two
 * tenants with overlapping families is the configuration in which FR-070 can actually be seen to
 * work or not.
 *
 * Also here, each for one requirement that has no other visible state:
 *
 *  - a **child with a login** (FR-067, G-23), so the constrained account can be signed into
 *    without first creating one;
 *  - a **child without one**, so the family page shows both states side by side;
 *  - a **coach with a public profile** (FR-061), which is what makes `AccountPhotoVoter`'s one
 *    outward grant reachable;
 *  - **branding on both organizations** (FR-071, FR-072) — one colour that needs white text and
 *    one that needs black, so NFR-065's computed foreground is visibly doing something;
 *  - an **emergency contact** (FR-061, BR-064) on the multi-trainer parent.
 *
 * No logos. A fixture cannot invent a valid PNG without shipping a binary, and `ImageUploader`
 * would reject anything less — a `logoPath` pointing at a file that does not exist would make
 * every page in the fixture data render a broken image and teach nobody anything. Logos are
 * covered by the upload tests, which build real images.
 */
class ProfileFixtures extends Fixture implements DependentFixtureInterface
{
    /** The parent whose family spans two organizations — the FR-070 subject. */
    public const MULTI_TRAINER_PARENT = 'family@example.com';

    /** The second tenant's owner, so isolation has somebody to be isolated from. */
    public const SECOND_TRAINER = 'trainer-two@example.com';

    /** A child login (FR-067). Signs in with the username, not this derived address. */
    public const CHILD_LOGIN_USERNAME = 'maya.example';

    public const SECOND_ORGANIZATION_NAME = 'Southside Skills';

    /**
     * Both are valid and both pass NFR-065's contrast rule, but they resolve to *different*
     * foregrounds — the first pairs with white text, the second with black. That is the point:
     * a bug in `accessibleForeground()` shows up as unreadable text in the fixture data rather
     * than only in a unit test.
     */
    public const FIRST_ORGANIZATION_COLOR = '#7c2d12';
    public const SECOND_ORGANIZATION_COLOR = '#fbbf24';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var Organization $firstOrganization */
        $firstOrganization = $this->getReference(AccountFixtures::ORGANIZATION_REFERENCE, Organization::class);
        /** @var User $firstTrainer */
        $firstTrainer = $this->getReference(AccountFixtures::TRAINER_REFERENCE, User::class);

        $secondTrainer = $this->trainer(self::SECOND_TRAINER, 'Nadia Trainer', $now);
        $manager->persist($secondTrainer);

        $secondOrganization = new Organization(self::SECOND_ORGANIZATION_NAME, $secondTrainer, $now);
        $manager->persist($secondOrganization);

        $manager->persist((new TrainerProfile($secondTrainer, $secondOrganization, $now))->update(
            'Southside Skills Academy',
            "14 Mill Lane\nSouthside",
            'https://southside-skills.example.com',
            'Small-group sessions for ages 8 to 16.',
            $now,
        ));

        // FR-071/FR-072 on both tenants, so switching context changes the page's colour.
        $manager->persist($this->branding($firstOrganization, self::FIRST_ORGANIZATION_COLOR, $now));
        $manager->persist($this->branding($secondOrganization, self::SECOND_ORGANIZATION_COLOR, $now));

        // FR-061: a coach who published their profile. The one case in which somebody else's
        // photograph and bio are visible to an organization's members.
        $coach = $manager->getRepository(User::class)->findOneBy(['email' => AccountFixtures::COACH]);

        if ($coach instanceof User) {
            $manager->persist((new CoachProfile($coach, $firstOrganization, $now))->update(
                'Fifteen years coaching junior squads, most of them in the rain.',
                'UEFA B Licence',
                'Safeguarding (renewed 2026), Emergency First Aid',
                true,
                $now,
            ));
        }

        $parent = $this->parent(self::MULTI_TRAINER_PARENT, 'Dana Ruiz', $now);
        $manager->persist($parent);

        // FR-065/BR-060: the parent is a player too, and they train with the first organization.
        // Without this the switcher would have only its children's group, which is the *other*
        // shape FR-069 describes — and both need to be reachable in fixture data.
        $parentProfile = PlayerProfile::forSelf($parent, 'Dana Ruiz', $now);
        $parentProfile->setBirthDate($now->modify('-38 years'), $now);
        $parentProfile->setGender(PlayerGender::Female, $now);
        $manager->persist($parentProfile);
        $manager->persist(new TrainerPlayerAssociation($firstOrganization, $parentProfile, null, $now->modify('-6 months')));

        // Child one trains with **both** organizations: the row that makes a context switch
        // within one child meaningful, and the row a leaked query would expose across tenants.
        $mateo = $this->child($parent, 'Mateo Ruiz', 11, PlayerGender::Male, $now);
        $mateo->setSchool('Mill Lane Primary', $now);
        $mateo->setJerseyNumber('07', $now);
        $manager->persist($mateo);
        $manager->persist(new TrainerPlayerAssociation($firstOrganization, $mateo, null, $now->modify('-5 months')));
        $manager->persist(new TrainerPlayerAssociation($secondOrganization, $mateo, null, $now->modify('-2 months')));

        // Child two trains with the second organization only, and has a login of her own (FR-067).
        $maya = $this->child($parent, 'Maya Ruiz', 14, PlayerGender::Female, $now);
        $maya->setSchool('Southside Academy', $now);
        $manager->persist($maya);
        $manager->persist(new TrainerPlayerAssociation($secondOrganization, $maya, null, $now->modify('-3 months')));

        $mayaLogin = $this->childLogin(self::CHILD_LOGIN_USERNAME, 'Maya Ruiz', $now);
        $manager->persist($mayaLogin);
        $maya->attachLogin($mayaLogin, $now);

        // Child three trains with nobody, which FR-064 explicitly allows and which the family
        // page has to render without looking broken.
        $luca = $this->child($parent, 'Luca Ruiz', 6, PlayerGender::Male, $now);
        $manager->persist($luca);

        // FR-061/BR-064: one contact, on the parent, for the whole family.
        $manager->persist(new EmergencyContact(
            $parent,
            'Rosa Ruiz',
            'Grandmother',
            '+48221234567',
            0,
            $now,
        ));

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        // MembershipFixtures rather than AccountFixtures: it creates the associations this file's
        // second tenant is contrasted against, and depending on the later of the two keeps the
        // ordering explicit rather than incidental.
        return [MembershipFixtures::class];
    }

    private function trainer(string $email, string $name, \DateTimeImmutable $now): User
    {
        $trainer = new User($email, $name, UserRole::Trainer, $now);
        $trainer->setPassword($this->passwordHasher->hashPassword($trainer, AccountFixtures::PASSWORD));
        $trainer->markEmailVerified($now);

        return $trainer;
    }

    private function parent(string $email, string $name, \DateTimeImmutable $now): User
    {
        $parent = new User($email, $name, UserRole::Player, $now);
        $parent->setPassword($this->passwordHasher->hashPassword($parent, AccountFixtures::PASSWORD));
        $parent->setPhone('+48221234567');
        $parent->markEmailVerified($now);

        return $parent;
    }

    /**
     * A child's login, built the way `ChildLoginManager` builds one: a derived undeliverable
     * address, verified because there is no address to verify, and no forced password change —
     * the fixture's whole purpose is to be signed into, and a change-password interstitial would
     * make that a two-step process for every test that uses it.
     */
    private function childLogin(string $username, string $name, \DateTimeImmutable $now): User
    {
        $account = new User($username . '@children.invalid', $name, UserRole::Player, $now);
        $account->setLoginUsername($username);
        $account->setPassword($this->passwordHasher->hashPassword($account, AccountFixtures::PASSWORD));
        $account->markEmailVerified($now);

        return $account;
    }

    private function child(
        User $parent,
        string $name,
        int $age,
        PlayerGender $gender,
        \DateTimeImmutable $now,
    ): PlayerProfile {
        $child = PlayerProfile::forChildOf($parent, $name, $now);
        $child->setBirthDate($now->modify(\sprintf('-%d years', $age)), $now);
        $child->setGender($gender, $now);

        return $child;
    }

    private function branding(Organization $organization, string $hex, \DateTimeImmutable $now): OrganizationBranding
    {
        $branding = new OrganizationBranding($organization, $now);
        $branding->setPrimaryColor(
            HexColor::tryParse($hex) ?? throw new \LogicException(\sprintf('Fixture colour "%s" is not a valid hex colour.', $hex)),
            $now,
        );

        return $branding;
    }
}
