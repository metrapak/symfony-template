# Authorization Design — Epic-01 Foundation

**Status**: proposed · **Depends on**: `architect-architecture.md` (AD-01, AD-02), `database-designer-schema.md`
**Covers**: role model, the access-decision strategy, 12 voters, `access_control`/firewall configuration, call sites,
collection scoping, and the test matrix. Implements E01-T017 and the authorization half of E01-T037/T061.

Baseline: the application currently has **no voters, no `role_hierarchy`, and an empty `access_control`**
(`config/packages/security.yaml`). Everything here is new, so no existing behaviour constrains the design.

**Non-negotiable rule**: hidden UI is never authorization. Every Twig `{% if is_granted(...) %}` in this epic has a
server-side counterpart on the route that the hidden control would have reached.

---

## 1. Role Model

```yaml
security:
    role_hierarchy:
        ROLE_PLAYER:      [ROLE_TRAINEE]
        ROLE_CHILD:       [ROLE_TRAINEE]
        ROLE_SUPER_ADMIN: [ROLE_ALLOWED_TO_SWITCH]
```

Two deliberate decisions:

**No inheritance between business roles.** `ROLE_SUPER_ADMIN` does **not** extend `ROLE_TRAINER`, and `ROLE_TRAINER`
does not extend `ROLE_COACH`/`ROLE_PLAYER`. Reasons:

- Per AD-02 a Super Admin has **no implicit tenant scope**. If `ROLE_SUPER_ADMIN` inherited `ROLE_TRAINER`, every
  trainer-scoped route would open for an admin with no organization to scope to — either a crash or, worse, an
  unscoped query. Admin reach is expressed by admin-specific attributes (`UserAdminVoter`), not by inheritance.
- Impersonation (US-01.07) is the *sanctioned* way for an admin to see a trainer's view, and it works by swapping
  the token, not by role inheritance. Inheritance would give admins a second, unaudited path to tenant data — which
  is exactly what the impersonation audit trail exists to prevent.

**`ROLE_CHILD` does not extend `ROLE_PLAYER`.** US-01.06 is a deny-list on top of the player surface; if a child
inherited `ROLE_PLAYER`, every player `access_control` rule would admit children and each capability would need an
individual re-deny. Instead both roles share `ROLE_TRAINEE` for the genuinely common surface (own dashboard, own
profile, viewing eligible events, viewing content), and everything a child must not reach is guarded by an attribute
that `ROLE_CHILD` never satisfies.

**This amends** `architect-architecture.md` §6 and backlog task **E01-T002**, which listed "`role_hierarchy`" without
qualification: the hierarchy exists, but only for `ROLE_TRAINEE` and `ROLE_ALLOWED_TO_SWITCH`.

---

## 2. Access-Decision Strategy — the critical configuration

```yaml
security:
    access_decision_manager:
        strategy: unanimous
        allow_if_all_abstain: false
```

Symfony's default strategy is `affirmative`: **one** granting voter wins, even if another voter denied. Under that
default, a "child capability" voter that denies is silently overridden by any other voter that grants the same
attribute — the deny-list from US-01.06 would be decorative. `unanimous` is therefore a correctness requirement of
this design, not a preference.

Consequences that every voter in this epic must respect:

1. **`supports()` must be narrow.** Under `unanimous`, a voter that returns `ACCESS_DENIED` for a subject it does not
   understand breaks unrelated features. Voters support exactly one attribute family and one subject type, and
   abstain otherwise.
2. **Abstain ≠ deny.** A voter abstains when the decision is not its business; it denies only when it owns the rule
   and the rule fails.
3. `allow_if_all_abstain: false` means an attribute nobody claims is denied. That is the safe default and it makes a
   typo'd attribute fail closed rather than open.
4. `RoleVoter` and `AuthenticatedVoter` abstain on non-role attributes, so plain `ROLE_*` checks keep working.

This is added to backlog task **E01-T017** as a required configuration change, with a functional test that proves a
deny beats a grant.

---

## 3. Attribute Naming

Attributes are `public const` strings on their voter, prefixed with the subject family so two voters can never
collide:

```php
final class PlayerProfileVoter extends Voter
{
    public const VIEW                 = 'PLAYER_PROFILE_VIEW';
    public const EDIT                 = 'PLAYER_PROFILE_EDIT';
    public const SET_AVAILABILITY     = 'PLAYER_PROFILE_SET_AVAILABILITY';
    public const MANAGE_ASSOCIATIONS  = 'PLAYER_PROFILE_MANAGE_ASSOCIATIONS';
    public const MANAGE_TOKEN_SETTING = 'PLAYER_PROFILE_MANAGE_TOKEN_SETTING';
}
```

Class-level capabilities (no subject yet — "may this user create *a* ShareLink at all") use a `*_CREATE` attribute
with a `null` subject and are decided from role + `TenantContext`. Item-level capabilities always carry the loaded
subject.

---

## 4. Voter Catalogue

Legend: **A** = anonymous, **SA** = Super Admin, **T** = Trainer, **C** = Coach, **P** = Player/Parent,
**Ch** = Child. "scope" means the subject must belong to the caller's `TrainerScope` (AD-02).

### 4.1 `TrainerOrganizationVoter` — subject `TrainerOrganization`
| Attribute | Granted to | Rule |
|---|---|---|
| `TRAINER_ORG_VIEW` | T (owner), C (active assignment in that org), P/Ch (active association) | membership, not role |
| `TRAINER_ORG_MANAGE` | T (owner only) | `org.ownerUserId === user.id` |
| `TRAINER_ORG_MANAGE_BRANDING` | T (owner only) | US-01.14 |

Super Admin is **not** granted here — admin org edits go through `UserAdminVoter::EDIT_ANY` on the owner account, so
every admin write to tenant data has one audited entry point.

### 4.2 `PlayerProfileVoter` — subject `PlayerProfile`
| Attribute | Granted to | Rule |
|---|---|---|
| `PLAYER_PROFILE_VIEW` | owner account; parent of a child profile; T with an active association in scope; C in the same org | four distinct membership rules, evaluated in that order |
| `PLAYER_PROFILE_EDIT` | owner account; parent of the child | trainers do **not** edit player profiles in Epic-01 (gap 9 — the trainer-side management surface is unspecified); skill level is the exception and is set through a separate `PLAYER_PROFILE_SET_SKILL` attribute granted to T in scope |
| `PLAYER_PROFILE_SET_AVAILABILITY` | owner account; parent of the child | FR-AVAIL-01 |
| `PLAYER_PROFILE_MANAGE_ASSOCIATIONS` | parent of the child; owner account if adult | **denied for `ROLE_CHILD` even on their own profile** — US-01.06 "cannot change trainer associations" |
| `PLAYER_PROFILE_MANAGE_TOKEN_SETTING` | parent of the child only | FR-APPR-02, per-child setting |

### 4.3 `TrainerPlayerAssociationVoter` — subject `TrainerPlayerAssociation`
`ASSOCIATION_VIEW` (parent/owner/trainer-in-scope), `ASSOCIATION_REMOVE` (parent of the child, or the adult account
owner, or T in scope removing from their own roster). Child: denied.

### 4.4 `CoachAssignmentVoter` — subject `CoachAssignment` or `null`
| Attribute | Subject | Granted to |
|---|---|---|
| `COACH_INVITE` | null | T with a scope |
| `COACH_ASSIGNMENT_VIEW` | assignment | T in scope, the coach themselves |
| `COACH_ASSIGNMENT_END` | assignment | T in scope |
| `COACH_ASSIGNMENT_ACCEPT` | assignment | the invited coach only, and only while `status = 'pending'` |

State matters: `ACCEPT` on an already-active or ended assignment is denied, so a replayed acceptance link cannot
reactivate an ended relationship.

### 4.5 `ShareLinkVoter` — subject `ShareLink` or `null`
| Attribute | Granted to | Rule |
|---|---|---|
| `SHARE_LINK_CREATE` | T with a scope | class-level |
| `SHARE_LINK_VIEW` / `SHARE_LINK_DEACTIVATE` | T in scope (owner org) | item-level |
| `SHARE_LINK_USE` | **A** (anonymous), P, T, C — **denied for Ch** | FR-LINK-06: the child block is authorization, not a domain rule, so it lives here |

Two rules worth stating explicitly:

- **Validity is not authorization.** `is_active`, `expires_at` and `max_uses` are domain state checked by
  `ShareLinkRepository::claimUse()` (schema §5.1). The voter answers "may *this caller* use a link at all", which is
  what the child block needs. Mixing the two would put a concurrency-sensitive counter inside a voter.
- **`coach_unique` links additionally require an email match**: an authenticated caller may use one only if their
  email equals `target_email`. Without this, a forwarded coach invitation would let any logged-in player be attached
  as a coach — the epic never says so, but a single-use link that anyone can redeem is an authorization hole.

### 4.6 `PurchaseApprovalVoter` — subject `PurchaseApprovalRequest` or `PlayerProfile`
| Attribute | Subject | Granted to |
|---|---|---|
| `PURCHASE_REQUEST` | `PlayerProfile` | the child on their own profile, or the parent |
| `PURCHASE_DECIDE` | request | the parent named on the request, only while `status = 'pending'` |
| `PURCHASE_APPROVAL_VIEW` | request | the parent, and the child the request belongs to |

`PURCHASE_DECIDE` checks `parentUserId`, not "is a parent of the child": the request records who must approve, and
that record is the authority. State check prevents deciding an expired or already-decided request.

### 4.7 `AvailabilityVoter` — subject `AvailabilitySlot` or `null`
`AVAILABILITY_EDIT_OWN` (coach for their own slots; player/parent via `PlayerProfileVoter::SET_AVAILABILITY`),
`AVAILABILITY_VIEW_FOR_TRAINER` (T in scope — FR-AVAIL-03; the *filter* is scoped by query, see §7).

### 4.8 `CoachConflictOverrideVoter` — subject `CoachAssignment`
`COACH_CONFLICT_OVERRIDE` — T in scope only, and a non-empty reason is a validation rule, not an authorization one.
Coaches may never override their own conflict (US-01.10 gives them "accept or request change", not override).

### 4.9 `ProfileVoter` — subject `Profile` / `CoachProfile`
`PROFILE_VIEW_OWN`, `PROFILE_EDIT_OWN` (the account owner), `COACH_PROFILE_VIEW_PUBLIC` (anyone **if**
`is_public = true`; otherwise only the owner and T in scope). Read-only fields (FR-PROF-02) are enforced by keeping
them out of the form, not by a voter — a voter cannot see which fields a request tried to change.

### 4.10 `UserAdminVoter` — subject `User` or `null`
| Attribute | Subject | Granted to | Extra rule |
|---|---|---|---|
| `USER_DIRECTORY_VIEW` | null | SA | — |
| `USER_EDIT_ANY` | user | SA | — |
| `USER_DEACTIVATE` / `USER_REACTIVATE` | user | SA | denied when the subject **is** the caller (an admin must not lock themselves out) and when `status = 'deleted'` |
| `USER_ANONYMIZE` | user | SA | denied for self; denied when already `deleted` (US-01.13 irreversibility) |
| `USER_CREATE_TRAINER` | null | SA | US-01.01 — only Super Admin creates trainers |

### 4.11 `ImpersonationVoter` — subject `User`
`USER_IMPERSONATE` granted only when **all** hold (US-01.07):
1. caller has `ROLE_SUPER_ADMIN` (and therefore `ROLE_ALLOWED_TO_SWITCH`);
2. caller is not already impersonating (`SwitchUserToken` → deny; no chained impersonation);
3. `subject.primaryRole !== 'ROLE_SUPER_ADMIN'` — the explicit spec prohibition;
4. `subject.id !== caller.id`;
5. `subject.status === 'active'` — impersonating a deactivated account would contradict "cannot log in".

Denial for rule 3 returns **403 with an explicit message** rather than 404: the admin already sees the account in
their own directory, so hiding existence would be theatre and would make the audit trail confusing.

### 4.12 `ChildCapabilityVoter` — subject `null`, attribute family `CHILD_CAPABILITY_*`
The US-01.06 deny-list as one cohesive voter. It **denies** for `ROLE_CHILD` and **abstains** for everyone else, so
under `unanimous` a child is stopped even when a feature voter would have granted:

| Attribute | Guards |
|---|---|
| `CAPABILITY_ADD_TRAINER` | ShareLink registration, "add trainer" flows |
| `CAPABILITY_MANAGE_PAYMENT_METHODS` | payment method screens (Epic-05 seam) |
| `CAPABILITY_PURCHASE_TOKENS` | token purchase |
| `CAPABILITY_COMPLETE_PURCHASE` | checkout without approval |
| `CAPABILITY_DELETE_OWN_ACCOUNT` | account deletion |
| `CAPABILITY_CHANGE_ASSOCIATIONS` | child↔trainer association changes |
| `CAPABILITY_VIEW_PARENT_DATA` | any read of the parent's own training data |

`CAPABILITY_VIEW_PARENT_DATA` is also enforced structurally: the child's context selector is built from
`findActiveTrainersForPlayer(child)` and never from the parent's associations, so the parent's data has no route to
appear (§7). The voter is the backstop, the scoped query is the mechanism.

---

## 5. Firewall and `access_control`

```yaml
firewalls:
    main:
        lazy: true
        provider: app_user_provider
        user_checker: App\Identity\Infrastructure\Security\UserChecker   # E01-T004
        login_throttling:
            max_attempts: 5              # per username+IP
            interval: '15 minutes'
        form_login:
            login_path: app_login
            check_path: app_login
            enable_csrf: true
        json_login:
            check_path: api_login
        switch_user:
            role: ROLE_ALLOWED_TO_SWITCH
            parameter: _switch_user
        logout:
            path: app_logout
```

```yaml
access_control:
    # public
    - { path: ^/login$,            roles: PUBLIC_ACCESS }
    - { path: ^/register,          roles: PUBLIC_ACCESS }
    - { path: ^/join/,             roles: PUBLIC_ACCESS }   # ShareLinkVoter decides who may *use* it
    - { path: ^/verify-email,      roles: PUBLIC_ACCESS }
    - { path: ^/password-reset,    roles: PUBLIC_ACCESS }
    - { path: ^/coaches/[^/]+/public, roles: PUBLIC_ACCESS }
    # coarse areas
    - { path: ^/admin,   roles: ROLE_SUPER_ADMIN }
    - { path: ^/trainer, roles: ROLE_TRAINER }
    - { path: ^/coach,   roles: ROLE_COACH }
    - { path: ^/family,  roles: ROLE_PLAYER }               # parent-only area; ROLE_CHILD excluded by design
    - { path: ^/my,      roles: ROLE_TRAINEE }              # shared player/child surface
    - { path: ^/,        roles: ROLE_USER }
```

`access_control` is a coarse first gate only — it answers "which area", never "which record". `^/family` excluding
`ROLE_CHILD` is the one place where the path rule carries real weight, and it is duplicated by
`ChildCapabilityVoter` on the actions inside, because a future route outside `^/family` must not become a bypass.

`login_throttling` requires `symfony/rate-limiter`, which is **not installed** — backlog task E01-T068.

Also required (E01-T009, session hardening): `cookie_secure: auto`, `cookie_samesite: lax`,
`cookie_httponly: true`, `gc_maxlifetime` per the resolved Q-01.07, and `session_fixation_strategy: migrate`.

---

## 6. Call Sites

Controller, one line, before anything else runs:

```php
#[Route('/family/children/{id}/trainers', name: 'family_child_trainers', methods: ['GET', 'POST'])]
public function manageTrainers(
    PlayerProfile $child,                                  // loaded by a scoped resolver, see §7
    Request $request,
    SetChildTrainerAssociations $setAssociations,
): Response {
    $this->denyAccessUnlessGranted(ChildCapabilityVoter::CAPABILITY_CHANGE_ASSOCIATIONS);
    $this->denyAccessUnlessGranted(PlayerProfileVoter::MANAGE_ASSOCIATIONS, $child);
    // form → command DTO → use case
}
```

Conventions:

- `#[IsGranted(attribute: PlayerProfileVoter::EDIT, subject: 'child')]` is preferred where a single attribute
  suffices; explicit `denyAccessUnlessGranted()` is used when two attributes must both hold (a capability check plus
  an ownership check), because that reads more honestly than two stacked attributes.
- **Voters never load data.** No `EntityManagerInterface`, no repository that issues a query per vote, no `Request`.
  Membership facts a voter needs (`is this player associated with this org`) are answered by a small, injected
  read-model service with request-scoped memoization, or — preferred — are already on the loaded subject because the
  controller fetched it through a scoped query.
- Services may re-assert authorization for defence in depth, but the **decision point is the controller**; a service
  called from a console command has no token and must not depend on one.

---

## 7. Collection Scoping — the half a voter cannot do

A voter protects `/{id}`; it does nothing for a list. Every collection in this epic is scoped in the query:

| Collection | Scoping mechanism |
|---|---|
| Trainer roster | `findRoster(TrainerScope, …)` — scope from `TenantContext`, never from a request parameter |
| Trainer's coaches / ShareLinks / overrides | same `TrainerScope` parameter |
| "My trainers" / context switcher | `findActiveTrainersForPlayer(playerProfileId)` for the caller's own profile; for a child, the child's profile — never the parent's |
| Parent's family view | `findChildrenOfParent(callerId)` |
| Parent's pending approvals | `findPendingForParent(callerId)` |
| Availability filter | `findPlayersAvailableAt(TrainerScope, …)` |
| Users tool | `searchDirectory()` — deliberately unscoped, reachable only behind `USER_DIRECTORY_VIEW` |

**Route subject loading**: `/{id}` routes must not rely on Doctrine's plain `MapEntity` resolution followed by a
voter for cross-tenant subjects. Where enumeration matters (player profiles, associations, ShareLinks, approvals) the
controller loads through the scoped repository method and returns **404** when the scoped query finds nothing — an
out-of-tenant id is indistinguishable from a non-existent one, which is the point.

**403 vs 404 policy**
- Out-of-tenant or non-member subject → **404** (no existence disclosure, no id enumeration).
- In-scope subject, insufficient capability (e.g. a child hitting an association change) → **403**.
- Not authenticated → redirect to login (`form_login`) / **401** on the JSON firewall path.
- Non-public coach profile → **404** for everyone except the owner and trainers in scope.
- Impersonating a Super Admin → **403** with the spec's validation message (see §4.11).

---

## 8. Test Matrix

Per voter, the seven required cases plus the state cases:

| Case | Expectation |
|---|---|
| anonymous | denied (or granted only for `SHARE_LINK_USE` and public profiles) |
| authenticated, wrong role | denied |
| authenticated, right role, **wrong tenant** | denied — and the controller returns 404, asserted separately |
| authenticated, right role, right tenant | granted |
| owner vs. non-owner (same role) | granted / denied |
| parent vs. other parent's child | granted / denied |
| child on own profile | granted for view/edit, **denied** for associations, trainers, purchases, parent data |
| privileged role (SA) on tenant attributes | **denied** (§1 — no inheritance), granted only on `USER_*` attributes |
| unsupported attribute | voter **abstains** (assert `ACCESS_ABSTAIN`, not denied) |
| unsupported subject type | voter **abstains** |
| object state | `COACH_ASSIGNMENT_ACCEPT` on active/ended → denied; `PURCHASE_DECIDE` on decided/expired → denied; `USER_ANONYMIZE` on already-deleted → denied; impersonating an inactive user → denied |

Additional suites:

1. **Strategy test** — one attribute where a feature voter grants and `ChildCapabilityVoter` denies must resolve to
   *denied*. This is the regression test for §2; if someone reverts the strategy to `affirmative`, this test fails
   rather than the child restrictions silently disappearing.
2. **Collection scoping suite** (E01-T024) — for every collection in §7, trainer A must not see trainer B's rows,
   and a child must not see the parent's. Tested at the HTTP level, because that is where the scope is resolved.
3. **`access_control` coverage test** — a parameterized functional test walks every route in `debug:router` and
   asserts that an anonymous request is either redirected or explicitly public. New unprotected routes then fail CI
   instead of shipping.
4. **Impersonation suite** — allowed target, Super-Admin target (403), self (403), inactive target (403), non-admin
   attempting `_switch_user` (403), chained impersonation (403), 1 h expiry, exit restores the admin token.
5. **CSRF suite** — every state-changing route rejects a missing/incorrect token.

---

## 9. Amendments to Earlier Artifacts

| Artifact | Amendment |
|---|---|
| `architect-architecture.md` §6 | `role_hierarchy` is limited to `ROLE_TRAINEE` and `ROLE_ALLOWED_TO_SWITCH`; there is **no** inheritance between business roles, and Super Admin gains no tenant access by role |
| Backlog **E01-T002** | the hierarchy is the two lines in §1; `access_control` is the block in §5 |
| Backlog **E01-T017** | now includes `access_decision_manager.strategy: unanimous`, `allow_if_all_abstain: false`, the 12 voters in §4, and the strategy regression test |
| Backlog **E01-T037** | child restrictions are `ChildCapabilityVoter` (§4.12) plus the structural scoping in §7 — not per-controller `if` statements |
| Backlog **E01-T061** | the five-condition `ImpersonationVoter` in §4.11, including no-chaining and inactive-target denial, which the epic does not mention |
| Backlog **E01-T028/T031** | `SHARE_LINK_USE` gains the `coach_unique` email match (§4.5) |
| Backlog **E01-T053** | trainers do not edit player profiles; skill level moves to its own `PLAYER_PROFILE_SET_SKILL` attribute (gap 9 remains open) |

## 10. Next Command Recommendation

1. `/dependency-manager` — `symfony/rate-limiter` for `login_throttling` (E01-T068) is now on the critical path for
   this configuration to be deployable as written.
2. `/architecture-implementer` — E01-T066 relocation, then the security configuration in §1/§2/§5 as one commit with
   the strategy regression test.
3. `/frontend-design` when the UI work starts: every `is_granted()` in Twig must name an attribute from §4, so the
   template layer cannot invent its own permission vocabulary.
