# TASK-005 — Availability & Conflict Override: Design Decisions

Scope: US-01.09, US-01.10 (FR-080 … FR-088, NFR-080 … NFR-083, BR-080 … BR-087).
Module: `src/Availability/`. Depends on TASK-004 (player profiles, coach profiles) and TASK-003
(associations, coach assignments).

## Blocking gaps, decided

| Gap | Decision | Why, and what it costs |
|:----|:---------|:-----------------------|
| **G-07** (blocking) — availability per profile or per (profile, trainer)? | **Per person.** `availability_slot` has no `organization_id` at all. | Availability describes when somebody can attend anything; a multi-trainer player's real constraints do not change per trainer. A per-trainer schema would ask a family to fill the same grid once per trainer and let the copies disagree. Cost: every trainer of a player sees the same times, so a family cannot show one trainer more availability than another. BR-087 governs who may *read* the times, not how many copies exist. |
| **G-27** — hourly blocks or arbitrary ranges? | **Fixed blocks, 60 minutes, configurable** (`AVAILABILITY_SLOT_MINUTES`). | The spec asks for both, which is not a schema. Blocks are the control FR-080 draws and the accessible table NFR-081 requires; runs of adjacent blocks merge into ranges on save, so families tick blocks and trainers read "Mon 5-8pm". Cost: 17:37 is not expressible. Stored ranges are plain minutes, so changing the block length does not invalidate saved weeks. |
| **G-29** — which time zone? | **One platform zone** (`AVAILABILITY_TIMEZONE`, default `UTC`), printed on every grid. | Per-user zones mean converting a *recurring* pattern across DST, where "Mondays at 5" is a different instant in July and January. That is a client decision, not one to guess. Making the assumption visible is the honest interim. |
| **G-28** — no date dimension / exceptions | **Deferred, explicitly.** | "Away next week" needs a date-scoped override table and belongs with events (Epic-02). Nothing here blocks adding it: a dated exception layers on top of a weekly pattern. |
| **G-30 / Q-01.06** — coach "requests a change"; is a coach notified? | **Not implemented.** The coach *sees* every override recorded against them on `/coach/my-times`. | The spec defines no recipient, state, notification or UI for a change request, and Q-01.06 is unanswered. Inventing a workflow would be inventing requirements; showing the record is the part that is unambiguously owed to the coach (FR-087). |

## Schema

Two tables (`migrations/Version20260822170300.php`), additive, no backfill.

`availability_slot` holds players *and* coaches, discriminated by `subject_type` — the same fact about
different people, so two tables would duplicate one query shape and both indexes. The price is that
`subject_id` carries no foreign key (it points at `player_profile` or at `"user"`); `AvailabilitySubject` is
the only thing that can construct a pair, so a coach id cannot be stored under the player type.

Times are **minutes since midnight** (`SMALLINT`), not `TIME`: `24:00` must be expressible as an end, a
recurring pattern must not shift with DST, and the matching predicate becomes two comparisons an index can
serve end to end.

Two indexes, each for one access path:

- `(subject_type, subject_id, day_of_week)` — read-and-replace one person's week.
- `(subject_type, day_of_week, available, start_minute, end_minute)` — the trainer's filter, answered from
  the index alone (NFR-080).

`coach_availability_override` is append-only: no setter, no status, no delete. `event_id` is a **nullable,
unconstrained integer** because Epic-02 owns events and none exist; the foreign key belongs to Epic-02's
first migration (tracked as R6). The conflicting window is stored alongside — not in the spec's field list —
because until `event_id` is filled, a row without it would record that somebody overrode *something*.
`organization_id` is stored rather than derived from the coach's current assignment, so a coach who moves to
another trainer (BR-044) does not take their previous trainer's records with them.

## Three states, not two

The load-bearing decision behind several requirements: an explicit refusal is stored as a whole-day
`available = false` row, so **"said no" is distinguishable from "said nothing"**.

- FR-083's "15 of 20" reports undeclared players as a third number instead of counting them as busy.
- A coach who has declared nothing produces **no conflict warning** (FR-085). Warning about absent data is
  how a warning stops meaning anything.
- A coach who declared a refusal *does* produce one.

`AvailabilitySummarizer` keeps the distinction in words too: "No preferred times set" versus "No available
times".

## Coverage, not overlap

"Available 18:00-20:00?" is answered by a range that *contains* the window, not one that intersects it —
somebody free until 19:00 cannot attend a session ending at 20:00. This stays a single-row comparison only
because `WeeklySchedule` normalizes on construction: ranges are sorted and adjacent or overlapping ones are
merged, so 16:00-18:00 plus 18:00-21:00 is one row covering 16:00-21:00. Boundaries are inclusive at both
ends; a merely adjacent range does not match. The rule is stated once in `TimeRange::covers()`, mirrored in
SQL, and pinned down in both places by tests.

A week is saved as a **whole value**: delete-and-insert inside one transaction. There is deliberately no
`addSlot()`, so there is no merge strategy to get wrong when two tabs are open on one grid, and no failure
mode where Monday is cleared and Tuesday never written (NFR-083).

## Boundaries

| Direction | Mechanism |
|:----------|:----------|
| Availability → who is in an organization | `OrganizationRosterProvider` (declared here, implemented by `Membership\Service\AvailabilityRosterProvider`) — the same inversion as `CoachOrganizationProvider` and `TrainerAssociationGateway`, because Membership already depends on Profile and this module must not reach into its repositories. |
| Epic-02 → the conflict decision | `CoachAvailabilityChecker`, returning `CoachAvailabilityVerdict`. A published boundary between epics, which is why it is an interface: the consumer does not exist to be refactored alongside. |
| Availability → family rules | `AvailabilityVoter` delegates the player cases to `ProfileVoter` (`VIEW`, `EDIT_OWN_BASICS`), so "is this your child?" has one implementation. |

**BR-087 is structural.** No matching method accepts an unscoped subject list; candidates always come from
the roster provider for one organization id. A forgotten `WHERE` cannot leak another academy's roster
because there is no query to forget it in.

**BR-082 is structural too.** The trainer's view has no POST route and is handed read models, and a trainer
who requests a player's own availability form is refused by the voter (403, tested).

## FR-088: availability never blocks

`CoachAvailabilityChecker` has no way to say "no". A conflict produces FR-085's warning and a second
submit; the second submit requires a reason through the `override` validation group and records it. The
verdict is **recomputed on that submit** rather than trusted from the rendered page, so a coach who opened
their times up in between does not get an override filed against a conflict that no longer exists — that
path reports "no conflict any more" and records nothing.

Coach assignment is Epic-02's. What ships is the check either side of it, as a trainer's pre-assignment
screen (`/trainer/coaches/{id}/availability-check`). Epic-02's assignment flow calls the same two services
with an event id; nothing here changes when it does.

## Accessibility (NFR-081, NFR-082)

Met structurally, not with ARIA: a real `<table>`, `<th scope="col">` hours, `<th scope="row">` days, and
every cell a native checkbox whose `<label>` carries the complete spoken name ("Monday 5:00 PM to 6:00 PM,
available") — because a screen reader landing mid-table announces the label, not the headers above it.
Keyboard operation and submission are native; `assets/availability-grid.js` adds drag-to-select, "copy
Monday to weekdays" and an `aria-live` running count on top of a control that already works without it, and
every enhanced control ships `hidden` until the script reveals it.

The inputs are written out by hand rather than through `form_widget()`: the project's Bootstrap form theme
wraps a checkbox in its own label markup, which would give all 168 cells a second, visible label. Each is
marked rendered so `form_rest()` still emits the CSRF token.

Rows are days and columns are hours, which is what lets the same markup reflow into a day-by-day list at
320px with the hour written beside each box (NFR-082).

## Verification

`make test` — 615 tests, 5587 assertions, all passing (66 of them new). `make lint` (php-cs-fixer +
PHPStan level 5) clean. `lint:container`, `lint:twig`, `lint:yaml`, `doctrine:schema:validate` clean, and
`migrations:diff` produces no schema change.

Not verified: a browser-driven keyboard walkthrough of the grid. The project configures no E2E harness
(Panther/Playwright are not installed), so NFR-081 is asserted structurally in
`PlayerAvailabilityTest::testTheGridIsATableOfLabelledCheckboxes` instead — the markup that makes keyboard
and screen-reader operation native, rather than the operation itself.

---

*Sources: `tasks/TASK-005/requirements-analyst-requirements.md`,
`specs/Epic-01_User_Management_Authentication_SPEC.md` (US-01.09, US-01.10, §8, §9, §10 Flow 6).*
