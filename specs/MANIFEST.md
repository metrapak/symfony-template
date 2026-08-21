# Project: Symfony Layered Architecture Accelerator

An AI-assisted development accelerator for Symfony 7.4 LTS and Symfony 8.1 projects. It provides native Claude Code, Cursor, and Codex workflows centered on pragmatic Controller -> Service -> Repository architecture and Symfony conventions.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, components, data flow | - | 2026-08-21 (Epic-01 foundation: AD-01 module layout, AD-02 tenancy) |
| database-designer-schema.md | Tables, constraints, indexes, migrations, repository methods | architect-architecture | 2026-08-21 (Epic-01: 19 tables) |
| security-voter-designer-authorization.md | Roles, access-decision strategy, voters, access_control, collection scoping | architect-architecture, database-designer-schema | 2026-08-21 (Epic-01: 12 voters) |
| api-designer-spec.md | Endpoints, schemas, authentication | architect-architecture | - |
| frontend-design-spec.md | Pages, components, state management | architect-architecture, api-designer-spec | - |
| docs-generator-implementation.md | Build process, deployment, tooling | - | - |

## Key Decisions

- Target Symfony 7.4 LTS and Symfony 8.1 while detecting each consuming project's installed versions.
- Use `.agents/skills` as the configured canonical source for shared skill parity, mirror Claude and Cursor semantics natively, and keep Codex support files under `.codex`.
- Enforce Controller -> Service -> Repository pragmatically, without requiring pass-through layers or interfaces without a real boundary.
- Epic-01 foundation lives in two modules, `App\Identity` (accounts, authentication, profiles, audit) and
  `App\Academy` (trainer organizations, associations, ShareLinks, family, availability, branding), with a one-way
  dependency `Academy -> Identity -> Shared`. See `architect-architecture.md` AD-01.
- Multi-tenancy is enforced by an explicit `TrainerScope` parameter on tenant-scoped repository methods plus voters
  and a request-scoped `TenantContext`; a global Doctrine filter is deliberately rejected. See AD-02.
- Epic-01 persistence: 19 tables, integer identity keys, string enums with CHECK constraints, hashed single-use
  tokens, and partial unique indexes for the "one active association/assignment" invariants.
  See `database-designer-schema.md`.
- Authorization: no inheritance between business roles (Super Admin gains no tenant access by role), and
  `access_decision_manager.strategy: unanimous` so a deny-list voter cannot be overridden by a granting voter.
  See `security-voter-designer-authorization.md`.

## Tech Stack

- PHP 8.2+ for Symfony 7.4 LTS; PHP 8.4+ for Symfony 8.1.
- Symfony components and conventions, Doctrine ORM/Migrations, Symfony Security, Messenger, Forms, Validator, Serializer, Twig, and Symfony UX as installed by the consuming project.

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
