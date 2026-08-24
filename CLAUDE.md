# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository layout: two things in one repo

1. **The application** — "Brainique Backend", a Symfony 7.4 / PHP 8.2+ app. It lives entirely under
   `src/` (so the Symfony project root is `src/`, `bin/console` is `src/bin/console`, and the PHP
   namespace root `App\` maps to `src/src/`). Everything runs in Docker (Nginx :8080, PHP-FPM,
   Postgres).
2. **The AI accelerator** — `AGENTS.md`, `ACCELERATOR.md`, `.claude/`, `.cursor/`, `.agents/`,
   `.codex/`, `specs/`, `tasks/`, `memory-bank/`, `project-brain/`, `examples/`. Policy, skills,
   agents, hooks, and the governed task/memory system. `VERSION` (2.0.0) versions the accelerator,
   not the app.

`AGENTS.md` is the enforceable policy for this repo and outranks this file. `.claude/DOD.md` is the
Definition of Done to run before claiming completion.

## Commands

All Make targets run inside the `php-fpm` container (`cd docker && docker compose run --rm -T
php-fpm …`), so run them from the repo root, not from `src/`.

```bash
make install      # first-time setup: .env copies, build, composer install, grumphp git hook, start
make start/stop/restart
make terminal     # bash inside php-fpm — use this for any bin/console work
make test         # bin/phpunit (whole suite)
make cs-check / make cs-fix    # PHP-CS-Fixer (@Symfony + @PSR12, risky allowed)
make stan         # PHPStan (level 5, paths: src) — note: README claims level 8/max, config says 5
make lint         # cs-check + stan
make migrate      # doctrine:migrations:migrate
make db-seed      # doctrine:fixtures:load
make approvals-expire          # app:approvals:expire (see "Purchase approvals" below)
```

**Single test / single directory** — there is no Make target; go through the container:

```bash
cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off bin/phpunit tests/Approval
cd docker && docker compose run --rm -T php-fpm php -dxdebug.mode=off bin/phpunit --filter testApproveTakesPayment
```

`src/scripts.sh [path] [-db]` is a legacy helper that rebuilds the schema from scratch (drop +
regenerate migrations + fixtures) before running phpunit. It deletes `migrations/*.php` — don't use
it on this repo's migration history.

GrumPHP runs php-cs-fixer + phpstan + yamllint on `pre-commit` via a hook that shells into Docker.
Never bypass it with `--no-verify` (the `bash-validator.sh` hook hard-blocks that string, along with
`git push --force`, `git reset --hard`, `doctrine:schema:drop`, `doctrine:fixtures:load` without
`--append`, and `DROP/TRUNCATE TABLE`).

## Architecture

### Two coexisting module styles — match the one you're in

`src/src/<Module>/` is the unit of organization. Each module owns its controllers, entities,
repositories, services, forms, DTOs, voters, and Doctrine mapping.

- **Current style (all feature work): flat, layered.** `Account`, `Approval`, `Availability`,
  `Membership`, `Profile` use `Controller/`, `Service/`, `Repository/`, `Entity/`, `Enum/`, `Dto/`,
  `Form/`, `Security/`, `ValueObject/`, `Mail/`, `Twig/`, `Command/`, `Message*/`, `DataFixtures/`.
  Controllers are sub-namespaced by audience (`Controller/Family/`, `Controller/Trainer/`,
  `Controller/Coach/`, `Controller/Player/`, `Controller/Admin/`).
- **Legacy demo style: DDD folders.** `Products`, `Starships` use
  `Domain/` + `Application/` + `Infrastructure/`. `Videos`, `ToDoList`, and `Shared` are a third,
  older shape. These are template leftovers (a starship API, a product list, a todo list) — public
  routes, thin on rules, and some put Doctrine calls straight in the controller. Don't copy them and
  don't refactor them as a side effect of feature work.

The enforced pattern is **Controller → Service → Repository**: controllers map input, authorize, and
call one service method; services own workflow, transactions, and side-effect ordering; repositories
own all QueryBuilder/DQL. Entities never touch HTTP, sessions, Twig, or mail.

Adding a module means three registrations: `config/routes.yaml` (attribute resource per module
controller dir), `config/packages/doctrine.yaml` (explicit `mappings` entry per module `Entity` dir),
and `config/services.yaml` (`App\` autowires all of `src/`, so `Entity/`, `Enum/`, `ValueObject/`,
`Exception/`, and `Message/` dirs must be added to the `exclude` list — they are data, not services).

### Domain model

Roles are a **single** `UserRole` enum value per user (`ROLE_SUPER_ADMIN`, `ROLE_TRAINER`,
`ROLE_COACH`, `ROLE_PLAYER`), stored as an enum column, with **no `role_hierarchy` between the four**
— a Super Admin inheriting `ROLE_TRAINER` would be routed into organization-scoped views with no
organization. `role_hierarchy` only grants `ROLE_ALLOWED_TO_SWITCH` to Super Admin.

Both parents and children hold `ROLE_PLAYER`. That is why `access_control` in `security.yaml` is only
a coarse gate and *whose* data a URL names is decided per request by a voter (`AvailabilityVoter`,
`ApprovalVoter`, `ChildActionVoter`, `ImpersonateVoter`) or a context resolver
(`TrainingContextResolver`). Never express family/tenant scoping as a role rule.

Organization tenancy is explicit: organization-scoped repository methods take the organization id as
a required parameter — there is no Doctrine SQL filter doing it invisibly.

### Session, auth, and the app's own idioms

- Session-based, server-rendered Twig. **No JSON API**, no API Platform.
- `User::isEqualTo()` (`EquatableInterface`) is what de-authenticates a session after a status or
  role change; `AccountStatusChecker` only runs on authentication, not on session refresh.
- Impersonation is Symfony `switch_user`, authorized and audited in
  `SwitchUserAuditSubscriber` on the `security.switch_user` event — not in a controller, because the
  firewall answers `?_switch_user=` on any URL.
- Uploads are written under `var/uploads` (outside the web root) and served only through
  `MediaController` after a voter decides.
- Unresolved spec questions ship as a container parameter with a default plus an env override
  (`app.*_default` + `%env(...)%` in `config/services.yaml`), so answering them later is
  configuration, not a rewrite. Follow that pattern instead of hardcoding.
- Money is integer minor units + currency (`Approval/ValueObject/Money`); different currencies refuse
  to add. Tokens are modelled as a zero-scale currency.
- Payment execution sits behind the `App\Approval\Payment\PaymentProcessor` port, aliased to
  `FakePaymentProcessor` in `config/services.yaml`. **No money moves.** Replacing it is one alias.
- Messenger has only a `sync://` transport configured, which is why approval expiry is a cron-driven
  sweep (`app:approvals:expire`) rather than a delayed message.
- Frontend is Twig + AssetMapper/importmap (`src/importmap.php`, single `app` entrypoint) + Tailwind
  via `symfonycasts/tailwind-bundle`, with plain ES modules in `src/assets/*.js`. No Stimulus/Turbo,
  no npm build.

Templates live in `src/templates/` grouped by audience (`family/`, `trainer/`, `coach/`, `player/`,
`admin/`, `account/`, `join/`) with a `_layout.html.twig` per audience — not mirrored from module
names.

### Tests

`src/tests/<Module>/{Unit,Integration,Functional}/`. DAMA Doctrine Test Bundle wraps each test in a
rolled-back transaction, so no manual cleanup; the test DB needs migrations run once
(`--env=test`). phpunit is strict: `failOnDeprecation`, `failOnNotice`, `failOnWarning` are all on, so
a new deprecation fails the suite. See `docs/DatabaseTestingSetup.md`.

## Working conventions

`declare(strict_types=1);` in new PHP files. Constructor injection only. Doctrine migrations for every
schema change, with real DB constraints where correctness depends on them (the codebase leans on
partial unique indexes, `ON DELETE RESTRICT`, and one optimistic-lock version column). Comments carry
rationale and requirement ids (`FR-094`, `NFR-066`, `G-31`, `D-04`) — existing code documents *why*
heavily, including why a listed feature was deliberately not built; match that.

Branches are `feat/epic-NN-task-NNN-slug`, commits `type(TASK-NNN): summary`.

Task docs go in `tasks/TASK-{NNN}/{skill-name}-{purpose}.md` (check `tasks/.task-counter` first);
living specs in `specs/` with `specs/MANIFEST.md` updated. `specs/MANIFEST.md` **Key Decisions** is
the fastest way to learn why the code is shaped the way it is — read it before changing auth,
membership, availability, or approval behavior. A `file-naming-validator.sh` hook rejects unprefixed
markdown in `tasks/` and `specs/`.

Built-in subagents (Explore, Plan, general-purpose) are disabled by `.claude/settings.json` and denied
by `subagent-gate.sh`. Use this project's own agents/skills (`/coder`, `/architect`,
`/security-reviewer`, `/verify`, …; see `.claude/skills/SKILL FLOW.md`) or do the work in the main
conversation. Skills do not chain automatically — only `/flow-feature`, `/flow-review`, and `/sdd`
orchestrate multiple agents.

Never read, print, or edit `.env` files (also denied in `.claude/settings.json`).

## Operational requirement

`app:approvals:expire` must be scheduled in **every** environment or pending child purchase requests
never auto-deny after their 48-hour window (FR-096):

```cron
*/15 * * * *  cd /path/to/app && php bin/console app:approvals:expire
```

Set `APP_BASE_URL` wherever that cron runs — mail sent from it has no request to take a host from.
