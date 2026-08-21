# TASK-001 — Atomic Task Backlog: Epic-01 User Management & Authentication

Derived from `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`.
Requirements, assumptions (A-xx), conflicts (C-xx) and gaps: see `requirements-analyst-requirements.md`.

**Conventions**
- ID: `E01-Tnnn`. Stable — do not renumber; append new ones at the end.
- Size: **S** ≤ 0.5 d · **M** 1–2 d · **L** 3–5 d.
- Prio: **P0** blocks the rest of the epic · **P1** required for epic acceptance · **P2** deferrable within epic.
- Status: `ready` · `blocked-question` (needs a client answer) · `blocked-epic` (needs another epic) · `assumption`
  (proceeds on a stated assumption; revisit if it is overturned).
- Every task's DoD implicitly includes: tests (see `.claude/DOD.md`), no PHPStan regression, CSRF on state changes,
  and no cross-tenant data exposure.

---

## Wave Overview

| Wave | Theme | Tasks | Gate to leave the wave |
|---|---|---|---|
| W0 | Identity foundation | T001–T012 | any role can log in, statuses enforced, audit + mail infrastructure exist |
| W1 | Auth flows | T013–T018 | verification, reset, forced change, RBAC matrix all green |
| W2 | Tenancy core | T019–T025 | trainer org + associations + isolation proven by tests |
| W3 | ShareLinks | T026–T033 | player self-registration and coach invite work end-to-end |
| W4 | Family | T034–T042 | child profiles, child login, association management, approvals |
| W5 | Availability | T043–T048 | player/coach availability + trainer view + override log |
| W6 | Admin tools & profiles | T049–T058 | Users tool, deactivate, GDPR delete, impersonation, profile edit |
| W7 | Branding, perf, hardening | T059–T065 | epic-level AC + NFRs verified |

---

## Summary Table

| ID | Task | US / FR | Prio | Size | Depends on | Status |
|---|---|---|---|---|---|---|
| E01-T001 | Role constants + single-role invariant | §9, FR-AUTHZ-01 | P0 | S | — | ready |
| E01-T002 | `role_hierarchy` + access_control skeleton | FR-AUTHZ-01 | P0 | S | T001 | ready |
| E01-T003 | User status enum + verification/timestamp fields + migration | FR-AUTH-09 | P0 | M | T001 | ready |
| E01-T004 | `UserChecker`: inactive/deleted cannot log in | FR-AUTH-09 | P0 | S | T003 | ready |
| E01-T005 | Common `Profile` entity + migration | FR-PROF-01 | P0 | M | T003 | ready |
| E01-T006 | Role-based post-login redirect | FR-AUTH-03 | P0 | S | T002 | ready |
| E01-T007 | Password policy (reuse `PasswordRequirements`) | FR-AUTH-02 | P0 | S | T003 | ready |
| E01-T008 | Login throttling / rate limiter | FR-AUTH-04 | P0 | S | — | ready |
| E01-T009 | Session hardening + idle timeout | FR-AUTH-05 | P0 | S | — | assumption (A-06, Q-01.07) |
| E01-T010 | Mail infrastructure + base email layout | Q-01.04 | P0 | M | — | blocked-question (catalogue) |
| E01-T011 | Audit log service + `AuditEntry` entity | FR-ADM-07 | P0 | M | T003 | ready |
| E01-T012 | Test data builders/fixtures per role | testing §12 | P0 | M | T005 | ready |
| E01-T013 | Email verification issue + consume (24 h) | FR-AUTH-07 | P0 | M | T003, T010 | ready |
| E01-T014 | Verification gate on login | FR-AUTH-07 | P0 | S | T013 | blocked-question (C-02) |
| E01-T015 | Password reset request + confirm (1 h, single use) | FR-AUTH-06 | P0 | M | T003, T010 | ready |
| E01-T016 | Force password change on first login | FR-AUTH-08 | P0 | M | T015 | ready |
| E01-T017 | Permission matrix doc + base voters | FR-AUTHZ-01 | P0 | M | T002 | ready |
| E01-T018 | Role dashboards (4 shells) | FR-AUTH-03 | P0 | M | T006, T017 | ready |
| E01-T019 | `TrainerOrganization` entity + migration | §8 | P0 | M | T003 | ready |
| E01-T020 | `PlayerProfile` entity + migration | §8, FR-FAM-01 | P0 | M | T005 | assumption (A-07) |
| E01-T021 | `TrainerPlayerAssociation` entity + migration | FR-AUTHZ-04 | P0 | M | T019, T020 | ready |
| E01-T022 | `CoachAssignment` + single-active-trainer enforcement | FR-AUTHZ-03 | P0 | M | T019 | ready |
| E01-T023 | Tenant-scoped repository contracts + voters | FR-AUTHZ-02 | P0 | L | T021, T022 | ready |
| E01-T024 | Cross-tenant isolation test suite | AC data integrity | P0 | M | T023 | ready |
| E01-T025 | Super Admin creates trainer account | US-01.01 | P0 | L | T019, T010, T016, T011 | ready |
| E01-T026 | `ShareLink` entity + code generator | §8, FR-LINK-01/02 | P0 | M | T019 | ready |
| E01-T027 | Trainer generates static player ShareLink + UI | FR-LINK-01 | P0 | M | T026 | ready |
| E01-T028 | Player registration via ShareLink (new account) | US-01.02 | P0 | L | T026, T020, T021, T013 | ready |
| E01-T029 | Existing user associates via ShareLink | FR-LINK-04 | P0 | M | T028 | ready |
| E01-T030 | Active trainer context (session) + isolation | FR-AUTHZ-05 | P0 | L | T021, T023 | ready |
| E01-T031 | Coach invite via unique ShareLink (7 d, one use) | US-01.08 | P0 | L | T026, T022 | ready |
| E01-T032 | Coach invitation status list + resend | FR-LINK-08 | P1 | M | T031 | ready |
| E01-T033 | ShareLink usage tracking records | FR-LINK-07 | P2 | S | T026 | ready |
| E01-T034 | Parent creates child profile | US-01.03 | P0 | L | T020, T021 | assumption (C-01) |
| E01-T035 | Child-trainer selection prompt on child creation | FR-FAM-03 | P1 | M | T034 | ready |
| E01-T036 | Duplicate-child warning | FR-FAM-07 | P2 | S | T034 | ready |
| E01-T037 | Child login account + capability restrictions | US-01.06 | P0 | L | T034, T017 | assumption (C-01) |
| E01-T038 | ShareLink blocking for children + parent email | FR-LINK-06 | P1 | M | T037, T026, T010 | ready |
| E01-T039 | Family-member selection on multi-trainer link | FR-LINK-05 | P1 | M | T029, T034 | ready |
| E01-T040 | Parent adds/removes child↔trainer associations | US-01.04 | P1 | L | T034, T021 | blocked-epic (RSVP cancel, Epic-02) |
| E01-T041 | Context selector UI (parent / child) | FR-FAM-05 | P1 | L | T030, T034 | ready |
| E01-T042 | In-app notification store + inbox | gap 8, FR-APPR-03 | P1 | M | T003 | ready |
| E01-T043 | `PurchaseApprovalRequest` domain + states | US-01.05 | P1 | L | T037 | blocked-epic (Epic-05) |
| E01-T044 | Per-child "tokens without approval" setting | FR-APPR-02 | P1 | M | T043 | ready |
| E01-T045 | Parent approve/deny UI + notes + notifications | FR-APPR-03 | P1 | L | T043, T042, T010 | ready |
| E01-T046 | 48 h auto-deny expiry job | FR-APPR-04 | P1 | M | T043 | ready |
| E01-T047 | `Availability` entity + repository queries | §8 | P1 | M | T020, T022 | assumption (A-08, C-03) |
| E01-T048 | Player/parent availability editor | US-01.09 | P1 | L | T047, T041 | ready |
| E01-T049 | Coach "My Times" editor (multi-slot) | US-01.10 | P1 | M | T047 | ready |
| E01-T050 | Trainer availability view + filter + Best Times summary | FR-AVAIL-03 | P1 | L | T047, T023 | ready |
| E01-T051 | Coach conflict check + override log | FR-AVAIL-04 | P1 | M | T049, T011 | blocked-epic (Epic-02 wiring) |
| E01-T052 | Notify coach on override | Q-01.06 | P2 | S | T051, T042 | blocked-question (Q-01.06) |
| E01-T053 | Self profile edit (all roles) | US-01.11 | P1 | L | T005, T020 | ready |
| E01-T054 | Photo upload + thumbnail + storage abstraction | FR-PROF-03 | P1 | M | T005 | ready |
| E01-T055 | Role-specific profile fields | FR-PROF-04 | P1 | M | T053 | assumption (A-07) |
| E01-T056 | Coach public profile (bio, credentials, visibility) | §3 coach | P1 | M | T022, T053 | ready |
| E01-T057 | Users tool: directory with search/filter/pagination | FR-ADM-02 | P1 | L | T003, T017 | ready |
| E01-T058 | Super Admin edits any user | FR-ADM-03 | P1 | M | T057, T053 | ready |
| E01-T059 | Deactivate / reactivate user | US-01.12 | P1 | M | T057, T004 | ready |
| E01-T060 | GDPR delete + anonymization + deletion log | US-01.13 | P1 | L | T057, T011 | ready |
| E01-T061 | Impersonation via `switch_user` + Super-Admin block | US-01.07 | P1 | L | T017, T057 | ready |
| E01-T062 | Impersonation banner + 1 h expiry | US-01.07 | P1 | M | T061 | ready |
| E01-T063 | Impersonation audit log + history report | FR-ADM-07 | P1 | M | T061, T011 | ready |
| E01-T064 | Portal branding: logo + primary color | US-01.14 | P1 | L | T019, T054, T030 | ready |
| E01-T065 | Epic-level verification pass (security, perf, a11y) | AC, NFR-01/03 | P1 | L | all above | ready |
| E01-T066 | Relocate `User` into `App\Identity`; module Doctrine mappings + route imports | AD-01 | P0 | S | — | ready |
| E01-T067 | Enable Messenger async + failed transports; document the worker | AD-02, NFR-01 | P0 | S | — | ready |
| E01-T068 | Approve + install `symfony/rate-limiter` (± `symfony/uid`) | FR-AUTH-04 | P0 | S | — | blocked-decision |
| E01-T069 | Add an image extension to the PHP image, or defer resizing | FR-PROF-03, FR-BRAND-01 | P1 | S | — | blocked-decision |

**Totals**: 69 atomic tasks — P0 ×36, P1 ×30, P2 ×3. Blocked: 3 on client questions, 4 on other epics, 2 on
internal decisions (dependency approval / Docker image), 6 proceeding on stated assumptions.

**Wave -1** (`E01-T066..T069`, added by `/architect`) runs before W0 — see the section at the end of this file.

---

## Wave 0 — Identity Foundation

### E01-T001 — Role constants + single-role invariant
Introduce the five role constants (§2 of the analysis) in one place and enforce that a user carries exactly one
business role plus the implicit `ROLE_USER`.
**DoD**: enum/class of role constants; `User::setRoles()` (or a validator) rejects multi-role input; unit tests for
each role and for the rejection path; existing users' `roles` arrays still load.

### E01-T002 — `role_hierarchy` + access_control skeleton
**DoD**: `security.yaml` declares the hierarchy and a first `access_control` block per role area; a functional test
asserts an anonymous request to a protected path redirects to login.

### E01-T003 — User status + verification/timestamp fields
Add `status` (active/inactive/deleted), `emailVerifiedAt`, `lastLoginAt`, `createdAt`, `updatedAt`.
**DoD**: entity + migration (existing rows default to active/verified-null); repository can filter by status; unit
tests for status transitions; migration up/down verified against a copy of the current schema.

### E01-T004 — `UserChecker`
**DoD**: inactive → "Account deactivated. Contact support."; deleted → same generic failure with no PII;
functional tests for both, plus the happy path; applies to `form_login` **and** `json_login`.

### E01-T005 — Common `Profile` entity
first/last name, phone, photo path, school; 1:1 with `User`.
**DoD**: entity + migration + repository; phone format validation; created for every new user; unit + integration
tests.

### E01-T006 — Role-based post-login redirect
**DoD**: each role lands on its own dashboard route; functional test per role; unauthenticated deep-link still
returns to the original target after login.

### E01-T007 — Password policy
Reuse `App\Products\Domain\Validator\Constraint\PasswordRequirements` (relocate to a shared namespace).
**DoD**: constraint applied on registration, reset and forced change; unit tests for accept/reject; no duplicate
constraint class left behind.

### E01-T008 — Login throttling
**DoD**: `login_throttling` configured with per-IP and per-username limits; functional test proves the limiter
triggers and recovers; applies to the JSON login endpoint too.

### E01-T009 — Session hardening — *assumption A-06*
**DoD**: secure/httponly/samesite cookie flags, idle timeout (7 d pending Q-01.07), session fixation strategy on
login; documented in `specs/` when architecture lands; revisit ticket noted if the client answers differently.

### E01-T010 — Mail infrastructure — *blocked-question Q-01.04*
Wrapper service + base Twig email layout + local capture in dev/test.
**DoD**: one service used by all later emails; base layout; test transport asserted in functional tests; the six
assumed emails (analysis §7) listed as stubs. Unblocking needs the client's email catalogue.

### E01-T011 — Audit log service + `AuditEntry`
Generic append-only audit: actor, action, subject, payload, timestamp.
**DoD**: entity + migration + service; used by at least one caller (T025); query by subject and by actor; tests
prove entries are never updated or deleted.

### E01-T012 — Test data builders per role
**DoD**: builders/fixtures for super admin, trainer, coach, parent-player, child; deterministic; used by at least
two functional tests; documented in `docs/DatabaseTestingSetup.md`.

---

## Wave 1 — Auth Flows

### E01-T013 — Email verification issue + consume (24 h)
**DoD**: signed single-use token, 24 h expiry, resend path, already-verified and expired branches covered by tests.

### E01-T014 — Verification gate on login — *blocked-question C-02*
**DoD**: gate applied per the resolved policy; functional tests for gated and exempt user classes. Do not start
before the client answers Q-01.05.

### E01-T015 — Password reset (1 h, single use)
**DoD**: request form does not disclose whether the email exists; token invalidated on use and on password change;
expiry tested; rate-limited.

### E01-T016 — Force password change on first login
**DoD**: flag set by admin-created accounts; every route except the change-password/logout routes redirects while
the flag is set; flag cleared on success; functional test walks invite → login → forced change → dashboard.

### E01-T017 — Permission matrix + base voters
Write the matrix (role × capability) into the task dir, then implement the voters it implies, including the child
allow/deny list from US-01.06.
**DoD**: matrix document; voters with unit tests per row; a functional smoke test per role asserting one allowed and
one denied route.

### E01-T018 — Role dashboard shells
**DoD**: four route+template shells with correct access control and navigation placeholders; no business widgets
yet; a11y baseline (landmarks, skip link, focus order).

---

## Wave 2 — Tenancy Core

### E01-T019 — `TrainerOrganization`
business name, address, website, description, owner user. Stripe/subscription/fee columns from §8 are **out of
scope** here (Epic-05 seam).
**DoD**: entity + migration + repository; one org per trainer enforced; tests.

### E01-T020 — `PlayerProfile` — *assumption A-07*
player name, birth date, gender, skill level (open enum placeholder), school, jersey, `isChild`, parent link,
emergency contact.
**DoD**: entity + migration; age derived from birth date; age 1–18 validation for child profiles; tests including
the boundary ages.

### E01-T021 — `TrainerPlayerAssociation`
trainer, player profile, originating ShareLink (nullable until T026), connectedAt, status.
**DoD**: entity + migration + unique (trainer, player) constraint; soft-deactivation instead of row deletion; tests
prove a second association creates no duplicate user.

### E01-T022 — `CoachAssignment` + single-active-trainer rule
**DoD**: entity + migration; at most one *active* assignment per coach enforced at the database level (partial
unique index) **and** in the service; test attempts a second active assignment and gets a domain error.

### E01-T023 — Tenant-scoped repositories + voters
**DoD**: every read path touching players/coaches requires an explicit trainer scope (per A-04); voters deny
cross-tenant subjects; no repository method returns unscoped tenant data; PHPStan clean.

### E01-T024 — Cross-tenant isolation test suite
**DoD**: functional tests where trainer A attempts to read/write trainer B's players, coaches, ShareLinks,
availability and branding — all denied; the suite is the regression net for FR-AUTHZ-02.

### E01-T025 — Super Admin creates trainer account (US-01.01)
**DoD**: form (business name, trainer name, email, phone), unique-email error message, org created, invite email or
temporary password, forced password change on first login, audit entry, new trainer visible as Active; functional
test covers the whole path plus the duplicate-email branch.

---

## Wave 3 — ShareLinks

### E01-T026 — `ShareLink` entity + code generator
code (URL-safe, unique), type (static|unique), trainer, creator, target email, expiresAt, maxUses, usedCount,
active.
**DoD**: entity + migration; collision-safe generator with a uniqueness test; expiry/uses evaluated by one domain
method with unit tests for every branch.

### E01-T027 — Trainer generates static player ShareLink
**DoD**: generate, view, copy, deactivate; unlimited uses / no expiry; tenant-scoped; functional test.

### E01-T028 — Player registration via ShareLink (US-01.02)
**DoD**: `/join/{code}` for anonymous users → registration (name, email, password, phone, player name/age/gender) →
account + player profile + association with the link owner + confirmation email; invalid/inactive code shows a clear
error; functional test end-to-end; registration completes within the < 2 s target locally.

### E01-T029 — Existing user associates via ShareLink
**DoD**: logged-in user hitting a new trainer's link gets a new association, never a second account; hitting an
already-associated link is idempotent with a friendly message; redirected into the new trainer context.

### E01-T030 — Active trainer context
**DoD**: session-scoped active trainer, persisted across requests; every tenant-scoped query in player-facing pages
derives its scope from it; switching context changes the visible data set; test proves data from trainer B never
appears while trainer A is active.

### E01-T031 — Coach invite via unique ShareLink (US-01.08)
**DoD**: trainer enters coach email (+ optional name/message) → single-use 7-day link + email; acceptance creates
the coach and the assignment; a coach already active elsewhere is rejected with a clear message; expired link shows
the resend hint; functional tests for accept, expired, already-active.

### E01-T032 — Coach invitation status + resend
**DoD**: Pending/Accepted/Expired list scoped to the trainer; resend invalidates the previous link; tests.

### E01-T033 — ShareLink usage tracking
**DoD**: one usage record per successful use (link, user, timestamp), `usedCount` consistent with the records;
exposed via a repository method for Epic-06.

---

## Wave 4 — Family

### E01-T034 — Parent creates child profile (US-01.03) — *assumption C-01*
**DoD**: "+ Add Child" form (name, age, gender required; school/photo optional), marked as child, linked to the
parent, age 1–18 enforced; parent remains a player in their own right (FR-FAM-02); functional tests.

### E01-T035 — Child-trainer selection prompt
**DoD**: single-trainer parent gets the Yes/No prompt, multi-trainer parent gets the checklist, "none" leaves the
child unassociated; each branch tested.

### E01-T036 — Duplicate-child warning
**DoD**: similar name+age produces a non-blocking warning with a confirm path; test.

### E01-T037 — Child login + capability restrictions (US-01.06) — *assumption C-01*
**DoD**: optional child credentials sharing the parent's contact info; the CAN/CANNOT list from US-01.06 enforced by
voters server-side; a test per denied capability (add trainer, payment methods, buy tokens, delete account, change
associations, read parent data).

### E01-T038 — ShareLink blocking for children
**DoD**: child hitting a link sees "Ask your parent to register you with this trainer"; parent receives the email
with the link and CTA; no association is created; tests for both effects.

### E01-T039 — Family-member selection on a new trainer link
**DoD**: "Who will train with [Trainer]?" checklist (Me + children); only selected members are associated; tests for
self-only, children-only and mixed selections.

### E01-T040 — Parent manages child↔trainer associations (US-01.04) — *blocked-epic Epic-02*
**DoD**: family view listing children with trainers and dates; add via ShareLink or from "My Trainers"; remove with
the confirmation copy from the spec; association soft-deleted with history retained; trainer's roster no longer
shows the child. The "cancels all upcoming RSVPs" clause is stubbed behind an interface until Epic-02.

### E01-T041 — Context selector UI
**DoD**: the three layouts documented in US-01.04 (parent-who-trains, parent-who-does-not, child); selection
persists; keyboard accessible; child variant exposes no parent data.

### E01-T042 — In-app notification store + inbox — *gap 8*
**DoD**: notification entity + service + unread badge + inbox page; used by T045; tests.

### E01-T043 — `PurchaseApprovalRequest` domain (US-01.05) — *blocked-epic Epic-05*
**DoD**: entity (child, parent, subject, amount, payment type, status, request/response/expiry timestamps, parent
notes) + state machine (pending → approved | denied | expired) with unit tests; payment execution and event
registration sit behind interfaces with in-memory fakes.

### E01-T044 — Per-child token-approval setting
**DoD**: per-child flag defaulting to OFF; ON skips approval and sends an informational notification; both branches
tested.

### E01-T045 — Parent approve/deny UI + notifications
**DoD**: pending list, approve/deny with notes, child sees the status change, parent notified by email + in-app on
request, child notified on decision; functional tests.

### E01-T046 — 48 h auto-deny expiry
**DoD**: scheduled/queued job expiring stale requests with notification; idempotent; test with a controlled clock.

---

## Wave 5 — Availability

### E01-T047 — `Availability` entity — *assumption A-08 / C-03*
owner (player profile or coach), day of week, start/end time, available flag, timestamps.
**DoD**: entity + migration + repository (by owner, by day, overlap detection); overlapping-slot validation;
per-owner (not per-trainer) shape documented as the resolved reading of C-03.

### E01-T048 — Player/parent availability editor (US-01.09)
**DoD**: weekly grid with per-day toggle or ranges, save with confirmation copy, parent can switch between children;
keyboard accessible; functional tests.

### E01-T049 — Coach "My Times" (US-01.10)
**DoD**: recurring weekly slots, multiple ranges per day, overlap rejected, save + reload verified by test.

### E01-T050 — Trainer availability view + filter
**DoD**: availability indicator on the player card, "Best Times" summary string, filter by day/time; tenant-scoped;
query performance checked against the player volumes in §11.

### E01-T051 — Coach conflict check + override log — *blocked-epic Epic-02*
**DoD**: service answering "is coach X available at slot Y" plus an override record (event ref, coach, trainer,
required reason, timestamp) written through the audit service; unit tests; the event-creation wiring is deferred to
Epic-02 and marked with an explicit seam.

### E01-T052 — Notify coach on override — *blocked-question Q-01.06*
**DoD**: notification sent per the resolved policy; skipped entirely if the client says no.

---

## Wave 6 — Profiles & Admin Tools

### E01-T053 — Self profile edit (US-01.11)
**DoD**: editable name/phone/photo/school; email, role, skill level and created-at rendered read-only and rejected
server-side if tampered with; confirmation message; save under the < 1 s target; functional test per role.

### E01-T054 — Photo upload + thumbnail
**DoD**: storage abstraction (local in dev), MIME/size validation, thumbnail generation, default avatar fallback;
malicious-upload test (wrong extension, oversized, non-image bytes).

### E01-T055 — Role-specific profile fields — *assumption A-07*
**DoD**: player school/jersey, parent emergency contact, coach bio/credentials/certifications/public flag, trainer
business/org fields; each visible only to its role; tests.

### E01-T056 — Coach public profile
**DoD**: public page respecting the visibility flag; hidden profiles return 404 for non-owners; tests.

### E01-T057 — Users tool directory (FR-ADM-02)
**DoD**: paginated list with tool-scoped search (not global) and role/status filters, sortable; indexes to meet the
10 000-rows-under-3 s target with a seeded benchmark recorded in the task dir; Super-Admin-only access test.

### E01-T058 — Super Admin edits any user
**DoD**: edit any user's account/profile with an audit entry; cannot escalate to a second business role (T001
invariant); tests.

### E01-T059 — Deactivate / reactivate (US-01.12)
**DoD**: confirmation modal copy from the spec; status flips; login blocked (T004); the user still appears in
historical listings marked inactive; reactivation restores login; tests for both directions.

### E01-T060 — GDPR delete (US-01.13)
**DoD**: warning modal; name → "Deleted User", email → `deleted_{id}@example.com`, phone/identifiers nulled, photo →
default avatar; status `deleted`; historical rows still resolve to "Deleted User"; deletion log retains the original
id, original email, actor, reason, timestamp; reactivation impossible; tests assert no PII remains on the user row
and that aggregate counts are unchanged.

### E01-T061 — Impersonation (US-01.07) — *assumption A-03*
**DoD**: `switch_user` enabled and restricted to Super Admin; a voter denies Super-Admin targets with a validation
error; confirmation modal; permissions/data match the target exactly; exit returns to the admin session; tests for
allowed target, Super-Admin target denial, and non-admin attempting to impersonate.

### E01-T062 — Impersonation banner + 1 h expiry
**DoD**: sticky, color-coded banner on every page with an exit action; session force-exits after 1 h; tests for the
banner presence and the expiry.

### E01-T063 — Impersonation audit + history report
**DoD**: session-level records (admin, target, start, end, duration) plus a request-scoped logger stamping
`admin_id` on writes made while impersonating (gap 11 — the spec's action-level ask is met at logger granularity,
not a full action journal); Impersonation History report for compliance; tests.

---

## Wave 7 — Branding, Performance, Hardening

### E01-T064 — Portal branding (US-01.14)
**DoD**: logo upload (PNG/JPG/SVG, ≤ 2 MB, auto-resize above 200×200) with preview; hex primary color with preview
and reset-to-default; applied to the trainer's org users via the active context (T030); other trainers' users are
unaffected (isolation test); invalid type/size/hex rejected.

### E01-T065 — Epic verification pass
**DoD**: every epic-level acceptance checkbox exercised by an automated test or an explicitly recorded manual check;
security tests (tenant isolation, impersonation limits, child restrictions, password/session/rate limits, CSRF
coverage); performance measurements against NFR-01 recorded with the numbers; WCAG 2.1 AA pass on the Epic-01
screens; `/verify` DoD report attached to the task dir.

---

## Suggested Execution Order

The spec's suggested order (§13) holds, refined by the dependency graph:

1. **W0 → W1** strictly sequential-ish; T008/T010/T011 can run in parallel with T003–T005.
2. **W2** starts as soon as T003+T005 land; T019–T022 are parallelizable, T023/T024 gate everything after.
3. **W3** needs T026 first; T027/T031 then run in parallel.
4. **W4** needs T034 before T037/T039/T040; T042 can be built any time after W0.
5. **W5** and **W6** are largely independent of each other and can run in parallel by two people.
6. **W7** last; T065 is the epic exit gate.

**Do not start** T014 (verification gate), T052 (override notification) and the second half of T034/T037 until the
client answers Q-01.05, Q-01.05b and Q-01.06. **Do not promise** T040's RSVP cancellation, T043/T045's real payment
execution or T051's event wiring inside Epic-01 — they are Epic-02/Epic-05 seams (analysis §6).

## Recommended Follow-Ups Before Coding

1. `/architect` — resolve A-01 (module layout) and A-04 (tenancy enforcement strategy) → `specs/architect-architecture.md`.
2. `/database-designer` — the ~14 tables implied by §8, with the indexes NFR-01 needs.
3. `/security-voter-designer` — the T017 permission matrix and the child capability list.
4. Send the client the seven questions in analysis §7 with the fallback assumptions attached, so silence still
   unblocks the work.


---

## Wave -1 — Architecture Prerequisites (added by `/architect`)

Source: `specs/architect-architecture.md` §9. These land **before** Wave 0.

### E01-T066 — Module relocation and registration
Move `App\Entity\User` → `App\Identity\Domain\Entity\User` and `App\Repository\UserRepository` →
`App\Identity\Infrastructure\Persistence\Doctrine\UserRepository`; add the `Identity` and `Academy` Doctrine
mappings and route imports; retire the `App` mapping; update the security provider FQCN and the two controller
imports. No migration — the table name is pinned by the entity attribute.
**DoD**: `lint:container`, `debug:router` and `doctrine:schema:validate --skip-sync` clean; a login functional test
passes in the same commit; `src/Entity/` and `src/Repository/` no longer exist.

### E01-T067 — Messenger transports
Enable an async (`doctrine://`) transport plus a `failed` transport and route mail/notification/expiry messages to
it, so registration and invite emails leave the request path (NFR-01).
**DoD**: config committed; a functional test asserts the message is queued rather than handled inline; the worker
requirement is recorded in the deployment notes.

### E01-T068 — Dependency approval — *blocked-decision*
`symfony/rate-limiter` is required by `login_throttling` (E01-T008) and is not installed; `symfony/uid` is optional
(opaque ShareLink codes and token ids).
**DoD**: `/dependency-manager` verdict recorded, `composer audit` clean, constraints pinned.

### E01-T069 — Image processing decision — *blocked-decision*
The PHP image installs only `pdo`, `pdo_pgsql` and `intl` — no `gd`/`imagick` — so thumbnail and logo resizing as
specified cannot run.
**DoD**: either the extension is added to `docker/php-fpm/Dockerfile` (every developer rebuilds the image) or the
decision to store originals without resizing is recorded and E01-T054 / E01-T064 are rescoped accordingly.

### Amendments to existing tasks
- **E01-T023** — implement `TrainerScope` (required first repository parameter) + `TenantContext` (per-role scope
  resolution; session-backed and revalidated against an active association for players) + voters. A global Doctrine
  filter is explicitly rejected — do not introduce one.
- **E01-T022** — enforce "one active coach assignment" with a PostgreSQL **partial unique index** and translate the
  constraint violation into a domain error; no pre-check `SELECT`.
- **E01-T012** — builders live under `tests/<Module>/…` and construct users through the use cases wherever an
  invariant applies.

### Amendments from `/security-voter-designer`
Source: `specs/security-voter-designer-authorization.md` §9.
- **E01-T002** — `role_hierarchy` is limited to `ROLE_PLAYER|ROLE_CHILD -> ROLE_TRAINEE` and
  `ROLE_SUPER_ADMIN -> ROLE_ALLOWED_TO_SWITCH`. No inheritance between business roles: Super Admin must gain **no**
  tenant access by role, because per AD-02 it has no tenant scope to resolve.
- **E01-T017** — additionally requires `access_decision_manager: { strategy: unanimous, allow_if_all_abstain: false }`
  and a regression test where one voter grants and `ChildCapabilityVoter` denies, asserting the result is *denied*.
  Under Symfony's default `affirmative` strategy the US-01.06 deny-list would be decorative.
- **E01-T017** — the 12 voters of §4 with narrow `supports()`; a voter must abstain (not deny) on subjects and
  attributes it does not own, otherwise `unanimous` breaks unrelated features.
- **E01-T037** — child restrictions are `ChildCapabilityVoter` plus structural query scoping (the child's context
  selector is built from the child's own associations), not per-controller conditionals.
- **E01-T061** — `ImpersonationVoter` has five conditions, including two the epic omits: no chained impersonation,
  and no impersonation of an inactive/deleted account.
- **E01-T028 / E01-T031** — `SHARE_LINK_USE` additionally requires an email match for `coach_unique` links; without
  it a forwarded coach invitation could attach any logged-in user as a coach.
- **E01-T053** — trainers do not edit player profiles in Epic-01; skill level moves to a separate
  `PLAYER_PROFILE_SET_SKILL` attribute granted to trainers in scope (gap 9 stays open).
- **New in E01-T065** — an `access_control` coverage test that walks `debug:router` and asserts every route is
  either explicitly public or protected, so a new unguarded route fails CI.
