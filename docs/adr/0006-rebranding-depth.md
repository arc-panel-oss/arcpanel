# ADR-0006 — Rebranding depth: visible + distribution + compatibility layer; own versioning

**Status:** Accepted (owner decision) · **Date:** 2026-10-04 · **Epic:** E9

## Context

ArcPanel starts from the HestiaCP 1.10.5 codebase and becomes its own product. The name "hestia" appears 14,061 times in 1,220 files. Of those, 1,320 are `/usr/local/hestia` paths and about 1,100 are `HESTIA_*` variable uses. Roadmap outcome O5 requires that upstream HestiaCP releases can still be merged in under a day. The servers also depend at runtime on HestiaCP infrastructure: `apt.hestiacp.com`, `ip.hestiacp.com` and raw files on the upstream GitHub repository. There are no existing HestiaCP servers to migrate. ArcPanel targets fresh installs only.

The upgrade machinery selects scripts in `install/upgrade/versions/` by comparing them to `VERSION`, and it reads the new version from `dpkg -l` for package `hestia`. Renaming the package or restarting the version number at `1.0.0` without a design would replay every upstream upgrade script.

## Options

| Option | What changes | Upstream merge cost | Effort |
| --- | --- | --- | --- |
| A. Visible + distribution only | UI, emails, installer, docs, domain, APT repo, package metadata | Low: string conflicts only | 2–4 weeks |
| **B. A + compatibility layer** | A, plus ArcPanel names for what admins see on the server: `arcpanel*` packages, `/usr/local/arcpanel` symlink, `$ARCPANEL`, `v-*-arcpanel-*` wrappers, `arcpanel` service; internals keep Hestia names | Low: same as A, wrappers are additive | A + 1–2 weeks |
| C. Full rename | Every path, variable, function, package and command, plus a migration script and an automated rename step for every upstream merge | High: every upstream patch must be rewritten | 6–9 weeks |

Version scheme options: own numbering from `1.0.0`; upstream number with a suffix (`1.10.5-arc.1`); calendar versioning (`2026.10.0`).

## Decision

1. **Option B.** ArcPanel names cover everything a user or administrator sees. Internal identifiers (`/usr/local/hestia`, `/etc/hestiacp`, `HESTIA_*`, function and class names, gettext domain `hestiacp`, `hestiaweb`) are not renamed. Old command, path and package names keep working.
2. **Own versioning from `1.0.0`.** `VERSION` keeps meaning the upstream base version (`1.10.5`), so upstream upgrade scripts keep working. The new key `ARCPANEL_VERSION` is what users see. ArcPanel upgrade steps live in `install/upgrade/arcpanel/`. The upstream version is read from a shipped `install/upstream.conf` instead of from `dpkg`.
3. **Own APT repository** at `apt.arcpanel.top`, built and signed in GitHub Actions. No runtime dependency on HestiaCP infrastructure.
4. **Fresh installs only.** In-place upgrade from HestiaCP (roadmap R4) is deferred.
5. User-visible output strings in `bin/`, `func/` and `install/` may be changed; comments, identifiers and paths may not.

Details are in the [rebranding spec](../features/specs/2026-10-04-001-arcpanel-rebranding-design.md).

## Consequences

- O5 holds: `bin/` and `func/` logic is unchanged, and merge conflicts are limited to output strings. `test/brand-check.sh` catches new "Hestia" strings after each merge.
- Each upstream merge must also update `install/upstream.conf`.
- Administrators see ArcPanel names, but internal paths such as `/usr/local/hestia` remain visible in logs, `ps` output and error messages. This is accepted.
- The `arcpanel` package declares `Provides`, `Conflicts` and `Replaces: hestia`, so it cannot be installed next to HestiaCP. That is intended.
- ArcPanel now operates its own release infrastructure: an APT host, a signing key and an IP lookup endpoint. Key rotation needs a runbook.
- Supersedes the roadmap's earlier "branding via White Label only" scope for E9. The roadmap rule "no deep rename of `HESTIA_*`" is confirmed, not changed.
