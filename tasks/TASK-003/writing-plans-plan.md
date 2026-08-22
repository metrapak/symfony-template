# TASK-003 Implementation Plan: ShareLink Invitations & Organization Membership

**Inputs**: `tasks/TASK-003/requirements-analyst-requirements.md` (FR-040…049),
`tasks/TASK-003/architect-architecture.md` (D1…D15).
**Target**: Symfony 7.4, PHP >= 8.2, PostgreSQL, Doctrine ORM 2.15. Every command runs through Docker via
the Makefile (`make test`, `make stan`, `make cs-fix`, `make migrate`).

Written for an engineer with no prior context. Each step names the files, the behaviour, and a command whose
output proves it landed. The steps are in dependency order and were executed in this order.

## Goal

Ship the invitation system that populates every trainer's organization: static player links, single-use
coach invitations, the redemption flows for new and existing accounts, the family-member selection, the
child block, and the association records the rest of the epic depends on.

## Non-Goals

- No profile screens, no context switcher, no branding — TASK-004 owns those. `PlayerProfile` is seeded
  here with only the fields this flow writes (D1).
- No child **logins**. FR-048's block is implemented and tested, but the accounts it refuses are created by
  TASK-004.
- No coach-transfer workflow (G-20): the schema permits it, no screen performs it.
- No JSON API. Server-rendered Twig with progressive enhancement, per the epic decision.
- No trainer roster/CRM screen. Associations are written and queryable; presenting them is a later task.

## Current → Target Behaviour

| Aspect | Before | After |
|:-------|:-------|:------|
| Player onboarding | None. Only a Super Admin creating trainers | `/join/{code}` → register or associate, in one transaction |
| Coach onboarding | None | Invite by email, single-use 7-day link, accept by registering or signing in |
| Multi-trainer players | Not modelled | `trainer_player_association`, unique per (organization, player) |
| Coach tenancy | `TenantContext` returned null behind a TODO | Resolved from the active `coach_assignment` |
| Link analytics | None | `share_link_redemption` plus a per-link usage counter |
| Family selection | None | "Who will train with {trainer}?" checklist for accounts with children |
| Child redemption | Undefined | Refused, recorded, parent emailed |

## Steps

### 1. Enums, value object, entities

- `src/Membership/Enum/`: `ShareLinkType`, `MembershipStatus`, `RedemptionOutcome`, `ShareLinkState`,
  `RedemptionAction`, `CoachInvitationStatus`.
- `src/Membership/ValueObject/ShareLinkCode.php` — `random_bytes(16)`, base32, 26 characters, validated on
  construction (D5).
- `src/Profile/Entity/PlayerProfile.php` + `Enum/PlayerGender.php` + repository (D1).
- `src/Membership/Entity/`: `ShareLink`, `TrainerPlayerAssociation`, `CoachAssignment`,
  `ShareLinkRedemption` (D2, D4).

Proof: `php -l` on each file; the entities are exercised by step 3's migration diff.

### 2. Repositories

`ShareLinkRepository` (`findOneByCode`, `consume`, per-organization listings),
`TrainerPlayerAssociationRepository`, `CoachAssignmentRepository`, `ShareLinkRedemptionRepository`,
`PlayerProfileRepository`. Every organization-scoped method takes the organization id as a required
parameter, per the epic's binding convention.

`consume()` is the conditional UPDATE from D3 — the only place `use_count` changes.

### 3. Migration

Generated with `doctrine:migrations:diff`, then documented and its bogus `CREATE SCHEMA public` removed
from `down()`.

```bash
make migrate
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:schema:validate
```

Both must be clean. If `schema:validate` reports drift on the partial index, the predicate string in
`CoachAssignment` no longer matches what PostgreSQL stores — see D4 before "fixing" it by deleting the
attribute.

### 4. Services

- `ShareLinkGenerator` — `createPlayerLink`, `createCoachLink`, `reissue`. Persists, never flushes on its
  own for the coach path, which belongs to a wider transaction.
- `ShareLinkResolver` — the state matrix (D6).
- `RedemptionRecorder` — persists without flushing, like `AuditLogger`.
- `RedemptionPlanner` — the decision table (D7).
- `PlayerRegistrationService` — account + profiles + association + redemption in one transaction (D8).
- `AssociationService` — idempotent attach for one profile or a selected family, with one retry on the
  unique-violation race (D9).
- `CoachInvitationService` — invite, resend, accept, register-and-accept; BR-044 enforced at all three
  points (D15).
- `ChildJoinRequestNotifier` — record, then email the parent, deduplicated per (link, child).
- `CoachDirectory` — the derived Pending/Accepted/Expired read model.
- `CoachAssignmentOrganizationProvider` + `Account\Service\CoachOrganizationProvider` (D11).

### 5. Input boundary

DTOs with Validator constraints (`PlayerRegistrationInput` with `child`/`self` validation groups,
`CoachRegistrationInput`, `CoachInviteInput`, `FamilySelectionInput`) and their form types. The group is
chosen from the submitted flag in `PlayerRegistrationFormType::configureOptions()`, which is what makes the
hidden-field enhancement safe (D12 note, NFR-043).

### 6. Controllers, routes, security

`JoinController` (public), `Trainer\ShareLinkController`, `Trainer\CoachInviteController`, `ShareLinkVoter`.
Wiring: `config/routes.yaml`, `config/packages/doctrine.yaml`, `config/services.yaml` (entity/enum/value
object exclusions), `config/packages/rate_limiter.yaml`, `config/packages/security.yaml` (`^/join` public).

```bash
cd docker && docker compose run --rm -T php-fpm bin/console lint:container
cd docker && docker compose run --rm -T php-fpm bin/console debug:router | grep join
```

### 7. Templates and progressive enhancement

`templates/join/*` (landing, both registration forms, and the unavailable / expired / blocked-child /
not-eligible / throttled states), `templates/trainer/*`, `templates/membership/email/*` in HTML and text.
`assets/copy-link.js` and `assets/join-registration.js`, both imported from `assets/app.js`; both reveal or
hide only what already works without them.

```bash
cd docker && docker compose run --rm -T php-fpm bin/console lint:twig templates
```

### 8. Fixtures

`MembershipFixtures` — a usable player link, a pending coach invitation, one that lapsed yesterday, a
player who already joined, and a parent with a child. Fixed codes, so the URLs can be written down.

```bash
make db-seed
```

### 9. Tests

| File | Covers |
|:-----|:-------|
| `Unit/ValueObject/ShareLinkCodeTest` | FR-049 code properties, malformed input |
| `Unit/Service/ShareLinkResolverTest` | D6 state matrix, the seven-day boundary on both sides |
| `Unit/Service/RedemptionPlannerTest` | D7 decision table including the child block |
| `Functional/PlayerRegistrationTest` | FR-042 end to end, both branches, both emails, validation |
| `Functional/ExistingPlayerAssociationTest` | FR-043 multi-trainer and idempotency, FR-044 checklist and tampering |
| `Functional/CoachInvitationTest` | FR-041, FR-045, FR-046, cross-tenant resend 403 |
| `Functional/ChildLinkBlockingTest` | FR-048, including the reload-does-not-re-email rule |
| `Functional/ShareLinkManagementTest` | FR-040, FR-049 uniform failures and rate limiting, IDOR |
| `Integration/MembershipConstraintsTest` | D4 constraints asserted against the database directly |

`tests/Account/Unit/Service/TenantContextTest` gains the coach branch; `AuthorizationMatrixTest` gains the
new trainer paths and `/join`.

```bash
make test
```

## Verification

```bash
make test     # 367 tests
make stan     # PHPStan level 5
make cs-check # PHP-CS-Fixer
cd docker && docker compose run --rm -T php-fpm bin/console doctrine:schema:validate
```

## Known Gaps Left Open

- **NFR-041 concurrency** is proven sequentially and by constraint, not by a parallel load test — DAMA's
  per-test transaction makes a second connection blind to the fixtures. Needs a load harness against a
  deployed instance.
- **G-15** (removing a trainer with an organization full of players, coaches and links) is untouched and
  now has more to break: it should be answered before TASK-004 adds branding to the same organization.
- **G-20** coach transfer has schema support and no workflow.
- **Q-01.04** email content is the shipping default, not an approved one.
