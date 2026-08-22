# TASK-002 Architecture: Super Admin User Management & Impersonation

**Inputs**: `tasks/TASK-002/requirements-analyst-requirements.md` (FR-020…033, NFR-020…023, BR-020…026),
`specs/Epic-01_User_Management_Authentication_SPEC.md` (US-01.01, US-01.07, US-01.12, US-01.13, §8, §9, §10 flows 4 and 7),
`specs/architect-architecture.md` (the epic-level decisions this task must not contradict).

**Scope**: decisions binding the implementation of TASK-002. Decisions that outlive this task are promoted
to `specs/architect-architecture.md` when the task lands.

**Status legend**: `Decided` — approved, not yet implemented. `Implemented` — in the codebase and verified.

---

## Gap resolutions confirmed with the requester (2026-08-22)

The requirements file left four questions open whose answers change the shape of the code. All four were
put to the requester before any code was written; the answers are binding for this task.

| Gap | Question | Answer | Consequence |
|:----|:---------|:-------|:------------|
| G-14 | What does a 1-hour impersonation expiry do? | **Exit to the admin view.** The operator keeps their own session. | A `kernel.request` subscriber restores the original token; no logout, no re-authentication. `endReason = expiry`. |
| G-16 | FR-025 erases PII, FR-027 retains the original email plus a data snapshot. | **Minimal deletion record.** Original user id, actor, reason, timestamp, and a SHA-256 digest of the original email. **No PII snapshot.** | `UserDeletionRecord` has no `originalEmail` and no `originalDataSnapshot` column. Deviation from FR-027 recorded below (D8) and carried to the epic gap list for legal sign-off. |
| G-17 | May a Super Admin deactivate or delete themselves, or the last Super Admin? | **Blocked, both.** | Two guard clauses in `UserDeactivator` and `UserAnonymizer`, enforced in the service, not the template. |
| G-18 | Is per-action logging during impersonation optional? | **Required for state-changing actions.** | `AuditLogEntry` plus an `ImpersonationContext` that stamps `impersonator_id` on every entry written while switched. |

---

## D1 — Profile fields land on `User` now, not in TASK-004

**Status**: Decided

FR-021 needs a trainer name and phone at creation; FR-025 must set name → `Deleted User` and phone → NULL.
Neither column exists: TASK-001 shipped `User` with credentials, role, status and timestamps only.

`User` gains **`name` (NOT NULL) and `phone` (nullable)** in this task. Full profiles — photo, school,
address, per-role profile tables — remain TASK-004.

`name` is a **required constructor argument**, not a nullable column with a setter. The alternative
(nullable column, presence enforced only by a Validator constraint at the boundary) leaves a latent trap:
`new User(...)` followed by a flush would hit a NOT NULL violation at runtime, in whatever code path forgot
to call the setter. Making it a constructor parameter turns that into a compile-time argument error. The
cost is updating the six existing construction sites, all of which are in this repository.

The migration backfills existing rows from the email local part before adding the NOT NULL constraint, so
the two accounts that predate this task (the CLI-created Super Admin and the fixtures) stay valid.

**No photo column.** FR-025's "photo → default avatar" is satisfied vacuously today and must be revisited
when TASK-004 adds the column — recorded as a follow-up, not silently dropped.

## D2 — Three new entities, one shared audit spine

**Status**: Decided

| Entity | Purpose | Lifetime |
|:-------|:--------|:---------|
| `AuditLogEntry` | Every sensitive/state-changing operation (BR-025, G-18) | Append-only, never updated |
| `ImpersonationSession` | One row per impersonation, opened on switch, closed on exit/expiry (FR-032) | Written twice: open, then close |
| `UserDeletionRecord` | GDPR deletion compliance (FR-027, as narrowed by G-16) | Append-only |

`AuditLogEntry` carries `actor` (who acted), `impersonator` (nullable — the Super Admin behind the actor),
`action`, `subjectType`, `subjectId`, `payload` (JSON), `occurredAt`.

**Actor and impersonator are `ManyToOne` associations with `ON DELETE RESTRICT`**, not loose integer ids.
The compliance report joins on them for every row, and RESTRICT encodes the rule the rest of this task
enforces anyway: a user row is never hard-deleted, only anonymized. A future hard delete would fail loudly
against this constraint instead of silently orphaning an audit trail.

**The subject is polymorphic** (`subjectType` string + `subjectId` int) with no FK, because an audit entry
must be able to point at any future entity without this table growing a column per module.

## D3 — Impersonation is Symfony's `switch_user`, wrapped, never re-implemented

**Status**: Decided

`switch_user` is enabled on the `main` firewall with `role: ROLE_ALLOWED_TO_SWITCH`, granted to
`ROLE_SUPER_ADMIN` through `role_hierarchy`.

This is **not** a contradiction of the epic decision that `role_hierarchy` stays empty. That decision is
about the four *business* roles: a Super Admin must not inherit `ROLE_TRAINER` and be routed into
organization-scoped views with no organization. `ROLE_ALLOWED_TO_SWITCH` is a capability grant, not a
business role, and `specs/architect-architecture.md` already states that "Super Admin capability comes from
explicit grants on `/admin/*` plus impersonation".

Flow, and why it is split the way it is:

1. `POST /admin/users/{id}/impersonate` — a real form submission, so CSRF applies (NFR-X03) and the
   confirmation in FR-028 has something to submit to. The controller authorizes through `ImpersonateVoter`
   and redirects to `/dashboard?_switch_user={email}`.
2. `SwitchUserListener` performs the actual switch on that redirect and issues its own redirect to
   `/dashboard`, which the existing role hub resolves to the impersonated user's landing page.
3. `SwitchUserAuditSubscriber` listens on `security.switch_user` and opens or closes the
   `ImpersonationSession`. Start and exit are distinguished by whether the event's token is a
   `SwitchUserToken`.

**The Super-Admin-target block is enforced in the subscriber, not only in the controller** (FR-030). The
firewall listener answers any request carrying `?_switch_user=`, so a controller-only check would be
bypassed by typing the URL. The subscriber throws `AccessDeniedException`, which
`SwitchUserListener::authenticate()` does not catch — it only catches `AuthenticationException` — so it
surfaces as a 403 rather than being swallowed.

`GET /impersonation/exit` exists as a named route so the banner and the tests have a stable target; it
redirects to `/admin?_switch_user=_exit` and lets Symfony restore the original token.

**Rejected**: a hand-rolled second token in the session. It would duplicate `SwitchUserToken`, miss the
`ContextListener` integration that makes the switch survive a redirect, and put an authentication primitive
in application code.

## D4 — Expiry is a request subscriber reading the open session row

**Status**: Decided

Symfony has no native expiry for `switch_user` (G-14). A `kernel.request` subscriber at priority 7 —
alongside `SessionIdleTimeoutSubscriber`, below the firewall at 8 so a token exists — checks whether the
current token is a `SwitchUserToken` whose open `ImpersonationSession` started more than an hour ago, and
if so restores the original token, closes the record with `endReason = expiry`, flashes a notice and
redirects to the admin dashboard.

**The elapsed time is read from the open database row, not from a session key.** A session key would be a
second source of truth for something the audit table already records authoritatively, and the two would
drift the first time a session was restored from a cookie without the key. The cost is one indexed lookup
per request, and only while a switch is active.

## D5 — Authorization placement

**Status**: Decided

| Control | Where | Why |
|:--------|:------|:----|
| The whole `/admin` tree is Super-Admin-only | `access_control` (already present from TASK-001) | Route-tree gate, no object involved |
| May *this* admin impersonate *this* user | `ImpersonateVoter` | A real object is being authorized (BR-021) |
| May this user be deactivated / deleted | Service guard clauses | The rule is about the domain state (last Super Admin, self), not about the request |

Deliberately **not** a voter for deactivate/delete: the constraint is an invariant of the account model, and
it must hold for a CLI or fixture caller too. A voter would only cover the HTTP path.

## D6 — Directory query shape

**Status**: Decided

`UserRepository::searchForDirectory()` builds one query with optional role, status and term predicates, and
KnpPaginator paginates it (NFR-020, 10,000 users under 3s).

- **Sort columns are whitelisted in the repository.** KnpPaginator's sortable links put a field name in the
  query string and interpolate it into DQL; an unfiltered value there is a DQL injection.
- **Search is tool-scoped** (email and name only) — FR-020 states explicitly that this is not a global
  platform search.
- **`Deleted` users are hidden unless the status filter selects them.** A directory whose default view is
  full of `deleted_17@example.com` rows is worse at its job, and the rows remain reachable.
- Index `(role, status)` on `"user"` supports the default filtered view; `email` is already uniquely indexed.

## D7 — Trainer creation is one transaction plus one post-commit side effect

**Status**: Decided

`TrainerAccountCreator::create()` wraps user + organization + audit entry in
`EntityManagerInterface::wrapInTransaction()`, then sends the invitation email **after commit**, per the
epic-level rule that side effects which cannot join a transaction are dispatched after it. A bounced
invitation must not roll back a created account; it is re-sendable, the account is not re-creatable.

The temporary password is generated by `TemporaryPasswordGenerator`, which draws from
`random_int()` and is shaped to satisfy `PasswordRequirements` (length and an uppercase letter) so a
generated credential can never be rejected by the very form the trainer is forced through next.
`mustChangePassword` is set, which TASK-001's `RequirePasswordChangeSubscriber` already enforces (FR-022).

The account is created **email-verified**: an administrator typing the address is the same trust level as
the CLI-created Super Admin, and `AccountStatusChecker` already exempts trainers from the verification gate.

## D8 — Deletion anonymizes; the compliance record holds no PII

**Status**: Decided

`UserAnonymizer::anonymize()` performs FR-025 exactly — name → `Deleted User`, email →
`deleted_{id}@example.com`, phone → NULL, status → `Deleted` — and additionally:

- replaces the password hash with one derived from fresh random bytes, so the credential is unusable even
  if the status check were ever bypassed;
- clears `emailVerifiedAt` and `mustChangePassword`;
- stamps `recordPasswordChange()`, which through TASK-001's `EquatableInterface` implementation
  de-authenticates every live session the deleted user had.

`UserDeletionRecord` stores `originalUserId`, `originalEmailDigest` (SHA-256 of the normalized address),
`anonymizedEmail`, `deletedBy`, `reason`, `deletedAt`.

**Deviation from FR-027, deliberate.** FR-027 asks for the original email in clear and "a backup of the
original data". Storing both would re-create, in a table nobody erases, exactly the personal data the
erasure request just removed — the record would defeat the operation it documents. The digest answers the
question the requirement is actually for ("was the account for this address deleted, when, by whom, and
why?") for anyone who can supply the address, and answers nothing to someone who cannot. It is a
verification token, not a secret: a holder of a leaked table can confirm an address they already guessed,
but cannot enumerate addresses from it.

This is a legal question, not an engineering one. It is carried to the epic gap list as **G-16, open,
awaiting legal sign-off**, and reverting to a full snapshot is a migration plus a service change, not a
redesign.

**FR-026 cascade audit, performed**: the only `ON DELETE CASCADE` referencing `"user"` is
`reset_password_request.user_id`. That table holds transient recovery tokens, not history, and nothing
aggregates over it. No change needed; the finding is recorded so the next task does not repeat the search.
`organization.owner_id` is already `RESTRICT`.

## D9 — The banner ships in the base layouts, not in a response listener

**Status**: Decided

FR-029 requires the banner on every page. It is included at the top of `templates/base.html.twig` **and**
`templates/videos/base.html.twig` — the two independent roots in this repository — as a `role="status"`
region, sticky, with a non-colour cue (a text label) so the meaning is not carried by colour alone
(NFR-X04).

**Rejected**: injecting the markup from a `kernel.response` subscriber. It would apply to every response
including partials and non-HTML bodies, and it would put presentation in the HTTP layer to avoid editing
two templates.

## D10 — Confirmation dialogs use native `<dialog>`, not Stimulus

**Status**: Decided

The requirements name a Stimulus controller. **Stimulus is not installed in this project** (no
`symfony/stimulus-bundle`, no `@hotwired/stimulus` in the importmap), and installing it to obtain three
confirmation dialogs would add a bundle, a recipe and a controllers.json to the deployment surface for
markup the platform provides natively.

The dialogs use the native `<dialog>` element driven by a ~40-line vanilla ES module loaded through
AssetMapper, which the project already uses. `showModal()` gives the focus trap, Escape-to-close and
`role="dialog"` semantics NFR-023 asks for, at no dependency cost. `window.confirm` is not used, per the
explicit prohibition.

**Progressive enhancement is preserved and is the actual security boundary**: every destructive action is a
`<form method="post">` with a CSRF token that works with JavaScript disabled. The dialog intercepts the
submit; it never *is* the submit.

## Component: Audit Logging (promoted to epic level on landing)

**Status**: Decided

`AuditLogger::log()` **persists but does not flush**. Callers own the transaction, so the audit entry
commits or rolls back with the change it describes (NFR-022). A logger that flushed on its own would record
operations that later rolled back.

`ImpersonationContext` resolves the Super Admin behind a switched token and is the single reader of
`SwitchUserToken` outside the security layer. `AuditLogger` asks it for the impersonator on every write, so
G-18's requirement holds for entries written by code that has never heard of impersonation.

## Deferred / Unresolved

| ID | Item | Blocks |
|:---|:-----|:-------|
| G-16 | Deletion record holds a digest, not the email and snapshot FR-027 asks for. Needs legal sign-off. | Compliance review |
| G-15 | Deactivating or deleting a **trainer** leaves their organization's players, coaches, links and branding undefined. Unchanged by this task — the guards here are about Super Admins. | TASK-003, TASK-004 |
| — | FR-025's "photo → default avatar" has no column to clear until TASK-004 adds one. | TASK-004 |
| Q-01.04 | Invitation email copy is written to a reasonable default; the client has not supplied the transactional email list. | — |
| R2 | Audit and directory queries are unbounded by tenant because Super Admin tooling is cross-tenant by design. Not a defect; noted so it is not "fixed". | — |
