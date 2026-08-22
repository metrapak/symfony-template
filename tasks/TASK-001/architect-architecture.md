# TASK-001 Architecture: Authentication & RBAC Foundation

## Context

Requirements: `tasks/TASK-001/requirements-analyst-requirements.md` (FR-001…012, NFR-001…006, BR-001…008).
Delivery: server-rendered Twig (decision D-02). Target: Symfony 7.4 on PHP >= 8.2, PostgreSQL, Doctrine ORM 2.15.

### What the codebase already provides

| Asset | State | Consequence for this design |
|:------|:------|:----------------------------|
| `App\Entity\User` | `id`, `email`, `roles` (JSON), `password`; `__serialize` hashes the password out of the session | Extend; the `roles` array is replaced — see D3 |
| `App\Repository\UserRepository` | `PasswordUpgraderInterface` implemented | Keep; add `UserLoaderInterface` — see D4 |
| `SecurityController` | Login/logout actions, `templates/security/login.html.twig` | Keep, relocate |
| `ApiLoginController` + `json_login` | Returns a placeholder token `'...'` | Delete — see D13 |
| `config/packages/csrf.yaml` | **Stateless CSRF** already on: form `token_id: submit`, stateless ids `submit`/`authenticate`/`logout` | FR-011 largely satisfied; new forms must reuse `submit` |
| `config/packages/doctrine.yaml` | **Explicit per-module mappings** (App, Products, Starships, ToDoList, Videos) | Module layout is the established convention — see D1 |
| `config/packages/messenger.yaml` | `sync://` only; no async transport enabled | Mail is synchronous — see R4 |
| `symfony/clock` | Installed | Inject `ClockInterface`; no `new \DateTimeImmutable()` in services |
| `App\Products\Domain\Validator\Constraint\PasswordRequirements` | Compound constraint: NotBlank, Length 8–255, NotCompromisedPassword, uppercase regex | Reuse — see D9 |
| **Not installed** | `symfony/rate-limiter`, reset-password bundle, verify-email bundle | Three composer additions — see D5, D6 |

## Decisions

### D1 — Module layout: `src/Account/`, flat layers

The repo holds three competing conventions: flat `App\Controller`/`App\Entity` (maker default), feature
modules with flat layers (`App\Videos\*`), and Domain/Application/Infrastructure (`App\Products\*`,
`App\Starships\*`). `doctrine.yaml` registers one mapping per feature module, so adding a module is the
path of least resistance and matches how the project already grows.

Chosen: **feature module with flat layers**, matching `App\Videos`. AGENTS.md mandates pragmatic
`Controller -> Service -> Repository`; a three-tier Domain/Application/Infrastructure split would add
directories without adding a boundary this task needs.

```
src/Account/
  Controller/   SecurityController, PasswordResetController, EmailVerificationController,
                PasswordChangeController, DashboardController
  Dto/          ForgotPasswordInput, ResetPasswordInput, ChangePasswordInput
  Entity/       User, Organization, ResetPasswordRequest
  Enum/         UserRole, UserStatus
  Form/         ForgotPasswordFormType, ResetPasswordFormType, ChangePasswordFormType
  Mail/         AccountMailer
  Repository/   UserRepository, OrganizationRepository, ResetPasswordRequestRepository
  Security/     AccountStatusChecker, RequirePasswordChangeSubscriber
  Service/      PasswordResetService, EmailVerificationService, PasswordChanger,
                RoleDashboardResolver, TenantContext, SuperAdminCreator
  Command/      CreateSuperAdminCommand
```

`App\Entity\User` moves to `App\Account\Entity\User`. **The table name stays `"user"`, so there is no
data migration** — only a namespace change plus the `doctrine.yaml` mapping entry. Touch points:
`security.yaml` provider, `UserRepository`, `ApiLoginController` (being deleted anyway), and the `App:`
mapping (removed, since `src/Entity` becomes empty and Doctrine errors on a mapping pointing at a
missing directory).

*Alternative if churn is unwanted*: leave `User` in `App\Entity` and put only new code under
`App\Account`. Cheaper, but splits the epic's central entity from its module. Recommend the move — it is
mechanical and reversible.

### D2 — Entry points

All entry points are HTTP controllers, plus one console command for bootstrap. No Messenger handlers and
no event subscribers carrying business logic; the single subscriber (D8) is a redirect guard, not a workflow.

### D3 — Exactly one role, enforced by the schema

FR-007/BR-002 require exactly one primary role. Validating "the `roles` array has length 1" is a rule that
can be violated by any code path that forgets it. Instead:

- Add a `role` column typed by a `UserRole` backed enum (`ROLE_SUPER_ADMIN`, `ROLE_TRAINER`, `ROLE_COACH`, `ROLE_PLAYER`).
- `getRoles()` returns `[$this->role->value, 'ROLE_USER']`.
- Drop the `roles` JSON column after backfill.

The invariant becomes structurally unrepresentable rather than validated. `UserInterface` is satisfied
unchanged, so the firewall and `#[IsGranted]` keep working.

**No role hierarchy between the four business roles.** The spec grants Super Admin "full access", but
inheriting `ROLE_TRAINER` would route a Super Admin into org-scoped trainer views with no organization and
break them. Super Admin gets explicit grants on `/admin/*`; seeing a trainer's view is what impersonation
(TASK-002) exists for. `role_hierarchy` stays empty for these four.

### D4 — Case-insensitive user loading

Emails are normalized to lowercase on write (in the DTO/service, not a Doctrine type). If the provider kept
`property: email`, a login as `Foo@Bar.com` would fail against a stored `foo@bar.com`. So:

- `UserRepository implements UserLoaderInterface` with `LOWER(u.email) = :identifier`.
- `security.yaml` provider drops `property:` and relies on the loader.
- Unique index on `LOWER(email)` (PostgreSQL functional index) rather than on the raw column, closing the
  concurrency race that a Validator `UniqueEntity` check cannot (patterns §4).

**No `EmailAddress` value object.** The requirements doc listed one; it is dropped deliberately. Its only
job here is normalization plus uniqueness, both of which now live in one write path and one index. A custom
Doctrine type on the column the user provider queries adds risk to the authentication path for no gain
(AGENTS.md: no mechanical abstraction).

### D5 — Password reset: `symfonycasts/reset-password-bundle`

Resolves gap G-11. The bundle implements the selector/verifier split — the emailed token is never stored,
only a hash, and lookup is by a non-secret selector so it is not timing-sensitive. Hand-rolling this is a
security exercise with no upside, and the bundle also gives per-user request throttling for free.

- `ResetPasswordRequest` entity implements the bundle's `ResetPasswordRequestInterface`.
- Lifetime configured to **3600s** (FR-004, BR-003).
- `PasswordResetService` wraps the bundle so controllers never touch bundle internals directly and the
  "same confirmation for unknown emails" rule (no user enumeration) lives in one place.

### D6 — Email verification: `symfonycasts/verify-email-bundle` (stateless)

The bundle signs a URI containing the user id and email; expiry lives in the signature. **This eliminates
the `EmailVerificationToken` entity** from the requirements doc — one fewer table, no cleanup job.

Consequence to handle: a signed URL stays valid until it expires even after use, so `verify()` must be
idempotent — an already-verified user is redirected with a neutral flash, not an error.

Whether verification gates login (**Q-01.05**, still open) is a **config flag** read by
`AccountStatusChecker`, so either answer is a one-line change. Default until the client answers:
verification required for `ROLE_PLAYER` and `ROLE_COACH`, not for admin-created trainers (who arrive via
an invitation link that already proves email control).

### D7 — Account status gate: `UserCheckerInterface`

`AccountStatusChecker::checkPreAuth()` throws `CustomUserMessageAccountStatusException` per status, wired as
the firewall's `user_checker`. This is the framework's own extension point and runs on every
authentication including remembered/refreshed sessions — a controller-level check would not.

FR-009 demands distinct messages for Inactive vs Deleted, which does leak account existence to someone who
already knows the password. That is the spec's explicit requirement; noted as an accepted trade-off.

### D8 — Forced password change: request subscriber

FR-006 cannot be expressed in `access_control`. `RequirePasswordChangeSubscriber` listens on
`kernel.request` at **priority 0** (after the firewall at priority 8, so a token exists), and redirects to
`account_password_change` when `mustChangePassword` is true.

Allowlist, or the app deadlocks: the change-password route itself, `app_logout`, and non-main requests
(`$event->isMainRequest()`). Profiler/asset paths are already outside the firewall.

### D9 — Validation boundary: Symfony Forms with DTO `data_class`

Twig delivery means Symfony Forms (CSRF, error rendering, `RepeatedType` for password confirmation). The
form's `data_class` is a plain input DTO in `Account/Dto/` carrying the Validator constraints, so business
input stays typed and allowlisted (patterns §4) while Twig still gets a real form to render.

Password rules reuse the existing `PasswordRequirements` compound constraint — **moved** from
`App\Products\Domain\Validator\Constraint` to `App\Shared\Domain\Validator\Constraint`, where it belongs.
This resolves the "password complexity unspecified" assumption in the requirements doc: the project already
answered it (min 8, max 255, one uppercase, not compromised).

`NotCompromisedPassword` calls the haveibeenpwned API. Tests must not depend on the network:
`framework.validation.not_compromised_password: false` under `when@test`.

### D10 — Authorization placement

| Concern | Mechanism | Why |
|:--------|:----------|:----|
| Role-gated route trees (`/admin`, `/trainer`, `/coach`, `/family`) | `access_control` | Coarse, path-shaped, no object involved |
| Anonymous-only routes (login, forgot password) | `access_control` + `PUBLIC_ACCESS` | Same |
| Object-level ownership | **Deferred to TASK-002/004** | No owned objects exist yet in this task; a voter now would have no subject |

No voters in TASK-001. Adding one before there is an object to authorize would be structure without a
boundary.

### D11 — Tenancy: a required parameter, not a Doctrine filter

`TenantContext` resolves the current `Organization` for a **trainer** (their owned org) or a **coach**
(their assignment's org, once TASK-003 lands). Players have no single organization — they have a selected
training context, which is TASK-004's separate `TrainingContextResolver`. `TenantContext` deliberately does
not pretend to answer for them: `currentOrganizationId(): ?int` and `requireOrganizationId(): int` (throws).

A Doctrine SQL filter was considered and **rejected**: it applies globally and silently, TASK-002's admin
tooling legitimately needs cross-tenant reads, and a filter accidentally disabled fails open. Instead the
convention — binding on all six tasks — is that **every organization-scoped repository method takes the
organization id as a required parameter**. A forgotten scope becomes a compile-time argument error rather
than a silent data leak. Admin cross-tenant queries are simply different, explicitly named methods.

### D12 — Transactions and side-effect ordering

Services own the boundary via `EntityManagerInterface::wrapInTransaction()`. Two flows need it:

- **Reset consumption**: mark the request used + set the new password + clear `mustChangePassword` commit together, or the token stays spendable after a partial failure.
- **Super admin bootstrap**: user + no org, trivial, but keep the pattern.

Email is dispatched **after** commit, never inside the transaction — a mail failure must not roll back a
completed password change. No `TransactionManager` port: Doctrine's EM is stable infrastructure, and
AGENTS.md forbids interfaces without a substitution boundary.

### D13 — Delete `ApiLoginController` and the `json_login` firewall entry

Resolves gap G-12. The action returns a hardcoded placeholder token, no token issuance exists, and D-02
scopes delivery to Twig. An unreviewed authentication endpoint handing out a fake credential is a liability;
a JSON API, if it ever arrives, is its own epic with its own design.

### D14 — Response contract

Twig throughout. POST/Redirect/GET with flash messages for every mutation. `/dashboard` is a **redirect
hub**: `RoleDashboardResolver` maps role → route name and the controller redirects to `/admin`, `/trainer`,
`/coach`, or `/family`. Each role then owns a stable URL that `access_control` can guard — cleaner than one
controller branching across four templates.

## Layer Placement

| Concern | Owner | Notes |
|:--------|:------|:------|
| HTTP mapping, CSRF check, redirect | Controller | Thin: map input, authorize, call one service method, redirect |
| Login/logout mechanics | Symfony firewall | `form_login`, `logout`; no custom authenticator |
| Account status refusal | `AccountStatusChecker` (`UserCheckerInterface`) | Runs on every authentication |
| Forced-password-change redirect | `RequirePasswordChangeSubscriber` | Adapter only, no business logic |
| Input shape and constraints | `Account/Dto/*` + Form types | Typed, allowlisted |
| Reset workflow, enumeration policy | `PasswordResetService` | Wraps the bundle |
| Verification workflow, idempotency | `EmailVerificationService` | Wraps the bundle |
| Password write + flag clearing | `PasswordChanger` | Single place that hashes |
| Role → landing route | `RoleDashboardResolver` | Pure function, unit-testable |
| Current organization | `TenantContext` | Trainer/coach only in this task |
| Queries and persistence | `Account/Repository/*` | Business-named methods; org id required where scoped |
| Local invariants (status transitions, role) | `User` entity | No HTTP, session, mailer, or Doctrine awareness |
| Email rendering and dispatch | `AccountMailer` | Thin adapter over `MailerInterface` |
| Transaction boundary | Services, `wrapInTransaction` | Mail dispatched post-commit |
| Uniqueness under concurrency | Migration: unique index on `LOWER(email)` | Validator alone cannot |

## Files Likely Touched

**Moved**
- `src/Entity/User.php` → `src/Account/Entity/User.php`
- `src/Repository/UserRepository.php` → `src/Account/Repository/UserRepository.php`
- `src/Controller/SecurityController.php` → `src/Account/Controller/SecurityController.php`
- `src/Products/Domain/Validator/Constraint/PasswordRequirements.php` → `src/Shared/Domain/Validator/Constraint/`

**Deleted**
- `src/Controller/ApiLoginController.php`

**New** — entities (`Organization`, `ResetPasswordRequest`), enums (`UserRole`, `UserStatus`), 4 controllers,
3 DTOs, 3 form types, 6 services, `AccountStatusChecker`, `RequirePasswordChangeSubscriber`, `AccountMailer`,
`CreateSuperAdminCommand`, 2 repositories.

**Config**
- `config/packages/doctrine.yaml` — add `Account` mapping, remove the now-empty `App` mapping
- `config/packages/security.yaml` — provider class + `UserLoaderInterface` (drop `property`), `user_checker`, `login_throttling`, `access_control` per role, empty `role_hierarchy`
- `config/packages/framework.yaml` — session `cookie_lifetime` / `gc_maxlifetime` from env (Q-01.07), `not_compromised_password: false` under `when@test`
- `config/packages/reset_password.yaml` — new (bundle recipe), lifetime 3600
- `config/packages/rate_limiter.yaml` — new, login limiters
- `.env` / `.env.example` — `SESSION_IDLE_TTL`, `MAILER_DSN` (present), `EMAIL_VERIFICATION_REQUIRED`

**Migrations** — one migration: alter `"user"` (add `status`, `role`, `email_verified_at`,
`must_change_password`, `last_login_at`, `created_at`, `updated_at`; backfill `role` from `roles`; drop
`roles`; swap the email unique index for a functional one on `LOWER(email)`), create `organization` and
`reset_password_request`.

**Templates** — `templates/account/`: login (relocated), forgot password, reset password, verification
notice, forced password change, four dashboard skeletons.

**Composer** — `symfony/rate-limiter`, `symfonycasts/reset-password-bundle`, `symfonycasts/verify-email-bundle`.

## Tests Needed

Following the project's existing `WebTestCase` style (`tests/Videos/Controller/`) with DAMA rollback per test.

**Unit** — `RoleDashboardResolver` mapping for all four roles; `UserRole`/`UserStatus` transitions;
`User::getRoles()` returns exactly the primary role plus `ROLE_USER`; `TenantContext` throwing for a player;
`AccountStatusChecker` decision matrix (status × verification-required flag) with a fake clock.

**Repository integration** — `UserRepository::loadUserByIdentifier` matches case-insensitively;
the functional unique index rejects `Foo@x.com` when `foo@x.com` exists; reset-request lookup by selector.

**Functional** — login success/failure per role with a generic error; Inactive and Deleted refused with the
exact FR-009 messages; full reset flow; expired token rejected; token single-use; unknown email
indistinguishable; verification flow including a second click on a used link; forced password change blocks
every other route and does not loop; **the authorization matrix** — four roles × the four dashboard route
trees asserting 200/403; CSRF rejection per form; `login_throttling` engaging after N failures.

**Console** — `CreateSuperAdminCommand` creates a usable account and exits non-zero on a duplicate email.

Fixtures: one user per role, one Inactive, one Deleted, one `mustChangePassword`, one unverified.

## Rollout Risks and Assumptions

- **R1 — `roles` → `role` migration.** Backfill must run before the column is dropped, in the same
  migration, and must handle a row with an empty or multi-valued array. Existing dev data has one user;
  production has none. Low risk, but write the backfill defensively and add a `down()`.
- **R2 — Rate limiter storage.** `cache.app` defaults to the filesystem, so limits are per-server and reset
  on cache clear. Acceptable single-node; a shared Redis/Doctrine store is needed before horizontal scaling.
  Note this against NFR-002 (1,000 concurrent users), which implies more than one node.
- **R3 — Three new composer dependencies.** Each is a Symfony-ecosystem package with recipes. Run
  `composer audit` after adding (the dependency-manager skill covers this).
- **R4 — Synchronous mail.** No async Messenger transport is configured, so a slow SMTP directly slows the
  reset and verification requests. Enabling `MESSENGER_TRANSPORT_DSN` and routing `SendEmailMessage` to it
  fixes this but requires operating a worker. Deferred; flagged for the deployment decision.
- **R5 — Namespace move.** `App\Entity` is referenced in `security.yaml` and `doctrine.yaml`. Miss one and
  the container fails loudly at boot, not silently at runtime. Low risk, caught by `make test`.
- **R6 — Q-01.05 still open.** Built as a config flag (D6), so the answer is not blocking, but the default
  ships if nobody decides.
- **R7 — Q-01.07 still open.** Session TTL defaults to a configurable 7-day idle window.
- **A1** — Assumes int auto-increment ids, consistent with every existing entity. Not UUIDs.
- **A2** — Assumes `declare(strict_types=1)` in new files. Only one existing file uses it; php-cs-fixer's
  `@Symfony` ruleset does not enforce it either way, and AGENTS.md prefers it.
- **A3** — Assumes remember-me is out of scope (the spec neither requires nor forbids it).
- **A4** — G-15 (deactivating a *trainer* and their organization's fate) is untouched here beyond creating
  `Organization`. It must be answered before TASK-002 implements deletion.

## Next Command Recommendation

**`writing-plans [TASK-001 context]`** — the design is settled and the remaining work is mechanical
sequencing (migration ordering, composer additions, move-then-extend steps).

Alternatives:
- `database-designer [TASK-001 context]` — if the `roles` → `role` migration and the functional unique index
  warrant their own review before planning. Reasonable given R1.
- `council [TASK-001 context]` — only if D1 (moving `User` into a module) or D3 (no role hierarchy) should be
  contested; both bind all six tasks.
