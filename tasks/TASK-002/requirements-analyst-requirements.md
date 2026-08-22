# TASK-002: Super Admin User Management & Impersonation Requirements

## Overview

The Super Admin control surface: a global user directory, trainer account creation, account editing,
the two-tier removal model (deactivate = soft, delete = GDPR anonymization), and user impersonation
with a full audit trail.

## Source

- `specs/Epic-01_User_Management_Authentication_SPEC.md` — US-01.01, US-01.07, US-01.12, US-01.13; §8 (Impersonation Audit Log, User Deletion Compliance), §9 (Trainer Creation, Impersonation Rules, Deactivation, Deletion), §10 Flows 4 and 7
- Depends on **TASK-001** (roles, status enum, forced password change, Organization)

## Functional Requirements

### FR-020: Users Tool — Global Directory
- **Acceptance**: Super Admin sees a paginated list of all users with name, email, role, status, created date. Filters by role and by status. Search is **tool-scoped** (searches users only — explicitly not a global platform search). Sortable columns.
- **Priority**: High

### FR-021: Create Trainer Account
- **Acceptance**: "Create User" → select "Trainer" → enter business name, trainer name, email, phone → account created with role `ROLE_TRAINER`, status `Active`, and its own Organization. Appears immediately in the directory. Duplicate email shows a clear field-level error. Required fields enforced.
- **Priority**: High
- **Restriction**: Only `ROLE_SUPER_ADMIN`. No public trainer registration route may exist.

### FR-022: Trainer Onboarding Credentials
- **Acceptance**: On creation the system either generates a temporary password or sends a setup link. Trainer receives a professional invitation email. A temporary password sets `mustChangePassword` (TASK-001 FR-006). Trainer can then log in and reach the trainer dashboard.
- **Priority**: High

### FR-023: Edit Any User Account
- **Acceptance**: Super Admin can edit any user's profile fields and role. Changes are validated identically to self-service edits. Email uniqueness re-checked on change.
- **Priority**: High

### FR-024: Deactivate and Reactivate User
- **Acceptance**: "Deactivate" shows a confirmation stating history is preserved. On confirm, status → `Inactive`; the user cannot log in. The user still appears in historical analytics, past event rosters, and CRM records (visually marked Inactive). "Reactivate" returns status to `Active`.
- **Priority**: High

### FR-025: GDPR Delete — PII Anonymization
- **Acceptance**: "Delete" shows a warning that the action is irreversible. On confirm: name → "Deleted User", email → `deleted_{id}@example.com`, phone → NULL, photo → default avatar, other personal identifiers → NULL. Status → `Deleted`.
- **Priority**: High

### FR-026: Historical Integrity After Deletion
- **Acceptance**: Attendance, payment, and analytics records survive deletion and render as "Deleted User". Aggregate totals (player counts, revenue sums, attendance rates) are numerically unchanged by a deletion.
- **Priority**: High
- **Note**: This is the requirement most likely to be broken by a naive implementation. It forbids `ON DELETE CASCADE` on any history-bearing FK to `user`.

### FR-027: Deletion Compliance Log
- **Acceptance**: Every deletion records original user ID, original email, who deleted, reason, timestamp, and a backup of the original data for legal compliance. Log is readable by Super Admin only.
- **Priority**: High
- **Tension**: The spec requires both erasing PII *and* retaining the original email plus a data backup. Retention period and access control for that backup are unspecified — see gap G-16.

### FR-028: Start Impersonation
- **Acceptance**: "Impersonate" on a user row → confirmation modal "View platform as {name} ({role})?" → on confirm the session switches. All navigation, permissions, and visible data match the impersonated user exactly.
- **Priority**: High

### FR-029: Impersonation Banner
- **Acceptance**: A sticky banner is visible on every page during impersonation: "Viewing as {name} | Exit Impersonation". Colour-coded (red/orange) to be unmistakable. "Exit Impersonation" returns to the Super Admin view.
- **Priority**: High

### FR-030: Super Admin Impersonation Blocked
- **Acceptance**: Attempting to impersonate another `ROLE_SUPER_ADMIN` fails with a validation error and is not merely hidden in the UI. Enforced server-side.
- **Priority**: High

### FR-031: Impersonation Session Expiry
- **Acceptance**: An impersonation session ends automatically after 1 hour, returning the operator to their own Super Admin view.
- **Priority**: Medium
- **Blocked by**: G-14 — Symfony's `switch_user` has no native expiry; requires a custom listener. Confirm whether expiry means "exit to admin" or "full logout".

### FR-032: Impersonation Audit Log
- **Acceptance**: Each session records impersonating admin, impersonated user, start time, end time, and duration. Actions performed while impersonating are logged with the admin's ID in context. An "Impersonation History" report is available for compliance.
- **Priority**: High

### FR-033: Trainer Creation Audit
- **Acceptance**: Creation of a trainer records who created it, when, and the trainer's details.
- **Priority**: Medium

## Non-Functional Requirements

| ID | Requirement | Metric |
|:---|:------------|:-------|
| NFR-020 | User list with 10,000 users | < 3 seconds, paginated |
| NFR-021 | Profile save | < 1 second |
| NFR-022 | Audit log writes | Must not be lost on request failure — write in the same transaction as the audited change |
| NFR-023 | Directory accessibility | Table has proper headers/scope; actions reachable by keyboard; confirmations are focus-trapped dialogs |

## Business Rules

- **BR-020** Only Super Admin can create trainer accounts; there is no trainer self-registration.
- **BR-021** Super Admin can impersonate any user except another Super Admin.
- **BR-022** Deactivation preserves all history and is reversible.
- **BR-023** Deletion anonymizes PII, preserves history as "Deleted User", and is irreversible.
- **BR-024** Analytics totals must remain accurate after a deletion.
- **BR-025** All sensitive operations (impersonation, deletion) are audit-logged.
- **BR-026** Temporary passwords must be changed on first login.

## Task Breakdown

### Entities

| Entity | Properties | Relations |
|:-------|:-----------|:----------|
| `ImpersonationSession` | `adminId`, `targetUserId`, `startedAt`, `endedAt`, `durationSeconds`, `endReason` (exit/expiry/logout) | belongs to two Users |
| `UserDeletionRecord` | `originalUserId`, `originalEmail`, `deletedByUserId`, `reason`, `deletedAt`, `originalDataSnapshot` (JSON) | references deleting admin |
| `AuditLogEntry` | `actorId`, `impersonatorId` (nullable), `action`, `subjectType`, `subjectId`, `payload` (JSON), `occurredAt` | belongs to User |

### Services

| Service | Purpose | Methods |
|:--------|:--------|:--------|
| `UserDirectoryQuery` | Paginated, filtered, sorted user listing | `search` |
| `TrainerAccountCreator` | Create trainer + Organization + credentials + invite | `create` |
| `UserDeactivator` | Status transitions | `deactivate`, `reactivate` |
| `UserAnonymizer` | GDPR anonymization + compliance record | `anonymize` |
| `ImpersonationAuditRecorder` | Open/close/expire sessions | `start`, `end`, `expireStale` |
| `AuditLogger` | Uniform sensitive-operation logging | `log` |

### Controllers

| Controller | Endpoints | Purpose |
|:-----------|:----------|:--------|
| `Admin\UserController` | `GET /admin/users`, `GET/POST /admin/users/new`, `GET/POST /admin/users/{id}/edit` | Directory, create, edit |
| `Admin\UserStatusController` | `POST /admin/users/{id}/deactivate`, `/reactivate`, `/delete` | Status transitions |
| `Admin\ImpersonationController` | `POST /admin/users/{id}/impersonate`, `GET /impersonation/exit` | Start / exit |
| `Admin\AuditController` | `GET /admin/audit/impersonations` | Compliance report |

### Backend Tasks
- [ ] Migration: CREATE `impersonation_session`, `user_deletion_record`, `audit_log_entry`; index on `(actor_id, occurred_at)` and `(target_user_id, started_at)`
- [ ] Migration: index `"user"` on `(role, status)` and `email` for directory filtering (NFR-020)
- [ ] Entities above
- [ ] Enable `switch_user` in `security.yaml` with a restricted role
- [ ] Voter: `ImpersonateVoter` — denies Super Admin targets (FR-030), denies non-Super-Admin actors
- [ ] Event subscriber: impersonation expiry listener (FR-031)
- [ ] Event subscriber: inject `impersonatorId` into every audit entry written while impersonating
- [ ] Request DTO + validator: `CreateTrainerRequest` (unique email, required business name/name/email/phone), `EditUserRequest`, `DeleteUserRequest` (reason required)
- [ ] Services above + DI wiring
- [ ] Repository: `UserRepository::searchForDirectory` with KnpPaginator; `ImpersonationSessionRepository::findOpenForAdmin`
- [ ] Temporary password generator (cryptographically random) + `mustChangePassword` flag
- [ ] Mailer: trainer invitation email
- [ ] **Audit** every history-bearing FK to `user` for `ON DELETE CASCADE` and remove it (FR-026)
- [ ] Twig extension or global: render a user as "Deleted User" consistently wherever a name is displayed

### Frontend Tasks (server-rendered)
- [ ] Templates: users list (filters + pagination), create-user form, edit-user form, impersonation banner partial, impersonation history report
- [ ] Progressive enhancement: confirmation dialogs for deactivate/delete/impersonate using a Stimulus controller — **no `window.confirm`**; server-side POST + CSRF remains the source of truth
- [ ] Impersonation banner rendered in the base layout, above all content, `position: sticky`
- [ ] Accessibility: dialogs are `role="dialog"` with focus trap and Escape to close; destructive actions have descriptive accessible names ("Delete user Jane Doe"), not just "Delete"; banner announced via `role="status"`

### Testing Tasks
- [ ] Integration: directory pagination, filtering by role and status, search scoping
- [ ] Integration: trainer creation happy path; duplicate email error; missing required fields
- [ ] Integration: created trainer can log in and is forced to change password
- [ ] Integration: deactivate → login refused; reactivate → login succeeds
- [ ] Integration: delete → PII anonymized to exact spec values; user cannot log in; cannot be reactivated
- [ ] Integration: **historical integrity** — create history rows, delete the user, assert rows still exist, render as "Deleted User", and aggregate totals are unchanged (FR-026)
- [ ] Integration: impersonation switches visible data; banner present; exit restores admin
- [ ] Integration: impersonating a Super Admin returns 403 (FR-030)
- [ ] Integration: expired impersonation session returns operator to admin view
- [ ] Integration: non-Super-Admin roles get 403 on every `/admin/*` route
- [ ] Unit: `UserAnonymizer` field-by-field output; temporary password generator entropy; duration calculation
- [ ] Browser/E2E: impersonate → observe target's dashboard → exit

## Validation Completeness

- [x] All functional requirements mapped to tasks
- [x] Happy path covered
- [x] Error cases identified (duplicate email, forbidden impersonation, irreversible delete confirmation)
- [x] Edge cases considered (cascade deletion destroying history, audit loss on rollback, impersonating while already impersonating, deleting oneself)
- [x] Security requirements addressed (voter-based impersonation limits, audit trail, admin-only routes)
- [x] Performance requirements noted (NFR-020, NFR-021)
- [x] Testing strategy defined

## Gap Analysis

- [ ] **G-16 (new)** — FR-025 erases PII while FR-027 retains original email and a full data snapshot. Retention period, encryption at rest, and who may read `UserDeletionRecord` are unspecified. As written this may not satisfy a GDPR erasure request. **Needs legal input.**
- [ ] **G-14** — Impersonation expiry has no native Symfony support; confirm expiry semantics (exit to admin vs full logout).
- [ ] **G-15** — Deactivating or deleting a **trainer** is undefined: what happens to their players, coaches, ShareLinks, events, and branding? US-01.12/13 are written from a player's perspective only.
- [ ] **G-17 (new)** — Can a Super Admin deactivate or delete *themselves*, or the last remaining Super Admin? Unspecified; recommend blocking both.
- [ ] **G-18 (new)** — "Actions taken (optional detailed log)" in §8 is optional. Decide now: without it, "all actions during impersonation logged with admin_id context" (US-01.07 security requirement) is not satisfiable.
- [ ] **Q-01.04 (P1)** — Trainer invitation email content and the full transactional email list.
- [ ] Spec does not say whether Super Admin can create Coach or Player accounts directly (only Trainer is specified). Assuming **no** — coaches and players arrive via ShareLink (TASK-003).
- [ ] "Deleted" users appear in the directory or are filtered out? Unspecified; assuming filterable, hidden by default.

## Next Steps (Suggested)

Do not auto-execute — see the epic index for the full ordering.
