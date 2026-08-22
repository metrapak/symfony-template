# TASK-003 Architecture: ShareLink Invitations & Organization Membership

**Inputs**: `tasks/TASK-003/requirements-analyst-requirements.md` (FR-040…049, NFR-040…043, BR-040…047),
`specs/Epic-01_User_Management_Authentication_SPEC.md` (US-01.02, US-01.06, US-01.08, §8, §9, §10 flows 1 and 5),
`specs/architect-architecture.md` (the epic-level decisions this task must not contradict).

**Scope**: decisions binding the implementation of TASK-003. Decisions that outlive this task are promoted
to `specs/architect-architecture.md` now that the task has landed.

**Status legend**: `Decided` — approved, not yet implemented. `Implemented` — in the codebase and verified.

---

## Gap resolutions applied

The requirements file left several questions open whose answers change the shape of the code. **These were
not confirmed with the requester** — the task was implemented in one pass — so each is an explicit,
reversible assumption recorded here and carried to the epic gap list.

| Gap | Question | Assumption taken | Cost of reversing |
|:----|:---------|:-----------------|:------------------|
| G-19 | What happens to players who already used a link the trainer then deactivates? | **They keep their place.** Deactivation blocks future redemptions only. | None. The alternative (cascade-deactivate associations) is a new service method plus a UI decision. |
| G-20 | How does a coach move between trainers? | **Not modelled as a workflow.** `CoachAssignment::end()` exists and the partial index permits a replacement, but no screen calls it. | Low. A trainer-side "remove coach" action plus a decision about who may end an assignment. |
| G-21 | Is the registrant registering themselves or a child? | **The form asks.** An explicit self/child radio, not an inference from the age. | Low, and asking is the safe default: the two branches write different rows. |
| Q-01.02 | Age group definitions | **Birth date is stored, age is derived.** Spec §9's "1-18 years" validates the derived age. | Low while age groups stay undefined; a stored integer age would have rotted annually. |
| Q-01.04 | Which automated emails are required | **Three ship**: coach invitation, registration confirmation, parent notification for a blocked child link. | Template-level. |
| — | May a trainer hold several active player links? | **Yes.** The requirements assumed one per organization; several is strictly more permissive and matches how a printed code and a campaign code coexist. | Adding a constraint later invalidates links already in circulation. Flagged as the riskiest of these assumptions. |

---

## D1 — Two new modules: `Membership` and a seeded `Profile`

**Status**: Implemented

`src/Membership/` holds everything about invitations and who belongs to whom: `ShareLink`,
`TrainerPlayerAssociation`, `CoachAssignment`, `ShareLinkRedemption`, their services, and the public
redemption flow. Flat layers, registered explicitly in `config/routes.yaml` and
`config/packages/doctrine.yaml`, per the epic's code-organization decision.

`src/Profile/` holds exactly one entity, `PlayerProfile`, and its repository. It is **TASK-004's module,
seeded here**: an association needs something to associate, and TASK-003 shipped first. Placing the entity
in its eventual home rather than inside `Membership` avoids a move — and a migration to rename its
table — when TASK-004 extends it with skill level, school, photo and emergency contact.

`PlayerProfile` carries only what the invitation flow writes: `owner` (the account that manages the
profile), `account` (the login it signs in as, null for a child without one), `displayName`, `birthDate`,
`gender`, `child`. The two user columns answer different questions, and FR-044 and FR-048 each need one of
them: family selection reads `owner`, the child block reads `account`.

## D2 — One table for both link types

**Status**: Implemented

A player link is `maxUses = null, expiresAt = null`; a coach invitation is `maxUses = 1, expiresAt = now +
7 days` addressed to one email. Two tables would duplicate the code column, the usage counter, the
resolver and every query, to express a difference two nullable columns already express. Spec §8 lists the
columns as one set, which is the same conclusion.

## D3 — Consumption is one conditional UPDATE, never read-then-write

**Status**: Implemented

`ShareLinkRepository::consume()` runs

```sql
UPDATE share_link SET use_count = use_count + 1
 WHERE id = :id AND active AND (max_uses IS NULL OR use_count < max_uses)
   AND (expires_at IS NULL OR expires_at > :now)
```

and returns whether exactly one row matched. NFR-041 asks for 100 concurrent redemptions of one link with
no lost associations; the failure mode it is really asking about is a hundred readers each seeing
`use_count = 0` on a single-use invitation and each concluding they are the allowed use. Putting the guard
in the WHERE clause makes the database serialize the row and lets exactly one caller win.

Consequence: the in-memory entity's `useCount` is stale after a consume. Nothing in the request that
consumed it reads the counter again, and the trainer's page loads it fresh.

## D4 — Three constraints carry the correctness, not the services

**Status**: Implemented (`migrations/Version20260822144052.php`)

| Constraint | Requirement | Why the service is not enough |
|:-----------|:------------|:------------------------------|
| `UNIQ_SHARE_LINK_CODE` | FR-040/041 | A code must resolve to one link for the resolver's answer to mean anything. |
| `UNIQ_ASSOCIATION_ORG_PLAYER` | FR-043 | Two concurrent redemptions both pass the "already associated?" read. The index is what makes the second a no-op instead of a duplicate roster row. |
| `UNIQ_COACH_ASSIGNMENT_ACTIVE_COACH` — **partial**, `WHERE status = 'active'` | FR-045, BR-044 | FR-045 says the rule must be "a database constraint plus a service check, not UI-only". A *full* unique index cannot express it: a coach who legitimately leaves one organization for another must keep the ended row. |

The partial index is declared on the entity as well as created in the migration, with its predicate written
the way PostgreSQL stores it (`((status)::text = 'active'::text)`). Doctrine compares that string against
`pg_get_indexdef` output, so the tidier spelling would leave `doctrine:migrations:diff` proposing a
drop-and-recreate forever. TASK-001 met the same wall with functional indexes and resolved it by leaving
them unmapped; here the index is a correctness guarantee rather than a performance one, so it is worth
carrying the ugly string to keep `doctrine:schema:validate` honest about it.

## D5 — `ShareLinkCode` is a value object with 128 bits of entropy

**Status**: Implemented

`random_bytes(16)`, base32 over an alphabet without `I`, `L`, `O` or `U`, 26 characters, validated on
construction. Nothing derives from the row id, the organization or the clock, so holding one code says
nothing about any other. Enumerating that namespace is arithmetic, not a rate-limiting problem — which is
why the limiter on `/join` is described below as protection against load, not against enumeration.

Codes are stored in clear rather than hashed. A hash would make the lookup a scan or require a second
lookup column, and the threat it defends against — a stolen database — has already lost the accounts these
links create.

## D6 — Failure is uniform, with one deliberate exception

**Status**: Implemented

`ShareLinkResolver` collapses four situations into three states. Malformed, unknown, deactivated and
consumed all return `Unusable` **carrying no link at all**, so a controller cannot accidentally render the
organization's name on a page meant to be indistinguishable from a 404. `Expired` is the exception,
required by FR-046 so the holder of a lapsed coach invitation can be told to ask for a new one; it
discloses that a code was once real, to somebody who already had it.

FR-046 and FR-049 do pull against each other here. The resolution is bounded: expiry is the only
distinguishable failure, and it is reachable only with a code that was actually issued.

There is deliberately no `ShareLinkRepository::findActiveByCode()` (the method the requirements named). An
active-only lookup returns null for an expired invitation and for an invented code alike, which is exactly
the distinction FR-046 needs.

## D7 — `RedemptionPlanner` decides, the controller dispatches

**Status**: Implemented

Who may do what with which kind of link is a decision table with six outcomes (register as player, register
as coach, associate a family, accept a coach invitation, block a child, refuse). It lives in a service, so
it has unit tests that need no HTTP kernel, and so the child block cannot be forgotten by a template.

The plan authorizes nothing on its own. Every service it routes to re-checks what it enforces, so a page
rendered before a link was withdrawn cannot authorize the submit that follows it.

## D8 — Registration and association are separate services

**Status**: Implemented

`PlayerRegistrationService` owns the new-account path end to end; `AssociationService` owns the harder
existing-account path, where the account may already be associated, may be a family, and may be clicking
twice. Sharing one service would mean one method with a "does the user exist yet" flag through the middle
of a transaction boundary.

Each owns its own transaction. Account, profiles, association and redemption record commit together
(NFR-041) — a half-registered visitor is the one outcome that cannot be retried, because the email address
is then taken by the row that would have been discarded.

## D9 — A repeat redemption consumes nothing and records nothing

**Status**: Implemented

FR-043 requires re-opening a link you already accepted to be a success that changes nothing. It could still
have been recorded as a redemption; it is not. The usage count then means "people who joined" rather than
"pages viewed", which is the number a trainer reads it as and the number Epic-06 will chart.

A blocked child **is** recorded (FR-047 asks for the outcome explicitly) but does not consume a use: nothing
was granted, and a child must not be able to spend an invitation that was never theirs.

## D10 — The account holder always gets a profile

**Status**: Implemented

Even when a parent registers a child, a self-profile is created for the parent. US-01.03 treats the parent
as a player too, and FR-044's "Me" checkbox needs something to point at the next time that family redeems a
link. Only the person who will actually train is associated with the trainer.

## D11 — `TenantContext`'s coach branch is answered through an inverted dependency

**Status**: Implemented

TASK-001 left `TenantContext` returning null for coaches behind a TODO, because the assignment record did
not exist. It exists now — but `TenantContext` lives in `Account`, which `Membership` depends on, so
reading `CoachAssignmentRepository` from there would make the two modules one.

`Account\Service\CoachOrganizationProvider` is an interface declared by the module that asks the question
and implemented by the module that can answer it. This is the accelerator's "interfaces only at real
substitution boundaries" rule being satisfied by a module boundary rather than by a second implementation.

## D12 — Registration cannot sign the visitor in while verification is required

**Status**: Implemented

FR-042 says a new player "can immediately see that trainer's events". TASK-001 ships
`EMAIL_VERIFICATION_REQUIRED=true`, and the firewall's user checker refuses unverified players and coaches
(Q-01.05, still open with the client). Both cannot be true at once.

`JoinController::finishRegistration()` attempts the programmatic login and falls back to the verification
notice, so the behaviour follows the configuration rather than a hardcoded assumption: with the gate off
the visitor lands on their dashboard, with it on they are told to check their inbox. Two emails are sent —
the confirmation FR-042 asks for, and the verification link that makes the account usable — because a
confirmation that does not mention the blocker sends people to an inbox with nothing to act on.

## D13 — Rate limiting is load protection, not the security boundary

**Status**: Implemented

`join_redemption` (30 / 15 min per IP) and `join_registration` (10 / hour per IP) are keyed on the client
IP and stored in the same node-local pool as TASK-001's login throttling (R2 applies unchanged). They stop
a scanner turning a public endpoint into free database load and bound the blast radius of any future
code-format mistake. What stops enumeration is D5.

## D14 — Authorization: one `access_control` rule and one voter

**Status**: Implemented

`^/join` is `PUBLIC_ACCESS`; everything a signed-in visitor may do there is decided per account by D7.
`^/trainer` is already `ROLE_TRAINER`, which is precisely the check that protects nothing when the URL
carries an id — every trainer holds that role. `ShareLinkVoter` authorizes the object, reading ownership
from the link's organization rather than from `createdBy`, because the organization is the tenant boundary.

## D15 — Coach invitations are re-issued in place

**Status**: Implemented

A resend rotates the code, resets the expiry and clears the usage count on the same row, and inviting an
address that already has an outstanding invitation re-issues that one. The Coaches list is then one line
per invited coach with a derived Pending / Accepted / Expired status, rather than one line per attempt.
The previous URL stops working the moment the new one is issued, which is what a resend should mean.

---

## Deviations from the requirements file

| Requirement text | What shipped | Why |
|:-----------------|:-------------|:----|
| `ShareLinkRepository::findActiveByCode` | `findOneByCode` plus resolver states | See D6: an active-only lookup cannot distinguish expired from unknown, which FR-046 requires. |
| "Concurrency: 100 simultaneous redemptions" as a test | Sequential proof that a single-use link consumes exactly once, plus the constraints in D4 | DAMA wraps each test in a transaction a second connection cannot see, so a real parallel test needs a load harness against a deployed instance. Recorded as an open verification item rather than claimed. |
| One active player link per organization (the requirements' stated assumption) | Several permitted | See the gap table above. |
