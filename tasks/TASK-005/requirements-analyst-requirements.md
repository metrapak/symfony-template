# TASK-005: Availability (Best Times / My Times) & Conflict Override Requirements

## Overview

Weekly availability capture for players and coaches, the trainer-facing views that turn it into
scheduling decisions, and the coach assignment conflict warning with a logged override.

Availability is **advisory throughout** — it informs scheduling and never blocks it.

## Source

- `specs/Epic-01_User_Management_Authentication_SPEC.md` — US-01.09, US-01.10; §8 (Best Times / Availability, Coach Availability Overrides), §9 (Best Times, Coach Availability Conflicts), §10 Flow 6
- Depends on **TASK-004** (player profiles, coach profiles, context switching)
- Integration point with **Epic-02** (event creation and coach assignment)

## Functional Requirements

### FR-080: Player Sets Availability
- **Acceptance**: From "Availability" / "Best Times", the player sees a weekly grid of days and time slots. For each day they either toggle Available / Not Available or select time ranges (e.g. "Monday 17:00–20:00", "Wednesday: Not Available"). Save persists and confirms: "Availability saved. Trainers can see these preferences when planning sessions."
- **Priority**: High

### FR-081: Parent Sets Availability per Child
- **Acceptance**: A parent can set separate availability for each child via the profile switcher. Each child's availability is independent of the parent's and of their siblings'.
- **Priority**: High
- **Blocked by**: **G-07** — whether availability is per profile or per (profile, trainer) is contradictory in the spec. This changes the primary key.

### FR-082: Coach Sets My Times
- **Acceptance**: From the coach dashboard, a recurring weekly schedule of weekdays and time ranges. Multiple non-contiguous slots per day are supported (e.g. "Monday 16:00–18:00 and 19:00–21:00"). Save persists.
- **Priority**: High

### FR-083: Trainer Views Player Availability
- **Acceptance**: In event creation and in the CRM, the trainer sees player availability. A player card shows a readable summary ("Best Times: Mon 5–8pm, Wed 6–9pm"). During event creation the trainer sees a count such as "Players available at this time: 15 of 20".
- **Priority**: Medium
- **Scoped**: The trainer sees availability only for players associated with their own organization.

### FR-084: Trainer Filters by Availability
- **Acceptance**: The trainer can filter to "players available on {day} at {time}". Results respect organization scoping.
- **Priority**: Medium

### FR-085: Coach Assignment Conflict Warning
- **Acceptance**: Assigning a coach to an event outside their stated availability shows "Coach {name} is not available at this time per their schedule. Continue anyway?" The assignment is **not blocked**.
- **Priority**: High

### FR-086: Override with Required Reason
- **Acceptance**: To proceed past the warning the trainer must enter a reason in a required text field. The override records event ID, coach ID, reason, and the overriding trainer ID with a timestamp. An empty reason blocks the override.
- **Priority**: High

### FR-087: Coach Sees Overridden Assignment
- **Acceptance**: The coach sees the assignment (they are never blocked from it) and can accept it or request a change.
- **Priority**: Medium
- **Blocked by**: **Q-01.06** — whether the coach is actively notified of an override is an open question. "Request a change" also has no defined workflow, recipient, or state.

### FR-088: Availability Is Never a Constraint
- **Acceptance**: No scheduling path is prevented by availability data. Availability produces suggestions, counts, filters, and warnings only.
- **Priority**: High

## Non-Functional Requirements

| ID | Requirement | Metric |
|:---|:------------|:-------|
| NFR-080 | Availability queries across thousands of players | Fast enough for interactive filtering; requires an index supporting day + time-range overlap |
| NFR-081 | Availability grid accessibility | WCAG 2.1 AA — a grid of toggles must be keyboard-operable and screen-reader navigable, with row/column headers programmatically associated |
| NFR-082 | Grid on mobile | Touch-friendly; reflows without horizontal scrolling at 320px |
| NFR-083 | Save latency | < 1 second for a full week of slots |

The accessibility requirement here is the hardest in the epic. A 7-day × N-slot toggle grid is the
classic screen-reader failure case; budget for it explicitly rather than treating it as polish.

## Business Rules

- **BR-080** Players and parents set preferred training times per player.
- **BR-081** Coaches set a recurring weekly availability schedule.
- **BR-082** Trainers may view and filter player availability but never edit it.
- **BR-083** Availability is used for scheduling suggestions, not restrictions.
- **BR-084** A coach assignment conflict produces a warning that a trainer may override.
- **BR-085** Every override requires a reason and is logged with who, when, and why.
- **BR-086** A coach is never blocked by an override; they may accept or request a change.
- **BR-087** Trainers see availability only for members of their own organization.

## Task Breakdown

### Entities

| Entity | Properties | Relations |
|:-------|:-----------|:----------|
| `AvailabilitySlot` | `subjectType` (player/coach), `subjectId`, `organizationId` (nullable — pending G-07), `dayOfWeek`, `startTime`, `endTime`, `isAvailable`, `createdAt`, `updatedAt` | belongs to PlayerProfile or coach User |
| `CoachAvailabilityOverride` | `eventId`, `coachUserId`, `overriddenByUserId`, `reason`, `createdAt` | belongs to coach User + trainer User |

`organizationId` on `AvailabilitySlot` is nullable **only** as a placeholder. Resolving G-07 makes it
either required (per-trainer availability) or removed entirely (per-profile availability). Leaving it
nullable permanently would allow both semantics to coexist and produce inconsistent trainer views.

### Services

| Service | Purpose | Methods |
|:--------|:--------|:--------|
| `AvailabilityService` | Read and replace a subject's weekly schedule | `weekFor`, `replaceWeek` |
| `AvailabilitySummarizer` | Human-readable summary for cards | `summarize` |
| `AvailabilityMatcher` | Count and filter available subjects for a window | `playersAvailableAt`, `isCoachAvailableAt` |
| `ConflictOverrideRecorder` | Persist overrides | `record` |

### Controllers

| Controller | Endpoints | Purpose |
|:-----------|:----------|:--------|
| `AvailabilityController` | `GET/POST /availability`, `GET/POST /availability/{playerProfileId}` | Player and per-child availability |
| `Coach\MyTimesController` | `GET/POST /coach/my-times` | Coach weekly availability |
| `Trainer\PlayerAvailabilityController` | `GET /trainer/players/availability` | View and filter |

Coach assignment itself belongs to Epic-02. This task supplies `AvailabilityMatcher::isCoachAvailableAt`
and `ConflictOverrideRecorder` as the interfaces that Epic-02's assignment flow calls.

### Backend Tasks
- [ ] **Resolve G-07 before writing the migration** — the answer determines the table's unique key
- [ ] Migration: CREATE `availability_slot` with an index on `(subject_type, subject_id, day_of_week)` and a composite index supporting overlap queries; CREATE `coach_availability_override`
- [ ] Entities above; `DayOfWeek` backed enum
- [ ] Value object: `TimeRange` — start before end, overlap detection, merge of adjacent ranges
- [ ] Request DTO + validator: `WeeklyAvailabilityRequest` (per-day ranges, start < end, no self-overlap, sane granularity), `OverrideRequest` (reason required, non-blank)
- [ ] Voter: a player edits only their own or their child's availability; a trainer may read but not write; a coach edits only their own
- [ ] Organization scoping on all trainer-facing availability reads (FR-083, BR-087)
- [ ] Services above + DI wiring
- [ ] Repository: `AvailabilitySlotRepository::forSubject`, `::subjectsAvailableAt` (overlap query, indexed); `CoachAvailabilityOverrideRepository::forEvent`
- [ ] Replace-week semantics: saving a week is atomic — delete-and-insert or diff, inside one transaction
- [ ] Interface for Epic-02: `CoachAvailabilityChecker` with the conflict-check contract and a documented return shape
- [ ] Fixtures: a coach with split daily slots, players with varied availability for filter testing

### Frontend Tasks (server-rendered)
- [ ] Templates: player availability grid, coach My Times grid, trainer availability filter view, player card availability summary partial, conflict warning + override form
- [ ] Progressive enhancement (Stimulus): drag-to-select ranges, click-toggle cells, "copy Monday to all weekdays", running summary of the current selection. The form must submit and validate correctly **without JavaScript** — the grid needs a non-JS fallback of per-day range inputs
- [ ] Accessibility: grid as a real `<table>` with `<th scope>` headers, each cell an actual checkbox with an accessible name ("Monday 5 PM to 6 PM, available"); `aria-live` region announcing selection changes; full keyboard operation with arrow keys plus space to toggle; never rely on colour alone to mean available
- [ ] Responsive: grid transposes to a day-by-day list on narrow screens

### Testing Tasks
- [ ] Integration: player saves a week; reload shows the same slots
- [ ] Integration: parent saves separate availability per child; children's schedules do not interfere
- [ ] Integration: coach saves multiple slots on one day
- [ ] Integration: validation rejects end before start, self-overlapping ranges, and out-of-range times
- [ ] Integration: trainer sees availability only for their own organization's players (BR-087)
- [ ] Integration: trainer cannot write player availability (403)
- [ ] Integration: filter returns exactly the players whose ranges overlap the queried window, including boundary-touching ranges
- [ ] Integration: conflict warning appears for an out-of-availability assignment; assignment still succeeds after override
- [ ] Integration: override with a blank reason is rejected; a valid override is persisted with all five fields
- [ ] Integration: availability never blocks a scheduling action (FR-088)
- [ ] Unit: `TimeRange` overlap, adjacency, merge, and boundary equality; `AvailabilitySummarizer` output formatting; midnight and end-of-day edges
- [ ] Browser/E2E: player sets availability by keyboard only and saves successfully (NFR-081)

## Validation Completeness

- [x] All functional requirements mapped to tasks
- [x] Happy path covered
- [x] Error cases identified (invalid ranges, blank override reason, unauthorized writes)
- [x] Edge cases considered (overlapping ranges, adjacent ranges, midnight boundaries, empty week, all-day unavailable)
- [x] Security requirements addressed (write voters, organization-scoped reads)
- [x] Performance requirements noted (NFR-080, NFR-083)
- [x] Testing strategy defined

## Gap Analysis

- [ ] **G-07 (blocking)** — US-01.03 says each child has availability preferences "**per trainer**"; US-01.09 says availability is stored "per player profile". These are different schemas and different UIs (one grid or one grid per trainer). **Must be resolved before the migration is written.** Recommendation: per profile, since availability describes when a person can attend anything, and a multi-trainer player's real-world constraints do not change per trainer.
- [ ] **G-27 (new)** — Slot granularity is undefined. US-01.09 says "hourly blocks or custom ranges" — both. Hourly blocks make the grid and the matching query simple; arbitrary ranges make summarization and overlap logic materially harder. Pick one.
- [ ] **G-28 (new)** — Availability has no date dimension: it is a recurring weekly pattern with no support for exceptions ("unavailable next week, on holiday"). Real scheduling needs this. Confirm it is deliberately deferred.
- [ ] **G-29 (new)** — No time zone is specified anywhere in the epic. A weekly time grid without a time zone is ambiguous the moment a trainer and player differ, and DST shifts break stored local times. Decide: organization time zone, user time zone, or explicitly single-time-zone MVP.
- [ ] **G-30 (new)** — FR-087 "coach can request a change" has no defined workflow: no recipient, no state, no notification, no UI. Currently unimplementable as specified.
- [ ] **Q-01.06 (P2)** — Should the coach be notified when overridden? Affects whether a notification and template are needed.
- [ ] Epic-02 does not exist, so `eventId` on `CoachAvailabilityOverride` has no table to reference yet. Either defer the override table to Epic-02 or create it with a nullable, unconstrained `event_id` and add the FK later. Recommend the latter with an explicit follow-up ticket.
- [ ] "Players available at this time: 15 out of 20" implies a denominator of associated players — whether inactive associations count is unspecified.

## Next Steps (Suggested)

Do not auto-execute — see the epic index for the full ordering.
