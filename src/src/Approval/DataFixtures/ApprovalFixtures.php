<?php

declare(strict_types=1);

namespace App\Approval\DataFixtures;

use App\Approval\Entity\ChildSpendingSetting;
use App\Approval\Entity\PurchaseApprovalRequest;
use App\Approval\Enum\PaymentType;
use App\Approval\ValueObject\Money;
use App\Profile\DataFixtures\ProfileFixtures;
use App\Profile\Entity\PlayerProfile;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\ClockInterface;

/**
 * The purchase states TASK-006 has to be exercised against.
 *
 * Chosen so that every state the parent's screen and the child's list can render is present at
 * once, because the bug this data exists to catch is a view that treats two of them as the same:
 *
 *  - a **pending USD request** from the child who has a login (Maya), which is the FR-090 subject
 *    and the row the approve/deny buttons act on;
 *  - a **pending request hours from expiry**, so the countdown and the "closest to lapsing first"
 *    ordering are visible without waiting two days for them;
 *  - an **approved** purchase carrying a payment reference, and a **denied** one carrying a
 *    parent's note, so "confirmed" and "denied with a reason" are distinguishable on sight;
 *  - an **expired** one, which is a denial nobody made and must not read like a parent's;
 *  - a **token purchase that needed no approval** for the sibling whose parent waived it — the
 *    FR-092 branch, and the only row whose status is `not_required`.
 *
 * The waived setting is on **one** child and not the family, which is BR-096 made visible: the
 * settings screen shows one child allowed and the others not, so a change that started applying
 * the setting family-wide would be obvious in the fixture data rather than only in a test.
 *
 * No notifications are seeded. A notification is a record that somebody was told something, and
 * inventing a history of messages nobody sent would make the inbox lie about what happened — the
 * same reason `AvailabilityFixtures` ships no override.
 */
class ApprovalFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // Looked up by name because `ProfileFixtures` publishes no references for the profiles it
        // creates, and adding some there for this file's benefit would make that fixture's
        // contract depend on this one — the convention `AvailabilityFixtures` established.
        $maya = $this->profileNamed($manager, 'Maya Ruiz');
        $mateo = $this->profileNamed($manager, 'Mateo Ruiz');

        if ($maya instanceof PlayerProfile) {
            // FR-091: dollars always wait for a parent, whatever any setting says.
            $manager->persist(PurchaseApprovalRequest::awaitingApproval(
                $maya,
                $maya->getOwner(),
                'stand-in:fixture-city-cup',
                'City Cup entry fee',
                Money::usd(4500),
                PaymentType::Usd,
                $now->modify('-2 hours'),
                $now->modify('+46 hours'),
            ));

            // Close enough to the mark that the countdown reads in hours, and first in the
            // parent's list because it is the one about to lapse.
            $manager->persist(PurchaseApprovalRequest::awaitingApproval(
                $maya,
                $maya->getOwner(),
                'stand-in:fixture-goalkeeper-clinic',
                'Goalkeeper clinic, Saturday morning',
                Money::usd(2000),
                PaymentType::Usd,
                $now->modify('-45 hours'),
                $now->modify('+3 hours'),
            ));

            $approved = PurchaseApprovalRequest::awaitingApproval(
                $maya,
                $maya->getOwner(),
                'stand-in:fixture-winter-camp',
                'Winter camp, week one',
                Money::usd(12000),
                PaymentType::Usd,
                $now->modify('-8 days'),
                $now->modify('-6 days'),
            );
            $approved->approve($now->modify('-7 days'), 'Yes — you asked for this at Christmas.');
            // The reference a real processor would return, in the shape `FakePaymentProcessor`
            // produces, so the "payment reference" line on the screen has something to render.
            $approved->recordPayment('fake-approval-fixture-winter-camp', $now->modify('-7 days'));
            $manager->persist($approved);

            $denied = PurchaseApprovalRequest::awaitingApproval(
                $maya,
                $maya->getOwner(),
                'stand-in:fixture-away-kit',
                'Second away kit',
                Money::usd(6500),
                PaymentType::Usd,
                $now->modify('-5 days'),
                $now->modify('-3 days'),
            );
            $denied->deny($now->modify('-5 days'), 'You already have one. Ask me again in the spring.');
            $manager->persist($denied);

            // FR-096: a denial nobody made. Its `parentNotes` are null by construction — see
            // `PurchaseApprovalRequest::expire()`, which takes no note for exactly this reason.
            $expired = PurchaseApprovalRequest::awaitingApproval(
                $maya,
                $maya->getOwner(),
                'stand-in:fixture-five-a-side',
                'Five-a-side league, spring',
                Money::tokens(8),
                PaymentType::Token,
                $now->modify('-6 days'),
                $now->modify('-4 days'),
            );
            $expired->expire($now->modify('-4 days'));
            $manager->persist($expired);
        }

        if ($mateo instanceof PlayerProfile) {
            // BR-096: the waiver is on this child and no other.
            $setting = new ChildSpendingSetting($mateo, $now->modify('-1 month'));
            $setting->decide(true, $mateo->getOwner(), $now->modify('-1 month'));
            $manager->persist($setting);

            // FR-092's other branch: processed immediately, parent informed rather than asked.
            $waived = PurchaseApprovalRequest::withoutApproval(
                $mateo,
                $mateo->getOwner(),
                'stand-in:fixture-skills-session',
                'Extra skills session',
                Money::tokens(3),
                PaymentType::Token,
                $now->modify('-2 days'),
            );
            $waived->recordPayment('fake-approval-fixture-skills-session', $now->modify('-2 days'));
            $manager->persist($waived);
        }

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [ProfileFixtures::class];
    }

    private function profileNamed(ObjectManager $manager, string $displayName): ?PlayerProfile
    {
        $profile = $manager->getRepository(PlayerProfile::class)->findOneBy(['displayName' => $displayName]);

        return $profile instanceof PlayerProfile ? $profile : null;
    }
}
