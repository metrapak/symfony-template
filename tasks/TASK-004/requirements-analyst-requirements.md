# TASK-004: Profiles, Family, Context Switching & Portal Branding Requirements

## Overview

Self-service identity for every role: profile editing with role-specific fields, the parent/child
family model, constrained child logins, the per-(player, trainer) context switcher that enforces
separated views, and trainer portal branding.

This is the largest and most interconnected task in the epic. The context-switching requirement
(FR-067, FR-068) is a **security boundary**, not a UI convenience.

## Source

- `specs/Epic-01_User_Management_Authentication_SPEC.md` — US-01.03, US-01.04, US-01.06, US-01.11, US-01.14; §8 (Profiles, Trainer/Coach/Player Profiles), §9 (Parent/Child Relationships, Multi-Tenancy), §10 Flow 2
- Depends on **TASK-001** (auth, roles, Organization), **TASK-003** (associations, ShareLinks)
- Scoping decision **D-03**: child logins ship in MVP, parent-owned, constrained

## Functional Requirements

### FR-060: Edit Own Profile (All Roles)
- **Acceptance**: Any authenticated user can edit first name, last name, phone, and profile photo. Email, role, skill level, and account creation date are **read-only**. Save persists and shows a confirmation. Phone format validated; required fields enforced.
- **Priority**: High

### FR-061: Role-Specific Profile Fields
- **Acceptance**: Player — school, jersey number, photo. Parent — emergency contact information when they have children. Coach — bio, credentials, certifications, public-profile visibility checkbox. Trainer — business name, organization details. Super Admin — admin notification settings. Each role sees only its own fields.
- **Priority**: High

### FR-062: Profile Photo Upload
- **Acceptance**: Image uploaded to file storage, thumbnail generated, photo URL updated. Type and size validated. Upload replaces the previous photo.
- **Priority**: Medium
- **Note**: Spec states no type or size limit for profile photos (only for branding logos). Applying the branding limits (PNG/JPG/SVG, 2MB) by default — see G-24 on SVG.

### FR-063: Parent Creates Child Profile
- **Acceptance**: From Player Profiles, "+ Add Child" → name, age, gender, optional school and photo → marked "Child" rather than "Self" → profile linked to the parent account. Required: name, age, gender. Age must be 1–18. A similar existing name/age triggers a **warning**, not a rejection.
- **Priority**: High

### FR-064: Trainer Selection on Child Creation
- **Acceptance**: If the parent has exactly one trainer, prompt "Will {child} also train with {trainer}?" (Yes/No). If the parent has several, show a trainer checklist. Selected trainers are associated. If none selected, the child profile exists with no association.
- **Priority**: High

### FR-065: Parent as Player
- **Acceptance**: A parent account owns a "Self" player profile and can train alongside their children. The context switcher shows their own contexts under a distinct heading from their children's.
- **Priority**: High

### FR-066: Parent Manages Child-Trainer Associations
- **Acceptance**: A Family / Player Profiles page lists every child with their associated trainers and association dates. **Add**: enter a ShareLink manually, or pick from trainers the parent is already associated with, then confirm. **Remove**: confirmation reads "Remove {child} from {trainer}? This will cancel all upcoming RSVPs." On confirm the association is deactivated, the child's data with that trainer is **soft-deleted with history preserved**, and the trainer no longer sees the child on their roster.
- **Priority**: High

### FR-067: Child Login with Constrained Permissions
- **Acceptance**: A child account may browse eligible events (view-only), RSVP and cancel RSVP (subject to parent approval), view purchased content, view their own progress, submit feedback requests, update basic profile info (photo, preferences), view tokens read-only, and switch between their own trainer contexts.
- **Priority**: High

### FR-068: Child Prohibitions (Server-Enforced)
- **Acceptance**: A child account **cannot** add trainers (ShareLink registration blocked — TASK-003 FR-048), add or remove payment methods, purchase tokens, complete purchases without approval, delete their account, change trainer associations, or view the parent's training data. Each prohibition returns 403 when attempted directly, not merely a hidden link.
- **Priority**: High

### FR-069: Context Switcher
- **Acceptance**: A navigation control lists every available (player, trainer) context. A parent who trains sees "Your Training" (their own contexts) and "Your Children's Training" (each child × trainer) as separate groups. A parent who does not train sees only the children group. A child sees a flat list of their own trainers with no "Me" section. The selected context persists across the session.
- **Priority**: High

### FR-070: Separated Views — Data Isolation per Context
- **Acceptance**: Each context shows completely isolated data: calendar, tokens, content, reservations. There is **no combined or unified view** anywhere in the platform. Switching context replaces the dataset entirely.
- **Priority**: High
- **Security**: Isolation must be enforced in the query layer. A request that manipulates the context identifier to reference a profile or organization the user has no association with must return 403 — never data.

### FR-071: Trainer Portal Branding — Logo
- **Acceptance**: Trainer uploads a logo (PNG, JPG, SVG; max 2MB; recommended 200×200, auto-resized if larger) with a preview before saving. The logo appears in the portal header for the trainer's players, coaches, and parents.
- **Priority**: Medium

### FR-072: Trainer Portal Branding — Colour
- **Acceptance**: A colour picker sets the primary brand colour in hex, used for UI gradient and accents, with real-time preview and a reset-to-default option. Changes are visible immediately to everyone in the trainer's organization.
- **Priority**: Medium
- **Excluded from MVP**: light/dark logo variants, font customization, full layout customization.

## Non-Functional Requirements

| ID | Requirement | Metric |
|:---|:------------|:-------|
| NFR-060 | Profile save | < 1 second |
| NFR-061 | Dashboard load in any context | < 2 seconds |
| NFR-062 | Self-service child profile creation success rate | ≥ 95% |
| NFR-063 | Data isolation between contexts | 0 cross-context leaks; verified by an explicit isolation test suite |
| NFR-064 | Accessibility | WCAG 2.1 AA — context switcher operable by keyboard and announced on change; colour picker has a text hex input |
| NFR-065 | Branding contrast | A trainer-chosen colour must not break text contrast requirements — validate or constrain the palette |
| NFR-066 | Uploads | Max 2MB, type-validated by content rather than extension, stored outside the web root or served through a controller |

## Business Rules

- **BR-060** A parent account is itself a player account.
- **BR-061** Child-trainer associations are explicit, except the single-trainer confirmation prompt.
- **BR-062** A parent can modify child-trainer associations at any time.
- **BR-063** Each child has separate calendar, RSVP status, attendance, and availability **per trainer**.
- **BR-064** The parent owns all contact information for the family.
- **BR-065** Children cannot self-associate with trainers.
- **BR-066** Removing a child from a trainer cancels upcoming RSVPs and soft-deletes that relationship's data.
- **BR-067** Email, role, and skill level are never self-editable (skill level is trainer-set).
- **BR-068** Age for a child profile must be 1–18.
- **BR-069** Branding is scoped to one organization and visible to all of its members.

## Task Breakdown

### Entities

| Entity | Properties | Relations |
|:-------|:-----------|:----------|
| `PlayerProfile` | `ownerUserId`, `kind` (self/child), `firstName`, `lastName`, `birthDate`, `gender`, `skillLevel`, `school`, `jerseyNumber`, `photoPath`, `loginEnabled`, `createdAt` | belongs to owning User; optional own login User; has many TrainerPlayerAssociation |
| `ParentChildLink` | `parentUserId`, `childProfileId`, `childUserId` (nullable) | joins parent User ↔ PlayerProfile |
| `EmergencyContact` | `parentUserId`, `name`, `relationship`, `phone` | belongs to parent User |
| `CoachProfile` | `userId`, `organizationId`, `bio`, `credentials`, `certifications`, `isPublic`, `joinedAt` | belongs to User + Organization |
| `TrainerProfile` | `userId`, `organizationId`, `businessName`, `address`, `website`, `description` | belongs to User + Organization |
| `OrganizationBranding` | `organizationId`, `logoPath`, `primaryColorHex`, `updatedAt` | belongs to Organization |
| `TrainingContext` (not persisted) | `playerProfileId`, `organizationId` | session-held selection |

Whether `PlayerProfile` uses an integer age or a birth date is a schema decision with a clear answer:
**store birth date**. An integer age is wrong within a year of being written. See Q-01.02.

### Services

| Service | Purpose | Methods |
|:--------|:--------|:--------|
| `ProfileUpdater` | Role-aware profile persistence | `updateFor` |
| `ChildProfileCreator` | Create child + optional associations | `create` |
| `FamilyAssociationManager` | Add/remove child-trainer links | `addTrainer`, `removeTrainer` |
| `TrainingContextResolver` | Available contexts, current selection, authorization | `availableFor`, `current`, `switchTo`, `assertAccess` |
| `ChildPermissionVoter` | Enforce FR-068 prohibitions | `vote` |
| `ImageUploader` | Validate, store, resize, thumbnail | `store`, `resize` |
| `BrandingService` | Read/write branding, resolve for a viewer | `update`, `resolveForOrganization` |

### Controllers

| Controller | Endpoints | Purpose |
|:-----------|:----------|:--------|
| `ProfileController` | `GET/POST /account/profile` | Self-service profile edit (all roles) |
| `Family\PlayerProfileController` | `GET /family`, `GET/POST /family/children/new`, `GET/POST /family/children/{id}/edit` | Child profile management |
| `Family\AssociationController` | `POST /family/children/{id}/trainers`, `DELETE /family/children/{id}/trainers/{orgId}` | Add/remove trainer associations |
| `ContextController` | `POST /context/switch` | Context selection |
| `Trainer\BrandingController` | `GET/POST /trainer/branding`, `POST /trainer/branding/reset` | Logo and colour |

### Backend Tasks
- [ ] Migration: CREATE `player_profile`, `parent_child_link`, `emergency_contact`, `coach_profile`, `trainer_profile`, `organization_branding`; index `player_profile(owner_user_id, kind)`
- [ ] Migration: extend `trainer_player_association` from TASK-003 if a minimal `PlayerProfile` was created there
- [ ] Entities above; `ProfileKind` and `Gender` backed enums
- [ ] Value objects: `PhoneNumber` (validated, normalized), `HexColor` (format + contrast check), `BirthDate` (with an `ageOn()` accessor)
- [ ] Request DTO + validator: `UpdateProfileRequest` (per-role variants or validation groups), `CreateChildRequest` (name/age/gender required, age 1–18, duplicate-name warning path), `AddTrainerRequest`, `RemoveTrainerRequest`, `BrandingRequest` (file type/size, hex format)
- [ ] Voter: `ProfileVoter` (own profile or own child only), `ChildActionVoter` (FR-068), `ContextVoter` (FR-070)
- [ ] **Doctrine filter or mandatory repository parameter** enforcing the active context on every context-scoped query (FR-070). Decide the mechanism before the first such query is written
- [ ] Services above + DI wiring
- [ ] Repository: `PlayerProfileRepository::findFamilyOf`, `findContextsFor`; `TrainerPlayerAssociationRepository::deactivate`
- [ ] Soft-delete strategy for child-trainer data on removal (FR-066), preserving history
- [ ] Upload handling: content-type sniffing, size limit, image resize, thumbnail, storage path outside web root
- [ ] SVG handling decision — see G-24
- [ ] Branding injection: Twig global or layout-level service call resolving the viewer's organization branding
- [ ] RSVP cancellation on association removal — **integration point with Epic-02**; expose as an interface with a no-op default until events exist
- [ ] Fixtures: parent with three children across two trainers, a child with login enabled, a coach with a public profile, a branded organization

### Frontend Tasks (server-rendered)
- [ ] Templates: profile edit (role-specific field partials), family list with per-child trainer table, add-child form with the single/multi trainer branch, add-trainer modal, remove-trainer confirmation, context switcher partial in the base layout, branding settings page with preview
- [ ] Progressive enhancement: context switcher as a Stimulus dropdown posting to `/context/switch`; colour picker with live preview; logo preview before upload; duplicate-name warning shown inline
- [ ] Branding applied via CSS custom properties set from `primaryColorHex` — never by injecting raw user input into a `<style>` block without escaping
- [ ] Accessibility: switcher is a proper listbox or `<select>` with grouped `<optgroup>` matching the spec's "Your Training" / "Your Children's Training" grouping; colour picker paired with a text hex field; confirmation dialogs focus-trapped; the remove-trainer warning is read before the confirm button
- [ ] Responsive: family table reflows on narrow screens; switcher usable at 320px

### Testing Tasks
- [ ] Integration: each role edits its own fields; read-only fields reject modification even when POSTed directly (FR-060, BR-067)
- [ ] Integration: child creation validation — missing fields, age 0, age 19, duplicate-name warning is non-blocking
- [ ] Integration: single-trainer prompt path and multi-trainer checklist path produce correct associations
- [ ] Integration: add trainer by ShareLink and by picking an existing trainer
- [ ] Integration: remove trainer → association deactivated, history retained, trainer roster no longer includes the child
- [ ] Integration: **context isolation** — a parent with two children across two trainers sees only the selected context's data; a forged context identifier returns 403 (FR-070, NFR-063)
- [ ] Integration: child cannot view parent's data (403)
- [ ] Integration: every child prohibition in FR-068 returns 403 when POSTed directly
- [ ] Integration: parent cannot edit another parent's child (403)
- [ ] Integration: trainer branding visible to that org's members and **not** to another org's members
- [ ] Integration: upload rejects oversize file, wrong type, and a file whose extension disagrees with its content
- [ ] Unit: `PhoneNumber`, `HexColor` contrast validation, `BirthDate::ageOn` across a birthday boundary, `TrainingContextResolver` grouping for all three switcher shapes
- [ ] Browser/E2E: parent creates a child, switches context, sees isolated data; trainer sets branding and a player sees it

## Validation Completeness

- [x] All functional requirements mapped to tasks
- [x] Happy path covered
- [x] Error cases identified (validation failures, forbidden cross-family access, bad uploads)
- [x] Edge cases considered (forged context, birthday boundary, child turning 19, parent with zero children, removing the last trainer, duplicate child names)
- [x] Security requirements addressed (voters, query-layer isolation, upload validation, escaped branding output)
- [x] Performance requirements noted (NFR-060, NFR-061)
- [x] Testing strategy defined

## Gap Analysis

- [ ] **G-01** — Resolved by decision D-03 (child logins ship). The spec's §9 sentence forbidding minor accounts must be corrected, and **COPPA implications for under-13 logins remain unassessed**. This needs legal review before release, independent of the engineering decision.
- [ ] **G-22 (new)** — What happens when a child profile reaches 19? Age validation caps at 18, but the spec never describes ageing out: automatic conversion to an independent account, continued parent management, or a blocked state?
- [ ] **G-23 (new)** — Child login creation is unspecified as a flow. US-01.06 says a child "can optionally have separate login (shares parent's contact info)" but no story covers *how* the parent creates those credentials, what email is used when the child has none, or how the child's first password is set.
- [ ] **G-24 (new)** — Branding allows **SVG** uploads (§US-01.14 validation). SVG is an XSS vector when served inline. Either sanitize server-side, serve with a restrictive `Content-Type` and CSP, or drop SVG. Recommend dropping SVG for MVP; the spec's own "auto-resize if larger" language implies raster anyway.
- [ ] **G-25 (new)** — "Update basic profile info (photo, preferences)" for children (FR-067) does not define which fields a child may change versus which stay parent-only. Name? Birth date? Currently ambiguous and directly security-relevant.
- [ ] **G-26 (new)** — Trainer branding is visible to "trainer's players, coaches, and parents", but a multi-trainer player belongs to several branded organizations. Presumably branding follows the active context — the spec never says so explicitly. Confirm, since it determines whether branding is resolved per-request from context or per-user.
- [ ] **G-07** — Availability is described as per-trainer in US-01.03 and per-profile in US-01.09. Affects the schema created in TASK-005 but the profile relationship originates here.
- [ ] **Q-01.01 (P2)** — Skill level values are undefined. Modelled as a nullable string until specified; an enum is preferable once the values are known.
- [ ] **Q-01.02 (P2)** — Age group definitions. Recommendation stands: store birth date regardless of how groups are later expressed.
- [ ] Spec does not state whether a parent may delete a child profile (as opposed to removing a trainer association). Assuming **not** in MVP.
- [ ] "Emergency contact info" is listed for parents but has no fields, cardinality, or requiredness defined.

## Next Steps (Suggested)

Do not auto-execute — see the epic index for the full ordering.
