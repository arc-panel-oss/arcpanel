# ArcPanel System Design

**Status:** Draft v1 · **Companion to:** [roadmap.md](../roadmap.md) · **Baseline:** HestiaCP 1.10.5 (see [current-features.md](../current-features.md))

This directory holds the system design for ArcPanel: how the roadmap's epics are built on top of the HestiaCP codebase. The roadmap says _what_ and _when_; these documents say _how_ and _why_.

Every design here follows the constraints the roadmap already fixed:

1. **Strangler migration.** Laravel runs next to the legacy `web/` app on the same port and takes over one page at a time.
2. **The CLI is the API.** Every state change goes through a `v-*` command in `bin/`. The web layer holds no business logic that the CLI does not have.
3. **Additive changes only** in `bin/`, `func/`, `install/`. New commands, new templates, new installer options, new hooks. Existing commands are not modified (one exception, recorded in [03-tenancy-and-authorization.md](./03-tenancy-and-authorization.md)).
4. **Owner-scoped from day one.** Every new page and command understands the admin / reseller / user model, so no second pass is needed when resellers ship.
5. **One or two developers.** Designs prefer boring, well-known tools over bespoke infrastructure.

## Documents

| #   | Document                                                                                                                                  | Roadmap epics | Status |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------- | ------------- | ------ |
| 01  | [Architecture overview](./01-architecture-overview.md) — current and target system, runtime topology, request flow, packaging             | all           | Draft  |
| 02  | [Laravel foundation](./02-laravel-foundation.md) — strangler routing, session bridge, `HestiaCommand`, policies, i18n, packaging          | E1            | Draft  |
| 03  | [Tenancy and authorization](./03-tenancy-and-authorization.md) — admin / reseller / user roles, `OWNER` model, policy layer, API keys     | E11           | Draft  |
| 04  | [Port registry](./04-port-registry.md) — allocation, enforcement, firewall integration                                                    | E12           | Draft  |
| 05  | [Design system and frontend](./05-design-system-and-frontend.md) — tokens, components, Blade + Alpine, Vue islands, shell, build pipeline | E2            | Draft  |
| 06  | [File Manager v2](./06-file-manager-v2.md) — browse, upload, editor, FileGator removal                                                    | E3            | Draft  |
| 07  | [Node platform](./07-node-platform.md) — per-user nvm, npm packages, Node Apps with pm2                                                   | E13, E14      | Draft  |
| 08  | [App Store](./08-app-store.md) — Docker engine, catalog format, lifecycle, system packages                                                | E4, E5        | Draft  |
| 09  | [Web templates with metadata](./09-web-templates-metadata.md) — sidecar format, new templates, picker                                     | E6            | Draft  |
| 10  | [Page migration and legacy retirement](./10-page-migration-and-retirement.md) — module order, page recipe, 2.0 cut-over, branding         | E7, E8, E9    | Draft  |
| 11  | [Testing and CI](./11-testing-and-ci.md) — Pest, Playwright, BATS, upstream-diff check                                                    | E10           | Draft  |
| 12  | [Security model](./12-security-model.md) — trust boundaries, sudo, sessions, secrets, isolation                                           | all           | Draft  |
| 13  | [Data model reference](./13-data-model-reference.md) — every new file, key and command introduced by the designs                          | all           | Draft  |
| 14  | [Later backlog designs](./14-later-backlog.md) — Job Center, alerts, Doctor, audit log, WAF and the rest of E15–E33                       | E15–E33       | Sketch |

Architecture Decision Records live in [`docs/adr/`](../adr/README.md). The ADRs the roadmap asks for in Q1 are drafted there with a recommendation each.

## How to read

- Start with **01** for the big picture, then **02** and **03**: everything else is built on the session bridge, `HestiaCommand` and the policy layer defined there.
- Each epic document has the same shape: _Goal_ → _Current state in the code_ → _Design_ (data, commands, services, UI) → _Failure modes_ → _Testing_ → _Open questions_.
- Paths such as `bin/v-add-user` refer to this repository; runtime paths such as `/usr/local/hestia/data/users/<user>/user.conf` refer to an installed server (`$HESTIA` = `/usr/local/hestia`).
- Commands written as `v-*` are shell scripts in `bin/`. New ones proposed by a design are marked **new**; everything else already exists in 1.10.5.

## Conventions used in the designs

| Term                | Meaning                                                                                                                                                                                      |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Legacy app**      | The PHP pages under `web/` served by `hestia-nginx` + `hestia-php` on port 8083                                                                                                              |
| **Panel app**       | The Laravel application introduced in E1 (repository path `web-app/`)                                                                                                                        |
| **`HestiaCommand`** | The single service through which the panel app runs `sudo /usr/local/hestia/bin/v-*`                                                                                                         |
| **Principal**       | Whoever is acting: a logged-in user, an impersonated user ("login as"), or an API access key                                                                                                 |
| **Effective user**  | The Hestia user whose objects are being read or changed (the impersonated user when "login as" is active)                                                                                    |
| **Owner**           | For a user: the reseller that manages it, or the root admin (`ROOT_USER`)                                                                                                                    |
| **Tree**            | A reseller plus all users whose `OWNER` is that reseller                                                                                                                                     |
| **Additive hook**   | A new `func/*.sh` file or a new `v-*` command called from an existing flow through a mechanism that already exists (queue pipe, cron, `conf.d` include), without editing the existing script |

## Maintenance

- Update the design when the roadmap changes an epic's scope, and when an ADR is accepted that changes a recommendation here.
- Keep the data model reference (**13**) in sync with the per-epic documents: it is the one place that lists every file format and key.
- Mark a document **Accepted** once its epic has started and the team has reviewed it; **Superseded** when the implementation diverged and a newer document replaced it.
