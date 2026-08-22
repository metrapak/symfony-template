<?php

declare(strict_types=1);

namespace App\Tests\Account\Functional\Admin;

use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use App\Account\Service\UserDirectoryQuery;

/**
 * FR-020 — the global user directory.
 */
final class UserDirectoryTest extends AdminWebTestCase
{
    public function testListsEveryUserWithTheColumnsTheSpecNames(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('trainer@example.com', UserRole::Trainer, name: 'Tara Trainer');

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Name', 'Email', 'Role', 'Status', 'Created', 'Actions'],
            $crawler->filter('table thead th')->each(static fn ($th): string => trim($th->text())),
        );
        self::assertSelectorTextContains('table', 'Tara Trainer');
        self::assertSelectorTextContains('table', 'trainer@example.com');
        self::assertSelectorTextContains('table', 'Trainer');
    }

    public function testFiltersByRole(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('trainer@example.com', UserRole::Trainer, name: 'Tara Trainer');
        $this->createUser('coach@example.com', UserRole::Coach, name: 'Casey Coach');

        $this->client->request('GET', '/admin/users?role=' . UserRole::Coach->value);

        self::assertSelectorTextContains('table', 'Casey Coach');
        self::assertSelectorTextNotContains('table', 'Tara Trainer');
    }

    public function testFiltersByStatus(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('active@example.com', UserRole::Player, name: 'Active Player');
        $this->createUser('inactive@example.com', UserRole::Player, UserStatus::Inactive, name: 'Dormant Player');

        $this->client->request('GET', '/admin/users?status=' . UserStatus::Inactive->value);

        self::assertSelectorTextContains('table', 'Dormant Player');
        self::assertSelectorTextNotContains('table', 'Active Player');
    }

    /**
     * FR-024: a deactivated user still appears in the tool, marked rather than removed.
     */
    public function testInactiveUsersAreListedByDefaultAndMarked(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('inactive@example.com', UserRole::Player, UserStatus::Inactive, name: 'Dormant Player');

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertSelectorTextContains('table', 'Dormant Player');
        self::assertCount(1, $crawler->filter('tr.status-inactive'));
    }

    /**
     * Anonymized rows are not interesting to an operator by default, and there are no names
     * left in them to recognize — but they stay one filter away rather than being unreachable.
     */
    public function testDeletedUsersAreHiddenUntilExplicitlyRequested(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('deleted@example.com', UserRole::Player, UserStatus::Deleted, name: 'Deleted User');

        $this->client->request('GET', '/admin/users');
        self::assertSelectorTextNotContains('table', 'deleted@example.com');

        $this->client->request('GET', '/admin/users?status=' . UserStatus::Deleted->value);
        self::assertSelectorTextContains('table', 'deleted@example.com');
    }

    public function testSearchMatchesNameAndEmailAndNothingElse(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('jane@example.com', UserRole::Player, name: 'Jane Doe');
        $this->createUser('bob@example.com', UserRole::Player, name: 'Bob Roberts');

        $this->client->request('GET', '/admin/users?q=jane');
        self::assertSelectorTextContains('table', 'Jane Doe');
        self::assertSelectorTextNotContains('table', 'Bob Roberts');

        $this->client->request('GET', '/admin/users?q=bob@example');
        self::assertSelectorTextContains('table', 'Bob Roberts');
        self::assertSelectorTextNotContains('table', 'Jane Doe');

        // Tool-scoped (FR-020): the role is a column, not a searchable field, so a search for
        // it must not behave like a filter.
        $this->client->request('GET', '/admin/users?q=ROLE_PLAYER');
        self::assertSelectorTextContains('table', 'No users match these filters.');
    }

    /**
     * A search term is data, not syntax: `%` is a literal percent sign to the operator typing
     * it, and must not silently match every row.
     */
    public function testSearchTreatsLikeWildcardsAsLiterals(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('jane@example.com', UserRole::Player, name: 'Jane Doe');

        $this->client->request('GET', '/admin/users?q=%25');

        self::assertSelectorTextContains('table', 'No users match these filters.');
    }

    /**
     * A filter and a search have to compose. Without explicit parentheses around the search's
     * OR, the role predicate would be swallowed by operator precedence and this returns Bob.
     */
    public function testSearchCombinesWithTheRoleFilterRatherThanOverridingIt(): void
    {
        $this->signInAsSuperAdmin();
        $this->createUser('jane@example.com', UserRole::Trainer, name: 'Jane Doe');
        $this->createUser('bob@example.com', UserRole::Player, name: 'Bob Doe');

        $this->client->request('GET', '/admin/users?q=doe&role=' . UserRole::Trainer->value);

        self::assertSelectorTextContains('table', 'Jane Doe');
        self::assertSelectorTextNotContains('table', 'Bob Doe');
    }

    public function testPaginatesRatherThanRenderingEveryUser(): void
    {
        $this->signInAsSuperAdmin();

        for ($i = 0; $i < UserDirectoryQuery::PAGE_SIZE + 5; ++$i) {
            $this->createUser(\sprintf('player%02d@example.com', $i), UserRole::Player, name: \sprintf('Player %02d', $i));
        }

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertCount(UserDirectoryQuery::PAGE_SIZE, $crawler->filter('table tbody tr'));

        $crawler = $this->client->request('GET', '/admin/users?page=2');
        self::assertGreaterThan(0, $crawler->filter('table tbody tr')->count());
        self::assertLessThan(UserDirectoryQuery::PAGE_SIZE, $crawler->filter('table tbody tr')->count());
    }

    /**
     * The sort field is interpolated into DQL. An unknown value must fall back, not reach the
     * parser — this is the assertion that stands between the query string and a DQL injection.
     */
    public function testUnknownSortFieldFallsBackInsteadOfReachingDql(): void
    {
        $this->signInAsSuperAdmin();

        $this->client->request('GET', '/admin/users?sort=u.password) OR (1=1&direction=desc');

        self::assertResponseIsSuccessful();
    }

    public function testSortDirectionIsHonouredForWhitelistedFields(): void
    {
        $this->signInAsSuperAdmin('admin@example.com', 'Ada Admin');
        $this->createUser('zoe@example.com', UserRole::Player, name: 'Zoe Zeta');
        $this->createUser('amy@example.com', UserRole::Player, name: 'Amy Alpha');

        $crawler = $this->client->request('GET', '/admin/users?sort=u.name&direction=asc');
        $names = $crawler->filter('table tbody tr td:first-child')->each(static fn ($td): string => trim($td->text()));

        self::assertSame(['Ada Admin', 'Amy Alpha', 'Zoe Zeta'], $names);
    }
}
