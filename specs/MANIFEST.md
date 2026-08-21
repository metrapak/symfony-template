# Project: Symfony Layered Architecture Accelerator

An AI-assisted development accelerator for Symfony 7.4 LTS and Symfony 8.1 projects. It provides native Claude Code, Cursor, and Codex workflows centered on pragmatic Controller -> Service -> Repository architecture and Symfony conventions.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, components, data flow | - | 2026-08-21 |
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

## Tech Stack

- PHP 8.2+ for Symfony 7.4 LTS; PHP 8.4+ for Symfony 8.1.
- Symfony components and conventions, Doctrine ORM/Migrations, Symfony Security, Messenger, Forms, Validator, Serializer, Twig, and Symfony UX as installed by the consuming project.

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
