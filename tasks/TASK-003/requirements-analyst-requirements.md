# TASK-003: ShareLink Invitations & Organization Membership Requirements

## Overview

The invitation system that populates every trainer organization: static ShareLinks for mass player
invitation, unique single-use links for coach recruitment, the redemption flows for new and existing
accounts, and the association records that multi-tenancy depends on.

## Source

- `specs/Epic-01_User_Management_Authentication_SPEC.md` — US-01.02, US-01.08; §8 (Trainer-Player Associations, ShareLinks), §9 (Player Registration, Coach Invitation, ShareLink Tracking, Multi-Tenancy), §10 Flows 1 and 5
- Depends on **TASK-001** (auth, roles, Organization, tenant context)
- Partially depended on by **TASK-004** (family-member selection, child link blocking)

## Functional Requirements

### FR-040: Generate Static Player ShareLink
- **Acceptance**: Trainer generates a link of the form `/join/{code}`. Unlimited uses, no expiry. Code is URL-safe and unique. Trainer can view, copy, and deactivate the link.
- **Priority**: High

### FR-041: Generate Unique Coach ShareLink
- **Acceptance**: Trainer enters coach email plus optional name and message → system generates a **single-use** link expiring in **7 days** → invitation email sent with the link and message.
- **Priority**: High

### FR-042: New Player Registration via ShareLink
- **Acceptance**: Anonymous visitor opens a player link → redirected to registration → submits name, email, password, phone, player name/age/gender → account and player profile created → automatically associated with the link's trainer → confirmation email sent → can immediately see that trainer's events and content. Player appears in the trainer's CRM.
- **Priority**: High

### FR-043: Existing Player Redeems a Different Trainer's Link
- **Acceptance**: A logged-in player opening another trainer's link gains a **new association** with no duplicate account, then is redirected to that trainer's events. Re-opening a link for an existing association is idempotent (no duplicate association, no error).
- **Priority**: High

### FR-044: Family-Member Selection on Redemption
- **Acceptance**: When the redeeming account is a parent with children, show "Who will train with {trainer}?" as a checklist of the parent (Me) plus every child. Only the selected family members are associated.
- **Priority**: High
- **Depends on**: TASK-004 (child profiles must exist). Implement the branch here, guarded so it degrades to a direct association when the account has no children.

### FR-045: Coach Registration and Single-Trainer Enforcement
- **Acceptance**: Coach opens their unique link → registers or logs in → is associated with the inviting trainer → appears in the trainer's Coaches list. A coach who is already **active** under another trainer is refused with a clear error. A coach can never be active under two trainers simultaneously; enforcement is a database constraint plus a service check, not UI-only.
- **Priority**: High

### FR-046: Invitation Status and Resend
- **Acceptance**: Trainer sees each coach invitation as `Pending`, `Accepted`, or `Expired`. An expired link shows the visitor a clear message. Trainer can resend, which issues a fresh link and expiry.
- **Priority**: Medium

### FR-047: ShareLink Usage Tracking
- **Acceptance**: Every redemption records which link was used, by whom, and when. Per-link usage count is incremented. Data is queryable for Epic-06 analytics.
- **Priority**: Medium

### FR-048: Child ShareLink Blocking
- **Acceptance**: A logged-in **child** opening any trainer link is **not** associated. They see "Ask your parent to register you with this trainer." An email is sent to the parent — subject "{child} wants to join {trainer}'s program", body containing the link and a "Review Registration" CTA.
- **Priority**: High
- **Depends on**: TASK-004 (child accounts). Source: US-01.06.

### FR-049: Link Integrity and Abuse Resistance
- **Acceptance**: Codes are generated with a cryptographic RNG, are not sequential or guessable, and are looked up in a way that does not leak validity through timing. `/join/{code}` is rate-limited. A deactivated or fully-consumed link behaves identically to an unknown one.
- **Priority**: High
- **Note**: Not in the spec — added because `/join/{code}` is a public, unauthenticated, account-creating endpoint. This is the epic's largest external attack surface.

## Non-Functional Requirements

| ID | Requirement | Metric |
|:---|:------------|:-------|
| NFR-040 | ShareLink registration | < 2 seconds |
| NFR-041 | Concurrent registrations on one link | 100 concurrent, no duplicate accounts or lost associations |
| NFR-042 | Onboarding time | Trainer onboards a player or coach in < 5 minutes end to end |
| NFR-043 | Registration form accessibility | WCAG 2.1 AA; multi-field form with grouped fieldsets and linked error messages |

## Business Rules

- **BR-040** Static player links: unlimited uses, no expiry.
- **BR-041** Unique coach links: one use, 7-day expiry.
- **BR-042** Every ShareLink belongs to exactly one trainer.
- **BR-043** An existing player redeeming a new link gains an association, never a second account.
- **BR-044** A coach may be active under only one trainer at a time.
- **BR-045** Every registration records which ShareLink produced it.
- **BR-046** Child accounts cannot self-associate with a trainer.
- **BR-047** Players may be associated with multiple trainers; each association's data is isolated (TASK-004 enforces the view separation).

## Task Breakdown

### Entities

| Entity | Properties | Relations |
|:-------|:-----------|:----------|
| `ShareLink` | `code` (unique), `type` (player/coach), `organizationId`, `createdByUserId`, `targetEmail` (coach only), `expiresAt`, `maxUses`, `useCount`, `isActive`, `createdAt` | belongs to Organization + creator User |
| `TrainerPlayerAssociation` | `organizationId`, `playerProfileId`, `viaShareLinkId`, `connectedAt`, `status` (active/inactive), `deactivatedAt` | joins Organization ↔ PlayerProfile |
| `CoachAssignment` | `organizationId`, `coachUserId`, `joinedAt`, `status`, `endedAt` | joins Organization ↔ User; **partial unique index on `coach_user_id` where status = active** |
| `ShareLinkRedemption` | `shareLinkId`, `userId`, `redeemedAt`, `outcome` (new_account / association / blocked_child) | belongs to ShareLink + User |

`PlayerProfile` is defined in TASK-004; this task references it. If TASK-003 is implemented first,
create a minimal `PlayerProfile` here and let TASK-004 extend it.

### Services

| Service | Purpose | Methods |
|:--------|:--------|:--------|
| `ShareLinkGenerator` | Create codes and links | `createPlayerLink`, `createCoachLink` |
| `ShareLinkResolver` | Validate and load a code | `resolve` (returns valid / expired / consumed / unknown) |
| `PlayerRegistrationService` | New account + profile + association | `registerViaShareLink` |
| `AssociationService` | Attach an existing player or family to an org | `associate`, `associateFamilyMembers`, `deactivate` |
| `CoachInvitationService` | Invite, accept, enforce single-trainer, resend | `invite`, `accept`, `resend` |
| `RedemptionRecorder` | FR-047 tracking | `record` |

### Controllers

| Controller | Endpoints | Purpose |
|:-----------|:----------|:--------|
| `JoinController` | `GET /join/{code}`, `GET/POST /join/{code}/register`, `POST /join/{code}/associate` | Public redemption flow |
| `Trainer\ShareLinkController` | `GET /trainer/share-links`, `POST /trainer/share-links`, `POST /trainer/share-links/{id}/deactivate` | Player link management |
| `Trainer\CoachInviteController` | `GET /trainer/coaches`, `GET/POST /trainer/coaches/invite`, `POST /trainer/coaches/invite/{id}/resend` | Coach invitations |

### Backend Tasks
- [ ] Migration: CREATE `share_link` (unique index on `code`), `trainer_player_association` (unique on `(organization_id, player_profile_id)`), `coach_assignment` (**partial unique index** on active coach), `share_link_redemption`
- [ ] Entities above; `ShareLinkType` backed enum
- [ ] Value object: `ShareLinkCode` — generation via `random_bytes`, URL-safe encoding, format validation (FR-049)
- [ ] Request DTO + validator: `PlayerRegistrationRequest` (email unique, password strength, age 1–18 for child / adult for self, gender, phone format), `CoachInviteRequest` (email required, valid format), `FamilySelectionRequest` (at least one member selected)
- [ ] Access control: `/join/*` public; `/trainer/*` requires `ROLE_TRAINER` **and** ownership of the target link (voter, not just role)
- [ ] Rate limiter on `/join/{code}` and on registration submission (FR-049)
- [ ] Services above + DI wiring
- [ ] Repository: `ShareLinkRepository::findActiveByCode`; `CoachAssignmentRepository::findActiveForCoach`; `TrainerPlayerAssociationRepository::existsFor`
- [ ] Idempotency: redeeming an already-associated link is a no-op success (FR-043)
- [ ] Transaction boundary: account + profile + association + redemption record commit together or not at all (NFR-041)
- [ ] Mailer: coach invitation, player registration confirmation, parent notification for blocked child link
- [ ] Fixtures: a trainer with a static link, a pending coach invite, an expired coach invite

### Frontend Tasks (server-rendered)
- [ ] Templates: join landing (valid / expired / unknown states), registration form with parent/child branch, family-member selection checklist, trainer ShareLink management page with copy-to-clipboard, coach invite form, coach list with invitation status badges, blocked-child message page
- [ ] Progressive enhancement: copy-link button (Stimulus), conditional reveal of child fields when "registering a child" is selected — server validation must still cover the hidden case
- [ ] Accessibility: checklist is a real `fieldset`/`legend` with checkboxes; status badges convey state in text, not colour alone; copy button announces success via `aria-live`

### Testing Tasks
- [ ] Integration: new player registers via static link → account, profile, association, redemption record all created
- [ ] Integration: existing logged-in player redeems a second trainer's link → one account, two associations
- [ ] Integration: redeeming the same link twice → idempotent, no duplicate association
- [ ] Integration: parent with children sees the selection checklist; only checked members are associated
- [ ] Integration: coach accepts a unique link → assignment created, link consumed
- [ ] Integration: consumed coach link rejected; expired coach link rejected with resend offered
- [ ] Integration: coach already active elsewhere is refused (FR-045); assert the DB constraint also rejects a direct double-insert
- [ ] Integration: child redeeming a link is blocked, no association created, parent email dispatched (FR-048)
- [ ] Integration: trainer cannot manage another trainer's ShareLinks (403)
- [ ] Integration: unknown, deactivated, and consumed codes are indistinguishable in response (FR-049)
- [ ] Integration: rate limiting engages on repeated `/join` hits
- [ ] Unit: `ShareLinkCode` generation and validation; `ShareLinkResolver` state matrix; expiry boundary at exactly 7 days
- [ ] Concurrency: 100 simultaneous redemptions of one static link produce 100 associations and no duplicate accounts (NFR-041)
- [ ] Browser/E2E: trainer generates link → player registers → player sees trainer's events

## Validation Completeness

- [x] All functional requirements mapped to tasks
- [x] Happy path covered
- [x] Error cases identified (expired, consumed, unknown, coach conflict, duplicate email)
- [x] Edge cases considered (double redemption, concurrent redemption, child redemption, self-invitation, trainer redeeming own link)
- [x] Security requirements addressed (unguessable codes, rate limiting, ownership voter, uniform failure responses)
- [x] Performance requirements noted (NFR-040, NFR-041)
- [x] Testing strategy defined

## Gap Analysis

- [ ] **G-08** — "Camp-to-User Conversion (Epic-08)" is in §3 MVP scope with five behaviours (post-submission prompt, pre-filled registration, auto-assign trainer, email-a-ShareLink alternative) but has **no user story or acceptance criteria**. It is an alternate entry point into this task's registration flow. Either write the story or move it out of MVP — it cannot be estimated as written.
- [ ] **G-19 (new)** — What happens when a trainer deactivates a static ShareLink that players already used? Do existing associations survive? Assuming yes (link deactivation only blocks future redemptions).
- [ ] **G-20 (new)** — Coach single-trainer rule says "active under one trainer at a time", implying a coach can *move* between trainers. The transition is unspecified: who ends the old assignment, and does the coach keep history with the previous trainer?
- [ ] **G-21 (new)** — Registration form collects "player name/age/gender" but does not state whether the registrant is registering themselves or a child at that moment. US-01.03 treats the parent as a player too. The form needs an explicit self-vs-child choice; the spec never defines it.
- [ ] **Q-01.02 (P2)** — Age group definitions affect the age field's validation and storage (birth date vs age integer). Storing an integer age silently rots; recommend birth date regardless.
- [ ] **Q-01.04 (P1)** — Confirmation and invitation email content.
- [ ] Spec says static links have "no expiry" and "unlimited uses" but `ShareLink` still carries `expiresAt` and `maxUses` (§8). Modelled as nullable to support both types.
- [ ] Whether a trainer may have multiple simultaneous static player links (e.g. one per campaign) is unspecified. Assuming one active link per organization; confirm, since Epic-06 analytics may want several.

## Next Steps (Suggested)

Do not auto-execute — see the epic index for the full ordering.
