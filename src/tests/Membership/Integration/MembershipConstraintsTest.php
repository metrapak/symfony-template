<?php

declare(strict_types=1);

namespace App\Tests\Membership\Integration;

use App\Account\Entity\Organization;
use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Membership\Entity\ShareLink;
use App\Membership\Entity\TrainerPlayerAssociation;
use App\Membership\Enum\ShareLinkType;
use App\Membership\Repository\ShareLinkRepository;
use App\Membership\ValueObject\ShareLinkCode;
use App\Profile\Entity\PlayerProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The guarantees that live in the database rather than in a service (FR-045, FR-043, NFR-041).
 *
 * Every check here goes around the application code on purpose. A rule enforced only by the
 * service that usually writes the row is a rule a fixture, a console command or next year's
 * code path can break silently; these tests assert the constraint itself.
 */
final class MembershipConstraintsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
    }

    /**
     * BR-044 / FR-045: "enforcement is a database constraint plus a service check, not
     * UI-only". This is the database half, tested by inserting straight into the table.
     */
    public function testThePartialIndexRefusesASecondActiveAssignmentForOneCoach(): void
    {
        $coach = $this->user('coach@example.com', UserRole::Coach);
        $first = $this->organization('trainer-one@example.com', 'Northside Academy');
        $second = $this->organization('trainer-two@example.com', 'Southside Sports');

        $this->insertAssignment($first, $coach, 'active');

        $this->connection->beginTransaction();

        try {
            $this->insertAssignment($second, $coach, 'active');
            $this->connection->rollBack();
            self::fail('The database accepted a coach as active under two organizations.');
        } catch (UniqueConstraintViolationException) {
            $this->connection->rollBack();
        }

        self::assertSame(1, $this->countAssignmentsFor($coach));
    }

    /**
     * And the other half of "partial": a coach who left may be hired again. A plain unique
     * index would forbid this forever, which is why the predicate is there.
     */
    public function testAnEndedAssignmentDoesNotBlockANewOne(): void
    {
        $coach = $this->user('coach@example.com', UserRole::Coach);
        $first = $this->organization('trainer-one@example.com', 'Northside Academy');
        $second = $this->organization('trainer-two@example.com', 'Southside Sports');

        $this->insertAssignment($first, $coach, 'inactive');
        $this->insertAssignment($second, $coach, 'active');

        self::assertSame(2, $this->countAssignmentsFor($coach));
    }

    /**
     * FR-043's idempotency rests on this index, not on the service's read-before-write.
     */
    public function testOnePlayerCannotBeAssociatedTwiceWithOneOrganization(): void
    {
        $organization = $this->organization('trainer@example.com', 'Northside Academy');
        $player = $this->user('pat@example.com');
        $profile = PlayerProfile::forSelf($player, 'Pat Player', new \DateTimeImmutable());
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        $this->entityManager->persist(new TrainerPlayerAssociation($organization, $profile, null, new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO trainer_player_association (id, organization_id, player_profile_id, connected_at, status)
                    VALUES (nextval('trainer_player_association_id_seq'), :organization, :profile, :now, 'active')
                    SQL,
                [
                    'organization' => $organization->getId(),
                    'profile' => $profile->getId(),
                    'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
            $this->connection->rollBack();
            self::fail('The database accepted a duplicate trainer-player association.');
        } catch (UniqueConstraintViolationException) {
            $this->connection->rollBack();
        }

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM trainer_player_association WHERE organization_id = :organization AND player_profile_id = :profile',
            ['organization' => $organization->getId(), 'profile' => $profile->getId()],
        ));
    }

    /**
     * NFR-041 in the only form a single-process test can prove it: consumption is one
     * conditional UPDATE, so the second caller is told no rather than both reading `0` and
     * both deciding they were the allowed use.
     *
     * True concurrency is not asserted here. DAMA wraps each test in a transaction that a
     * second connection cannot see, so parallel redemptions of one link need a load test
     * against a real deployment; what is provable in-process is that no caller can succeed
     * twice against a single-use link.
     */
    public function testASingleUseLinkCanBeConsumedExactlyOnce(): void
    {
        $link = $this->coachLink();
        $repository = static::getContainer()->get(ShareLinkRepository::class);
        $now = new \DateTimeImmutable();

        self::assertTrue($repository->consume($link, $now));
        self::assertFalse($repository->consume($link, $now), 'A spent invitation must not be spendable again.');

        self::assertSame(1, $this->useCountOf($link));
    }

    public function testAStaticPlayerLinkNeverRunsOutAndCountsEveryUse(): void
    {
        $link = $this->playerLink();
        $repository = static::getContainer()->get(ShareLinkRepository::class);
        $now = new \DateTimeImmutable();

        for ($use = 0; $use < 5; ++$use) {
            self::assertTrue($repository->consume($link, $now));
        }

        self::assertSame(5, $this->useCountOf($link));
    }

    public function testConsumingRefusesDeactivatedAndExpiredLinks(): void
    {
        $repository = static::getContainer()->get(ShareLinkRepository::class);
        $now = new \DateTimeImmutable();

        $deactivated = $this->playerLink();
        $deactivated->deactivate($now);
        $this->entityManager->flush();
        self::assertFalse($repository->consume($deactivated, $now));

        $expired = $this->coachLink($now->modify('-1 minute'));
        self::assertFalse($repository->consume($expired, $now));
    }

    private function user(string $email, UserRole $role = UserRole::Player): User
    {
        $user = new User($email, ucfirst(strstr($email, '@', true) ?: $email), $role, new \DateTimeImmutable());
        $user->setPassword('not-a-real-hash');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function organization(string $ownerEmail, string $name): Organization
    {
        $organization = new Organization($name, $this->user($ownerEmail, UserRole::Trainer), new \DateTimeImmutable());

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        return $organization;
    }

    private function playerLink(): ShareLink
    {
        $organization = $this->organization('trainer-' . uniqid() . '@example.com', 'Northside Academy');

        $link = new ShareLink(
            ShareLinkCode::generate(),
            ShareLinkType::Player,
            $organization,
            $organization->getOwner(),
            new \DateTimeImmutable(),
        );

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $link;
    }

    private function coachLink(?\DateTimeImmutable $expiresAt = null): ShareLink
    {
        $organization = $this->organization('trainer-' . uniqid() . '@example.com', 'Northside Academy');
        $now = new \DateTimeImmutable();

        $link = new ShareLink(
            ShareLinkCode::generate(),
            ShareLinkType::Coach,
            $organization,
            $organization->getOwner(),
            $now,
        );
        $link->addressTo('coach@example.com', null, null)->expiresOn($expiresAt ?? $now->modify('+7 days'));

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $link;
    }

    private function insertAssignment(Organization $organization, User $coach, string $status): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO coach_assignment (id, organization_id, coach_id, joined_at, status, ended_at)
                VALUES (nextval('coach_assignment_id_seq'), :organization, :coach, :now, :status, :endedAt)
                SQL,
            [
                'organization' => $organization->getId(),
                'coach' => $coach->getId(),
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'status' => $status,
                'endedAt' => 'inactive' === $status ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            ],
        );
    }

    private function countAssignmentsFor(User $coach): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM coach_assignment WHERE coach_id = :coach',
            ['coach' => $coach->getId()],
        );
    }

    private function useCountOf(ShareLink $link): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT use_count FROM share_link WHERE id = :id',
            ['id' => $link->getId()],
        );
    }
}
