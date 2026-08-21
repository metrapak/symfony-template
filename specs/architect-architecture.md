# Architecture — Epic-01 Foundation (Identity, Tenancy, Family, Availability)

**Status**: proposed · **Scope**: resolves A-01 (module layout) and A-04 (multi-tenancy enforcement) from
`tasks/TASK-001/requirements-analyst-requirements.md`, plus the cross-cutting conventions the 65 backlog tasks need.
**Inputs**: `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`, `tasks/TASK-001/*`, current `src/`.
**Applies to**: `src/` — Symfony 7.4 LTS, PHP 8.2+ (runtime 8.4), Doctrine ORM 2.15, PostgreSQL 15, Twig, Knp Paginator.

---

## 1. Baseline Constraints Found in the Codebase

These are not preferences; they shape the decisions below.

| Finding | Where | Consequence |
|---|---|---|
| Doctrine mappings are declared **per module**, `auto_mapping: true` plus 5 explicit `mappings` entries | `config/packages/doctrine.yaml` | Every new module costs one mapping block. Many modules = many blocks and no other benefit. |
| Routes are imported **per module directory** | `config/routes.yaml` | Same per-module config cost for controllers. |
| Autowiring registers all of `src/`, excluding `**/Domain/Entity/` and `**/Domain/Enum/` | `config/services.yaml` | If entities live in `<Module>/Entity/` (the `Videos/` style) they are **not** excluded and get registered as services. New modules must either use `Domain/Entity/` or the exclude list must be extended. |
| Two competing module styles coexist: flat (`Videos/`, `ToDoList/`) and Domain/Application/Infrastructure (`Products/`, `Starships/`) | `src/src/` | A style must be chosen explicitly for Epic-01, otherwise 65 tasks will drift. |
| `App\Entity\User` is referenced from only **3 places** | `UserRepository`, `ApiLoginController`, `security.yaml` | Relocating it is a 4-line change with **no migration** (table name is pinned by `#[ORM\Table(name: '`user`')]`). |
| PostgreSQL 15 | `docker/compose.yml` | Partial unique indexes (`WHERE status = 'active'`) are available — the correct tool for "one active coach assignment". |
| `symfony/clock` is installed | `composer.lock` | Token expiry (24 h / 1 h / 48 h) must depend on `ClockInterface`, never `new \DateTimeImmutable()`, so expiry is testable. |
| `symfony/rate-limiter`, `symfony/uid`, `symfony/scheduler` are **not** installed | `composer.lock` | `login_throttling` (E01-T008), opaque ids, and the 48 h expiry job need dependency approval first. |
| No `gd` / `imagick` extension | `docker/php-fpm/Dockerfile` | Thumbnail + logo resize (E01-T054, E01-T064) cannot be implemented as specified until an extension is added to the image. |
| Messenger has only the `sync` transport; `async`/`failed` are commented out | `config/packages/messenger.yaml` | Emails and the expiry job run inline unless an async transport is enabled. Affects the < 2 s ShareLink registration target. |
| Existing controllers inject `EntityManagerInterface` and mutate entities directly | `Videos/Controller/AdminController.php` | The Controller → Service → Repository rule is *not* currently upheld. Epic-01 sets the new baseline without rewriting the pet modules. |
| Repositories: `ServiceEntityRepository` everywhere; `Products`/`Starships` add a Domain interface | — | Interfaces only where there is a real second implementation or a genuine inversion need. |

---

## 2. AD-01 — Module Layout

### Decision

Two modules, plus the existing `Shared`:

```text
src/
├── Identity/                 # account & authentication — knows nothing about trainers
│   ├── Domain/Entity/        # User, Profile, EmailVerificationToken, PasswordResetToken, AuditEntry
│   ├── Domain/Enum/          # UserStatus, Role
│   ├── Domain/Repository/    # interfaces only where inverted (see §4.5)
│   ├── Application/          # use cases: RegisterUser, VerifyEmail, RequestPasswordReset, ...
│   ├── Infrastructure/
│   │   ├── Controller/       # login/registration/verification/reset/profile
│   │   ├── Persistence/Doctrine/
│   │   ├── Security/         # UserChecker, voters, ImpersonationVoter, TenantContext consumers
│   │   ├── Form/
│   │   └── Mailer/
│   └── ...
├── Academy/                  # the tenant domain — depends on Identity, never the reverse
│   ├── Domain/Entity/        # TrainerOrganization, PlayerProfile, TrainerPlayerAssociation,
│   │                         # CoachAssignment, ShareLink, ShareLinkUsage, ChildLink,
│   │                         # PurchaseApprovalRequest, Availability, AvailabilityOverride, Branding
│   ├── Domain/Enum/
│   ├── Domain/Tenancy/       # TrainerScope value object (see AD-02)
│   ├── Application/          # use cases per workflow
│   ├── Infrastructure/       # Controller / Persistence / Security(voters) / Form / Twig
│   └── ...
└── Shared/                   # existing: cross-cutting infrastructure only
```

**Dependency rule** (the point of the split): `Academy → Identity → Shared`. `Identity` must never import
`App\Academy\*`. Doctrine associations therefore always point *from* Academy *to* Identity
(`TrainerOrganization.owner → User`, `PlayerProfile.account → User`), never back. `User` holds **no** collection of
associations, organizations, or player profiles — those are queried through Academy repositories with an explicit
scope. This is what keeps "Identity" reusable and stops Epic-01 from becoming one cyclic blob.

**Style**: `Domain / Application / Infrastructure` (the `Products`/`Starships` style), because entities placed in
`<Module>/Domain/Entity/` are already excluded from service registration by `services.yaml` — the flat `Videos/Entity`
style would register every entity as a service.

**`App\Entity\User` moves to `App\Identity\Domain\Entity\User`.** Three references and one mapping block change; the
table name is pinned by the entity attribute, so **no migration and no data change**. Doing it now, before 65 tasks
reference it, costs minutes; doing it later costs a repo-wide rename. `App\Repository\UserRepository` moves to
`App\Identity\Infrastructure\Persistence\Doctrine\UserRepository`, keeping `PasswordUpgraderInterface`.

### Required configuration changes

```yaml
# config/packages/doctrine.yaml — add two mappings, retarget the App one
Identity: { is_bundle: false, type: attribute, dir: '%kernel.project_dir%/src/Identity/Domain/Entity', prefix: 'App\Identity\Domain\Entity' }
Academy:  { is_bundle: false, type: attribute, dir: '%kernel.project_dir%/src/Academy/Domain/Entity',  prefix: 'App\Academy\Domain\Entity' }
# the existing `App` mapping (src/Entity) is removed once User has moved and src/Entity is empty
```

```yaml
# config/routes.yaml — add two imports
identity_controllers: { resource: '../src/Identity/Infrastructure/Controller/', type: attribute }
academy_controllers:  { resource: '../src/Academy/Infrastructure/Controller/',  type: attribute }
```

```yaml
# config/packages/security.yaml
providers.app_user_provider.entity.class: App\Identity\Domain\Entity\User
```

### Alternatives rejected

| Option | Why not |
|---|---|
| Four modules (Identity / Organization / Family / Availability) — the A-01 draft | Family, availability and associations all reference `PlayerProfile` and `TrainerOrganization` in both directions. Four modules would need cross-module Doctrine associations in both directions, i.e. four *labels* on one bounded context, plus four mapping blocks and four route imports. False boundaries cost more than they document. |
| One module (`App\Academy` containing auth) | Authentication, password reset and audit have zero knowledge of trainers and are the part most likely to be reused or replaced (SSO/2FA in Phase 2). Merging them removes the one boundary that is real. |
| Keep everything in `App\Entity` / `App\Controller` | 14 new entities and ~30 controllers in a flat namespace, against the repo's own module precedent. |
| Keep `User` in `App\Entity` while its module lives in `Identity` | The aggregate everything references would sit outside its module, and `Identity` would own two Doctrine mapping homes. |

---

## 3. AD-02 — Multi-Tenancy Enforcement

### The actual shape of the problem

The epic has **two different isolation requirements**, and only one of them is row ownership:

1. **Trainer-side scope** (FR-AUTHZ-02): a trainer may only reach data belonging to *their* organization.
2. **Player-side context** (FR-AUTHZ-04/05): one player account is legitimately associated with *several* trainers
   and must see exactly one trainer's slice at a time — the isolation boundary is the **association**, not the row.
   The same `PlayerProfile` row is visible to trainer A and trainer B.

A row-level "owner_id = current tenant" model cannot express (2) at all.

### Decision

**Explicit scope object + voters. No global Doctrine filter.**

1. **`TrainerScope`** — a small value object (`App\Academy\Domain\Tenancy\TrainerScope`) wrapping one trainer
   organization id. Every tenant-scoped repository method takes it as a **required first parameter**:

   ```php
   public function findPlayersForTrainer(TrainerScope $scope, PlayerListCriteria $criteria): PaginationInterface;
   public function findActiveCoachAssignment(TrainerScope $scope, UserId $coach): ?CoachAssignment;
   ```

   A method that needs a scope and does not take one is a review defect that is visible in the signature — this is
   the enforcement mechanism, and it survives CLI commands, fixtures, Messenger handlers and reports, none of which
   run inside a firewall.

2. **`TenantContext`** — one request-scoped service that resolves the scope from the authenticated user:
   - `ROLE_TRAINER` → their own organization (no choice, no session input);
   - `ROLE_COACH` → the organization of their single active `CoachAssignment`;
   - `ROLE_PLAYER` / `ROLE_CHILD` → the **active trainer context** stored in the session, validated on every read
     against an existing active association (a stale or forged session value yields no scope, not another trainer's
     data);
   - `ROLE_SUPER_ADMIN` → no implicit scope; admin surfaces must pass a scope explicitly or use methods documented as
     platform-wide.

3. **Voters for object-level access** (`TrainerOrganizationVoter`, `PlayerProfileVoter`, `ShareLinkVoter`,
   `ChildVoter`, `ImpersonationVoter`) — everything reachable by id from a URL is checked by voter, because a scoped
   query protects lists while a voter protects `/{id}` routes.

4. **`access_control` for coarse path rules only** (`^/admin` → `ROLE_SUPER_ADMIN`, `^/trainer` → `ROLE_TRAINER`, …).
   It is a first gate, never the authorization argument.

5. **Child capabilities** (FR-AUTHZ-06/07) are a deny-list voter derived from the US-01.06 table, not scattered
   `if` statements — one voter, one test per denied capability.

6. **A guard test suite** (E01-T024) is part of the mechanism, not an afterthought: trainer A attempts to read and
   write every one of trainer B's resource types and is denied.

### Alternatives rejected

| Option | Why not |
|---|---|
| Doctrine SQL filter (`@Filter` on tenant column) | Cannot express the multi-trainer player (requirement 2). Applies to reads only — writes and `UPDATE`/`DELETE` DQL stay unguarded. Silently disabled in fixtures/CLI/tests, so a passing test suite proves nothing. Hides the scope, so a missing scope produces *no visible defect*. |
| Trainer id column on every table | Duplicates the association's meaning, and would still need the association table for multi-trainer players — two sources of truth for who may see whom. |
| Separate database/schema per trainer | Massive operational cost for a platform whose players deliberately span trainers; cross-tenant player identity becomes impossible. |
| Voters only, no scoped repositories | Voters cannot protect list queries; the N+1 of "load all, filter in PHP" also breaks NFR-01 at 10 000 users. |

---

## 4. Cross-Cutting Conventions

### 4.1 Entry points

| Kind | Use for | Rule |
|---|---|---|
| Twig controller (`AbstractController`) | every user-facing flow in this epic | thin: map request → authorize → invoke one use case → redirect/render |
| JSON controller | existing `api_login` surface only | request DTO in, response DTO out, never an entity |
| Console command | GDPR bulk operations, backfills, dev seeding | argument validation only, then delegate |
| Messenger handler | outbound email, 48 h approval expiry, image derivation | idempotent, retryable |
| Event subscriber | `lastLoginAt` stamping, impersonation audit hook | adapter only — never the workflow (see `examples/symfony-clean-code-patterns.md` §9) |

Twig-first is deliberate: the epic's screens are server-rendered forms, the repo has no JS build beyond
AssetMapper + Tailwind, and NFR-03 (WCAG AA) is cheapest on progressively enhanced HTML.

### 4.2 Validation

- **Multi-step or multi-entity flows** (registration via ShareLink, trainer creation, child creation, approvals):
  Symfony Form mapped to an **immutable command DTO**, validated with constraints + validation groups; the use case
  receives the DTO. Never `data_class` on an entity for these.
- **Single-entity edits** (profile fields, branding): Form on the entity is acceptable, with constraints on the
  entity and read-only fields *absent from the form* (FR-PROF-02 — a hidden-but-mapped field is a tamper hole).
- Password policy reuses the existing `PasswordRequirements` constraint, relocated to
  `App\Identity\Domain\Validator\Constraint`.
- Cross-record rules that need a query (unique email, coach-already-active, age 1–18 for children) are **domain
  invariants** enforced in the use case *and* by a database constraint; the Validator layer only pre-empts them for
  a friendly message.

### 4.3 Authorization layering

```text
access_control (path, coarse)
  → controller: denyAccessUnlessGranted(Voter::ACTION, $subject)   # object-level
    → use case: TenantContext scope + domain invariants            # data-level
      → repository: TrainerScope required in the signature         # query-level
```

Impersonation uses Symfony `switch_user` restricted to `ROLE_SUPER_ADMIN`, with a voter denying Super-Admin targets
and a 1 h ceiling enforced by an impersonation-start timestamp in the session, checked by a subscriber.

### 4.4 Service (use-case) boundary

One class per workflow, `__invoke`, named after the business action, no mode flags:
`CreateTrainerAccount`, `RegisterPlayerViaShareLink`, `AssociateExistingUserWithTrainer`, `InviteCoach`,
`AcceptCoachInvitation`, `CreateChildProfile`, `SetChildTrainerAssociations`, `RequestPurchaseApproval`,
`DecidePurchaseApproval`, `ExpireStalePurchaseApprovals`, `SetAvailability`, `OverrideCoachConflict`,
`DeactivateUser`, `ReactivateUser`, `AnonymizeUser`, `StartImpersonation`, `UpdateBranding`.
Services return domain values or void — never `Response`, never `Form`.

### 4.5 Repository boundary

- `ServiceEntityRepository` per aggregate, business-readable method names, `TrainerScope` first where applicable.
- **Domain interfaces only where inversion is real**: `AvatarStorage`, `PaymentGateway` (Epic-05 seam),
  `EventRegistrar` (Epic-02 seam), `Clock` (use `ClockInterface`), `MailSender`. Doctrine repositories get **no**
  interface — there is no second implementation and the epic does not need one.
- Pagination via Knp Paginator (already configured) for the Users tool and rosters; `Doctrine\ORM\Tools\Pagination`
  where a plain `Paginator` suffices.

### 4.6 Transaction boundary

The use case owns it: one `EntityManagerInterface::wrapInTransaction()` per invocation. Rules:

- No side effect inside the transaction. Emails and notifications are dispatched to Messenger **after** commit
  (`DispatchAfterCurrentBusStamp` or an explicit post-commit dispatch), so a rolled-back registration never mails.
- Uniqueness that matters under concurrency is enforced by a database constraint and the resulting
  `UniqueConstraintViolationException` is translated into a domain error — not by a pre-check `SELECT`:
  - `user(email)` unique (exists);
  - `coach_assignment(coach_id) WHERE status = 'active'` **partial unique index** (PostgreSQL) for FR-AUTHZ-03;
  - `trainer_player_association(trainer_id, player_profile_id)` unique;
  - `share_link(code)` unique.
- Anonymization (US-01.13) is one transaction that rewrites PII and writes the deletion log, or neither.

### 4.7 Response contracts

Web: Twig template + explicit view model array (no entity graph passed to templates where a scope decision was
made), POST-redirect-GET with flash messages, CSRF token on every state change (`csrf.yaml` is enabled; form login
already sets `enable_csrf: true`). JSON: response DTO + serializer groups. Branding is exposed to templates through
one `BrandingProvider` reading from the active context, not by a global Twig variable computed per request.

### 4.8 Audit

`AuditEntry` is append-only (no update/delete methods on the repository) and written **inside** the use case's
transaction for state-changing admin actions (trainer creation, deactivation, anonymization, override,
impersonation start/stop). Impersonation additionally stamps `admin_id` on a request-scoped logger processor — this
meets the spec's "all actions logged with admin_id context" at logger granularity; a full action journal is
explicitly out of scope (gap 11 in the requirements analysis).

---

## 5. Layer Placement

| Capability | Entry point | Use case | Repository | Authorization | Notes |
|---|---|---|---|---|---|
| Login / logout | existing `SecurityController` | — (Symfony) | `UserRepository` | firewall + `UserChecker` | add throttling + status check |
| Email verification | `Identity\...\VerificationController` | `SendVerificationEmail`, `VerifyEmail` | `EmailVerificationTokenRepository` | signed token | 24 h via `ClockInterface` |
| Password reset | `Identity\...\PasswordResetController` | `RequestPasswordReset`, `ResetPassword` | `PasswordResetTokenRepository` | token + throttle | 1 h, single use |
| Forced first-login change | subscriber + controller | `ChangePassword` | — | flag on `User` | subscriber redirects, does not decide |
| Create trainer | `Academy\...\Admin\TrainerController` | `CreateTrainerAccount` | `TrainerOrganizationRepository` | `access_control` + voter | audit + invite mail |
| Users tool | `Academy\...\Admin\UserDirectoryController` | `ListUsers` (query service) | `UserRepository::search()` | `ROLE_SUPER_ADMIN` | Knp pagination + indexes |
| Deactivate / reactivate | admin controller | `DeactivateUser` / `ReactivateUser` | `UserRepository` | voter | audit |
| GDPR delete | admin controller | `AnonymizeUser` | `UserRepository`, `DeletionLogRepository` | voter | one transaction |
| Impersonation | `switch_user` + banner subscriber | `StartImpersonation` (audit) | `ImpersonationLogRepository` | `ImpersonationVoter` | 1 h ceiling |
| ShareLink generation | `Academy\...\Trainer\ShareLinkController` | `CreateShareLink` | `ShareLinkRepository` | `ShareLinkVoter` + scope | static vs unique |
| Registration via link | `Academy\...\JoinController` | `RegisterPlayerViaShareLink` / `AssociateExistingUserWithTrainer` | `ShareLinkRepository`, `TrainerPlayerAssociationRepository` | anonymous + link validity | < 2 s target |
| Coach invite / accept | trainer + join controllers | `InviteCoach`, `AcceptCoachInvitation` | `CoachAssignmentRepository` | voter + scope | partial unique index |
| Active trainer context | context controller + `TenantContext` | `SwitchTrainerContext` | association repo | association must exist | session-backed |
| Child profile | `Academy\...\Family\ChildController` | `CreateChildProfile`, `SetChildTrainerAssociations` | `PlayerProfileRepository` | `ChildVoter` (parent owns child) | age 1–18 |
| Child restrictions | — | — | — | `ChildCapabilityVoter` | deny-list from US-01.06 |
| Purchase approval | `Academy\...\Family\ApprovalController` + Messenger | `RequestPurchaseApproval`, `DecidePurchaseApproval`, `ExpireStalePurchaseApprovals` | `PurchaseApprovalRequestRepository` | `ChildVoter` / parent voter | `PaymentGateway` seam |
| Availability | `Academy\...\AvailabilityController` | `SetAvailability` | `AvailabilityRepository` | voter + scope | one set per owner (C-03) |
| Coach conflict override | (Epic-02 wiring) | `OverrideCoachConflict` | `AvailabilityOverrideRepository` | trainer voter + scope | reason required, audited |
| Profile edit | `Identity\...\ProfileController` | `UpdateProfile` | `ProfileRepository` | own-record voter | read-only fields not in form |
| Photo / logo upload | controllers above | `StoreAvatar`, `UpdateBranding` | — | voter | `AvatarStorage` interface; needs `gd` |

---

## 6. Files Likely Touched

**Configuration** (all in `src/config/`): `packages/doctrine.yaml` (2 mappings added, `App` mapping retired),
`routes.yaml` (2 imports), `packages/security.yaml` (provider class, `role_hierarchy`, `access_control`,
`switch_user`, `login_throttling`), `packages/messenger.yaml` (async + failed transport), `packages/framework.yaml`
(session cookie hardening, rate limiter), `services.yaml` (bind for upload paths, `TenantContext` scoping).

**Moved**: `src/Entity/User.php` → `src/Identity/Domain/Entity/User.php`;
`src/Repository/UserRepository.php` → `src/Identity/Infrastructure/Persistence/Doctrine/UserRepository.php`;
`src/Products/Domain/Validator/Constraint/PasswordRequirements.php` → `src/Identity/Domain/Validator/Constraint/`.
Import updates in `src/Controller/ApiLoginController.php` and `src/Controller/SecurityController.php`.

**New**: `src/Identity/**`, `src/Academy/**`, `templates/identity/**`, `templates/academy/**`,
`migrations/Version*` (one per backlog wave, not one per entity), `docker/php-fpm/Dockerfile` (image extension).

---

## 7. Tests Needed

| Layer | What | Where |
|---|---|---|
| Unit | role invariant, token expiry with a frozen clock, `TrainerScope`, state machines (approval, association status), child capability voter, availability overlap | `tests/Identity/Unit`, `tests/Academy/Unit` |
| Repository integration | scoped queries return only in-scope rows; partial unique index rejects a second active coach assignment; directory search/pagination against a seeded 10 000-user set | `tests/Academy/Integration` |
| Functional | login per role, throttling, verification, reset, forced change, ShareLink registration (new + existing + child-blocked), coach invite lifecycle, context switching, child profile CRUD, approval flow, profile edit, deactivate/delete, impersonation (allowed / Super-Admin denied / non-admin denied) | `tests/{Identity,Academy}/Functional` |
| Security regression | the E01-T024 cross-tenant matrix; CSRF absence rejected; read-only field tampering rejected; upload of a non-image | `tests/Academy/Functional/Isolation` |
| Messenger | approval expiry handler idempotency; email dispatched only after commit | `tests/Academy/Messenger` |

The existing `dama/doctrine-test-bundle` (configured) gives per-test transaction rollback; `tests/` already follows
`tests/<Module>/<Kind>/…`, so keep that shape.

---

## 8. Rollout Risks

| Risk | Impact | Mitigation |
|---|---|---|
| `User` relocation touches the security provider | a wrong FQCN breaks all logins | single commit, `lint:container` + a login functional test in the same commit; no migration involved |
| Messenger is `sync`-only | registration and invite emails run inline; NFR-01 (< 2 s) at risk; a mail failure fails the request | enable a `doctrine://` async transport + `failed` transport **before** E01-T010 lands; a worker becomes a deployment requirement |
| `symfony/rate-limiter` absent | E01-T008 cannot start | `/dependency-manager` vets `symfony/rate-limiter`; no throttling means no FR-AUTH-04 |
| No `gd`/`imagick` | E01-T054 and E01-T064 resize/thumbnail cannot ship | add the extension to `docker/php-fpm/Dockerfile` (image rebuild for every developer) or store originals only and defer resizing — a scope decision, not an implementation detail |
| 14 new tables in one epic | migration review fatigue, drift between branches | one migration per wave, `doctrine:schema:validate --skip-sync` in the DoD, no hand-edited migrations after review |
| `user.status` added to an existing table | existing rows | default `active` in the migration; verification timestamp nullable |
| Session-backed trainer context | forged/stale session value | `TenantContext` revalidates against an active association on every resolve; never trust the session id alone |
| Uploads under `public/` | direct URL access to avatars/logos, path traversal | dedicated directory per kind, generated filenames, MIME sniffing, size limit, no user-supplied paths |
| Partial unique index is PostgreSQL-specific | portability | acceptable — the project targets PostgreSQL 15; documented here so nobody "fixes" it into a plain unique index |

**Assumptions carried from the requirements analysis** (unchanged and still open): A-05 (payment/event seams stubbed),
A-06 (7-day session pending Q-01.07), A-07 (open enums pending Q-01.01/02), A-08 + C-03 (availability per owner),
C-01 (no independent minors), C-02 (verification gate policy).

---

## 9. Impact on the Backlog

Amendments to `tasks/TASK-001/requirements-analyst-backlog.md`:

- **E01-T066 (new, P0, S, no deps)** — relocate `User`/`UserRepository` into `App\Identity`, retire the `App`
  Doctrine mapping, add the two module mappings and route imports. **Must land before E01-T001.**
- **E01-T067 (new, P0, S)** — enable Messenger async + failed transports and document the worker requirement.
  Blocks E01-T010.
- **E01-T068 (new, P0, S)** — dependency approval + install for `symfony/rate-limiter` (and `symfony/uid` if opaque
  ids are wanted for ShareLink codes). Blocks E01-T008.
- **E01-T069 (new, P1, S)** — add an image extension to the PHP image, or record the decision to defer resizing.
  Blocks the resize half of E01-T054 and E01-T064.
- **E01-T023** is now specified as `TrainerScope` + `TenantContext` + voters (not a Doctrine filter), and grows the
  `TenantContext` resolution rules for all five roles.
- **E01-T022** must use a PostgreSQL partial unique index, with the domain error translated from the constraint
  violation.
- **E01-T012** fixtures/builders live per module under `tests/<Module>/…` and must build users through the use cases
  where invariants matter.

---

## 10. Next Command Recommendation

1. `/database-designer` — turn §5 into the concrete schema: ~14 tables, the partial unique index for
   `coach_assignment`, the composite indexes the Users tool and roster queries need for NFR-01, and the migration
   split per wave.
2. `/security-voter-designer` — the E01-T017 permission matrix, the child capability deny-list, the impersonation
   voter, and `access_control` blocks.
3. `/dependency-manager` — vet `symfony/rate-limiter` (+ optional `symfony/uid`) and the async transport choice.
4. Then `/architecture-implementer` for the E01-T066 relocation and the module skeletons, before any feature coding.
