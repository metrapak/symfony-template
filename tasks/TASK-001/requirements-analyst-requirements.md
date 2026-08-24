# TASK-001: Authentication & RBAC Foundation Requirements

## Overview

Establishes the security foundation for Epic-01: email/password authentication, the four-role
authorization model, account status lifecycle, credential recovery flows, and the multi-tenancy
primitive that every later task scopes its queries by.

This is the only task with no dependencies and it blocks the other five.

## Source

- `specs/Epic-01_User_Management_Authentication_SPEC.md` — §3 (Core Authentication & Authorization), §6 (User Roles), §9 (Authentication & Security / Role-Based Access / Multi-Tenancy), §13 Epic AC (Authentication & Authorization), §11 Performance, §16 Security
- Scoping decisions D-01, D-02 — see `tasks/requirements-analyst-epic-01-index.md`

## Functional Requirements

### FR-001: Email/Password Login
- **Acceptance**: All four roles authenticate with email + password against the `main` firewall. Invalid credentials return a generic error (no user enumeration). Login form is CSRF-protected.
- **Priority**: High

### FR-002: Logout and Session Management
- **Acceptance**: Authenticated user can log out; session is invalidated. Sessions expire after a configured inactivity period. Session cookie is `httponly`, `secure`, `samesite=lax`.
- **Priority**: High
- **Blocked by**: Q-01.07 (timeout duration unspecified — implement configurable, default 7 days)

### FR-003: Login Rate Limiting
- **Acceptance**: Repeated failed logins from the same IP and/or for the same account are throttled. Throttled attempt shows a clear retry message. Limits configurable per environment.
- **Priority**: High

### FR-004: Password Reset Flow
- **Acceptance**: User requests reset by email → receives link → sets new password → can log in with it. Token expires after **1 hour**. Token is single-use and invalidated on use. Requesting a reset for an unknown email shows the same confirmation message (no enumeration).
- **Priority**: High

### FR-005: Email Verification
- **Acceptance**: New account receives a verification email. Link expires after **24 hours**. Clicking marks the account verified. Expired link offers resend.
- **Priority**: High
- **Blocked by**: **Q-01.05** — whether verification gates login is unresolved. Implement the flag and the gate behind configuration so either answer is a config change, not a rewrite.

### FR-006: Forced Password Change on First Login
- **Acceptance**: An account created with a system-generated temporary password is flagged `must_change_password`. Any authenticated request other than the change-password route redirects there until a new password is set.
- **Priority**: High
- **Source**: US-01.01 Implementation Notes

### FR-007: Four-Role Authorization Model
- **Acceptance**: Every user has **exactly one** primary role: `ROLE_SUPER_ADMIN`, `ROLE_TRAINER`, `ROLE_COACH`, `ROLE_PLAYER`. Role hierarchy configured. Assigning two primary roles is rejected by validation.
- **Priority**: High

### FR-008: Role-Based Dashboard Routing
- **Acceptance**: After successful login the user lands on the dashboard for their role. Direct navigation to another role's dashboard returns 403.
- **Priority**: High

### FR-009: Account Status Gate
- **Acceptance**: Status is one of `Active`, `Inactive`, `Deleted`. `Active` may log in. `Inactive` is refused with "Account deactivated. Contact support." `Deleted` is refused and can never be reactivated.
- **Priority**: High

### FR-010: Authorization Enforced Server-Side
- **Acceptance**: Every restricted route is guarded by `access_control` or a voter — not only by hiding the link in Twig. A functional test asserts 403 for each role against each route it must not reach.
- **Priority**: High

### FR-011: CSRF Protection
- **Acceptance**: All POST/PUT/PATCH/DELETE forms carry and validate a CSRF token. A request with a missing or stale token is rejected.
- **Priority**: High

### FR-012: Tenant Context Primitive
- **Acceptance**: A single injectable service resolves the current organization (tenant) for the authenticated user. Organization-scoped repository methods take the tenant as a required argument — a query that forgets it must not compile into a valid call.
- **Priority**: High
- **Note**: This is the mechanism that makes the "0% data leakage" metric achievable. Design it before any tenant-scoped feature is written.

## Non-Functional Requirements

| ID | Requirement | Metric |
|:---|:------------|:-------|
| NFR-001 | Dashboard load | < 2 seconds |
| NFR-002 | Concurrent users supported | 1,000 |
| NFR-003 | Password storage | Hashed with Symfony `auto` hasher (currently bcrypt/argon2id); never logged, never in session |
| NFR-004 | Auth form accessibility | WCAG 2.1 AA — labels, keyboard reachable, visible focus, error text linked to input |
| NFR-005 | Auth screens responsive | Usable at 320px width, touch-friendly targets |
| NFR-006 | Token entropy | Reset and verification tokens cryptographically random, compared in constant time |

## Business Rules

- **BR-001** Email is unique across all users and is the login identifier.
- **BR-002** Each user has exactly one primary role.
- **BR-003** Password reset links expire after 1 hour; email verification links after 24 hours.
- **BR-004** Login attempts are rate-limited to prevent brute force.
- **BR-005** Inactive users cannot log in but retain all history.
- **BR-006** Deleted users cannot log in and cannot be reactivated.
- **BR-007** Trainers see only their own organization's data; enforcement is server-side.
- **BR-008** Trainer accounts are never self-registered (creation itself is TASK-002; the *absence* of a public trainer signup route is enforced here).

## Task Breakdown

### Entities

| Entity | Properties | Relations |
|:-------|:-----------|:----------|
| `User` (**extend existing**) | + `status` (enum), `emailVerifiedAt`, `mustChangePassword`, `lastLoginAt`, `createdAt`, `updatedAt` | has one Organization (trainer) or belongs to one (coach) |
| `Organization` | `name` (business name), `ownerId`, `address`, `website`, `description`, `createdAt` | owned by one User (trainer) |
| `PasswordResetToken` | `userId`, `hashedToken`, `expiresAt`, `usedAt` | belongs to User |
| `EmailVerificationToken` | `userId`, `hashedToken`, `expiresAt`, `usedAt` | belongs to User |

`Organization` lives here rather than in a later task because FR-012 (tenant context) cannot be
built without it, and every subsequent task scopes to it.

### Services

| Service | Purpose | Methods |
|:--------|:--------|:--------|
| `PasswordResetService` | Issue and consume reset tokens | `requestReset`, `validateToken`, `resetPassword` |
| `EmailVerificationService` | Issue and consume verification tokens | `sendVerification`, `verify`, `resend` |
| `AccountStatusChecker` (`UserCheckerInterface`) | Block Inactive/Deleted at auth time | `checkPreAuth`, `checkPostAuth` |
| `RoleDashboardResolver` | Map role → landing route | `resolveFor` |
| `TenantContext` | Resolve current organization | `currentOrganization`, `requireOrganization` |
| `PasswordChangeGuard` (event subscriber) | Enforce FR-006 redirect | `onKernelRequest` |

### Controllers

| Controller | Endpoints | Purpose |
|:-----------|:----------|:--------|
| `SecurityController` (**exists**) | `GET/POST /login`, `POST /logout` | Login form, logout |
| `PasswordResetController` | `GET/POST /password/forgot`, `GET/POST /password/reset/{token}` | Reset flow |
| `EmailVerificationController` | `GET /verify/{token}`, `POST /verify/resend` | Verification flow |
| `PasswordChangeController` | `GET/POST /account/password` | Voluntary and forced change |
| `DashboardController` | `GET /dashboard` | Role-routed landing |

### Backend Tasks
- [ ] Migration: ALTER `"user"` — add `status`, `email_verified_at`, `must_change_password`, `last_login_at`, `created_at`, `updated_at`; backfill existing rows to `Active`
- [ ] Migration: CREATE `organization`, `password_reset_token`, `email_verification_token`; index tokens on `hashed_token`, FK on `user_id`
- [ ] Entity: extend `App\Entity\User`; add status enum (PHP 8.4 backed enum)
- [ ] Entity: `Organization`, `PasswordResetToken`, `EmailVerificationToken`
- [ ] Value object: `EmailAddress` (validated, normalized lowercase) — enforces BR-001 at the type level
- [ ] Request DTO + validator: `LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`, `ChangePasswordRequest`
- [ ] Security config: role hierarchy, `access_control` rules per role, session lifetime, remember-me decision
- [ ] Security config: `login_throttling` (FR-003) with per-IP and per-account limiters
- [ ] `AccountStatusChecker` wired as the firewall's `user_checker`
- [ ] Services listed above + DI wiring
- [ ] Repository: `UserRepository::findOneByEmail`, `findActiveByEmail`; token repositories with expiry-aware lookups
- [ ] Mailer: reset email, verification email (templated, plain-text alternative)
- [ ] Console command: create the first Super Admin (bootstrap — no UI path exists for it)
- [ ] Fixtures: one user per role for dev and test

### Frontend Tasks (server-rendered)
- [ ] Templates: login (extend existing `templates/security/`), forgot password, reset password, verify notice, forced password change, four role dashboards (skeletons)
- [ ] Progressive enhancement: show/hide password toggle, client-side password strength hint (server validation remains authoritative)
- [ ] Accessibility: `<label for>` on every field, `aria-describedby` for errors, focus moved to first invalid field on submit, error summary at top of form

### Testing Tasks
- [ ] Integration: login success and failure per role; generic error on bad credentials
- [ ] Integration: Inactive and Deleted users refused with correct messages (FR-009)
- [ ] Integration: full reset flow; expired token rejected; token single-use; unknown email indistinguishable
- [ ] Integration: verification flow; expired link resend path
- [ ] Integration: forced password change blocks all other routes (FR-006)
- [ ] Integration: **authorization matrix** — every role × every restricted route asserts 200/403 (FR-010)
- [ ] Integration: CSRF rejection on each form
- [ ] Integration: rate limiting engages after N failures
- [ ] Unit: `EmailAddress` value object, token expiry logic, `RoleDashboardResolver`, `TenantContext`
- [ ] Browser/E2E: login → dashboard per role; forgot-password happy path

## Validation Completeness

- [x] All functional requirements mapped to tasks
- [x] Happy path covered
- [x] Error cases identified (bad credentials, expired token, inactive account, CSRF, throttled)
- [x] Edge cases considered (user enumeration, token reuse, forced change bypass, session fixation)
- [x] Security requirements addressed (hashing, CSRF, rate limit, constant-time compare, server-side authz)
- [x] Performance requirements noted (NFR-001, NFR-002)
- [x] Testing strategy defined

## Gap Analysis

- [ ] **Q-01.05 (P1, blocking)** — Must email verification complete before first login? Mitigated by building the gate as configuration, but the default must be chosen before release.
- [ ] **Q-01.07 (P2)** — Session timeout duration. Proceeding with a configurable 7-day default.
- [ ] **Q-01.04 (P1)** — Full list of transactional emails. This task implements reset + verification only; a wider list may add templates.
- [ ] **G-11** — Package choice for reset/verification is undecided. `symfonycasts/reset-password-bundle` and `verify-email-bundle` are **not installed**; the entity design above assumes hand-rolled tokens. If the bundles are adopted, `PasswordResetToken` / `EmailVerificationToken` are replaced by bundle-provided schema. **Decide before writing the migration.**
- [ ] **G-12** — `ApiLoginController` and the `json_login` firewall entry already exist but D-02 scopes delivery to Twig. Keep, remove, or leave dormant? Leaving an unauthenticated-by-design JSON endpoint in place unreviewed is a security risk.
- [ ] **G-10** — "1,000 concurrent users" (NFR-002) has no defined load-test method or environment. Not verifiable as written.
- [ ] **G-15** — Deactivating or deleting a *trainer* has undefined consequences for their organization. Relevant to the `Organization` design introduced here.
- [ ] Remember-me functionality is neither required nor excluded by the spec. Assumed **out of scope**.
- [ ] Spec never states password complexity rules. Assuming Symfony's `PasswordStrength` constraint at a medium threshold; confirm with client.

## Next Steps (Suggested)

Do not auto-execute — see the epic index for the full ordering.
