<?php

declare(strict_types=1);

namespace App\Account\Dto;

use App\Account\Enum\UserRole;
use App\Account\Enum\UserStatus;
use Symfony\Component\HttpFoundation\Request;

/**
 * The directory's query-string state, resolved once (FR-020).
 *
 * A readonly value object rather than four `$request->query->get()` calls scattered through
 * the controller and the repository: an unknown role or status becomes null here — a stale
 * bookmark shows the unfiltered directory instead of a 500 — and nothing downstream has to
 * ask whether the value it holds was validated.
 */
final readonly class UserDirectoryFilter
{
    /**
     * The columns the directory may be sorted by, as the DQL paths KnpPaginator's sortable
     * links emit.
     *
     * A whitelist rather than sanitization: the paginator interpolates this value straight
     * into the ORDER BY clause, so anything not enumerated here would be reaching the DQL
     * parser from the query string.
     *
     * @var list<string>
     */
    public const SORTABLE_FIELDS = ['u.name', 'u.email', 'u.role', 'u.status', 'u.createdAt', 'u.lastLoginAt'];

    public const DEFAULT_SORT_FIELD = 'u.createdAt';
    public const DEFAULT_SORT_DIRECTION = 'desc';

    public function __construct(
        public ?UserRole $role = null,
        public ?UserStatus $status = null,
        public ?string $term = null,
        public ?string $sort = null,
        public ?string $direction = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            role: UserRole::tryFrom((string) $request->query->get('role', '')),
            status: UserStatus::tryFrom((string) $request->query->get('status', '')),
            term: trim((string) $request->query->get('q', '')) ?: null,
            sort: self::sortField($request),
            direction: 'asc' === mb_strtolower((string) $request->query->get('direction', '')) ? 'asc' : 'desc',
        );
    }

    /**
     * An unrecognized value becomes null rather than an error: a stale bookmark should show
     * the directory in its default order, not a 500.
     */
    private static function sortField(Request $request): ?string
    {
        $requested = (string) $request->query->get('sort', '');

        return \in_array($requested, self::SORTABLE_FIELDS, true) ? $requested : null;
    }
}
