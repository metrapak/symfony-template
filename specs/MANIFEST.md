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

## Tech Stack

- PHP 8.2+ for Symfony 7.4 LTS; PHP 8.4+ for Symfony 8.1.
- Symfony components and conventions, Doctrine ORM/Migrations, Symfony Security, Messenger, Forms, Validator, Serializer, Twig, and Symfony UX as installed by the consuming project.

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
