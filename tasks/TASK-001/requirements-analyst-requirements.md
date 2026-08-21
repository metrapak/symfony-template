# TASK-001 — Requirements Analysis: Epic-01 User Management & Authentication

**Source**: `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` (1103 lines, business-level)
**Target codebase**: `src/` — Symfony 7.4 LTS, PHP >= 8.2, Doctrine ORM, Twig, existing `App\Entity\User`
**Scope of this document**: normalize the epic into verifiable requirements, expose gaps/conflicts, and record the
assumptions the backlog is built on. The atomic task list lives in `requirements-analyst-backlog.md`.
**Not in scope**: technical architecture (module layout, table design, endpoint contracts) — that belongs to
`/architect` → `specs/architect-architecture.md` and is only *assumed* here (see §5).

---

## 1. Existing Code Baseline (what already exists)

| Asset | State | Consequence for Epic-01 |
|---|---|---|
| `App\Entity\User` | `id`, `email` (unique), `roles` (json), `password`; implements `UserInterface`, `PasswordAuthenticatedUserInterface` | Extend, do not replace. Needs status, verification, timestamps. |
| `App\Repository\UserRepository` | password upgrader only | Needs directory queries (filter/sort/paginate). |
| `App\Controller\SecurityController` | form login (`app_login`), logout (`app_logout`) | Reuse as the login entry point. |
| `App\Controller\ApiLoginController` | `json_login` at `api_login` | Keep; role rules must apply to it too. |
| `config/packages/security.yaml` | `app_user_provider`, `main` firewall, form+json login, CSRF on form login. `switch_user` **commented out**, `access_control` **empty**, no `role_hierarchy`, no `login_throttling` | Impersonation, RBAC and rate limiting are all greenfield config. |
| `App\Products\Domain\Validator\Constraint\PasswordRequirements` | existing custom constraint | Reuse for password policy instead of writing a new one. |
| Existing modules `ToDoList/`, `Videos/`, `Products/`, `Starships/`, `Shared/` | mixed layouts: flat (`Videos/`) and Domain/Application/Infrastructure (`Products/`) | Epic-01 module layout must be an explicit decision, not inherited by accident. |
| `migrations/` | single migration `Version20260310102045` | All Epic-01 schema arrives as new migrations. |

---

## 2. Role Model (normalized)

Business rule §9: *"Each user has exactly one role"*. The epic names 4 roles but describes a 5th behaviour class
(child login with reduced permissions, US-01.06), which cannot be expressed by the Player role alone.

| Role constant | Epic name | Notes |
|---|---|---|
| `ROLE_SUPER_ADMIN` | Super Admin | Platform-wide. Cannot be impersonated (US-01.07). |
| `ROLE_TRAINER` | Trainer / Business Owner | Owns exactly one organization; tenant boundary. |
| `ROLE_COACH` | Coach / Contractor | Active under **exactly one** trainer at a time (hard rule). |
| `ROLE_PLAYER` | Player / Parent | One account, may train themselves *and* own child profiles. |
| `ROLE_CHILD` | (implied by US-01.06) | Reduced capability set; owned by a parent account. |

**Requirement**: single-role invariant must be enforced in code (entity setter/validator), because the storage
column is a JSON array that structurally allows many.

---

## 3. Functional Requirements (traceable)

Priority: **P0** = epic-blocking foundation, **P1** = required for epic acceptance, **P2** = deferrable within epic.

### 3.1 Authentication & Session
| ID | Requirement | US / §  | Prio |
|---|---|---|---|
| FR-AUTH-01 | Email+password login for all 5 role classes; email unique platform-wide | §9 | P0 |
| FR-AUTH-02 | Passwords hashed with Symfony `auto` hasher; complexity enforced on set/reset | §9, §13 | P0 |
| FR-AUTH-03 | Post-login redirect to role-appropriate dashboard | §9 | P0 |
| FR-AUTH-04 | Login attempts rate-limited (brute-force protection) | §9, §13 | P0 |
| FR-AUTH-05 | Session expires after inactivity; secure cookie flags | §9 | P0 |
| FR-AUTH-06 | Password reset flow; token single-use, expires in 1 hour | §9, §13 | P0 |
| FR-AUTH-07 | Email verification; token expires in 24 hours | §9, §13 | P0 |
| FR-AUTH-08 | Temporary password must be changed on first login | US-01.01 | P0 |
| FR-AUTH-09 | Inactive users cannot log in ("Account deactivated. Contact support."); deleted users cannot log in | US-01.12, US-01.13 | P0 |
| FR-AUTH-10 | CSRF protection on every state-changing operation | §13 | P0 |

### 3.2 Authorization & Multi-Tenancy
| ID | Requirement | US / § | Prio |
|---|---|---|---|
| FR-AUTHZ-01 | RBAC enforced server-side, not only in UI | §9 | P0 |
| FR-AUTHZ-02 | Trainer sees/manages only own organization data (0% cross-tenant leakage) | §9, AC | P0 |
| FR-AUTHZ-03 | Coach active under exactly one trainer; enforced at write time | §9, US-01.08 | P0 |
| FR-AUTHZ-04 | Player may associate with many trainers, one account, **isolated per-trainer views** | US-01.02 | P0 |
| FR-AUTHZ-05 | Active-trainer context persists across the session | US-01.02 | P0 |
| FR-AUTHZ-06 | Child capability allow/deny list enforced server-side | US-01.06 | P0 |
| FR-AUTHZ-07 | Child cannot read parent's training data | US-01.06 | P0 |

### 3.3 Super Admin
| ID | Requirement | US | Prio |
|---|---|---|---|
| FR-ADM-01 | Create trainer accounts (only Super Admin; no trainer self-registration) | US-01.01 | P0 |
| FR-ADM-02 | Users tool: global directory, tool-scoped search + filters, pagination | US-01.01, AC | P1 |
| FR-ADM-03 | Edit any user account/profile | US-01.01 | P1 |
| FR-ADM-04 | Deactivate / reactivate user, history preserved | US-01.12 | P1 |
| FR-ADM-05 | GDPR delete: anonymize PII, history shows "Deleted User", irreversible | US-01.13 | P1 |
| FR-ADM-06 | Impersonate any user except another Super Admin; sticky banner; 1h expiry | US-01.07 | P1 |
| FR-ADM-07 | Audit log for impersonation, trainer creation, deletion; Impersonation History report | US-01.07, US-01.13 | P1 |

### 3.4 Invitations (ShareLinks)
| ID | Requirement | US | Prio |
|---|---|---|---|
| FR-LINK-01 | Static player ShareLink: unlimited uses, no expiry, owned by trainer | US-01.02, §9 | P0 |
| FR-LINK-02 | Unique coach ShareLink: single use, 7-day expiry, target email | US-01.08, §9 | P0 |
| FR-LINK-03 | New user via ShareLink → account created + associated with link owner | US-01.02 | P0 |
| FR-LINK-04 | Existing user via ShareLink → new association, **no duplicate account** | US-01.02 | P0 |
| FR-LINK-05 | Parent with children → "who will train with X?" selection (self + children) | US-01.02 | P1 |
| FR-LINK-06 | Child clicking a ShareLink is blocked; parent is emailed a registration CTA | US-01.06 | P1 |
| FR-LINK-07 | Usage tracked per link (count, timestamps, which registration) for Epic-06 | §9 | P2 |
| FR-LINK-08 | Coach invite status visible to trainer: Pending / Accepted / Expired + resend | US-01.08 | P1 |

### 3.5 Profiles & Family
| ID | Requirement | US | Prio |
|---|---|---|---|
| FR-PROF-01 | Common profile: first/last name, phone, photo, school | US-01.11, §8 | P0 |
| FR-PROF-02 | Self-service profile edit; email/role/skill-level/created-at read-only | US-01.11 | P1 |
| FR-PROF-03 | Photo upload → stored + thumbnail generated | US-01.11 | P1 |
| FR-PROF-04 | Role-specific fields (player: school/jersey; coach: bio/credentials/public flag; trainer: business/org) | US-01.11 | P1 |
| FR-FAM-01 | Parent creates child profiles (name, age 1–18, gender required) | US-01.03 | P0 |
| FR-FAM-02 | Parent account is itself a player account (can train) | US-01.03, §3 | P0 |
| FR-FAM-03 | Child-trainer association is explicit; single-trainer parents get a Yes/No prompt | US-01.03 | P1 |
| FR-FAM-04 | Parent adds/removes child↔trainer associations at any time; removal soft-deletes history | US-01.04 | P1 |
| FR-FAM-05 | Context selector: parent sees "Me" + children × trainers; child sees own trainers only | US-01.04, US-01.06 | P1 |
| FR-FAM-06 | Optional child login sharing parent contact info | US-01.03, US-01.06 | P1 |
| FR-FAM-07 | Duplicate-child warning on similar name+age | US-01.03 | P2 |

### 3.6 Purchase Approval (child)
| ID | Requirement | US | Prio |
|---|---|---|---|
| FR-APPR-01 | USD purchase by child → "Pending Parent Approval" | US-01.05 | P1 |
| FR-APPR-02 | Token spend by child → approval required unless per-child override enabled (default OFF) | US-01.05 | P1 |
| FR-APPR-03 | Parent notified (email + in-app); can Approve / Deny / request info, with notes | US-01.05 | P1 |
| FR-APPR-04 | Requests auto-deny after 48h with notification | US-01.05 | P1 |
| FR-APPR-05 | Approval record stores child, parent, purchase, amount, type, status, timestamps | §8 | P1 |

### 3.7 Availability
| ID | Requirement | US | Prio |
|---|---|---|---|
| FR-AVAIL-01 | Player/parent sets weekly availability per player profile | US-01.09 | P1 |
| FR-AVAIL-02 | Coach sets recurring weekly slots, multiple ranges per day | US-01.10 | P1 |
| FR-AVAIL-03 | Trainer views + filters players by availability; "Best Times" summary on player card | US-01.09 | P1 |
| FR-AVAIL-04 | Assigning a coach to a conflicting slot warns; override requires a reason and is logged | US-01.10 | P1 |
| FR-AVAIL-05 | Availability is advisory (suggestion), never a hard scheduling block | §9 | P1 |

### 3.8 Branding
| ID | Requirement | US | Prio |
|---|---|---|---|
| FR-BRAND-01 | Trainer uploads logo (PNG/JPG/SVG, ≤2MB, recommend 200×200, auto-resize) | US-01.14 | P1 |
| FR-BRAND-02 | Trainer picks primary color (hex) used for gradient/accents, with reset-to-default | US-01.14 | P1 |
| FR-BRAND-03 | Branding preview before save; applied immediately to that trainer's org users | US-01.14 | P1 |

### 3.9 Non-Functional
| ID | Requirement | Source | Prio |
|---|---|---|---|
| NFR-01 | Dashboard < 2s; user list of 10 000 < 3s paginated; profile save < 1s; ShareLink registration < 2s | §11 | P1 |
| NFR-02 | 1 000 concurrent users; ShareLink registration handles 100 concurrent | AC, §12 | P2 |
| NFR-03 | WCAG 2.1 AA, keyboard navigation, contrast, focus indicators | §13 | P1 |
| NFR-04 | Responsive / touch-friendly | §13 | P1 |
| NFR-05 | Token lifetimes: verification 24h, reset 1h, impersonation 1h, approval 48h | §13 | P0 |

---

## 4. Gaps, Conflicts and Spec Defects Found

**Blocking contradictions** (need a decision before the affected tasks start):

1. **C-01 — Minor accounts.** §9 states *"ALL players under 18 require parent-managed accounts (no independent
   accounts for minors)"*, while US-01.06 Q-01.05 still asks whether 16–18 year olds may have independent accounts.
   Affects: child login, ShareLink blocking, approval workflow. **Backlog assumes §9 (no independent minors).**
2. **C-02 — Email verification gate.** Q-01.05 (§12) asks whether verification is required *before* login; §3 lists
   email verification as in-scope but never states the gate. **Backlog assumes: verification required for
   self-registered players/coaches, not for Super-Admin-created trainers (they use the invite link).**
3. **C-03 — Availability granularity.** US-01.09 says each child has availability preferences *"per trainer"*, while
   §8 models availability as belonging to the player/coach with no trainer column. **Backlog assumes one availability
   set per player profile (not per trainer)** and flags the alternative as a schema-affecting decision.

**Numbering / cross-reference defects in the spec** (documentation quality, not blocking):

4. §3 says *"Simple portal branding ... see US-01.12"* and the footer says *"User Stories: 12 (includes portal
   branding - US-01.12)"*, but branding is **US-01.14** and there are **14** stories.
5. US-01.06 references *"parent approval - see US-01.04"*; the approval story is **US-01.05**.
6. Section numbers repeat: two `## 10`, two `## 11`, two `## 12` (Data/Flows/Questions vs. AC/Mockups/Testing).
7. Q-01.03 is missing from the open-questions table (jumps Q-01.02 → Q-01.04).

**Silent gaps** (present in AC, absent from requirements text):

8. No requirement for **in-app notifications**, although FR-APPR-03 ("email + in-app") depends on it. Treated as a
   minimal in-app notification store in the backlog.
9. No statement of what a **Trainer** may do to their own org's users (§3 "Manage own organization users" is one
   line with no AC). Backlog covers read+availability-view only; trainer-side user editing is called out as a gap.
10. **Email change flow** is explicitly excluded ("cannot change - requires separate flow") but that flow is never
    specified anywhere. Left out of the backlog as out-of-scope-by-omission.
11. Impersonation AC says *"all actions during impersonation logged with admin_id context"* — an action-level audit
    log much broader than the session-level log in §8. Backlog implements session-level + a request-scoped logger
    hook, and flags the delta.

---

## 5. Assumptions the Backlog Is Built On

| ID | Assumption | Why | Confirm with |
|---|---|---|---|
| A-01 | New code lives in a dedicated module rather than growing `App\Entity\*` — proposed: `App\Identity` (users, auth, profiles, audit), `App\Organization` (trainer org, coach assignment, ShareLinks, branding), `App\Family` (child profiles, approvals), `App\Availability` | repo has module-per-feature precedent (`Videos/`, `Products/`) | `/architect` |
| A-02 | `App\Entity\User` is extended in place; existing rows keep working | login/api-login already wired to it | `/architect` |
| A-03 | Impersonation uses Symfony `switch_user` + a voter denying Super-Admin targets, rather than a custom mechanism | native, audited, session-bound | `/security-voter-designer` |
| A-04 | Tenant isolation is enforced by explicit trainer-scoped repository methods + voters (not a global Doctrine filter) | filters are easy to bypass in reports/CLI | `/architect` |
| A-05 | Payment execution (FR-APPR-01/02) and event/RSVP linkage sit behind interfaces stubbed in Epic-01 | Epic-05 / Epic-02 not built yet | product owner |
| A-06 | Session idle timeout = 7 days pending Q-01.07 | mid-point of the offered options | client |
| A-07 | Skill level and age group are stored as open enums with a placeholder set pending Q-01.01 / Q-01.02 | avoids blocking the profile work | client |
| A-08 | Availability granularity = per player profile (see C-03) | matches §8 data model | client |

---

## 6. Cross-Epic Seams (cannot be finished inside Epic-01)

| Seam | Epic-01 delivers | Blocked part | Depends on |
|---|---|---|---|
| Child purchase approval | approval record, states, 48h expiry, parent UI, notifications | actual charge / token debit | Epic-05 Payments |
| Approval ↔ event | approval references an opaque purchase subject | RSVP registration on approve | Epic-02 Events |
| Coach conflict override | conflict-check service + override log | assignment UI at event creation | Epic-02 Events |
| Association removal | soft-delete association + history retention | "cancel all upcoming RSVPs" | Epic-02 Events |
| ShareLink analytics | usage records | dashboards | Epic-06 Marketing |
| Camp-to-User conversion | ShareLink-by-email path reused | camp form + prefill | Epic-08 |
| Trainer Stripe/subscription/fee fields (§8) | not created in Epic-01 | whole billing model | Epic-05 Payments |

---

## 7. Open Questions — Impact Map

| Q | Question | Blocks | Fallback used |
|---|---|---|---|
| Q-01.01 | Skill level definitions | player profile field shape | open enum placeholder (A-07) |
| Q-01.02 | Age group definition | player profile, event eligibility later | store birth date, derive age (A-07) |
| Q-01.04 | Which automated emails are required | email catalogue / templates | 6 emails: trainer invite, coach invite, welcome/verify, password reset, child-link parent notice, approval request |
| Q-01.05 | Verification required before login? | login gate | C-02 assumption |
| Q-01.05b | Independent accounts for 16–18? | child model | C-01 assumption (§9 wins) |
| Q-01.06 | Notify coach when overridden? | override notification task | notification task kept, flagged P2 |
| Q-01.07 | Session timeout | session config | 7 days (A-06) |

---

## 8. Completeness Verdict

Epic-01 is **sufficiently specified to start P0 foundation work** (roles, user/profile model, auth flows,
tenancy, ShareLinks). It is **not** sufficiently specified to *finish*:

- everything gated by C-01/C-02/C-03 (child model, verification gate, availability shape),
- the trainer-side user management surface (gap 9),
- the action-level impersonation audit (gap 11),
- anything crossing into Epic-02/Epic-05 (§6).

Recommended next step after this decomposition: `/architect` for module layout + tenancy strategy (A-01, A-04),
then `/database-designer` for the ~14 new tables implied by §8.
