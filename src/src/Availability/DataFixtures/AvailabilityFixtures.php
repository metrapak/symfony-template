<?php

declare(strict_types=1);

namespace App\Availability\DataFixtures;

use App\Account\DataFixtures\AccountFixtures;
use App\Account\Entity\User;
use App\Availability\Entity\AvailabilitySlot;
use App\Availability\Enum\DayOfWeek;
use App\Availability\ValueObject\AvailabilitySubject;
use App\Availability\ValueObject\TimeRange;
use App\Profile\DataFixtures\ProfileFixtures;
use App\Profile\Entity\PlayerProfile;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\ClockInterface;

/**
 * The availability shapes TASK-005 has to be exercised against.
 *
 * Chosen so that every state the trainer's view can render is present in one screen, because the
 * bug this data exists to catch is a view that treats two of them as one:
 *
 *  - a **coach with a split day** (Monday 16:00-18:00 *and* 19:00-21:00), which is US-01.10's own
 *    example and the case that proves adjacent-range merging did not swallow the gap;
 *  - a coach day marked **explicitly not available**, which is a declared "no" and must not read
 *    the same as silence;
 *  - a player **available** in the obvious filter window (Monday evening), one **unavailable**
 *    then but available elsewhere, and one who has **declared nothing at all**. With only the
 *    first two, "15 of 20" and "15 of 15" are indistinguishable and the undeclared count is
 *    untested.
 *
 * There is deliberately no override row. An override is a trainer's recorded judgement about a
 * specific conflict, and a fixture inventing one would put a sentence in a real trainer's name
 * into every developer's database — the same reason `ProfileFixtures` ships no logo.
 */
class AvailabilityFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        $coach = $manager->getRepository(User::class)->findOneBy(['email' => AccountFixtures::COACH]);

        if ($coach instanceof User) {
            $subject = AvailabilitySubject::coach($coach);

            // US-01.10's example, verbatim: two blocks on one Monday with an evening gap.
            $manager->persist($this->slot($subject, DayOfWeek::Monday, 16 * 60, 18 * 60, $now));
            $manager->persist($this->slot($subject, DayOfWeek::Monday, 19 * 60, 21 * 60, $now));
            $manager->persist($this->slot($subject, DayOfWeek::Tuesday, 16 * 60, 20 * 60, $now));
            $manager->persist($this->slot($subject, DayOfWeek::Saturday, 9 * 60, 12 * 60, $now));

            // A declared "no", which is what makes a conflict a conflict rather than an unknown.
            $manager->persist(AvailabilitySlot::unavailableAllDay($subject, DayOfWeek::Wednesday, $now));
        }

        // The multi-trainer family from TASK-004's fixtures. Looked up by name because
        // `ProfileFixtures` publishes no references for the profiles it creates, and adding some
        // there for this file's benefit would make that fixture's contract depend on this one.
        $dana = $this->profileNamed($manager, ProfileFixtures::MULTI_TRAINER_PARENT, 'Dana Ruiz');

        if ($dana instanceof PlayerProfile) {
            $subject = AvailabilitySubject::player($dana);
            $manager->persist($this->slot($subject, DayOfWeek::Monday, 17 * 60, 20 * 60, $now));
            $manager->persist($this->slot($subject, DayOfWeek::Wednesday, 18 * 60, 21 * 60, $now));
        }

        $mateo = $this->profileNamed($manager, ProfileFixtures::MULTI_TRAINER_PARENT, 'Mateo Ruiz');

        if ($mateo instanceof PlayerProfile) {
            $subject = AvailabilitySubject::player($mateo);
            // Free on Monday afternoon but not the evening: the player a Monday-evening filter
            // must exclude even though they have declared a Monday.
            $manager->persist($this->slot($subject, DayOfWeek::Monday, 14 * 60, 16 * 60, $now));
            $manager->persist($this->slot($subject, DayOfWeek::Thursday, 16 * 60, 18 * 60, $now));
            $manager->persist(AvailabilitySlot::unavailableAllDay($subject, DayOfWeek::Sunday, $now));
        }

        // Maya Ruiz is left with nothing on purpose — the undeclared third of the count.

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [ProfileFixtures::class];
    }

    private function slot(
        AvailabilitySubject $subject,
        DayOfWeek $day,
        int $startMinute,
        int $endMinute,
        \DateTimeImmutable $now,
    ): AvailabilitySlot {
        return AvailabilitySlot::available($subject, $day, TimeRange::fromMinutes($startMinute, $endMinute), $now);
    }

    private function profileNamed(ObjectManager $manager, string $ownerEmail, string $displayName): ?PlayerProfile
    {
        $owner = $manager->getRepository(User::class)->findOneBy(['email' => $ownerEmail]);

        if (!$owner instanceof User) {
            return null;
        }

        $profile = $manager->getRepository(PlayerProfile::class)->findOneBy([
            'owner' => $owner,
            'displayName' => $displayName,
        ]);

        return $profile instanceof PlayerProfile ? $profile : null;
    }
}
