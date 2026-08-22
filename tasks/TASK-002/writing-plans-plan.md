# TASK-002 Implementation Plan: Super Admin User Management & Impersonation

**Inputs**: `tasks/TASK-002/requirements-analyst-requirements.md` (FR-020…033),
`tasks/TASK-002/architect-architecture.md` (D1…D10).
**Target**: Symfony 7.4, PHP >= 8.2, PostgreSQL, Doctrine ORM 2.15. Every command runs through Docker via
the Makefile (`make test`, `make stan`, `make cs-fix`, `make migrate`).

Written for an engineer with no prior context. Each step names the files, the behavior, and a command whose
output proves it landed.

## Goal

Ship the Super Admin control surface: a global user directory, trainer account creation with an emailed
temporary credential, account editing, the two-tier removal model (deactivate = reversible, delete = GDPR
anonymization), impersonation with a sticky banner and a full audit trail, and an impersonation history
report.

## Non-Goals

- No ShareLinks, no player or coach creation — a Super Admin creates **trainers** only (TASK-003).
- No profile photos, no per-role profile tables, no branding (TASK-004).
- No JSON API. Server-rendered Twig, per the epic decision.
- No trainer-side user management. Everything here is `/admin/*`.
- No resolution of G-15 (what happens to a trainer's organization when the trainer is removed).

## Current → Target Behavior

| Aspect | Current | Target |
|:-------|:--------|:-------|
| User directory | None | `/admin/users`, paginated, filterable by role and status, searchable, sortable |
| Trainer accounts | Only via `app:create-super-admin` (Super Admins only) | `/admin/users/new`, creates user + organization + temp password + invitation email |
| Editing a user | None | `/admin/users/{id}/edit`, including role change |
| Deactivation | Status column exists, nothing writes it | `POST /admin/users/{id}/deactivate` and `/reactivate`, guarded, audited |
| Deletion | None | `POST /admin/users/{id}/delete`, anonymizes PII, writes a compliance record |
| Impersonation | `switch_user` commented out in `security.yaml` | Enabled, voter-guarded, banner, 1-hour expiry, audited |
| Audit trail | None | `audit_log_entry` written in-transaction for every sensitive operation |
| `User.name` / `User.phone` | Do not exist | `name` NOT NULL (backfilled), `phone` nullable |

## Compatibility Constraints

1. **Table `"user"` keeps its name and its quoting.** Migrations ALTER; they never CREATE it.
2. `config/routes.yaml` and `config/packages/doctrine.yaml` register modules explicitly. New entities under
   `src/Account/Entity/` and new controllers under `src/Account/Controller/Admin/` are covered by the
   existing `Account` entries — directory resources recurse — so no config change is needed for either.
3. `config/packages/csrf.yaml` enables **stateless** CSRF with token id `submit`. New forms use the default
   id. Hand-written `<form>` tags use `csrf_token('submit')` and post `_token`, matching that id.
4. `config/services.yaml` excludes `src/Account/Entity/` and `src/Account/Enum/` from autowiring. New
   entities and enums land there and inherit the exclusion; new services and subscribers do not.
5. `role_hierarchy` is empty by design for the four business roles. Adding `ROLE_ALLOWED_TO_SWITCH` under
   `ROLE_SUPER_ADMIN` is a capability grant, not a business role (D3).
6. Existing public pages (`/videos`, `/products`, `/starships`) have no `access_control` rule. Do not add a
   catch-all.

## Assumptions Needing Proof

- **A1** No existing `"user"` row would produce a duplicate or empty `name` when backfilled from the email
  local part. The backfill uses `split_part(email, '@', 1)`, which is non-empty for any address that passed
  `User::setEmail()`. Verified by running the migration against the dev database.
- **A2** `SwitchUserListener` does not catch `AccessDeniedException` thrown from a `security.switch_user`
  listener, so the FR-030 block surfaces as 403. Verified by reading
  `vendor/symfony/security-http/Firewall/SwitchUserListener.php` and pinned by a functional test.
- **A3** The firewall's `AccessListener` runs after `SwitchUserListener`, so `/admin?_switch_user=_exit`
  can be requested while the impersonated (non-admin) token is still active. Pinned by the exit test.
- **A4** DAMA rolls back the database per test; the rate-limiter cache pool does not roll back and is
  cleared by `AccountWebTestCase`. New tests inherit both behaviors.

## Steps

### Step 1 — `User` gains `name` and `phone`

Files: `src/Account/Entity/User.php`, `src/migrations/Version20260822120000.php`, plus the six construction
sites (`SuperAdminCreator`, `CreateSuperAdminCommand`, `AccountFixtures`, `AccountWebTestCase`,
`UserTest`, `UserRepositoryTest`).

`name` becomes the second constructor argument (D1). Add `getDisplayName()` returning the name — the single
place templates ask for a human label, so anonymization renders as "Deleted User" everywhere at once
(FR-026's display half).

Migration: add both columns nullable, backfill `name` from `split_part(email, '@', 1)`, set `name` NOT NULL,
add index `IDX_USER_ROLE_STATUS` on `(role, status)` (NFR-020).

**Proof**: `make migrate` then `bin/console doctrine:schema:validate --skip-sync`.

### Step 2 — Audit spine

Files: `src/Account/Enum/AuditAction.php`, `src/Account/Entity/AuditLogEntry.php`,
`src/Account/Repository/AuditLogEntryRepository.php`, `src/Account/Service/ImpersonationContext.php`,
`src/Account/Service/AuditLogger.php`.

`AuditLogger::log()` persists without flushing (D-audit). `ImpersonationContext::impersonator()` returns the
original user when the token is a `SwitchUserToken`, otherwise null.

### Step 3 — Impersonation entities and repository

Files: `src/Account/Enum/ImpersonationEndReason.php`, `src/Account/Entity/ImpersonationSession.php`,
`src/Account/Repository/ImpersonationSessionRepository.php`
(`findOpenForAdmin`, `paginateHistory`).

### Step 4 — Deletion record

Files: `src/Account/Entity/UserDeletionRecord.php`,
`src/Account/Repository/UserDeletionRecordRepository.php`.

### Step 5 — Migration for the three tables

File: `src/migrations/Version20260822120100.php`. Indexes on `(actor_id, occurred_at)`,
`(target_user_id, started_at)`, `(admin_id, ended_at)` for the open-session lookup, and
`(original_email_digest)`.

### Step 6 — Directory query

Files: `src/Account/Repository/UserRepository.php` (`directoryQuery`),
`src/Account/Dto/UserDirectoryFilter.php`, `src/Account/Service/UserDirectoryQuery.php`.

Sort whitelist on the filter DTO (see Deviation 1). `Deleted` hidden unless explicitly selected.

### Step 7 — Account lifecycle services

Files: `src/Account/Service/TemporaryPasswordGenerator.php`,
`src/Account/Service/TrainerAccountCreator.php`, `src/Account/Service/UserProfileEditor.php`,
`src/Account/Service/UserDeactivator.php`, `src/Account/Service/UserAnonymizer.php`,
`src/Account/Service/ImpersonationAuditRecorder.php`, new exceptions under `src/Account/Exception/`.

Guards: no self-deactivation, no self-deletion, no removal of the last active Super Admin (G-17).

### Step 8 — DTOs and forms

Files: `src/Account/Dto/{CreateTrainerInput,EditUserInput,DeleteUserInput}.php`,
`src/Account/Form/{CreateTrainerFormType,EditUserFormType,DeleteUserFormType}.php`.

Email uniqueness is validated at the boundary **and** enforced by the unique index; the service catches the
constraint violation, because a Validator check cannot close a concurrency race.

### Step 9 — Security wiring

Files: `config/packages/security.yaml` (enable `switch_user`, add the `ROLE_ALLOWED_TO_SWITCH` grant),
`src/Account/Security/ImpersonateVoter.php`, `src/Account/Security/SwitchUserAuditSubscriber.php`,
`src/Account/Security/ImpersonationExpirySubscriber.php`, `config/services.yaml`
(`app.impersonation_ttl` parameter, bound as `int $impersonationTtl`).

### Step 10 — Controllers

Files under `src/Account/Controller/Admin/`: `UserController`, `UserStatusController`,
`ImpersonationController`, `AuditController`.

### Step 11 — Templates and assets

Files: `templates/admin/_layout.html.twig`, `templates/admin/users/{index,new,edit}.html.twig`,
`templates/admin/audit/impersonations.html.twig`, `templates/admin/_confirm_form.html.twig`,
`templates/_impersonation_banner.html.twig`, `templates/account/email/trainer_invitation.{html,txt}.twig`,
`assets/confirm-dialog.js` (imported from `assets/app.js`), banner include in `templates/base.html.twig` and
`templates/videos/base.html.twig`.

### Step 12 — Tests

`tests/Account/Functional/Admin/` — directory, creation, editing, status transitions, deletion, historical
integrity, impersonation, authorization matrix.
`tests/Account/Unit/` — anonymizer output, temporary password shape, impersonation context, duration.

**Proof**: `make test`, `make stan`, `make cs-check`.

## Deviations From This Plan, Recorded While Building

**Deviation 1 — KnpPaginator owns the sort, and the whitelist moved with it.** The plan put the sort
whitelist in the repository. It cannot live there: `knp_pagination_sortable()` puts the field in the query
string and the paginator's `OrderByWalker` interpolates it into the `ORDER BY` itself, so a repository-level
`orderBy` competes with the paginator rather than constraining it, and an unrecognized field still reaches
the DQL parser (it surfaced in testing as a 500 from a hand-edited URL). Sorting is now owned in one place:
`UserDirectoryFilter::SORTABLE_FIELDS` is the whitelist, the controller drops an unrecognized value from the
query string at the boundary, `UserDirectoryQuery` passes the same list to the paginator as
`SORT_FIELD_ALLOW_LIST`, and the repository applies no ordering at all.

**Deviation 2 — native `<dialog>` instead of Stimulus.** Recorded in the architecture doc as D10: Stimulus
is not installed in this project, and the native element supplies the focus trap, Escape handling and
dialog semantics NFR-023 asks for without adding a bundle.

**Deviation 3 — `UserProfileEditor` added.** The requirements listed six services and no owner for FR-023.
Putting the edit workflow in the controller would have left the email-uniqueness race and the
last-Super-Admin demotion guard at the HTTP boundary, where a console caller would miss both.

**Deviation 4 — trainer creation returns a result object.** The account is committed before the invitation
is attempted, so a transport failure cannot be reported by throwing. `TrainerCreated` carries whether the
invitation was delivered, and the controller tells the operator — otherwise the failure lives only in a log
file and the trainer never signs in.

**Deviation 5 — G-18 is unit-tested, not covered end to end.** The impersonator stamping is in place and
exercised in `AuditLoggerTest`, but no *audited* action is reachable by an impersonable role yet: every
audited operation today is `/admin`-only, and a Super Admin cannot be impersonated. The end-to-end
assertion becomes possible when a trainer- or coach-facing audited action ships.

## Risks

| Risk | Mitigation |
|:-----|:-----------|
| `name` NOT NULL breaks an unmigrated environment | Backfill runs inside the same migration, before the constraint |
| A `?_switch_user=` URL bypasses the controller's checks | The block lives in the `security.switch_user` subscriber, which the firewall always runs (D3) |
| Audit entry lost when the audited change rolls back | `AuditLogger` never flushes; the caller's transaction owns both |
| Sort parameter interpolated into DQL | Whitelist in the repository, asserted by a test |
| Directory slow at 10,000 users | `(role, status)` index plus pagination; `make test` includes a seeded assertion on the query shape |
