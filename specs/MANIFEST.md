# Project: Symfony Layered Architecture Accelerator

An AI-assisted development accelerator for Symfony 7.4 LTS and Symfony 8.1 projects. It provides native Claude Code, Cursor, and Codex workflows centered on pragmatic Controller -> Service -> Repository architecture and Symfony conventions.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, components, data flow | - | 2026-08-22 |
| api-designer-spec.md | Endpoints, schemas, authentication | architect-architecture | - |
| frontend-design-spec.md | Pages, components, state management | architect-architecture, api-designer-spec | - |
| docs-generator-implementation.md | Build process, deployment, tooling | - | - |

## Key Decisions

- Target Symfony 7.4 LTS and Symfony 8.1 while detecting each consuming project's installed versions.
- Use `.agents/skills` as the configured canonical source for shared skill parity, mirror Claude and Cursor semantics natively, and keep Codex support files under `.codex`.
- Enforce Controller -> Service -> Repository pragmatically, without requiring pass-through layers or interfaces without a real boundary.
- Authentication is session-based and server-rendered (Twig); there is no JSON API. The placeholder `json_login` path is removed.
- Exactly one role per user, enforced by a schema enum column, with no `role_hierarchy` between the four business roles.
- Organization-scoped repository methods take the organization id as a required parameter; no Doctrine SQL filter for tenancy.
- Unresolved requirements ship as a container parameter with its own default plus an env-var override, so answering them is a configuration change rather than a rewrite.
- `User` implements `EquatableInterface` so a status or role change de-authenticates existing sessions; the user checker alone does not run on session refresh.
- Impersonation is Symfony's `switch_user`, authorized and audited on the `security.switch_user` event rather than in a controller, because the firewall listener answers `?_switch_user=` on any URL.
- Impersonation expires after a configurable window via a request subscriber, reading elapsed time from the open audit row rather than from a session key.
- Users are removed by anonymizing the row in place, never by deleting it, so historical rows and aggregate totals survive an erasure; every FK to `"user"` is `ON DELETE RESTRICT`.
- The GDPR compliance record stores a SHA-256 digest of the original address instead of the address and data snapshot the spec asks for, so the record does not re-create the data the erasure removed (open: G-16, legal sign-off).
- Audit writes persist without flushing, so an entry commits or rolls back with the change it describes.
- Invitation codes are 128 bits from a CSPRNG: enumeration is defeated by the key space, and the rate limiter on the public redemption endpoint is load protection, not the security boundary.
- Unknown, deactivated and consumed invitation codes are one indistinguishable response; only an expired coach invitation is told apart, because FR-046 requires offering a resend.
- A use of an invitation is claimed by one conditional UPDATE, never read-then-write, so concurrent redemptions of a single-use link cannot all succeed.
- Membership correctness lives in the schema: unique (organization, player) associations, and a **partial** unique index enforcing one active coach assignment per coach.
- Player profiles store a birth date and derive age, because a stored age is correct on the day it is typed and wrong every year after.
- Availability belongs to a person, not to a (person, trainer) pair: one grid per player or coach, read by every trainer they train with (G-07).
- Availability times are minutes since midnight in one configured platform time zone, so `24:00` is expressible and a recurring pattern does not move with DST (G-29).
- A week of availability is saved as a whole value — normalized, then delete-and-insert in one transaction — never slot by slot.
- Availability matching asks whether a declared range *covers* the window, not whether it overlaps it; adjacent ranges are merged on save so that stays a single-row comparison.
- Declared unavailability is a stored row, so "said no" is distinguishable from "said nothing": undeclared people are counted separately and never produce a conflict warning.
- Availability is advisory everywhere: a conflict yields a warning plus a reason-bearing override record, and no code path can refuse a scheduling action on availability grounds.
- Whether a child purchase needs a parent's approval is decided from the profile, never the role, and USD is never waivable; the per-child token waiver is the only exception the rule admits.
- Payment execution sits behind a `PaymentProcessor` port with a recording fake, so the approval workflow ships complete and Epic-05 is one container alias.
- One approval takes one payment: an optimistic-lock version column is flushed before the processor is called, so a concurrent second approval loses the race before any money.
- The approval state machine lives on the entity as a transition table rather than in Symfony Workflow, which is not installed; a purchase that needed no approval is its own final state, not a fake approval.
- Approval expiry is a cron-driven sweep dispatching one message per due request, because the only configured transport is synchronous and a delayed message would fire immediately.
- In-app notifications are a module-scoped table, not a platform service: a child login's address is undeliverable by construction, so in-app is the only channel a child has.
- Money is integer minor units plus a currency, with tokens modelled as a zero-scale currency; amounts of different currencies refuse to add rather than being converted.

## Tech Stack

- PHP 8.2+ for Symfony 7.4 LTS; PHP 8.4+ for Symfony 8.1.
- Symfony components and conventions, Doctrine ORM/Migrations, Symfony Security, Messenger, Forms, Validator, Serializer, Twig, and Symfony UX as installed by the consuming project.

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
