<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Dto\UserDirectoryFilter;
use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * The read side of the Users tool (FR-020, NFR-020).
 *
 * Thin on purpose: the query shape belongs to the repository, and paginating it is the only
 * thing left. It exists as a service rather than as three lines in the controller so the page
 * size is decided in one place and the controller keeps no knowledge of the paginator.
 */
final readonly class UserDirectoryQuery
{
    public const PAGE_SIZE = 25;

    public function __construct(
        private UserRepository $users,
        private PaginatorInterface $paginator,
    ) {
    }

    /**
     * @return PaginationInterface<int, User>
     */
    public function search(UserDirectoryFilter $filter, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->users->directoryQuery($filter),
            max(1, $page),
            self::PAGE_SIZE,
            [
                // Defence in depth. The controller has already dropped an unrecognized sort
                // field from the query string; this makes the paginator refuse it too, so a
                // future caller that forgets cannot hand the DQL parser a query-string value.
                PaginatorInterface::SORT_FIELD_ALLOW_LIST => UserDirectoryFilter::SORTABLE_FIELDS,
                PaginatorInterface::DEFAULT_SORT_FIELD_NAME => UserDirectoryFilter::DEFAULT_SORT_FIELD,
                PaginatorInterface::DEFAULT_SORT_DIRECTION => UserDirectoryFilter::DEFAULT_SORT_DIRECTION,
            ],
        );
    }
}
