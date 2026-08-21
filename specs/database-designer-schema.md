# Database Design — Epic-01 Foundation

**Status**: proposed · **Depends on**: `architect-architecture.md` (AD-01 modules, AD-02 tenancy)
**Platform**: PostgreSQL 15 · Doctrine ORM 2.15 · Doctrine Migrations · attribute mapping ·
naming strategy `underscore_number_aware`
**Sources**: `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` §8/§9/§11,
`tasks/TASK-001/requirements-analyst-*.md`

Every constraint and index below names the invariant or the query it exists for. Anything without such a
justification was left out on purpose.

---

## 1. Global Decisions

| Decision | Choice | Why |
|---|---|---|
| Primary keys | `INT` identity via sequence (Doctrine `AUTO`) | The 6 existing tables already use it; no requirement here needs distributed id generation. US-01.13 explicitly puts the user id in the anonymized email, so an internal id is already public-facing by spec. |
| Public secrets | random 32-byte tokens, stored **hashed** (SHA-256), never plaintext | A leaked backup must not yield working password-reset links. Lookup is by hash, so the hash column carries the unique index. |
| ShareLink code | 12-char URL-safe random string, stored as-is, unique | It appears in a URL (`/join/ABC123`) and must be human-shareable. Not a hash — it is a public identifier, not a secret credential; safety comes from `is_active`/`expires_at`/`max_uses`, not from secrecy. |
| Enum storage | `VARCHAR` + `CHECK` constraint, mapped to PHP string-backed enums | Native PG enums need `ALTER TYPE` for every new value, which is a migration hazard across branches. The codebase already uses string-backed PHP enums (`StarshipStatusEnum`). |
| Timestamps | `TIMESTAMP(0) WITHOUT TIME ZONE` in UTC, `datetime_immutable` | Matches the existing migration; immutable types prevent accidental mutation of audit rows. |
| Money | `amount_cents INT` + `currency CHAR(3)` | No float money. Epic-05 will own the real payment record; this is only the approval subject. |
| Deletes | `ON DELETE RESTRICT` for every user/tenant reference; `CASCADE` only for tokens | US-01.12/13 say history is *never* deleted — rows are deactivated or anonymized. RESTRICT turns "someone wrote a `DELETE`" into an error instead of silent history loss. Tokens are the one thing that is genuinely disposable. |
| Soft state | explicit `status` columns + `*_at` timestamps, no `deleted_at` convention | The epic has three distinct lifecycles (user, association, assignment); a generic soft-delete flag would flatten them. |
| Concurrency | `#[ORM\Version]` on `purchase_approval_request` and `coach_assignment`; atomic `UPDATE` for `share_link.used_count` | These are the only three places where two actors race for the same row (see §5). |
| Timezone | new `trainer_organization.timezone` column | **Gap found**: §8 stores availability as day + start/end time with no timezone anywhere in the epic. "Monday 17:00–20:00" is meaningless without one, and trainers in different zones are inevitable. |

---

## 2. Table Model

### 2.1 Identity module (`App\Identity\Domain\Entity`)

#### `user` (existing — altered)

| Column | Type | Null | Default | Note |
|---|---|---|---|---|
| `id` | INT | no | sequence | existing |
| `email` | VARCHAR(180) | no | — | existing, unique |
| `roles` | JSON | no | — | existing; kept for the Symfony provider |
| `password` | VARCHAR(255) | no | — | existing |
| `primary_role` | VARCHAR(32) | no | `'ROLE_PLAYER'` on backfill | see below |
| `status` | VARCHAR(16) | no | `'active'` | `active` \| `inactive` \| `deleted` |
| `email_verified_at` | TIMESTAMP | yes | NULL | FR-AUTH-07 |
| `must_change_password` | BOOLEAN | no | `false` | FR-AUTH-08 |
| `last_login_at` | TIMESTAMP | yes | NULL | §8 |
| `created_at` | TIMESTAMP | no | backfilled | §8 |
| `updated_at` | TIMESTAMP | no | backfilled | §8 |

`primary_role` is a denormalization of the single-role invariant (FR-AUTHZ-01), kept in sync by one setter that
writes both it and `roles`. Reason: the Users tool must filter by role over 10 000 rows (NFR-01), and the existing
column is `JSON`, not `jsonb` — there is no usable index for role equality on it. A `VARCHAR` + btree is the cheap,
portable answer, and the CHECK constraint keeps it honest.

- `CHECK (primary_role IN ('ROLE_SUPER_ADMIN','ROLE_TRAINER','ROLE_COACH','ROLE_PLAYER','ROLE_CHILD'))`
- `CHECK (status IN ('active','inactive','deleted'))`

#### `profile` — common person fields (FR-PROF-01)
`id`, `user_id` (NOT NULL, **UNIQUE**, FK → `user` RESTRICT), `first_name` VARCHAR(80) NOT NULL,
`last_name` VARCHAR(80) NOT NULL, `phone` VARCHAR(32) NULL, `photo_path` VARCHAR(255) NULL,
`school` VARCHAR(120) NULL, `created_at`, `updated_at`.
The UNIQUE on `user_id` *is* the 1:1 — Doctrine's `OneToOne` alone does not enforce it in the database.

#### `coach_profile` — role-specific fields (FR-PROF-04)
`id`, `user_id` (NOT NULL, UNIQUE, FK RESTRICT), `bio` TEXT NULL, `credentials` TEXT NULL,
`certifications` TEXT NULL, `is_public` BOOLEAN NOT NULL DEFAULT false, `created_at`, `updated_at`.
Separate from `profile` because it exists for one role only; keeping it inline would mean five always-NULL columns
on every player row.

#### `email_verification_token` / `password_reset_token` (identical shape)
`id`, `user_id` (NOT NULL, FK **CASCADE**), `token_hash` CHAR(64) NOT NULL **UNIQUE**,
`expires_at` TIMESTAMP NOT NULL, `consumed_at` TIMESTAMP NULL, `created_at` TIMESTAMP NOT NULL.
Single-use is enforced by `consumed_at IS NULL` in the consuming `UPDATE`'s `WHERE` clause (see §5.4), not by a
read-then-write.

#### `audit_entry` (FR-ADM-07)
`id`, `actor_user_id` (NULL for system actions, FK RESTRICT), `action` VARCHAR(64) NOT NULL,
`subject_type` VARCHAR(64) NOT NULL, `subject_id` INT NULL, `payload` JSONB NOT NULL DEFAULT `'{}'`,
`created_at` TIMESTAMP NOT NULL.
`jsonb` (not `json`) here because audit queries do filter inside the payload; the existing tables' `json` choice is
not a precedent worth copying for a new column. Append-only: the repository exposes no update or delete method, and
the migration grants no application-level need for one.

#### `user_deletion_log` (US-01.13, legal retention)
`id`, `original_user_id` INT NOT NULL (**no FK, deliberately**), `original_email` VARCHAR(180) NOT NULL,
`deleted_by_user_id` INT NOT NULL (FK RESTRICT), `reason` TEXT NOT NULL, `deleted_at` TIMESTAMP NOT NULL.
No FK on `original_user_id` so the record survives independently of the user row it describes — this table is the
compliance evidence and must not be reachable by cascade.

#### `impersonation_session` (US-01.07)
`id`, `admin_user_id` NOT NULL (FK RESTRICT), `target_user_id` NOT NULL (FK RESTRICT),
`started_at` NOT NULL, `expires_at` NOT NULL, `ended_at` NULL, `created_at`.
Duration is derived (`ended_at - started_at`), never stored — a stored duration can disagree with its own
timestamps. `CHECK (admin_user_id <> target_user_id)`.

#### `notification` — in-app inbox (gap 8, FR-APPR-03)
`id`, `user_id` NOT NULL (FK RESTRICT), `type` VARCHAR(64) NOT NULL, `payload` JSONB NOT NULL DEFAULT `'{}'`,
`read_at` TIMESTAMP NULL, `created_at` NOT NULL.

### 2.2 Academy module (`App\Academy\Domain\Entity`)

#### `trainer_organization` (§8, US-01.14)
`id`, `owner_user_id` NOT NULL **UNIQUE** (FK RESTRICT), `business_name` VARCHAR(160) NOT NULL,
`address` VARCHAR(255) NULL, `website` VARCHAR(255) NULL, `description` TEXT NULL,
`timezone` VARCHAR(64) NOT NULL DEFAULT `'UTC'`, `logo_path` VARCHAR(255) NULL,
`primary_color` CHAR(7) NULL, `created_at`, `updated_at`.

- UNIQUE `owner_user_id` = "one organization per trainer".
- Branding lives inline: two columns, always read together with the org, never queried independently — a separate
  table would add a join to every branded page render for nothing.
- `CHECK (primary_color IS NULL OR primary_color ~ '^#[0-9A-Fa-f]{6}$')` — FR-BRAND-02 hex format, enforced where it
  cannot be bypassed by a non-form write path.
- Stripe / subscription / platform-fee columns from §8 are **not created here** (Epic-05 seam).

#### `player_profile` (§8, US-01.03)
`id`, `account_user_id` NULL (FK RESTRICT), `parent_account_user_id` NULL (FK RESTRICT),
`display_name` VARCHAR(120) NOT NULL, `birth_date` DATE NULL, `gender` VARCHAR(16) NOT NULL,
`skill_level` VARCHAR(32) NULL, `school` VARCHAR(120) NULL, `jersey_number` SMALLINT NULL,
`is_child` BOOLEAN NOT NULL, `emergency_contact_name` VARCHAR(120) NULL,
`emergency_contact_phone` VARCHAR(32) NULL, `photo_path` VARCHAR(255) NULL,
`token_spending_requires_approval` BOOLEAN NOT NULL DEFAULT true, `created_at`, `updated_at`.

Invariants as CHECK constraints, because both branches are reachable from three different use cases:
- `CHECK ((is_child = false AND account_user_id IS NOT NULL AND parent_account_user_id IS NULL)
   OR (is_child = true AND parent_account_user_id IS NOT NULL))` — an adult profile owns its account; a child
  profile always has a parent and *may* have its own login (FR-FAM-06).
- `CHECK (is_child = false OR birth_date IS NOT NULL)` — age 1–18 cannot be validated without a birth date.
- Partial UNIQUE on `account_user_id WHERE account_user_id IS NOT NULL` — one player profile per account.

`birth_date` rather than `age`: age is derived (Q-01.02 unresolved), and a stored age silently rots.
`token_spending_requires_approval` defaults to `true` — FR-APPR-02's default-OFF permission, expressed positively so
the safe value is the column default.

#### `trainer_player_association` (FR-AUTHZ-04, US-01.04)
`id`, `trainer_organization_id` NOT NULL (FK RESTRICT), `player_profile_id` NOT NULL (FK RESTRICT),
`share_link_id` NULL (FK RESTRICT), `status` VARCHAR(16) NOT NULL, `connected_at` NOT NULL,
`disconnected_at` NULL, `created_at`, `updated_at`.

- `CHECK (status IN ('active','inactive'))`
- **Partial UNIQUE `(trainer_organization_id, player_profile_id) WHERE status = 'active'`** — this is the
  no-duplicate-association rule (FR-LINK-04) *and* it still allows the remove/re-add history that US-01.04 requires.
  A plain unique index would make re-joining a trainer impossible.
- `CHECK (status = 'active' OR disconnected_at IS NOT NULL)`

#### `coach_assignment` (FR-AUTHZ-03, US-01.08)
`id`, `coach_user_id` NOT NULL (FK RESTRICT), `trainer_organization_id` NOT NULL (FK RESTRICT),
`share_link_id` NULL (FK RESTRICT), `status` VARCHAR(16) NOT NULL, `invited_at` NOT NULL,
`joined_at` NULL, `ended_at` NULL, `version` INT NOT NULL DEFAULT 1, `created_at`, `updated_at`.

- `CHECK (status IN ('pending','active','inactive'))`
- **Partial UNIQUE `(coach_user_id) WHERE status = 'active'`** — the hard rule "a coach works for exactly one
  trainer at a time", enforced where two concurrent invitation acceptances cannot both win. A service-level check
  cannot do this.
- Pending invitations are deliberately *not* unique-constrained: two trainers may both have an open invitation to
  the same coach; only acceptance is exclusive. The rejection message in US-01.08 fires when acceptance loses.
- `version` for optimistic locking on `pending → active`.

#### `share_link` (§8, FR-LINK-01/02)
`id`, `code` VARCHAR(24) NOT NULL **UNIQUE**, `type` VARCHAR(16) NOT NULL,
`trainer_organization_id` NOT NULL (FK RESTRICT), `created_by_user_id` NOT NULL (FK RESTRICT),
`target_email` VARCHAR(180) NULL, `expires_at` TIMESTAMP NULL, `max_uses` INT NULL,
`used_count` INT NOT NULL DEFAULT 0, `is_active` BOOLEAN NOT NULL DEFAULT true, `created_at`, `updated_at`.

- `CHECK (type IN ('player_static','coach_unique'))`
- `CHECK (type = 'player_static' AND max_uses IS NULL AND expires_at IS NULL AND target_email IS NULL
   OR type = 'coach_unique' AND max_uses = 1 AND expires_at IS NOT NULL AND target_email IS NOT NULL)`
  — the two link kinds from §9 are structurally different, and this constraint is what stops a coach link from
  being created without an expiry.
- `CHECK (used_count >= 0 AND (max_uses IS NULL OR used_count <= max_uses))` — the counter cannot exceed the cap
  even if the application forgets (see §5.1).

#### `share_link_usage` (FR-LINK-07)
`id`, `share_link_id` NOT NULL (FK RESTRICT), `user_id` NOT NULL (FK RESTRICT),
`trainer_player_association_id` NULL (FK RESTRICT), `coach_assignment_id` NULL (FK RESTRICT), `used_at` NOT NULL.
Kept separate from the counter so Epic-06 gets per-use facts without turning `share_link` into an event log.

#### `purchase_approval_request` (US-01.05)
`id`, `child_player_profile_id` NOT NULL (FK RESTRICT), `parent_user_id` NOT NULL (FK RESTRICT),
`trainer_organization_id` NOT NULL (FK RESTRICT), `subject_type` VARCHAR(32) NOT NULL,
`subject_ref` VARCHAR(64) NOT NULL, `payment_type` VARCHAR(8) NOT NULL, `amount_cents` INT NULL,
`currency` CHAR(3) NULL, `token_amount` INT NULL, `status` VARCHAR(16) NOT NULL,
`requested_at` NOT NULL, `expires_at` NOT NULL, `decided_at` NULL, `decided_by_user_id` NULL (FK RESTRICT),
`parent_note` TEXT NULL, `version` INT NOT NULL DEFAULT 1.

- `CHECK (payment_type IN ('usd','token'))`, `CHECK (status IN ('pending','approved','denied','expired'))`
- `CHECK (payment_type = 'usd' AND amount_cents IS NOT NULL AND currency IS NOT NULL
   OR payment_type = 'token' AND token_amount IS NOT NULL)`
- `CHECK (status = 'pending' OR decided_at IS NOT NULL)` — `expired` counts as decided (by the job).
- `subject_type` / `subject_ref` is an **opaque reference**, not an FK: the purchased thing lives in Epic-02/Epic-05.
  Deliberately un-joinable until those epics exist, so nothing pretends the link is enforced.
- `version` guards the parent-approves vs. job-expires race (§5.2).

#### `availability_slot` (§8, US-01.09/10)
`id`, `player_profile_id` NULL (FK RESTRICT), `coach_user_id` NULL (FK RESTRICT),
`day_of_week` SMALLINT NOT NULL, `start_time` TIME NOT NULL, `end_time` TIME NOT NULL,
`is_available` BOOLEAN NOT NULL DEFAULT true, `created_at`, `updated_at`.

- `CHECK ((player_profile_id IS NOT NULL) <> (coach_user_id IS NOT NULL))` — exactly one owner. Two nullable FKs
  instead of a polymorphic `owner_type/owner_id` pair, so both owners keep real referential integrity.
- `CHECK (day_of_week BETWEEN 1 AND 7)` (ISO-8601, Monday = 1), `CHECK (start_time < end_time)`.
- Overlap prevention is **application-level** (repository overlap query inside the use case), not an exclusion
  constraint: `btree_gist` would be needed for a range EXCLUDE over a partial owner key, and the write volume here
  (a handful of slots per person, one editor per row) does not justify the extension. Documented as a conscious
  trade-off — the owner is the only writer, so the race is theoretical.
- One availability set per owner, **not per trainer** — resolves C-03 in favour of §8's data model.

#### `coach_availability_override` (FR-AVAIL-04)
`id`, `coach_user_id` NOT NULL (FK RESTRICT), `trainer_organization_id` NOT NULL (FK RESTRICT),
`event_ref` VARCHAR(64) NULL, `overridden_by_user_id` NOT NULL (FK RESTRICT), `reason` TEXT NOT NULL,
`created_at` NOT NULL.
`reason` is NOT NULL because US-01.10 makes it required; `event_ref` is nullable only until Epic-02 supplies real
event ids, and that nullability is the visible marker of the seam.

---

## 3. Relationship Summary

| From | To | Kind | Owning side | Delete |
|---|---|---|---|---|
| `profile` | `user` | 1:1 | `profile` | RESTRICT |
| `coach_profile` | `user` | 1:1 | `coach_profile` | RESTRICT |
| `email_verification_token` / `password_reset_token` | `user` | N:1 | token | CASCADE |
| `audit_entry` | `user` (actor) | N:1 nullable | audit | RESTRICT |
| `impersonation_session` | `user` ×2 | N:1 | session | RESTRICT |
| `notification` | `user` | N:1 | notification | RESTRICT |
| `trainer_organization` | `user` (owner) | 1:1 | org | RESTRICT |
| `player_profile` | `user` (account, parent) | N:1 ×2 nullable | profile | RESTRICT |
| `trainer_player_association` | org, `player_profile`, `share_link` | N:1 ×3 | association | RESTRICT |
| `coach_assignment` | `user` (coach), org, `share_link` | N:1 ×3 | assignment | RESTRICT |
| `share_link` | org, `user` (creator) | N:1 ×2 | link | RESTRICT |
| `share_link_usage` | link, user, association, assignment | N:1 ×4 | usage | RESTRICT |
| `purchase_approval_request` | `player_profile`, `user` ×2, org | N:1 | request | RESTRICT |
| `availability_slot` | `player_profile` \| `user` (coach) | N:1 exclusive | slot | RESTRICT |
| `coach_availability_override` | `user` ×2, org | N:1 | override | RESTRICT |

Per AD-01 the direction is always Academy → Identity. **No inverse collections are mapped on `User`** — no
`$user->getAssociations()`. Every such read goes through a scoped repository method, which is what makes the
`TrainerScope` requirement enforceable instead of bypassable via a lazy collection.

---

## 4. Index Plan

Each index names the query it serves. Queries are the repository methods in §7.

| Table | Index | Serves |
|---|---|---|
| `user` | UNIQUE `(email)` *(exists)* | login, uniqueness (FR-AUTH-01) |
| `user` | `(primary_role, status, id DESC)` | Users tool default listing + role/status filter over 10 000 rows (NFR-01); trailing `id` gives a stable sort key |
| `user` | `(lower(email) varchar_pattern_ops)` | Users tool prefix search `email ILIKE 'ab%'` — a plain btree cannot serve a pattern search |
| `user` | `(status)` partial `WHERE status <> 'active'` | admin filters for inactive/deleted, a small slice of a large table |
| `profile` | `(lower(last_name), lower(first_name))` | name search + alphabetical roster ordering |
| `profile` | UNIQUE `(user_id)` | the 1:1 invariant |
| `email_verification_token`, `password_reset_token` | UNIQUE `(token_hash)` | token consumption — the only lookup path |
| ″ | `(user_id, consumed_at)` | "does this user have a live token" (resend throttling) |
| ″ | `(expires_at)` partial `WHERE consumed_at IS NULL` | the cleanup job scans only live rows |
| `trainer_organization` | UNIQUE `(owner_user_id)` | one org per trainer; also the `TenantContext` lookup for `ROLE_TRAINER` |
| `player_profile` | partial UNIQUE `(account_user_id) WHERE account_user_id IS NOT NULL` | one profile per account |
| `player_profile` | `(parent_account_user_id, is_child)` | the family view (US-01.04) — a parent's children in one index scan |
| `player_profile` | `(lower(display_name), birth_date)` | duplicate-child warning (FR-FAM-07) and roster search |
| `trainer_player_association` | partial UNIQUE `(trainer_organization_id, player_profile_id) WHERE status='active'` | FR-LINK-04 under concurrency |
| `trainer_player_association` | `(trainer_organization_id, status, player_profile_id)` | the trainer roster query — the single hottest tenant-scoped read |
| `trainer_player_association` | `(player_profile_id, status)` | "my trainers" / context switcher (FR-AUTHZ-05), and the `TenantContext` revalidation on every player request |
| `coach_assignment` | partial UNIQUE `(coach_user_id) WHERE status='active'` | FR-AUTHZ-03 under concurrency |
| `coach_assignment` | `(trainer_organization_id, status, invited_at DESC)` | the trainer's coach list + invitation status view (FR-LINK-08) |
| `share_link` | UNIQUE `(code)` | `/join/{code}` resolution |
| `share_link` | `(trainer_organization_id, type, is_active)` | the trainer's link management screen |
| `share_link` | `(target_email)` partial `WHERE type='coach_unique'` | "is there already an open invite for this coach" |
| `share_link_usage` | `(share_link_id, used_at DESC)` | per-link usage history (Epic-06) |
| `share_link_usage` | `(user_id)` | "which link brought this user in" (attribution) |
| `purchase_approval_request` | `(parent_user_id, status, requested_at DESC)` | the parent's pending-approvals list |
| `purchase_approval_request` | `(status, expires_at)` partial `WHERE status='pending'` | the 48 h expiry job scans only candidates |
| `purchase_approval_request` | `(child_player_profile_id, status)` | the child's own status view |
| `availability_slot` | `(player_profile_id, day_of_week, start_time)` | a player's weekly grid render |
| `availability_slot` | `(coach_user_id, day_of_week, start_time)` | coach My Times render + the conflict check |
| `availability_slot` | `(day_of_week, start_time, end_time)` | the trainer's "who is free Mon 17:00–20:00" filter, joined through the roster index above |
| `audit_entry` | `(subject_type, subject_id, created_at DESC)` | per-user audit trail |
| `audit_entry` | `(actor_user_id, created_at DESC)` | per-admin activity |
| `impersonation_session` | `(admin_user_id, started_at DESC)`, `(target_user_id, started_at DESC)` | the Impersonation History report (FR-ADM-07) |
| `impersonation_session` | `(expires_at)` partial `WHERE ended_at IS NULL` | force-expiry of abandoned sessions |
| `notification` | `(user_id, read_at, created_at DESC)` | inbox + unread badge |
| `coach_availability_override` | `(coach_user_id, created_at DESC)`, `(trainer_organization_id, created_at DESC)` | override audit views |

**Deliberately not indexed**: `user.roles` (superseded by `primary_role`), `share_link.used_count`,
every `created_at`/`updated_at` without a listed query, and free-text substring search across names. If substring
search is later required, the answer is the `pg_trgm` extension with a GIN index — a dependency decision, not a
silent addition.

**Pagination**: offset pagination via Knp Paginator (already configured) is adequate at the stated 10 000-user
scale, but only with a **stable sort** — every paginated query orders by a unique tail (`… , id DESC`), otherwise
rows repeat or vanish between pages. Keyset pagination is the documented upgrade path if the directory grows past
~100 000 rows; it is not needed now and would complicate the Knp integration.

---

## 5. Concurrency and Write Paths

### 5.1 One-time coach link (FR-LINK-02, 100 concurrent registrations target)
Never read-then-increment. Claim the use atomically:

```sql
UPDATE share_link
   SET used_count = used_count + 1
 WHERE id = :id AND is_active = true
   AND (expires_at IS NULL OR expires_at > :now)
   AND (max_uses IS NULL OR used_count < max_uses)
```
Zero affected rows means "link exhausted/expired" — the only correct answer under concurrency. The CHECK constraint
on `used_count <= max_uses` is the backstop if this is ever written wrongly.

### 5.2 Parent approves while the expiry job runs (FR-APPR-04)
`purchase_approval_request.version` + `#[ORM\Version]`. The loser gets `OptimisticLockException`; the parent's UI
retries and shows the current state, the job simply skips the row. No lost decision, no double processing.

### 5.3 Two trainers' invitations accepted at once (FR-AUTHZ-03)
Both transactions insert/activate; the partial unique index lets exactly one commit. The other's
`UniqueConstraintViolationException` is translated into the US-01.08 "already active under another trainer" message.

### 5.4 Token consumption (FR-AUTH-06/07)
```sql
UPDATE password_reset_token SET consumed_at = :now
 WHERE token_hash = :hash AND consumed_at IS NULL AND expires_at > :now
```
Single-use is a property of the `UPDATE`, so a double-submitted reset link cannot set the password twice.

### 5.5 GDPR anonymization (US-01.13)
One transaction: write `user_deletion_log` (original id + email), then null/overwrite
`user.email → 'deleted_{id}@example.com'`, `user.status → 'deleted'`, `profile.first_name → 'Deleted'`,
`profile.last_name → 'User'`, `profile.phone → NULL`, `profile.photo_path → NULL`, `profile.school → NULL`,
`player_profile.display_name → 'Deleted User'`, `player_profile.emergency_contact_* → NULL`,
`player_profile.photo_path → NULL`, `coach_profile.bio/credentials/certifications → NULL`. Association,
assignment, availability, approval, audit and usage rows are **untouched** — that is the "history preserved"
requirement. Photo files are deleted from storage in the same use case, after commit.

---

## 6. Migrations

One migration per backlog wave, never one per entity — reviewers can reason about a wave, not about 19 files.

| # | Wave | Contents | Backfill |
|---|---|---|---|
| M1 | W-1/W0 | `user` ALTER (7 columns + 2 CHECKs), `profile`, `coach_profile`, both token tables, `audit_entry`, `user_deletion_log`, `notification` | `UPDATE "user" SET primary_role = COALESCE(roles->>0, 'ROLE_PLAYER'), status='active', created_at=now(), updated_at=now()`; then `SET NOT NULL`, then drop the temporary defaults |
| M2 | W2 | `trainer_organization`, `player_profile`, `trainer_player_association`, `coach_assignment` (+ the two partial unique indexes) | none (new tables) |
| M3 | W3 | `share_link`, `share_link_usage`, FK columns on the two association tables | none |
| M4 | W4 | `purchase_approval_request`, `impersonation_session` | none |
| M5 | W5 | `availability_slot`, `coach_availability_override` | none |
| M6 | W7 | the search/perf indexes from §4 measured as missing after benchmarking | none |

**Rules**
- New NOT NULL columns on the existing `user` table land in three steps: add nullable → backfill → `SET NOT NULL`.
  A single-step NOT NULL with a default rewrites the table and hides bad data.
- Index creation on `user` in M6 uses `CREATE INDEX CONCURRENTLY` (outside a transaction — the migration must
  declare `isTransactional(): false`), so a production build does not take a write lock.
- `down()` is written for every migration and drops in reverse dependency order. M1's `down()` **loses**
  `status`/`verified_at` data — stated in the migration's description, because a silent lossy rollback is worse than
  a loud one.
- The Messenger `doctrine://` transport (E01-T067) creates `messenger_messages` itself; do not hand-write it into
  these migrations.
- DoD gate per wave: `doctrine:migrations:migrate` up **and** down on a scratch database, then
  `doctrine:schema:validate --skip-sync`.

---

## 7. Repository Methods

`TrainerScope` (AD-02) is the required first parameter wherever a tenant boundary exists.

**Identity**
```php
UserRepository:
  findOneByEmail(string $email): ?User
  searchDirectory(UserDirectoryCriteria $criteria, int $page): PaginationInterface   // super-admin only, unscoped by design
  countByRoleAndStatus(): array                                                      // dashboard tiles
  upgradePassword(...)                                                               // existing
ProfileRepository:            findOneForUser(UserId $id): ?Profile
CoachProfileRepository:       findOneForCoach(UserId $id): ?CoachProfile
                              findPublicForCoach(UserId $id): ?CoachProfile
EmailVerificationTokenRepository / PasswordResetTokenRepository:
                              consumeValid(string $tokenHash, \DateTimeImmutable $now): ?Token
                              hasLiveTokenFor(UserId $id): bool
                              deleteExpiredBefore(\DateTimeImmutable $cutoff): int
AuditEntryRepository:         append(AuditEntry $entry): void
                              findForSubject(string $type, int $id, int $page): PaginationInterface
                              findForActor(UserId $actor, int $page): PaginationInterface
ImpersonationSessionRepository:
                              findOpenForAdmin(UserId $admin): ?ImpersonationSession
                              findHistory(ImpersonationHistoryCriteria $c, int $page): PaginationInterface
                              closeExpired(\DateTimeImmutable $now): int
NotificationRepository:       findInbox(UserId $user, int $page): PaginationInterface
                              countUnread(UserId $user): int
UserDeletionLogRepository:    record(UserDeletionLog $log): void
```

**Academy**
```php
TrainerOrganizationRepository:
  findOneForOwner(UserId $owner): ?TrainerOrganization
  get(TrainerScope $scope): TrainerOrganization
PlayerProfileRepository:
  findRoster(TrainerScope $scope, RosterCriteria $c, int $page): PaginationInterface
  findChildrenOfParent(UserId $parent): array
  findOneForAccount(UserId $account): ?PlayerProfile
  findSimilarForParent(UserId $parent, string $name, ?\DateTimeImmutable $birthDate): array
TrainerPlayerAssociationRepository:
  findActive(TrainerScope $scope, PlayerProfileId $player): ?TrainerPlayerAssociation
  findActiveTrainersForPlayer(PlayerProfileId $player): array          // context switcher
  existsActiveForAccountAndScope(UserId $account, TrainerScope $scope): bool   // TenantContext revalidation
  findHistoryForPlayer(PlayerProfileId $player): array
CoachAssignmentRepository:
  findActiveForCoach(UserId $coach): ?CoachAssignment
  findForTrainer(TrainerScope $scope, ?string $status, int $page): PaginationInterface
  activate(CoachAssignment $a): void                                    // optimistic-locked
ShareLinkRepository:
  findActiveByCode(string $code, \DateTimeImmutable $now): ?ShareLink
  claimUse(ShareLinkId $id, \DateTimeImmutable $now): bool              // atomic UPDATE, §5.1
  findForTrainer(TrainerScope $scope, ?string $type): array
  findOpenCoachInviteFor(TrainerScope $scope, string $email): ?ShareLink
ShareLinkUsageRepository:
  record(ShareLinkUsage $usage): void
  countForLink(ShareLinkId $id): int
PurchaseApprovalRequestRepository:
  findPendingForParent(UserId $parent, int $page): PaginationInterface
  findForChild(PlayerProfileId $child, int $page): PaginationInterface
  findExpiredPending(\DateTimeImmutable $now, int $limit): array         // job batch
AvailabilitySlotRepository:
  findForPlayer(PlayerProfileId $player): array
  findForCoach(UserId $coach): array
  findOverlapping(AvailabilityOwner $owner, int $day, string $from, string $to): array
  findPlayersAvailableAt(TrainerScope $scope, int $day, string $from, string $to, int $page): PaginationInterface
  isCoachAvailableAt(UserId $coach, int $day, string $from, string $to): bool
CoachAvailabilityOverrideRepository:
  record(CoachAvailabilityOverride $o): void
  findForTrainer(TrainerScope $scope, int $page): PaginationInterface
```

`searchDirectory` is the one intentionally unscoped method in the whole model; it is Super-Admin-only and named so
that its absence of a `TrainerScope` reads as a decision rather than an omission.

---

## 8. Tests Needed

| Kind | Test |
|---|---|
| Constraint | second active association for the same (trainer, player) is rejected; re-adding after `disconnected` succeeds |
| Constraint | second **active** coach assignment rejected; second **pending** allowed |
| Constraint | `player_profile` CHECK rejects a child without a parent, and an adult profile without an account |
| Constraint | `share_link` CHECK rejects a `coach_unique` link with no expiry or `max_uses <> 1` |
| Constraint | `availability_slot` CHECK rejects two owners, zero owners, and `end_time <= start_time` |
| Constraint | `primary_color` CHECK rejects `red`, accepts `#1A2B3C` |
| Concurrency | two parallel `claimUse` calls on a `max_uses = 1` link → exactly one success |
| Concurrency | parent decision vs. expiry job on the same request → one wins, state consistent |
| Concurrency | two parallel invitation acceptances for one coach → one active assignment |
| Query | `findRoster` returns only in-scope players (the E01-T024 matrix) |
| Query | `findPlayersAvailableAt` returns only in-scope players and respects slot boundaries |
| Query | `searchDirectory` sort is stable across pages (no repeats/gaps) on a seeded 10 000-row set, and completes inside NFR-01 |
| Migration | `migrate` up then down on a scratch DB; `schema:validate --skip-sync` clean; M1 backfill maps `roles` → `primary_role` for pre-existing rows |
| Anonymization | after `AnonymizeUser`, no PII remains on `user`/`profile`/`player_profile`/`coach_profile`, while association/approval/audit/usage row counts are unchanged |
| N+1 | roster and directory pages execute a bounded number of queries (assert query count, `dama` bundle already gives per-test isolation) |

---

## 9. Risks and Assumptions

| Item | Note |
|---|---|
| **Timezone** | `trainer_organization.timezone` is an addition, not a spec requirement — §8 never mentions timezones for availability. If the client says "single region", the column stays at `'UTC'` and costs nothing; if not, retrofitting it after availability data exists means interpreting historical rows. |
| `primary_role` denormalization | Two columns hold one fact. Mitigated by a single setter and a CHECK, but a raw SQL `UPDATE` to `roles` alone would desynchronize them. A follow-up option is dropping `roles` entirely and building it in `getRoles()` from `primary_role`, which removes the duplication — recommended once nothing else reads `roles`. |
| Availability overlap in application code | Accepted trade-off (§2.2). If concurrent editing of one owner's slots ever becomes real (e.g. a parent and a co-parent), the answer is `btree_gist` + an EXCLUDE constraint. |
| `subject_ref` un-joinable | Intentional Epic-05/02 seam. Until then nothing verifies that a referenced purchase exists — the approval flow must not be presented as production-complete. |
| Q-01.01 / Q-01.02 open | `skill_level VARCHAR(32)` and derived age are placeholders (A-07). A later enum will need a data migration over existing rows. |
| C-01 (no independent minors) | `player_profile` CHECK encodes it. If 16–18-year-olds later get independent accounts, that CHECK and the `ROLE_CHILD` model both change — a schema change, not a config flag. |
| 10 000-user target unverified | The index plan is designed for it, not measured. E01-T057 must seed and record real numbers; §4's assumptions are falsifiable there. |
| `jsonb` vs `json` | New columns use `jsonb` while the existing tables use `json`. Deliberate divergence, documented here so it is not read as an inconsistency to "fix". |

---

## 10. Next Command Recommendation

1. `/security-voter-designer` — the permission matrix and child deny-list now have concrete tables to authorize against.
2. `/architecture-implementer` — E01-T066 relocation, then the M1 entities and migration.
3. `/api-designer` only if a JSON surface beyond the existing `api_login` is actually wanted; the epic is
   server-rendered, so this may be skippable for Epic-01.
