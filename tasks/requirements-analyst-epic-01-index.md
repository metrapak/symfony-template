# Epic-01: User Management & Authentication — Task Index

## Overview

Analysis of `specs/Epic-01_User_Management_Authentication_SPEC.md` (1100 lines, 14 user stories, 4 roles).
Decomposed into **6 task directories** ordered by dependency. Each has its own
`requirements-analyst-requirements.md` with full requirement IDs, task breakdown, and gap analysis.

## Source

- **Spec**: `specs/Epic-01_User_Management_Authentication_SPEC.md`
- **Analyzed**: 2026-08-21
- **Priority**: P0 — Foundation (blocks Epics 02-07)

## Scoping Decisions (confirmed with requester, 2026-08-21)

| # | Decision | Rationale / Impact |
|:--|:---------|:-------------------|
| D-01 | Split epic into 6 tasks | 14 stories in one breakdown would exceed plannable size |
| D-02 | **Server-rendered Twig + progressive enhancement** (Stimulus/Turbo) | Matches spec's 12-screen list and the installed stack. No JSON API layer in scope |
| D-03 | **Child logins ship in MVP**, parent-owned, constrained permissions | Follows US-01.06. The §9 sentence "ALL players under 18 require parent-managed accounts" is a **spec defect** — see G-01 |
| D-04 | Child purchase approval built now, **payment execution behind a port interface** | Epic-05 does not exist; workflow + state machine are independently valuable and testable with a fake |

## Task Map

| Task | Title | User Stories | Depends On |
|:-----|:------|:-------------|:-----------|
| **TASK-001** | Authentication & RBAC Foundation | Epic AC §Auth, US-01.01 (partial) | — |
| **TASK-002** | Super Admin User Management & Impersonation | US-01.01, US-01.07, US-01.12, US-01.13 | TASK-001 |
| **TASK-003** | ShareLink Invitations & Organization Membership | US-01.02, US-01.08 | TASK-001 |
| **TASK-004** | Profiles, Family, Context Switching & Branding | US-01.03, US-01.04, US-01.06, US-01.11, US-01.14 | TASK-001, TASK-003 |
| **TASK-005** | Availability (Best Times / My Times) & Conflict Override | US-01.09, US-01.10 | TASK-004 |
| **TASK-006** | Child Purchase Approval Workflow | US-01.05 | TASK-004 |

### Recommended implementation order

```
TASK-001 ──┬── TASK-002
           └── TASK-003 ── TASK-004 ──┬── TASK-005
                                      └── TASK-006
```

TASK-002 and TASK-003 can run in parallel once TASK-001 lands. TASK-005 and TASK-006 can run in
parallel once TASK-004 lands.

## Delivery Status

| Task | Status | Branch |
|:-----|:-------|:-------|
| TASK-001 | Merged | `feat/epic-01-user-management-auth` (PR #2) |
| TASK-002 | Implemented, awaiting review | `feat/epic-01-task-002-admin-user-management` |
| TASK-003…006 | Not started | — |

## Existing Codebase Baseline

Verified in `src/` before analysis:

| Asset | State | Consequence |
|:------|:------|:------------|
| `App\Entity\User` | Exists — `id`, `email`, `roles` (JSON), `password` only | Must be extended, not created (TASK-001) |
| `config/packages/security.yaml` | `form_login` + `json_login`, `app_user_provider` | Reuse; `json_login` needs a keep/drop decision (G-12) |
| `switch_user` | Present but **commented out** | Impersonation foundation exists (TASK-002) |
| Symfony version | 7.4.* | Matches spec target |
| Doctrine ORM | 2.15 (PostgreSQL) | Migrations via doctrine-migrations-bundle |
| Installed | Mailer, Messenger, Form, Validator, Twig, Tailwind, KnpPaginator, Fixtures, DAMA, PHPUnit | Covers most needs |
| **Not installed** | `symfonycasts/reset-password-bundle`, `symfonycasts/verify-email-bundle`, rate-limiter config, image processing lib | TASK-001 must add or hand-roll (G-11) |
| Existing migration | `Version20260310102045` creates `"user"` table | New migrations must ALTER, not CREATE |

## Epic-Level Gap Analysis

Defects and unknowns found in the spec itself. IDs `G-nn` are new findings; `Q-nn` are the spec's own
open questions carried forward.

### Spec defects (need client/author correction)

- [ ] **G-01** — §9 Business Rules states "ALL players under 18 require parent-managed accounts (no independent accounts for minors)", directly contradicting US-01.06 which fully specifies child logins. *Resolved for now by D-03 (child logins ship); the spec text must be corrected.*
- [ ] **G-02** — §3 and §4 both reference "US-01.12" for portal branding, but US-01.12 is "Super Admin Deactivates User". Branding is **US-01.14**.
- [ ] **G-03** — Footer claims "User Stories: 12"; the spec contains **14** (US-01.01 … US-01.14).
- [ ] **G-04** — US-01.05 and US-01.06 both cross-reference "parent approval — see US-01.04", but approval is US-01.05; US-01.04 is child-trainer associations.
- [ ] **G-05** — Duplicate section numbers: two `§10` (User Flows / Epic AC), two `§11` (Performance / Mockups), two `§12` (Questions / Testing).
- [ ] **G-06** — `Q-01.05` is used twice with different content: inside US-01.06 (COPPA / 16-18 independent accounts) and in the §12 table (email verification before login). `Q-01.03` is missing from the table entirely.
- [ ] **G-07** — Availability scope contradiction: US-01.03 says each child has availability "**per trainer**"; US-01.09 says availability is stored "per player profile" (trainer-agnostic). These produce different schemas. *Blocking for TASK-005.*
- [ ] **G-08** — "Camp-to-User Conversion (Integration with Epic-08)" is listed in §3 MVP scope with 5 bullet behaviours but has **no user story and no acceptance criteria**. Cannot be estimated or tested as written.

### Unspecified behaviour

- [ ] **G-09** — US-01.06 says child RSVP and RSVP-cancellation "require parent approval", but the approval workflow (US-01.05) covers **payments only**. Approval for free-event RSVPs is undefined: same 48h expiry? Same notification? Silent auto-approve?
- [ ] **G-10** — Success metrics "0% data leakage between trainer organizations" and "platform handles 1,000 concurrent users" have no stated verification method. Recommend converting to explicit test criteria (isolation test suite + load test target) or removing from the Definition of Done.
- [ ] **G-11** — Spec says "specific security implementations decided by development team". Package selection for password reset, email verification, image resizing, and rate limiting is therefore an open architecture decision, not a requirement.
- [ ] **G-12** — Spec §9 requires permissions "enforced on both frontend (UI) and backend (API)", but D-02 scopes delivery to server-rendered Twig. Decide whether the existing `ApiLoginController` / `json_login` firewall stays, is removed, or is deferred to a later epic.
- [ ] **G-13** — WCAG 2.1 AA is required in §13 but appears in **no** epic-level acceptance criterion. Accessibility is currently untestable as a gate.
- [x] **G-14** — *Resolved 2026-08-22 (TASK-002).* Expiry means **exit to the admin view**, not logout. Implemented as a `kernel.request` subscriber reading the open audit row; window is `IMPERSONATION_TTL`, default 3600s.
- [ ] **G-15** — No requirement covers what happens to a **trainer's** organization data when the trainer themself is deactivated or GDPR-deleted (US-01.12 / US-01.13 are written for players). Do their players, coaches, ShareLinks, and branding survive? *Unchanged by TASK-002; carried to TASK-003/004.*
- [x] **G-17** — *Resolved 2026-08-22 (TASK-002).* Self-deactivation, self-deletion, and removal or demotion of the last active Super Admin are all blocked in the services.
- [x] **G-18** — *Resolved 2026-08-22 (TASK-002).* Per-action audit logging is required and implemented; `impersonator_id` is stamped on every entry written during a switch.
- [ ] **G-16** — *Answered by TASK-002, still open with legal.* The deletion compliance record stores a SHA-256 digest of the original address instead of the cleartext address and data snapshot FR-027 asks for.
- [ ] **G-19** — *New (TASK-002).* FR-025's "photo → default avatar" has no column to clear until profiles land in TASK-004.

### Spec's own open questions (carried forward)

| ID | Question | Priority | Blocks |
|:---|:---------|:--------:|:-------|
| Q-01.01 | Skill level definitions (Beginner/Intermediate/Advanced/Elite or custom)? | P2 | TASK-004 |
| Q-01.02 | Age group definitions (birth year / age range / grade level)? | P2 | TASK-004 |
| Q-01.04 | Which automated emails are required? | **P1** | TASK-001, TASK-002, TASK-003, TASK-006 |
| Q-01.05 | Email verification: required before login, or optional? | **P1** | **TASK-001 (blocking)** |
| Q-01.06 | Should a coach be notified when their availability is overridden? | P2 | TASK-005 |
| Q-01.07 | Session timeout duration (1 / 7 / 30 days)? | P2 | TASK-001 |

## Cross-Cutting Requirements (apply to all 6 tasks)

- **NFR-X01** Multi-tenancy: every query touching organization-scoped data must be filtered server-side by tenant. UI-only filtering is a security defect.
- **NFR-X02** Audit logging for all sensitive operations (impersonation, deletion, override, approval).
- **NFR-X03** CSRF protection on every state-changing form.
- **NFR-X04** WCAG 2.1 AA: keyboard navigation, screen reader support, contrast, visible focus.
- **NFR-X05** Responsive / touch-friendly on all screens.
- **NFR-X06** Soft delete preserves history; hard delete anonymizes PII while keeping aggregate totals accurate.
