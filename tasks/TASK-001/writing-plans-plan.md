# TASK-001 Implementation Plan: Authentication & RBAC Foundation

**Inputs**: `tasks/TASK-001/requirements-analyst-requirements.md` (FR-001…012), `tasks/TASK-001/architect-architecture.md` (D1…D14).
**Target**: Symfony 7.4, PHP >= 8.2, PostgreSQL, Doctrine ORM 2.15. All commands run through Docker (`docker/compose.yml`) via the Makefile.

Written for an engineer with no prior context on this codebase. Every step names files, behavior, tests, and a command whose output proves the step landed.

## Goal

Ship the security foundation Epic-01 depends on: email/password login, a four-role model with exactly one
role per user, account status lifecycle, password reset, email verification, forced password change,
login throttling, and the tenancy primitive that the other five tasks scope their queries by.

## Non-Goals

- No user management UI (creating, editing, deactivating, deleting users) — that is TASK-002.
- No impersonation — TASK-002.
- No ShareLinks, registration, profiles, children, availability, or approvals — TASK-003…006.
- No JSON API. The existing `json_login` path is removed, not extended (D13).
- No remember-me (A3).
- No object-level voters — there are no owned objects yet (D10).
- No async mail worker (R4). Mail stays synchronous.

## Current → Target Behavior

| Aspect | Current | Target |
|:-------|:--------|:-------|
| Login | Works via `form_login`; any `App\Entity\User` row can log in | Unchanged mechanics; refused unless status `Active` (and verified, per flag) |
| Roles | `roles` JSON array, effectively unused | Single `role` enum column; four business roles; no hierarchy |
| Status | No concept | `Active` / `Inactive` / `Deleted`, enforced at authentication |
| Password recovery | None | Reset via `symfonycasts/reset-password-bundle`, 1h expiry, single use |
| Email verification | None | Stateless signed URL via `symfonycasts/verify-email-bundle`, 24h expiry |
| Brute force | Unthrottled | `login_throttling`, 5 attempts / 15 min per username+IP |
| Post-login landing | Whatever `_target_path` says | `/dashboard` redirect hub → per-role route tree |
| Session | PHP defaults, no lifetime set | Explicit idle TTL, httponly, samesite lax |
| JSON login | `ApiLoginController` returns a hardcoded `'...'` token | Deleted |
| Code location | `App\Entity\User`, `App\Controller\SecurityController` | `App\Account\*` module |

## Compatibility Constraints

1. **Table name `"user"` must not change.** The move in D1 is namespace-only. If a migration proposes renaming a table, the mapping is wrong — stop and fix the mapping.
2. **`config/routes.yaml` and `config/packages/doctrine.yaml` register each module explicitly.** A new module needs an entry in both, and an entry pointing at a directory that no longer exists breaks container compilation.
3. **`config/packages/csrf.yaml` already enables stateless CSRF** with form `token_id: submit` and stateless ids `submit` / `authenticate` / `logout`. New forms must use the default `submit` id; do not introduce new stateless ids without adding them to that list.
4. `security.yaml` names the provider class explicitly — it moves with the entity.
5. Existing public pages (`/videos`, `/products`, `/starships`) have no `access_control` rules. Do not add a catch-all rule; it would lock them.

## Assumptions Needing Proof (verify in Step 0 / Step 1)

- **A1** No two existing `"user"` rows differ only by email case. Step 4 aborts the migration if they do.
- **A2** `symfonycasts/reset-password-bundle` and `verify-email-bundle` current releases support Symfony 7.4. Confirm at `composer require` time; if not, fall back to hand-rolled tokens per the original requirements design and re-open gap G-11.
- **A3** `symfony/clock` is currently a *transitive* dependency (it is in `vendor/` but not in `composer.json`). Step 1 promotes it to an explicit requirement.
- **A4** DAMA rollback (`dama_doctrine_test_bundle.yaml`, test env only) isolates functional tests. Existing tests rely on it; new tests inherit the behavior.

## Deviations From the Architecture Doc

Two refinements, both narrowing risk. The architecture doc is otherwise implemented as written.

**Refinement 1 — no functional unique index.** D4 called for `CREATE UNIQUE INDEX ... (LOWER(email))`.
Doctrine attributes cannot express a functional index, so `doctrine:migrations:diff` would propose dropping
it on every future diff and `doctrine:schema:validate` would report drift forever. Since emails are
normalized to lowercase on write, a plain unique index on the normalized column gives the identical
guarantee. **Keep the existing `UNIQ_IDENTIFIER_EMAIL` index**, lowercase the identifier inside
`loadUserByIdentifier`, and lowercase existing rows in the backfill.

**Refinement 2 — `User implements EquatableInterface`.** Neither the requirements nor the architecture doc
covers this: Symfony's `ContextListener` refreshes the session user each request but **does not re-run the
user checker**. Without action, deactivating a user leaves their existing session fully working until it
expires. `isEqualTo()` returning false when `status` or `role` differs from the session copy forces
de-authentication. This is what makes TASK-002's FR-024 ("user cannot log in") actually hold for users who
are already logged in.

## Layer Placement

Per D10 and the project's `Controller -> Service -> Repository` policy:

| Layer | Owns | Must not |
|:------|:-----|:---------|
| Controller | Request mapping, form handling, CSRF, `denyAccessUnlessGranted`, redirect + flash | Queries, hashing, mail, business branching |
| Firewall / `UserCheckerInterface` | Authentication mechanics, status refusal | Business workflow |
| Event subscriber | The forced-password-change redirect only | Any business decision |
| Service | Workflow, transaction boundary, post-commit side effects | HTTP, Twig, `Request`, response objects |
| Repository | QueryBuilder/DQL, persistence helpers, org-scoped signatures | Authorization |
| Entity | Local invariants (status/role transitions), `EquatableInterface` | HTTP, session, mailer, Doctrine |

---

# Steps

Each step is independently verifiable. Do not start a step until the previous one's verification passes.

## Step 0 — Establish a green baseline

**Do**: nothing but run the suite, so later failures are attributable.

```bash
make start
make test
make lint
```

**Expected**: record the pass/fail state. If anything already fails, note it here before proceeding —
do not fix unrelated pre-existing failures inside this task.

---

## Step 1 — Add dependencies

**Files**: `src/composer.json`, `src/composer.lock`, `src/config/bundles.php`, new
`src/config/packages/reset_password.yaml`, `src/config/packages/verify_email.yaml` (recipe-generated).

```bash
cd docker && docker compose run --rm -T php-fpm composer require \
  symfony/rate-limiter symfony/clock \
  symfonycasts/reset-password-bundle symfonycasts/verify-email-bundle
cd docker && docker compose run --rm -T php-fpm composer audit
```

- `symfony/rate-limiter` — required by `login_throttling` (FR-003); not currently installed.
- `symfony/clock` — promoted from transitive to explicit (A3); services inject `ClockInterface` so expiry is testable.
- The two SymfonyCasts bundles — D5, D6.

**Verify**: `composer validate --strict` passes; `bundles.php` gains both bundles; `composer audit` is clean
or every finding is triaged in writing.

**If a bundle rejects Symfony 7.4**: stop, re-open gap G-11, and escalate rather than pinning an old release.

---

## Step 2 — Create the module and move existing code (no behavior change)

**Move** (git mv, so history follows):

| From | To |
|:-----|:---|
| `src/src/Entity/User.php` | `src/src/Account/Entity/User.php` |
| `src/src/Repository/UserRepository.php` | `src/src/Account/Repository/UserRepository.php` |
| `src/src/Controller/SecurityController.php` | `src/src/Account/Controller/SecurityController.php` |
| `src/src/Products/Domain/Validator/Constraint/PasswordRequirements.php` | `src/src/Shared/Domain/Validator/Constraint/PasswordRequirements.php` |
| `src/templates/security/login.html.twig` | `src/templates/account/login.html.twig` |

**Delete**: `src/src/Controller/ApiLoginController.php` (D13).

**Edit namespaces** in every moved file plus the `SecurityController` render path
(`account/login.html.twig`).

**Config edits**:

- `config/packages/doctrine.yaml` — add an `Account` mapping (`dir: %kernel.project_dir%/src/Account/Entity`, `prefix: App\Account\Entity`, `type: attribute`, `is_bundle: false`); **remove the `App:` mapping** — `src/Entity/` is now empty and Doctrine fails on a mapping whose directory is gone.
- `config/routes.yaml` — add `account_controllers` pointing at `../src/Account/Controller/`; **remove the `controllers:` entry** — `src/Controller/` is now empty.
- `config/packages/security.yaml` — provider class becomes `App\Account\Entity\User`; remove the `json_login` block.
- Remove the now-empty `src/src/Entity/`, `src/src/Repository/`, `src/src/Controller/` directories.

**Verify** — the point of this step is that nothing changed but the map:

```bash
cd docker && docker compose run --rm -T php-fpm bin/console lint:container
cd docker && docker compose run --rm -T php-fpm bin/console debug:router
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:schema:validate --skip-sync
make test
```

**Expected**: container compiles, `app_login` / `app_logout` still routed, `api_login` **gone**, schema
still in sync (proving the table did not move), suite as green as Step 0.

---

## Step 3 — Enums and entity fields (code only, no migration yet)

**New** `src/src/Account/Enum/UserRole.php`:

```php
enum UserRole: string
{
    case SuperAdmin = 'ROLE_SUPER_ADMIN';
    case Trainer    = 'ROLE_TRAINER';
    case Coach      = 'ROLE_COACH';
    case Player     = 'ROLE_PLAYER';
}
```

**New** `src/src/Account/Enum/UserStatus.php`: `Active`, `Inactive`, `Deleted` (backed by
`'active'|'inactive'|'deleted'`), plus `canAuthenticate(): bool` returning true only for `Active`.

**Edit** `App\Account\Entity\User`:

- Add `#[ORM\Column(type: Types::STRING, length: 32, enumType: UserRole::class)] private UserRole $role;`
- Add `status` (same pattern, `UserStatus`), `emailVerifiedAt` (`?\DateTimeImmutable`), `mustChangePassword` (`bool`, default `false`), `lastLoginAt` (`?\DateTimeImmutable`), `createdAt`, `updatedAt`.
- Replace `getRoles()` with `return [$this->role->value, 'ROLE_USER'];`. **Delete `setRoles()` and the `$roles` property** (FR-007/BR-002 — D3).
- `setEmail()` lowercases: `$this->email = strtolower(trim($email));` (Refinement 1).
- Implement `EquatableInterface::isEqualTo(UserInterface $user): bool` — false unless `$user` is a `User` with the same id, `status`, and `role` (Refinement 2).
- Leave `__serialize()` alone; it already keeps the password hash out of the session.

**New** `src/src/Account/Entity/Organization.php`: `id`, `name`, `owner` (`ManyToOne User`, unique),
`createdAt`, `updatedAt`. Minimal by design — branding and profile fields arrive in TASK-004.

**New** `src/src/Account/Entity/ResetPasswordRequest.php` implementing the bundle's
`ResetPasswordRequestInterface` and using `ResetPasswordRequestTrait`; declare the `ManyToOne User`
relation yourself (the trait does not).

**Tests** — `tests/Account/Unit/Entity/UserTest.php`:
- `getRoles()` returns exactly `[role, 'ROLE_USER']` for each of the four roles.
- `setEmail('Foo@Bar.COM')` stores `foo@bar.com`.
- `isEqualTo` false on differing status, false on differing role, true on identical.

```bash
make test
make stan
```

**Expected**: unit tests pass. `doctrine:schema:validate` now reports drift — that is correct; Step 4 closes it.

---

## Step 4 — Expand migration: add columns, backfill, create tables

**New** `src/migrations/VersionYYYYMMDDHHMMSS.php` (generate the skeleton with
`bin/console make:migration`, then **hand-edit** — the generator cannot write the backfill or the guard).

`up()`, in order:

1. **Guard A1 before touching anything.** Abort if lowercasing emails would collide:

```php
$collisions = (int) $this->connection->fetchOne(
    'SELECT COUNT(*) FROM (SELECT LOWER(email) FROM "user" GROUP BY LOWER(email) HAVING COUNT(*) > 1) c'
);
$this->abortIf($collisions > 0, 'Email case collisions exist; resolve them before migrating.');
```

2. `ALTER TABLE "user" ADD role VARCHAR(32) DEFAULT NULL` (nullable for now — expand phase).
3. Add `status VARCHAR(16) DEFAULT 'active' NOT NULL`, `email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL`, `must_change_password BOOLEAN DEFAULT false NOT NULL`, `last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL`, `created_at`/`updated_at` (`NOT NULL`, default `CURRENT_TIMESTAMP` for existing rows).
4. **Backfill `role` from the JSON array.** Use `jsonb_exists()`, **not** the `?` operator — `?` collides with Doctrine's parameter placeholders and will throw at execution:

```sql
UPDATE "user" SET role = CASE
    WHEN jsonb_exists(roles::jsonb, 'ROLE_SUPER_ADMIN') THEN 'ROLE_SUPER_ADMIN'
    WHEN jsonb_exists(roles::jsonb, 'ROLE_TRAINER')     THEN 'ROLE_TRAINER'
    WHEN jsonb_exists(roles::jsonb, 'ROLE_COACH')       THEN 'ROLE_COACH'
    ELSE 'ROLE_PLAYER'
END
```

5. `UPDATE "user" SET email = LOWER(TRIM(email))` (Refinement 1).
6. `CREATE TABLE organization` (+ sequence, FK to `"user"`, unique index on `owner_id`) and
   `CREATE TABLE reset_password_request` (+ sequence, FK to `"user"`, index on `selector`).

`down()`: drop the two tables, drop the added columns. The lowercasing is not reversible — say so in a
comment rather than pretending.

**Do not drop `roles` in this migration.** Keeping it means Step 4 is revertible on its own.

```bash
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:migrations:migrate --no-interaction
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:migrations:migrate prev --no-interaction   # prove down() works
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:migrations:migrate --no-interaction
```

**Expected**: migrate up, down, and up again cleanly. Every existing row has a non-null `role` and a
lowercase email.

---

## Step 5 — Contract migration: drop `roles`

**New** migration: `ALTER TABLE "user" DROP roles`, then `ALTER TABLE "user" ALTER role SET NOT NULL`.

Separate from Step 4 so the expand phase can be deployed and verified before the column disappears.
`down()` re-adds `roles JSON NOT NULL DEFAULT '[]'` and makes `role` nullable again.

```bash
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:migrations:migrate --no-interaction
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:schema:validate --skip-sync
make test
```

**Expected**: schema validate reports **in sync** — this is the checkpoint proving Steps 3–5 agree.

---

## Step 6 — Case-insensitive user loading

**Edit** `App\Account\Repository\UserRepository`: implement `UserLoaderInterface`.

```php
public function loadUserByIdentifier(string $identifier): ?User
{
    return $this->createQueryBuilder('u')
        ->andWhere('LOWER(u.email) = :identifier')
        ->setParameter('identifier', strtolower(trim($identifier)))
        ->getQuery()
        ->getOneOrNullResult();
}
```

Keep `upgradePassword()` as-is. Add `findOneByEmail(string $email): ?User` for service use.

**Edit** `config/packages/security.yaml`: remove `property: email` from `app_user_provider` so the loader is used.

**Tests** — `tests/Account/Integration/Repository/UserRepositoryTest.php` (`KernelTestCase`):
- a user stored as `foo@bar.com` loads via `Foo@Bar.COM`
- unknown identifier returns null
- inserting a second row with the same email throws a unique-constraint violation (proves the DB, not just the Validator, closes the race)

```bash
make test
```

---

## Step 7 — Account status gate

**New** `src/src/Account/Security/AccountStatusChecker` implementing `UserCheckerInterface`:

- `checkPreAuth()`: `Inactive` → `CustomUserMessageAccountStatusException('Account deactivated. Contact support.')`; `Deleted` → `CustomUserMessageAccountStatusException('This account no longer exists.')`.
- `checkPostAuth()`: if verification is required for this user's role and `emailVerifiedAt` is null, throw `CustomUserMessageAccountStatusException` pointing at the resend page.
- Constructor takes `bool $emailVerificationRequired` bound from `%env(bool:EMAIL_VERIFICATION_REQUIRED)%`.

**Q-01.05 is still open** (D6). Default: required for `ROLE_PLAYER` and `ROLE_COACH`, not for `ROLE_TRAINER`
or `ROLE_SUPER_ADMIN` (both are admin-created via an invitation link that already proves email control).
Encode that as a method on the checker, not scattered `if` statements — the client's answer changes one place.

**Edit** `security.yaml`: `user_checker: App\Account\Security\AccountStatusChecker` on the `main` firewall.
**Edit** `.env` / `.env.example`: `EMAIL_VERIFICATION_REQUIRED=1`.
**Edit** `config/services.yaml`: bind `bool $emailVerificationRequired`.

**Tests** — unit for the decision matrix (status × role × flag), plus functional:
`tests/Account/Functional/LoginTest.php` asserting an Inactive user sees the exact FR-009 string and a
Deleted user cannot authenticate.

```bash
make test
```

---

## Step 8 — Session lifetime and login throttling

**Edit** `config/packages/framework.yaml`, `session` block:

```yaml
cookie_httponly: true
cookie_lifetime: '%env(int:SESSION_IDLE_TTL)%'
gc_maxlifetime: '%env(int:SESSION_IDLE_TTL)%'
```

`gc_maxlifetime` is the server-side idle window; `cookie_lifetime` decides whether the session survives a
browser restart. **Q-01.07 is unresolved** — default `SESSION_IDLE_TTL=604800` (7 days) in `.env`, both
knobs set together so the two do not silently disagree. `cookie_secure: auto` and `cookie_samesite: lax`
are already set. Confirm `session_fixation_strategy` is Symfony's default `migrate` (do not override).

Also under `when@test` add `validation: not_compromised_password: false` — `PasswordRequirements` includes
`NotCompromisedPassword`, which calls the haveibeenpwned API. Without this the suite depends on the network.

**Edit** `security.yaml`, inside the `main` firewall:

```yaml
login_throttling:
    max_attempts: 5
    interval: '15 minutes'
```

Symfony's built-in throttling applies `max_attempts` per username+IP **and** `5 × max_attempts` globally per
IP, so FR-003's two dimensions are both covered without a custom limiter and without a
`rate_limiter.yaml`. Storage is `cache.app` — see R2.

**Tests** — `tests/Account/Functional/LoginThrottlingTest.php`: six consecutive bad logins; the sixth
response differs from the fifth (throttled). Clear the limiter cache in `setUp` so the test is repeatable.

```bash
cd docker && docker compose run --rm -T php-fpm bin/console lint:yaml config
make test
```

---

## Step 9 — Password change + forced-change guard

**New** `src/src/Account/Dto/ChangePasswordInput.php`: `plainPassword` with
`#[PasswordRequirements]` (now in `App\Shared\Domain\Validator\Constraint`) and `currentPassword` with
`#[SecurityAssert\UserPassword]`.

**New** `src/src/Account/Form/ChangePasswordFormType.php`: `data_class` = the DTO, `RepeatedType` for the
new password, default CSRF `token_id: submit`.

**New** `src/src/Account/Service/PasswordChanger.php`:

```php
public function change(User $user, string $plainPassword): void
```
Hashes via `UserPasswordHasherInterface`, sets `mustChangePassword = false`, stamps `updatedAt` from
`ClockInterface`, flushes. This is the **only** place in the module that hashes a password.

**New** `src/src/Account/Controller/PasswordChangeController.php`: `GET|POST /account/password`, route
`account_password_change`. Handles the form, calls `PasswordChanger`, flashes, redirects to `/dashboard`.

**New** `src/src/Account/Security/RequirePasswordChangeSubscriber.php` — `kernel.request`, **priority 7**
(the firewall listener runs at 8, so a token exists by then). Redirect to `account_password_change` when
`mustChangePassword` is true. Guard clauses, or the app deadlocks:

- skip when `!$event->isMainRequest()`
- skip when there is no authenticated `User`
- skip when the current route is already `account_password_change` or `app_logout`

**Tests** — functional:
- a flagged user requesting `/dashboard` is redirected to `/account/password`
- the same user can load `/account/password` (no redirect loop) and can log out
- a successful change clears the flag and unblocks `/dashboard`
- wrong `currentPassword` re-renders with an error and does not change the password
- a weak new password is rejected

```bash
make test
```

---

## Step 10 — Password reset flow

**Config** `config/packages/reset_password.yaml`:

```yaml
symfonycasts_reset_password:
    request_password_repository: App\Account\Repository\ResetPasswordRequestRepository
    lifetime: 3600
    throttle_limit: 3600
    enable_garbage_collection: true
```

**New** `src/src/Account/Repository/ResetPasswordRequestRepository.php` — extends
`ServiceEntityRepository`, implements `ResetPasswordRequestRepositoryInterface`, uses
`ResetPasswordRequestRepositoryTrait`, implements `createResetPasswordRequest()`.

**New** `src/src/Account/Service/PasswordResetService.php`:

- `requestReset(string $email): void` — look up by normalized email; **return silently when not found or not `Active`** (FR-004: no enumeration; the controller flashes the same message either way). Generate a token via `ResetPasswordHelperInterface`, then send mail **after** the request is persisted.
- `validateToken(string $token): User` — delegate, mapping bundle exceptions to named application exceptions (`ResetTokenExpired`, `ResetTokenInvalid`, `ResetRequestedTooOften`).
- `resetPassword(string $token, string $plainPassword): void` — inside `EntityManagerInterface::wrapInTransaction()`: consume the token via `removeResetRequest()`, then `PasswordChanger::change()`. Both commit or neither (D12). Mail, if any, is dispatched after commit.

**New** `src/src/Account/Mail/AccountMailer.php` — thin adapter over `MailerInterface` with
`sendPasswordReset`, `sendEmailVerification`. Templated emails with a plain-text alternative.

**New** DTOs/forms: `ForgotPasswordInput` (`#[Assert\Email]`), `ResetPasswordInput`
(`#[PasswordRequirements]` + `RepeatedType`).

**New** `src/src/Account/Controller/PasswordResetController.php`:
- `GET|POST /password/forgot` → `account_password_forgot`; always flashes the same confirmation.
- `GET|POST /password/reset/{token}` → **store the token in the session and redirect to the tokenless URL** (the bundle's documented pattern), so the token never sits in the browser history or a `Referer` header on the form page.

**Tests** — functional, using `ClockSensitiveTrait` for expiry and the existing `EmailsTest.php` as the
mailer-assertion reference:
- full happy path: request → email sent → follow link → set password → log in with it
- unknown email produces an identical response and flash to a known email, and sends no mail
- expired token rejected (advance the clock past 3600s)
- token single-use: replaying the same token after success fails
- Inactive user requesting a reset gets the same neutral response and no mail
- a second request inside the throttle window is refused

```bash
make test
```

---

## Step 11 — Email verification flow

**Config** `config/packages/verify_email.yaml`: `symfonycasts_verify_email: lifetime: 86400` (24h, BR-003).

**New** `src/src/Account/Service/EmailVerificationService.php`:

- `sendVerification(User $user): void` — `VerifyEmailHelperInterface::generateSignature('account_verify_email', (string) $user->getId(), $user->getEmail())`, then mail via `AccountMailer`.
- `verify(Request-derived primitives, User $user): void` — **idempotent**: if `emailVerifiedAt` is already set, return without error. A signed URL stays valid until it expires even after use (D6), so a second click must be a no-op, not a failure. Otherwise validate the signature, stamp `emailVerifiedAt` from `ClockInterface`, flush.
- Map `ExpiredSignatureException` / `InvalidSignatureException` / `WrongEmailVerifyException` to named application exceptions.

The service must not accept a `Request` object (Golden Principle 3) — the controller extracts the signed URI
and the `id` query parameter and passes primitives.

**New** `src/src/Account/Controller/EmailVerificationController.php`:
- `GET /verify/email` → `account_verify_email` (the signature binds the `id` query parameter). Must be reachable while **anonymous** — users click the link from a mail client where they are not logged in.
- `POST /verify/resend` → re-signs and re-sends for the current user.

**Tests** — functional:
- valid signature marks the account verified
- clicking the same link twice succeeds both times and does not error (idempotency)
- a tampered signature is rejected
- an expired signature is rejected (clock advanced past 86400s)
- changing the email invalidates a previously issued signature
- with `EMAIL_VERIFICATION_REQUIRED=1`, an unverified player cannot log in; a verified one can

```bash
make test
```

---

## Step 12 — Dashboard hub and access control

**New** `src/src/Account/Service/RoleDashboardResolver.php`: `resolveRouteName(UserRole $role): string` —
a pure `match`, no HTTP awareness.

**New** `src/src/Account/Controller/DashboardController.php`: `GET /dashboard` → `account_dashboard`,
redirects to the resolved route. Four placeholder route trees, each rendering a skeleton template:
`/admin` (`admin_dashboard`), `/trainer` (`trainer_dashboard`), `/coach` (`coach_dashboard`),
`/family` (`family_dashboard`).

**Edit** `security.yaml` — `access_control`, **first match wins, so public routes come first**:

```yaml
- { path: ^/login,           roles: PUBLIC_ACCESS }
- { path: ^/password/forgot, roles: PUBLIC_ACCESS }
- { path: ^/password/reset,  roles: PUBLIC_ACCESS }
- { path: ^/verify,          roles: PUBLIC_ACCESS }
- { path: ^/admin,           roles: ROLE_SUPER_ADMIN }
- { path: ^/trainer,         roles: ROLE_TRAINER }
- { path: ^/coach,           roles: ROLE_COACH }
- { path: ^/family,          roles: ROLE_PLAYER }
- { path: ^/account,         roles: ROLE_USER }
- { path: ^/dashboard,       roles: ROLE_USER }
```

Leave `role_hierarchy` empty for the four business roles (D3). No catch-all rule — the existing public
pages must stay public (Constraint 5).

**Edit** `templates/account/login.html.twig`: point `_target_path` at `account_dashboard`, add a link to
`/password/forgot`, keep the `csrf_token('authenticate')` hidden field, keep `autocomplete` attributes.

**Tests** — `tests/Account/Functional/AuthorizationMatrixTest.php`, the highest-value test in this task:
a data provider crossing **four roles × the four dashboard trees** asserting 200 for the owning role and
403 for the other three (16 assertions), plus anonymous access to each protected path redirecting to
`/login`, plus each public path reachable anonymously.

```bash
cd docker && docker compose run --rm -T php-fpm bin/console debug:router
make test
```

---

## Step 13 — Tenant context

**New** `src/src/Account/Repository/OrganizationRepository.php`: `findOneByOwner(User $owner): ?Organization`.

**New** `src/src/Account/Service/TenantContext.php`:

- `currentOrganizationId(): ?int` — trainer → their owned organization; coach → **null with a documented TODO** until TASK-003 creates `CoachAssignment`; player/super-admin → null.
- `requireOrganizationId(): int` — throws a named `NoOrganizationInContext` exception.

**Document the binding convention in the class docblock**, because it constrains all six tasks (D11):
every organization-scoped repository method takes the organization id as a **required** parameter. No
Doctrine SQL filter — it applies globally and silently, TASK-002 legitimately needs cross-tenant reads, and
a disabled filter fails open.

**Tests** — unit with a fake repository: trainer resolves their org; player returns null;
`requireOrganizationId()` throws for a player.

---

## Step 14 — Super admin bootstrap command

**New** `src/src/Account/Service/SuperAdminCreator.php`: creates an `Active`, verified
`ROLE_SUPER_ADMIN` user; throws a named exception on duplicate email.

**New** `src/src/Account/Command/CreateSuperAdminCommand.php` — `app:account:create-super-admin`, email
argument, password prompted **hidden** (never a CLI argument, so it stays out of shell history), returns
`Command::SUCCESS` / `Command::FAILURE`. There is no UI path to the first admin account; this is it.

**Tests** — `CommandTester`: creates a usable account (assert it can then authenticate), exits non-zero on
a duplicate email, and rejects a password failing `PasswordRequirements`.

---

## Step 15 — Fixtures

**New** `src/src/Account/DataFixtures/AccountFixtures.php` (follow `src/Videos/DataFixtures/`), covering
every branch later tasks and tests need: one Active user per role, one Inactive, one Deleted, one with
`mustChangePassword`, one unverified player, and one Organization owned by the trainer.

```bash
make db-seed
```

---

## Step 16 — Templates and accessibility

**New/edit** under `src/templates/account/`: `login.html.twig` (moved), `forgot_password.html.twig`,
`reset_password.html.twig`, `verify_notice.html.twig`, `change_password.html.twig`, and four dashboard
skeletons.

Per NFR-004 and the project's `wcag-accessibility` guidance:
- every input has a real `<label for>`; no placeholder-as-label
- validation errors linked via `aria-describedby`, plus an error summary at the top of the form
- focus moves to the first invalid field on re-render
- `autocomplete="email"`, `current-password`, `new-password` set correctly
- flash messages in an `aria-live="polite"` region
- the show/hide-password toggle is a Stimulus enhancement with an accessible name; **the form must work with JavaScript disabled** — server-side validation stays authoritative

```bash
cd docker && docker compose run --rm -T php-fpm bin/console lint:twig templates
```

---

## Step 17 — Update living specs

Per the planning method, durable decisions must not live only in task docs.

- **New** `specs/architect-architecture.md` — the epic-spanning decisions: module layout, one-role enum with
  no hierarchy, the tenancy convention (D11), the `EquatableInterface` session-invalidation rule, and the
  removal of the JSON login path.
- **Edit** `specs/MANIFEST.md` — fill the `architect-architecture.md` row's `Last Updated`, and record under
  Key Decisions that authentication is session-based and server-rendered with no JSON API.

---

# Test Plan by Layer

| Layer | Files | Covers |
|:------|:------|:-------|
| Unit | `tests/Account/Unit/Entity/UserTest.php`, `Enum/`, `Security/AccountStatusCheckerTest.php`, `Service/RoleDashboardResolverTest.php`, `Service/TenantContextTest.php` | Role derivation, email normalization, `isEqualTo`, status × role × flag matrix, route mapping, tenancy resolution |
| Repository integration | `tests/Account/Integration/Repository/UserRepositoryTest.php`, `ResetPasswordRequestRepositoryTest.php` | Case-insensitive load, DB unique constraint, selector lookup |
| Functional | `LoginTest`, `LoginThrottlingTest`, `PasswordResetTest`, `EmailVerificationTest`, `PasswordChangeTest`, `AuthorizationMatrixTest` | Routing, validation, CSRF, status refusal, throttling, token expiry/reuse, redirect guard, 200/403 matrix |
| Console | `tests/Account/Functional/Command/CreateSuperAdminCommandTest.php` | Exit codes, duplicate handling, password policy |
| Not covered | Browser/E2E | No browser tooling is configured in this project — report `N/A - tooling not configured` |

Mailer assertions use the framework's `MailerAssertionsTrait` (see `tests/Shared/Functional/.../EmailsTest.php`).
Time-dependent tests use `symfony/clock`'s `ClockSensitiveTrait`; no test may call `sleep()`.

# Verification Commands

```bash
make test                     # PHPUnit
make cs-check                 # PHP-CS-Fixer (dry run)
make stan                     # PHPStan level 5
make lint                     # cs-check + stan

cd docker && docker compose run --rm -T php-fpm composer validate --strict
cd docker && docker compose run --rm -T php-fpm composer audit
cd docker && docker compose run --rm -T php-fpm bin/console lint:container
cd docker && docker compose run --rm -T php-fpm bin/console lint:yaml config
cd docker && docker compose run --rm -T php-fpm bin/console lint:twig templates
cd docker && docker compose run --rm -T php-fpm bin/console debug:router
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:schema:validate --skip-sync
cd docker && docker compose run --rm -T php-fpm bin/console cache:warmup --env=prod
```

`composer test`, `composer analyse`, and `composer lint` are **not defined** in this project (only
`cs-check` / `cs-fix`) — report them as `N/A - tooling not configured` and use the Makefile targets.
`grumphp` runs PHP-CS-Fixer, PHPStan, and yamllint on pre-commit, so commits enforce a subset automatically.

# Rollout Risks and Rollback

| ID | Risk | Mitigation / rollback |
|:---|:-----|:----------------------|
| R1 | `roles` → `role` backfill loses role data | Expand (Step 4) and contract (Step 5) are separate migrations; `roles` survives until Step 5. Step 4 proves `down()` works before proceeding |
| R1b | `?` operator in backfill SQL collides with Doctrine placeholders | Use `jsonb_exists()`; called out in Step 4 |
| R2 | Login throttling stored in filesystem `cache.app` — per-node, resets on cache clear | Acceptable single-node. A shared Redis/Doctrine store is required before scaling out, which NFR-002's 1,000 concurrent users implies. **Unresolved** |
| R3 | Four new composer dependencies | `composer audit` in Step 1; all are Symfony-ecosystem packages with recipes |
| R4 | Mail is synchronous (`sync://` only) — slow SMTP slows reset/verification requests | Accepted for now. Fix is `MESSENGER_TRANSPORT_DSN` + routing `SendEmailMessage` async, which needs a worker. **Deferred** |
| R5 | Namespace move breaks container/route/Doctrine config | Fails loudly at boot, not silently at runtime. Step 2 verifies before any behavior change |
| R6 | Email lowercasing is irreversible | Step 4 aborts on collisions rather than silently merging; `down()` documents the irreversibility |
| R7 | Forced-change subscriber could deadlock the app | Allowlist tested explicitly in Step 9 (loop and logout cases) |
| R8 | Q-01.05 and Q-01.07 ship as defaults if nobody decides | Both are single-config-key changes (Steps 7, 8) |
| R9 | G-15 (trainer deactivation vs their organization) unanswered | `Organization` is created here but no deletion behavior. **Must be resolved before TASK-002** |

No feature flag guards this work: it is the application's first real authentication. Rollback is
`git revert` plus `doctrine:migrations:migrate prev` twice.

# Definition of Done (`.claude/DOD.md`, Standard tier)

- [x] `git diff --stat` reviewed — 107 files, +8402/-292
- [x] Task file naming follows the skill-prefix convention
- [ ] No `.env` secrets read or committed — **first clause passes, second fails.** `.env` is git-ignored (`.gitignore:2`) and only `.env.example`/`.env.test` are tracked, so no secret was committed. But the two new keys were never added to `.env.example`: git history shows that file has been touched exactly once, by `19d77ff` (the initial template commit, predating this epic), and `b96d000` did not modify it — see note 4
- [x] `memory-bank/scripts/validate.py` passes; Project Brain validation passes
- [x] `composer validate --strict` passes — `name`/`description` added (`abed1c9`); the advisories this surfaced are cleared in `0a75c8c`
- [x] `php -l` clean on changed files — 68 changed PHP files, 0 failures
- [x] `make test` passes — 155 tests, 364 assertions
- [x] `make cs-check` and `make stan` pass — 0 of 112 files fixable; no PHPStan errors
- [x] `lint:container`, `debug:router`, `lint:yaml config` (28 files), `lint:twig templates` (50 files) clean
- [x] `doctrine:schema:validate --skip-sync` in sync; migrations included and `down()` proven — all 3 epic migrations rolled back (18 queries) and re-applied against the `_test` database, leaving dev data untouched; R1's expand/contract split verified in that cycle
- [x] Happy path and highest-risk failure path tested per flow — see note 3
- [x] `Controller -> Service -> Repository` respected; no queries in controllers; no `Request` in services — one boundary leak found and fixed (note 1); note 2 accepted
- [x] Pragmatic SOLID review: no pass-through layers introduced — see note 5
- [x] Validation at DTOs/forms; authorization via `access_control` and `user_checker`
- [x] OWASP review: enumeration, CSRF, session fixation, throttling, token single-use, no secrets logged
- [x] Self-reviewed against `.claude/GOLDEN-PRINCIPLES.md` — all 10 principles, one nit (note 1)
- [x] `specs/` updated (Step 17) — `specs/architect-architecture.md` present; `MANIFEST.md` row stamped 2026-08-21 and Key Decisions record session-based auth with no JSON API (verified: no `json_login` or `ApiLoginController` remains)

## Review notes

1. **Controller-level data access — fixed.** `EmailVerificationController::verify()` used to inject
   `UserRepository` and call `$users->find($userId)` itself, resolving the id before handing a `User`
   to the service. That lookup now lives in `EmailVerificationService::verify()`, whose signature took
   `(string $signedUrl, User $user)` and now takes `(string $signedUrl, int $userId)`; the service
   already had the repository injected, so nothing new was wired. The controller passes
   `$request->query->getInt('id')` and keeps only its `AccountException` -> flash mapping.

   A missing id now raises `VerificationLinkInvalid`, the same exception a tampered signature maps to.
   That is deliberate — it already carried the exact message the controller used to hardcode
   ('This verification link is not valid.'), so the 400 and the user-visible text are unchanged, and an
   unknown id stays indistinguishable from a bad signature rather than becoming an id oracle.
   `EmailVerificationTest::testUnknownUserIdIsRejected` covers it and still passes.
2. **`Request` in a service.** `EmailVerificationService` builds `Request::create($signedUrl)` internally
   (line 73) because the bundle's URL-string API is deprecated. No service *signature* accepts a
   `Request`, and the reason is documented at the call site. Accepted.
3. **Test-plan deviations.** The planned `ResetPasswordRequestRepositoryTest` was not written; its
   concerns are covered functionally by `PasswordResetTest::testExpiredTokenIsRejected` and
   `::testTokenCannotBeUsedTwice`. No `UserRoleTest`; role derivation is covered by
   `UserTest::testGetRolesReturnsExactlyThePrimaryRolePlusRoleUser` and `RoleDashboardResolverTest`.
   Substantively covered, structurally different from the plan.
4. **`.env.example` is missing both new keys — open.** `SESSION_IDLE_TTL` and
   `EMAIL_VERIFICATION_REQUIRED` were never documented in `src/.env.example`. Settled from git history
   rather than by reading the file (this environment blocks reads of `.env*`): the file has exactly one
   commit in the whole repo, `19d77ff` "Add Symfony DDD project template", which predates Epic-01, and
   the epic commit `b96d000` does not touch it.

   Impact is documentation only, which is why nothing failed: both parameters carry in-container
   defaults (`config/services.yaml:19,26`), so the container resolves them whether or not the env vars
   exist. The cost is discoverability — an operator reading `.env.example` cannot tell that a 7-day
   session window and a login-blocking verification gate are tunable. Lines to add:

   ```dotenv
   ###> app/session ###
   # Authenticated session idle window in seconds. Default: 604800 (7 days).
   SESSION_IDLE_TTL=604800
   # Whether an unverified email blocks login for players and coaches. Default: true.
   EMAIL_VERIFICATION_REQUIRED=true
   ###< app/session ###
   ```

   Not applied here: this session's permission settings deny writes to that path.
5. **Interface count.** The plan predicted one new interface (`UserLoaderInterface`, implemented not
   declared). The code also declares the `AccountException` marker interface
   (`src/Account/Exception/AccountException.php:14`), which the controllers catch to map failures to
   messages. Idiomatic typed-exception marker, not a pass-through layer — the plan's wording is simply
   out of date.
6. **Still open from the plan's own risk table.** R2 (login throttling in filesystem `cache.app`, per-node
   — needs a shared store before NFR-002's 1,000 concurrent users), R4 (mail is synchronous on `sync://`),
   and R9 (G-15, trainer deactivation vs. their organization) — R9 is marked **must be resolved before
   TASK-002**. `doctrine/cache` is also flagged abandoned by `composer audit`.

# Open Questions Blocking Nothing But Shipping Defaults

| ID | Question | Default taken | Where to change |
|:---|:---------|:--------------|:----------------|
| Q-01.05 | Verification required before login? | Yes for player/coach, no for trainer/admin | `EMAIL_VERIFICATION_REQUIRED` + `AccountStatusChecker` |
| Q-01.07 | Session timeout? | 7-day idle | `SESSION_IDLE_TTL` |
| Q-01.04 | Full transactional email list | Reset + verification only | `AccountMailer` |
| G-15 | Trainer deactivation vs organization | Not addressed | Must be answered before TASK-002 |
