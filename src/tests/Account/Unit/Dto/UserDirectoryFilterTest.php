<?php

declare(strict_types=1);

namespace App\Tests\Account\Unit\Dto;

use App\Account\Dto\UserDirectoryFilter;
use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * FR-020 — the directory's query-string state, and the whitelist that stands between it and
 * the ORDER BY clause.
 */
final class UserDirectoryFilterTest extends TestCase
{
    public function testReadsEveryFilterFromTheQueryString(): void
    {
        $filter = UserDirectoryFilter::fromRequest(Request::create('/admin/users', 'GET', [
            'role' => UserRole::Coach->value,
            'status' => UserStatus::Inactive->value,
            'q' => '  Jane  ',
            'sort' => 'u.name',
            'direction' => 'asc',
        ]));

        self::assertSame(UserRole::Coach, $filter->role);
        self::assertSame(UserStatus::Inactive, $filter->status);
        self::assertSame('Jane', $filter->term);
        self::assertSame('u.name', $filter->sort);
        self::assertSame('asc', $filter->direction);
    }

    public function testAnEmptyQueryStringMeansNoFilters(): void
    {
        $filter = UserDirectoryFilter::fromRequest(Request::create('/admin/users'));

        self::assertNull($filter->role);
        self::assertNull($filter->status);
        self::assertNull($filter->term);
        self::assertNull($filter->sort);
    }

    /**
     * A stale bookmark or a hand-edited URL should show the directory in its default order,
     * not a 500 — and above all, an unrecognized value must not reach the DQL parser.
     */
    public function testAnUnrecognizedSortFieldIsDiscarded(): void
    {
        foreach (['u.password', 'password', 'u.name) OR (1=1', ''] as $hostile) {
            $filter = UserDirectoryFilter::fromRequest(
                Request::create('/admin/users', 'GET', ['sort' => $hostile]),
            );

            self::assertNull($filter->sort, \sprintf('"%s" was accepted as a sort field.', $hostile));
        }
    }

    public function testEverySortableFieldIsAccepted(): void
    {
        foreach (UserDirectoryFilter::SORTABLE_FIELDS as $field) {
            $filter = UserDirectoryFilter::fromRequest(
                Request::create('/admin/users', 'GET', ['sort' => $field]),
            );

            self::assertSame($field, $filter->sort);
        }
    }

    public function testTheDefaultSortFieldIsItselfOnTheWhitelist(): void
    {
        // Otherwise the paginator's own allow-list check would reject the default it was
        // given, and the directory would fail with no filters applied at all.
        self::assertContains(UserDirectoryFilter::DEFAULT_SORT_FIELD, UserDirectoryFilter::SORTABLE_FIELDS);
    }

    public function testDirectionIsNormalizedToAscendingOrDescending(): void
    {
        foreach (['ASC' => 'asc', 'asc' => 'asc', 'desc' => 'desc', 'sideways' => 'desc', '' => 'desc'] as $input => $expected) {
            $filter = UserDirectoryFilter::fromRequest(
                Request::create('/admin/users', 'GET', ['direction' => (string) $input]),
            );

            self::assertSame($expected, $filter->direction);
        }
    }

    public function testAnUnknownRoleOrStatusIsIgnoredRatherThanFatal(): void
    {
        $filter = UserDirectoryFilter::fromRequest(Request::create('/admin/users', 'GET', [
            'role' => 'ROLE_WIZARD',
            'status' => 'exploded',
        ]));

        self::assertNull($filter->role);
        self::assertNull($filter->status);
    }
}
