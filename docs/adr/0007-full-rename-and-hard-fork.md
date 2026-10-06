# ADR-0007 — Full rename of internal names and hard fork from HestiaCP

**Status:** Accepted (owner decision) · **Date:** 2026-10-06 · **Epic:** E9 (R5)

Supersedes decisions 1, 2 and 5 of [ADR-0006](./0006-rebranding-depth.md). Its decisions 3 (own APT repository) and 4 (fresh installs only) still hold.

## Context

ADR-0006 chose option B: ArcPanel names for what users and administrators see, and HestiaCP names for every internal identifier, so that upstream HestiaCP releases could still be merged in under a day (roadmap outcome O5). On 2026-10-06 the owner chose option C, a full rename, for these reasons:

- **Upstream merges are already expensive.** E8 deleted `web/` and moved its pieces to `lib/` and `data/`. It changed about 60 upstream files under recorded exceptions, and it needs `.arcpanel/retired-paths` and `scripts/upstream-merge-cleanup.sh` for every merge. Since E1, every user-facing feature lives in `web-app/` and in new commands, so merges bring in fewer and fewer upstream features.
- **A product that does not hide its base.** Administrators see `/usr/local/hestia`, `hestia.conf`, `HESTIA_*`, `hestiaweb` and `v-*-hestia-*` in logs, `ps`, errors, documentation and scripts. That is the "visible internals" consequence that ADR-0006 accepted.
- **No installed base to migrate.** There are no ArcPanel 1.x production servers and no HestiaCP servers to take over (ADR-0006 decision 4). Version 2.0 is not released yet, so it can carry one breaking change for everything.

## Options

| Option                                      | What changes                                                                                         | Cost                                                                           |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| B. Keep ADR-0006                            | Nothing                                                                                              | Visible internal names stay; merge cost grows with every epic                  |
| C1. Full rename with a compatibility layer  | Every internal name, plus symlinks and aliases for the old paths, commands, packages and the service | Two names for everything, tested twice; aliases must be maintained without end |
| **C2. Full rename, clean break, hard fork** | Every internal name; old names are removed; no upstream merges; fresh installs only                  | One large change in 2.0; HestiaCP fixes must be ported by hand                 |

## Decision

1. **Option C2.** ArcPanel is a hard fork. It no longer merges HestiaCP releases. Upstream fixes, security fixes first, are read and ported by hand. Outcome O5 is dropped.
2. **Every internal identifier gets an ArcPanel name.** This covers paths, configuration files and keys, shell variables and functions, command names, Debian packages, system users and groups, services, the gettext domain, PHP namespaces, the iptables chain, the API response header, JSON keys and markers written into third-party configuration files. The [full rename spec](../features/specs/2026-10-06-005-e9-full-rename-design.md) holds the mapping.
3. **No compatibility layer.** Old paths, command names, package names and the `hestia` service do not exist in 2.0. The option B aliases (`/usr/local/arcpanel` symlink, `v-*-arcpanel-*` wrappers) become the real names. The only exceptions are input formats: backups made by HestiaCP and VestaCP can still be restored or imported.
4. **Fresh installs only.** 2.0 has no in-place upgrade from ArcPanel 1.x or from HestiaCP. The 1.x → 2.0 upgrade step of E8 (`install/upgrade/arcpanel/2.0.0.sh`) is removed.
5. **One version.** `VERSION` in `arcpanel.conf` is the ArcPanel version. `ARCPANEL_VERSION`, `install/upstream.conf` and the upstream upgrade steps in `install/upgrade/versions/` up to 1.10.5 are removed. Upgrade steps of later ArcPanel versions go into `install/upgrade/versions/<version>.sh`.
6. **Attribution stays.** The copyright notices, the "Based on HestiaCP" line and the credits to HestiaCP and VestaCP stay, as GPLv3 requires.
7. **Ships in 2.0.**

## Consequences

- One very large change touches about 1,200 files. It is made by an ordered mapping script plus manual fixes, milestone by milestone, with BATS, smoke and e2e on a freshly installed server after each milestone. Every test server must be reinstalled.
- The rules "additive changes only" and "internal names stay Hestia" (AGENTS.md §5.2 and §5.6, design 01 §3.2) are replaced. ArcPanel owns every file, and `test/upstream-diff.sh`, its exception list, `.arcpanel/retired-paths` and the merge clean-up script are removed. The brand check fails on any "hestia" outside the attribution and backup-format exceptions.
- Third-party tools written for HestiaCP stop working without changes. That includes WHMCS and Blesta modules, scripts that call `/usr/local/hestia/bin` and API clients that read `Hestia-Exit-Code`. The release notes must say so.
- HestiaCP security advisories must be watched and ported by hand. The maintainer owns that duty, and the release checklist names it.
- Documentation written for HestiaCP (forum posts, upstream docs) no longer matches ArcPanel paths.
