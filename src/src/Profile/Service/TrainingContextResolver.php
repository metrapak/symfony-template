<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Account\Entity\User;
use App\Account\Enum\UserRole;
use App\Profile\Dto\AssociationRecord;
use App\Profile\Dto\TrainingContextOption;
use App\Profile\Entity\PlayerProfile;
use App\Profile\Exception\ContextNotAvailable;
use App\Profile\Repository\PlayerProfileRepository;
use App\Profile\ValueObject\TrainingContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Which (player, trainer) contexts a user has, which one is selected, and whether they may
 * have it (FR-069, FR-070, NFR-063).
 *
 * **This is a security boundary, not a UI convenience.** Everything else about separated views
 * follows from `assertAccess()`: a request that names a context is allowed to proceed only if
 * the current user genuinely holds an active association for that exact (profile,
 * organization) pair. Three properties make that hold, and each is here rather than at a call
 * site:
 *
 *  - **The session is a cache, never an authority.** `current()` re-authorizes the stored
 *    selection on every read and forgets it if it no longer holds, so a parent who removes a
 *    child from a trainer stops seeing that context on their next request rather than at
 *    session expiry. A selection that was legitimate when it was made is not evidence that it
 *    still is.
 *  - **Availability is computed from associations, never from the request.** The switcher's
 *    options and the authorization check read the same list from the same gateway, so a
 *    context cannot be reachable while being unlisted, or listed while being unreachable.
 *  - **A child sees only their own.** A child login resolves contexts from *their profile*
 *    rather than from what their owner manages, which is FR-068's "cannot view the parent's
 *    training data" enforced in the one place that decides what a request may see, instead of
 *    in each screen that renders it.
 *
 * `availableFor()` also produces the three switcher shapes US-01.04 draws — parent who trains,
 * parent who does not, child — as one list plus a grouping flag. They are unit-tested here
 * because they are a rule about families, not a template detail.
 */
final class TrainingContextResolver
{
    /**
     * Namespaced so it cannot collide with the firewall's own session keys, and versioned by
     * shape: a stored value from an older format is unparseable rather than misread.
     */
    public const SESSION_KEY = 'app_training_context';

    /**
     * Per-request memo, keyed by user id.
     *
     * Not premature: rendering one page calls `availableFor()` from the switcher partial, from
     * `current()`, and again from whatever authorizes the context — three identical queries for
     * a list that cannot change mid-request. NFR-061 gives the dashboard two seconds in any
     * context, and this is the cheapest second of it. The service is request-scoped, so the
     * memo dies with the request and cannot serve one user another's contexts.
     *
     * @var array<int, list<AssociationRecord>>
     */
    private array $recordCache = [];

    public function __construct(
        private readonly TrainerAssociationGateway $associations,
        private readonly PlayerProfileRepository $profiles,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Every context this user may act in, ordered so their own come before their children's
     * and each player's trainers stay in the order they joined.
     *
     * @return list<TrainingContextOption>
     */
    public function availableFor(User $user): array
    {
        return array_map(
            static fn (AssociationRecord $record): TrainingContextOption => new TrainingContextOption(
                context: new TrainingContext($record->playerProfileId, $record->organizationId),
                playerName: $record->playerName,
                organizationName: $record->organizationName,
                own: $record->ownProfile,
                connectedAt: $record->connectedAt,
            ),
            $this->recordsFor($user),
        );
    }

    /**
     * The contexts belonging to the user themself — US-01.04's "Your Training" group.
     *
     * @return list<TrainingContextOption>
     */
    public function ownContextsFor(User $user): array
    {
        return array_values(array_filter($this->availableFor($user), static fn (TrainingContextOption $o): bool => $o->own));
    }

    /**
     * The contexts belonging to the user's children — "Your Children's Training".
     *
     * @return list<TrainingContextOption>
     */
    public function childContextsFor(User $user): array
    {
        return array_values(array_filter($this->availableFor($user), static fn (TrainingContextOption $o): bool => !$o->own));
    }

    /**
     * The selected context, or the first available one when nothing is selected yet.
     *
     * Defaulting rather than returning null is deliberate: FR-070 says there is no combined
     * view anywhere in the platform, so "no context" is not a state any screen can render. A
     * user with no associations at all genuinely has none, and gets null — that is the empty
     * family, not an unselected one.
     */
    public function current(User $user): ?TrainingContextOption
    {
        $available = $this->availableFor($user);

        if ([] === $available) {
            return null;
        }

        $stored = TrainingContext::tryParse($this->session()?->get(self::SESSION_KEY));

        if (null !== $stored) {
            foreach ($available as $option) {
                if ($option->context->equals($stored)) {
                    return $option;
                }
            }

            // Held a context the user no longer has. Dropped rather than kept, so the next
            // request does not re-check a selection that has already been refused once.
            $this->session()?->remove(self::SESSION_KEY);
        }

        return $available[0];
    }

    /**
     * Selects a context, refusing anything the user does not hold (FR-070, NFR-063).
     *
     * @throws ContextNotAvailable
     */
    public function switchTo(User $user, ?TrainingContext $context): TrainingContextOption
    {
        $option = $this->assertAccess($user, $context);

        $this->session()?->set(self::SESSION_KEY, $option->context->toKey());

        return $option;
    }

    /**
     * The gate every context-scoped read passes through.
     *
     * Returns the option rather than a boolean so a caller cannot check access and then use a
     * different context than the one it checked — the authorized value and the usable value
     * are the same object.
     *
     * @throws ContextNotAvailable when the pair is malformed, unknown, another family's, or
     *                             no longer associated
     */
    public function assertAccess(User $user, ?TrainingContext $context): TrainingContextOption
    {
        if (null === $context) {
            throw ContextNotAvailable::forContext(null);
        }

        foreach ($this->availableFor($user) as $option) {
            if ($option->context->equals($context)) {
                return $option;
            }
        }

        throw ContextNotAvailable::forContext($context);
    }

    /**
     * Clears the stored selection.
     *
     * Called when an association is removed: the parent may still hold the context they were
     * looking at, and re-resolving on the next request is cheaper to reason about than working
     * out whether the removed one was the selected one.
     */
    public function forget(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
        $this->recordCache = [];
    }

    /**
     * @return list<AssociationRecord>
     */
    private function recordsFor(User $user): array
    {
        $userId = (int) $user->getId();

        if (isset($this->recordCache[$userId])) {
            return $this->recordCache[$userId];
        }

        return $this->recordCache[$userId] = $this->loadRecordsFor($user);
    }

    /**
     * @return list<AssociationRecord>
     */
    private function loadRecordsFor(User $user): array
    {
        // Only a player-role account has training contexts at all: a trainer has an
        // organization, a coach has an assignment, and a Super Admin has neither. Answering
        // for them would invite a screen to treat "no contexts" as an error rather than as the
        // right answer for that role.
        if (UserRole::Player !== $user->getRole()) {
            return [];
        }

        $profile = $this->profiles->findProfileForAccount($user);

        // FR-068: a child login is scoped to its own profile. Checked before the owner path,
        // because a child holds ROLE_PLAYER like anyone else and `findManagedBy` would
        // otherwise hand it whatever its account happens to own.
        if (null !== $profile && $profile->isChild()) {
            return $this->associations->activeAssociationsForProfile($profile);
        }

        return $this->associations->activeAssociationsForOwner($user);
    }

    /**
     * The profile a context points at, once access to that context has been asserted.
     *
     * Loading it any other way is the mistake this method exists to prevent: an id taken
     * straight from a request and passed to `find()` is FR-070's forged identifier.
     *
     * @throws ContextNotAvailable
     */
    public function profileFor(User $user, ?TrainingContext $context): PlayerProfile
    {
        $option = $this->assertAccess($user, $context);

        return $this->profiles->findOneById($option->context->playerProfileId)
            ?? throw ContextNotAvailable::forContext($context);
    }

    private function session(): ?SessionInterface
    {
        // Null in a console command or a request with no session started. Returning null
        // rather than throwing keeps the resolver usable from a CLI context, where "which
        // context is selected" has no answer and the caller is not asking for one.
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
