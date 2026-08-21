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

`TenantContext` is designed to answer for trainers and coaches. **As implemented it resolves trainers only**
— a coach reaches their organization through an assignment record that does not exist yet, so the coach
branch returns null behind a documented TODO and must be completed by the coach-management task.

Players have no single organization — they have a selected training context, resolved separately, and it
must be authorized server-side on every request: a forged context identifier returns 403, never data.

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

Both are container parameters in `config/services.yaml` carrying their own defaults and reading an
environment variable through the `default:` env processor. Consequence: an existing deployment boots
unchanged without either variable set, and setting the variable overrides it. `cookie_lifetime` and
`gc_maxlifetime` both read the same parameter so the two cannot silently disagree.

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
| G-15 | Deactivating or deleting a **trainer** has undefined consequences for their organization's players, coaches, links, and branding | Epic-01 TASK-002 |
| G-07 | Availability is specified both per-profile and per-(profile, trainer) | Epic-01 TASK-005 |
| G-29 | No time zone is defined anywhere, yet weekly availability stores local times | Epic-01 TASK-005 |

---

*Sources: `specs/Epic-01_User_Management_Authentication_SPEC.md`, `tasks/TASK-001/architect-architecture.md`, `tasks/TASK-001/writing-plans-plan.md`.*
