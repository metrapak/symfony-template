# Architecture

System design decisions for the platform. Scope: decisions that bind more than one task or epic.

**Status legend**: `Decided` — approved, not yet implemented. `Implemented` — in the codebase and verified.

## Stack

| Concern | Choice |
|:--------|:-------|
| Framework | Symfony 7.4 LTS, PHP >= 8.2 |
| Persistence | PostgreSQL via Doctrine ORM 2.15, schema changes by migration only |
| Delivery | Server-rendered Twig with Stimulus/Turbo progressive enhancement. **No JSON API** |
| Layering | Pragmatic `Controller -> Service -> Repository` (see `AGENTS.md`) |
| Identifiers | Integer auto-increment, consistent across all entities. Not UUIDs |

## Component: Code Organization

**Status**: Implemented (Epic-01 TASK-001)

Feature modules under `src/<Module>/` with **flat layers** (`Controller/`, `Service/`, `Repository/`,
`Entity/`, `Enum/`, `Dto/`, `Form/`, `Security/`), matching the existing `App\Videos` module.

Both `config/packages/doctrine.yaml` and `config/routes.yaml` register each module **explicitly**. Adding a
module requires an entry in both; an entry pointing at a directory that no longer exists breaks container
compilation.

The Domain/Application/Infrastructure split used by `App\Products` and `App\Starships` is **not** the
pattern for new work. It adds directories without adding a boundary these features need.

## Component: Authentication

**Status**: Implemented (Epic-01 TASK-001)

- Session-based `form_login` on the `main` firewall. No token issuance, no stateless authentication.
- Password reset via `symfonycasts/reset-password-bundle` (selector/verifier split; the emailed token is never stored, only its hash). Lifetime 1 hour, single use.
- Email verification via `symfonycasts/verify-email-bundle` — **stateless signed URLs**, so there is no verification token table. Lifetime 24 hours. Verification is idempotent: a signed URL stays valid until expiry even after use, so a repeat click is a no-op rather than an error.
- Login throttling via `login_throttling` (5 attempts / 15 min per username+IP, plus a global per-IP limiter). Counters live in the `cache.rate_limiter` pool, backed by `cache.app` (filesystem) and therefore **per-node** — a shared store is required before horizontal scaling. Tests must clear that pool explicitly: it outlives the per-test database rollback.
- Emails are dispatched **synchronously**; no async Messenger transport is configured.

**Removed**: the `json_login` firewall entry and `ApiLoginController`. They returned a hardcoded placeholder
token. A JSON API, if ever needed, is a separate design.

Implementation notes settled while building this:

- The reset link is a two-step exchange: `GET /password/reset/{token}` parks the token in the session and
  redirects to the tokenless `/password/reset`, so the secret never enters browser history or a `Referer`
  header sent from the form page.
- The verification link carries the user id as a **signed** query parameter (`['id' => ...]` extra param).
  The route has to load the user while anonymous, and covering the id with the signature stops it being
  swapped for another account's.
- `EmailVerificationService::verify()` validates the signature **before** the already-verified no-op.
  Short-circuiting first would let anyone confirm which account ids are verified by guessing.
- Resending a verification link is reachable anonymously and keyed on an email address, because a user who
  cannot sign in until they verify is exactly who needs it. It follows the same
  no-enumeration rule as password reset: one confirmation message for every outcome, and no mail for an
  unknown or already-verified address.
- Verification expiry is asserted against configuration rather than by advancing a clock: the bundle
  compares against `time()`, which `symfony/clock` cannot mock.

## Component: Authorization

**Status**: Implemented (Epic-01 TASK-001)

Four roles: `ROLE_SUPER_ADMIN`, `ROLE_TRAINER`, `ROLE_COACH`, `ROLE_PLAYER`.

**Exactly one role per user, enforced by the schema.** A single `role` column typed by a backed enum;
`getRoles()` derives `[role, 'ROLE_USER']`. There is no `roles` array and no setter for one, so the
invariant cannot be violated by a code path that forgets to validate it.

**`role_hierarchy` is deliberately empty for the four business roles.** A Super Admin inheriting
`ROLE_TRAINER` would enter organization-scoped trainer views with no organization and break them. Super
Admin capability comes from explicit grants on `/admin/*` plus impersonation.

Placement: `access_control` for role-gated route trees; voters only where a real object is being authorized.

## Component: Account Lifecycle

**Status**: Implemented (Epic-01 TASK-001, consumed by TASK-002)

Status is `Active` / `Inactive` / `Deleted`, enforced at authentication by a `UserCheckerInterface`
implementation rather than by controller checks, so it applies to every authentication path.

**Sessions must be invalidated on status or role change.** Symfony's `ContextListener` refreshes the session
user each request but does **not** re-run the user checker, so a deactivated user's existing session would
otherwise keep working. `User` implements `EquatableInterface`, returning false when `status` or `role`
differs from the session copy, which forces de-authentication. Without this, "a deactivated user cannot log
in" holds only for new logins.

Emails are normalized to lowercase on write, and the user provider loads case-insensitively via
`UserLoaderInterface` (`Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface`). Uniqueness is
enforced by the database index, not only by a Validator constraint, because a Validator check cannot close
a concurrency race.

The index stays a **plain** unique index on the normalized column rather than a functional index on
`LOWER(email)`. Doctrine attributes cannot express a functional index, so `doctrine:migrations:diff` would
propose dropping it on every future diff and `doctrine:schema:validate` would report drift permanently.
Because the column is normalized on write, the guarantee is identical.

There is deliberately **no `EmailAddress` value object**. Its only jobs here are normalization and
uniqueness, which now live in one write path and one index; a custom Doctrine type on the column the user
provider queries would add risk to the authentication path for no gain.

## Component: Multi-Tenancy

**Status**: Implemented (Epic-01 TASK-001, binds all Epic-01 tasks)

An `Organization` is a trainer's tenant. Trainers see only their own organization's data; players may be
associated with several organizations and see **separated views** with no combined view anywhere.

**Every organization-scoped repository method takes the organization id as a required parameter.** A
forgotten scope becomes an argument error at compile time rather than a silent cross-tenant data leak.

A Doctrine SQL filter was considered and **rejected**: it applies globally and silently, Super Admin tooling
legitimately needs cross-tenant reads, and a filter accidentally disabled fails open.

`TenantContext` answers for trainers and coaches. A trainer's tenant is the organization they own; a
coach's is the organization of their **active** `coach_assignment`, resolved through the
`CoachOrganizationProvider` interface (declared in `Account`, implemented in `Membership`) so the module
that owns tenancy does not depend on the module that depends on it. A coach with no active assignment still
has no tenant. TASK-003 closed the TODO TASK-001 left here.

Players have no single organization — they have a selected training context, resolved separately, and it
must be authorized server-side on every request: a forged context identifier returns 403, never data.

## Component: Audit Logging

**Status**: Implemented (Epic-01 TASK-002)

One append-only `audit_log_entry` table records every sensitive operation. An entry carries the **actor**
(the identity the action was performed as), the **impersonator** (nullable — the Super Admin behind that
identity), the action, a polymorphic subject (`subjectType` + `subjectId`, no FK, so a later module can
audit its own entities without a column per module), a JSON payload, and a timestamp.

**`AuditLogger::log()` persists but never flushes.** The audited change and its record must commit or roll
back together, and only the calling service knows where that transaction begins. A logger that flushed on
its own would produce entries for operations that later rolled back — a false record, which is worse than a
missing one.

`ImpersonationContext` is the only reader of `SwitchUserToken` outside the security layer. `AuditLogger`
asks it for the impersonator on every write, so an entry written by code that has never heard of
impersonation still carries the admin behind it.

Every foreign key from an audit or history table to `"user"` is **`ON DELETE RESTRICT`**. Users are never
hard-deleted — they are anonymized in place — so a CASCADE would only ever fire for a code path that is not
supposed to exist.

## Component: Impersonation

**Status**: Implemented (Epic-01 TASK-002)

Symfony's `switch_user`, wrapped — never re-implemented. `ROLE_ALLOWED_TO_SWITCH` is granted to
`ROLE_SUPER_ADMIN` through `role_hierarchy`; this is a capability grant, not a business role, and does not
reopen the "no hierarchy between the four roles" decision above.

**Authorization is enforced on the `security.switch_user` event, not in the controller.**
`SwitchUserListener` answers `?_switch_user=` appended to *any* URL, so a controller-only check would be a
check anyone could skip by editing the address bar. `SwitchUserAuditSubscriber` throws
`AccessDeniedException` — which `SwitchUserListener` does not catch, only `AuthenticationException` — so a
forbidden target surfaces as 403. `ImpersonateVoter` mirrors the same rules for the button and for a clean
403 on the POST.

Refused targets: any Super Admin (FR-030), any `Deleted` account, and any target at all when the actor is
already switched. `Inactive` targets are allowed on purpose — "why can I not sign in" is a support question
this feature exists to answer.

**Expiry has no native support and is a `kernel.request` subscriber** at priority 7. It reads elapsed time
from the open `impersonation_session` row rather than from a session key, so there is one authoritative
answer that survives a session restored from a cookie. On expiry the original token is restored, the record
is closed with `endReason = expiry`, and the operator is redirected to the admin dashboard — expiry hands
back the borrowed identity, it does not end the operator's own session. Window: `IMPERSONATION_TTL`,
default 3600s.

## Component: Account Removal

**Status**: Implemented (Epic-01 TASK-002)

Two verbs, and they are not variants of one another:

- **Deactivate** writes status only. All history stays, and it is reversible. Existing sessions end through
  `User::isEqualTo()`.
- **Delete** anonymizes the row in place — name → `Deleted User`, email → `deleted_{id}@example.com`, phone
  → NULL, password → fresh random bytes, verification cleared, status → `Deleted` (terminal).

**The user row is never deleted.** That is what keeps FR-026 true: every history row keeps pointing at the
same id, aggregate counts and sums are numerically unchanged by an erasure, and each row renders "Deleted
User" because they all read `User::getDisplayName()`.

`UserDeletionRecord` holds `originalUserId`, a **SHA-256 digest** of the original address, the anonymized
address, the actor, a required reason, and a timestamp. It deliberately does **not** hold the address in
clear or a data snapshot, as FR-027 asks — that would re-create the personal data the erasure just removed,
in a table nobody erases. See G-16 below; this needs legal sign-off, and reverting is a migration plus a
service change.

Two guards, in the services rather than in a voter so they hold for a console or fixture caller too: nobody
may deactivate or delete their own account, and the last active Super Admin may not be deactivated, deleted,
or demoted. There is no self-registration and no UI path to create a Super Admin, so removing the last one
locks everybody out until somebody reaches a shell.

## Component: Workflow and Transactions

**Status**: Implemented

Services own the workflow and the transaction boundary (`EntityManagerInterface::wrapInTransaction()`).
Side effects that cannot participate in a transaction — email, external calls — are dispatched **after
commit**, so a delivery failure never rolls back committed state.

Services never receive a `Request` or return a `Response`. Time comes from `ClockInterface`, never
`new \DateTimeImmutable()`, so expiry logic is testable.

## Component: Configuration of Open Questions

**Status**: Implemented (Epic-01 TASK-001)

Two requirements are still unanswered by the client, so both ship as a single switch rather than as
structure that would need rewriting:

| Question | Default shipped | Where to change |
|:---------|:----------------|:----------------|
| Must email verification precede first login? | Yes for player and coach, no for trainer and super admin | `EMAIL_VERIFICATION_REQUIRED`, then `AccountStatusChecker::requiresVerifiedEmail()` |
| Session timeout duration? | 7-day idle window | `SESSION_IDLE_TTL` |
| How long is one availability block (G-27)? | 60 minutes | `AVAILABILITY_SLOT_MINUTES` (must divide 1440) |
| Which time zone are stored availability times in (G-29)? | `UTC`, printed on every grid | `AVAILABILITY_TIMEZONE` |

Both are container parameters in `config/services.yaml` carrying their own defaults and reading an
environment variable through the `default:` env processor. Consequence: an existing deployment boots
unchanged without either variable set, and setting the variable overrides it. `cookie_lifetime` and
`gc_maxlifetime` both read the same parameter so the two cannot silently disagree.

## Component: Invitations (ShareLinks)

**Status**: Implemented (Epic-01 TASK-003)

One `share_link` table for both kinds of invitation. A player link is `maxUses = null, expiresAt = null`
(unlimited, never expires); a coach invitation is `maxUses = 1, expiresAt = now + 7 days` addressed to one
email. Two tables would duplicate the code column, the counter, the resolver and every query to express a
difference two nullable columns already express.

Codes are a value object: `random_bytes(16)` rendered as 26 base32 characters over an alphabet without
`I`, `L`, `O` or `U`. Nothing derives from the row, the organization or the clock. **Enumeration is
defeated by the key space, not by the rate limiter** — the limiter on `/join` exists so a scanner cannot
turn a public endpoint into free database load.

**A use is claimed by one conditional UPDATE**, never by read-then-write:
`UPDATE … SET use_count = use_count + 1 WHERE id = ? AND active AND (max_uses IS NULL OR use_count <
max_uses) AND (expires_at IS NULL OR expires_at > ?)`. The failure this prevents is a hundred concurrent
redemptions of a single-use invitation each reading `use_count = 0` and each deciding they are the allowed
use (NFR-041).

**Failure is uniform.** Malformed, unknown, deactivated and consumed codes all resolve to one state that
carries no link at all, so no template can leak which of the four it was. Expired is the single
distinguishable failure, required by FR-046 so the holder of a lapsed coach invitation can be told to ask
for a new one — a bounded disclosure to somebody who already had a real code.

Deactivating a link withdraws an invitation; it does not expel the players who already accepted one
(G-19).

## Component: Organization Membership

**Status**: Implemented (Epic-01 TASK-003)

Two membership records, both append-and-amend rather than delete:

- `trainer_player_association` joins an organization to a `player_profile`, **unique on (organization,
  player_profile)**. That index — not the service's read-before-write — is what makes redeeming a link
  twice idempotent (FR-043), because two concurrent redemptions both pass the read.
- `coach_assignment` joins an organization to a coach, with a **partial unique index on `coach_id` where
  `status = 'active'`**. BR-044 says a coach works for one trainer at a time; a full unique index cannot
  express it, because a coach who leaves one organization for another must keep the ended row. FR-045
  requires the rule to be "a database constraint plus a service check, not UI-only", and this is the
  constraint half. The predicate is written in the entity mapping exactly as PostgreSQL stores it, or
  every future `migrations:diff` would propose dropping and recreating the index.

`share_link_redemption` records every use — which link, by whom, when, and what it produced (new account /
association / blocked child) — for Epic-06. A repeat redemption that changes nothing is deliberately *not*
recorded and consumes no use, so the counter means "people who joined" rather than "pages viewed".

`PlayerProfile` lives in `src/Profile/` and is TASK-004's entity, seeded by TASK-003 with only the columns
the invitation flow writes. It carries two user references that answer different questions: `owner` is the
account that manages the profile (what family selection reads), `account` is the login it signs in as, null
until TASK-004 ships child logins (what the child block reads).

## Component: Public Redemption Flow

**Status**: Implemented (Epic-01 TASK-003)

`/join/{code}` is the only unauthenticated, account-creating endpoint in the application. Four properties
hold, and all four live in the controller rather than in its templates:

- Every entry point is rate limited by IP, with separate budgets for viewing and for submitting.
- Failure is the uniform response described above; only an expired invitation is distinguishable.
- **Nothing is decided in the controller.** `RedemptionPlanner` maps (link type, current account) onto one
  of six outcomes — register as player, register as coach, associate a family, accept a coach invitation,
  block a child, refuse — and every service it routes to re-checks what it enforces, so a page rendered
  before a link was withdrawn cannot authorize the submit that follows it.
- State changes are POSTs with CSRF tokens, except the refusal FR-048 puts on a GET; that one is
  deduplicated per (link, child) so a reload does not email the parent again.

Registration attempts a programmatic sign-in and falls back to the verification notice when the firewall
refuses, so the behaviour follows `EMAIL_VERIFICATION_REQUIRED` rather than assuming an answer to Q-01.05.

Object-level authorization for the trainer side is `ShareLinkVoter`: `^/trainer` is `ROLE_TRAINER`, which
every trainer holds, so the role rule protects nothing once a URL carries an id.

## Component: Weekly Availability

**Status**: Implemented (Epic-01 TASK-005)

One `availability_slot` table holds players and coaches alike, discriminated by `subject_type`. The two are
the same fact about different people — a weekday, a window, and whether the person can attend — so two
tables would duplicate one query shape and both of its indexes. The cost is that `subject_id` can carry no
foreign key: it points at `player_profile` for a player and at `"user"` for a coach. The pairing is
enforced instead by `AvailabilitySubject`, the only thing that can construct one.

**Availability is per person, not per (person, trainer)** — G-07 answered. US-01.03's "per trainer" reading
is not implemented: a player who trains with two academies does not become free at different hours for
each, and a per-trainer schema would ask a family to fill the same grid once per trainer and then let the
copies disagree. Every trainer of a player therefore reads the same declared times, and BR-087 governs
*who may read them*, not how many copies exist.

**Times are minutes since midnight**, not `TIME` columns. `24:00` has to be expressible as an end, and a
recurring weekly pattern must not move when the clocks do. Two smallint columns also make the matching
predicate two comparisons an index can serve end to end.

**Coverage, not overlap.** "Is this person available 18:00-20:00?" is answered by a *containing* range, not
an intersecting one: somebody free until 19:00 cannot attend a session that runs to 20:00. That works as a
single-row comparison only because a saved week is normalized — adjacent ranges merge before they are
written, so 16:00-18:00 plus 18:00-21:00 is one row. `WeeklySchedule` owns that normalization, and a week is
saved as a **whole value**: delete-and-insert in one transaction, never a per-day write.

**Three states, not two.** A declared refusal ("never on Wednesdays") is stored as a whole-day
`is_available = false` row, so it is distinguishable from silence. That distinction is load-bearing three
times: the trainer's "15 of 20" reports undeclared players separately rather than counting them as busy; a
coach who has declared nothing produces **no conflict warning**, because warning about absent data teaches
trainers to click through warnings; and a coach who declared a refusal does produce one.

**Availability never constrains (FR-088, BR-083).** `CoachAvailabilityChecker` — the interface Epic-02's
assignment flow will call — returns a verdict and has no way to refuse. A conflict yields FR-085's warning
and, past it, a required reason recorded by `ConflictOverrideRecorder` on `coach_availability_override`,
together with an audit entry in the same transaction (NFR-X02).

Coach assignment itself is Epic-02's. What ships here is the check either side of it, reachable as a
trainer's pre-assignment screen at `/trainer/coaches/{id}/availability-check`. The verdict is recomputed on
the confirming submit, so a coach who opened their times up between the warning and the confirmation does
not get an override filed against a conflict that no longer exists.

Trainer-facing reads take their candidate list from `OrganizationRosterProvider` — declared in
`Availability`, implemented in `Membership` — and no matching method accepts an unscoped list. BR-087 is
therefore structural rather than a `WHERE` clause somebody has to remember.

The grid is a real `<table>` of native checkboxes with per-cell labels ("Monday 5:00 PM to 6:00 PM,
available"), submittable with JavaScript disabled; drag-to-select and the running count are enhancements on
top (NFR-081). Rows are days and columns are hours, which is what lets the same markup reflow into a
day-by-day list at 320px (NFR-082).

## Cross-Cutting Requirements

- CSRF on every state-changing form. Stateless CSRF is configured (`config/packages/csrf.yaml`) with form token id `submit`.
- WCAG 2.1 AA: labels, keyboard operation, visible focus, errors linked to inputs, no colour-only meaning.
- Responsive and touch-friendly at 320px and up.
- Audit logging for sensitive operations (impersonation, deletion, override, approval).
- Soft delete preserves history; hard delete anonymizes PII while keeping aggregate totals accurate.

## Deferred / Unresolved

| ID | Item | Blocks |
|:---|:-----|:-------|
| R2 | Rate-limiter and session storage are node-local; a shared store is needed for the 1,000-concurrent-user target | Horizontal scaling |
| R4 | No async Messenger transport, so mail is synchronous | Deployment decision |
| G-16 | The deletion compliance record holds a digest, not the original email and data snapshot FR-027 asks for. Needs legal sign-off | Compliance review |
| G-15 | Deactivating or deleting a **trainer** has undefined consequences for their organization's players, coaches, links, and branding. Unanswered by TASK-003, which added more to break | Epic-01 TASK-004 |
| G-20 | A coach may move between trainers — the schema permits it, no workflow performs it. Who ends the old assignment, and what history the coach keeps, is unspecified | Epic-01 TASK-004 |
| G-21 | Answered by TASK-003 with an explicit self-versus-child choice on the registration form; the spec still never defines it | Spec correction |
| R5 | NFR-041's 100-concurrent-redemption target is proven by constraint and sequentially, not by a parallel load test — DAMA's per-test transaction is invisible to a second connection | Load harness |
| G-19 | FR-025 requires "photo → default avatar"; there is no photo column until profiles land | Epic-01 TASK-004 |
| Q-01.02 | Age groups are undefined. TASK-003 stores a **birth date** and derives age, so the answer stays a presentation decision | Epic-01 TASK-004 |
| G-07 | *Answered by TASK-005.* Availability is per profile; the per-trainer reading is not implemented. The spec text still says both | Spec correction |
| G-29 | *Assumed by TASK-005.* One platform time zone, configured and displayed. Per-user zones would mean converting a recurring pattern across DST — still unanswered by the client | Client decision |
| G-27 | *New (TASK-005).* Slot granularity was specified as "hourly blocks or custom ranges". Fixed blocks ship, configurable; arbitrary minute boundaries are not offered | Client decision |
| G-28 | *New (TASK-005).* Availability has no date dimension — no "away next week" exceptions. Deliberately deferred; real scheduling will need it | Epic-02 |
| G-30 | *New (TASK-005).* FR-087's "coach can request a change" has no recipient, state, notification or UI anywhere in the spec, so it is not implemented. The coach *sees* every override recorded against them | Client decision |
| Q-01.06 | Whether a coach is actively notified of an override is unanswered, so nothing is sent. The record is on the coach's own page | Client decision |
| R6 | `coach_availability_override.event_id` is a nullable, unconstrained integer: Epic-02 owns events and none exist. The foreign key belongs to Epic-02's first migration | Epic-02 |

---

*Sources: `specs/Epic-01_User_Management_Authentication_SPEC.md`, `tasks/TASK-001/architect-architecture.md`,
`tasks/TASK-005/architect-architecture.md`,
`tasks/TASK-001/writing-plans-plan.md`, `tasks/TASK-002/architect-architecture.md`,
`tasks/TASK-002/writing-plans-plan.md`, `tasks/TASK-003/architect-architecture.md`,
`tasks/TASK-003/writing-plans-plan.md`.*
