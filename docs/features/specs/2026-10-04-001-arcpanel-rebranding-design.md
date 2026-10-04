# ArcPanel rebranding — design

**Status:** Approved design (owner, 2026-10-04) · **Epic:** E9 (Sprint 0) · **Decision record:** [ADR-0006](../../adr/0006-rebranding-depth.md) · **Roadmap:** [§3 E9](../../roadmap.md)

## 1. Summary

ArcPanel is built on the HestiaCP 1.10.5 codebase. This spec turns every user-visible and distribution-level "Hestia" into "ArcPanel" in a four-week Sprint 0, adds a thin compatibility layer so administrators see ArcPanel names on the server, and leaves internal identifiers (`/usr/local/hestia`, `HESTIA_*`, `func/` function names) unchanged so upstream merges stay cheap.

## 2. Decisions taken in the brainstorm

| # | Question | Decision |
| --- | --- | --- |
| D1 | Depth of the rename | **Visible + distribution + compatibility layer.** New names for what an administrator sees; old internal names keep working and stay the implementation. |
| D2 | In-place upgrade from existing HestiaCP servers | **Not needed now.** Fresh installs only. Roadmap milestone R4 is deferred. |
| D3 | Brand identity | Domain **`arcpanel.top`**; GitHub **`arc-panel-oss/arcpanel`**; no logo or colours yet, so a brand kit is part of this spec. |
| D4 | Package distribution | **Own APT repository** at `apt.arcpanel.top`, packages built and signed in GitHub Actions. |
| D5 | Version scheme | **Own numbering starting at `1.0.0`**; the upstream base version is stored separately. |

## 3. Goals and non-goals

### Goals

- A fresh ArcPanel install shows the ArcPanel name, logo and colours in the web UI, notification emails, installer output, CLI messages and documentation.
- No runtime traffic to HestiaCP infrastructure: no `apt.hestiacp.com`, `ip.hestiacp.com`, `storage.hestiacp.com`, or `raw.githubusercontent.com/hestiacp` requests from an ArcPanel server.
- Administrators can use ArcPanel names on the server: `/usr/local/arcpanel`, `$ARCPANEL`, `v-*-arcpanel-*`, `systemctl … arcpanel`, `dpkg -l arcpanel`.
- Old names keep working, so upstream documentation, scripts and integrations still run.
- Upstream HestiaCP releases can still be merged; conflicts are limited to changed user-visible strings.

### Non-goals

- Renaming `/usr/local/hestia`, `/etc/hestiacp`, `HESTIA_*` variables, shell function names, PHP class names, or the gettext domain `hestiacp`.
- Rewriting code comments that mention Hestia.
- Upgrading an existing HestiaCP server to ArcPanel in place (R4, deferred).
- Renaming the `hestiaweb` system user or the `admin` defaults.
- Rewriting historical `CHANGELOG.md` entries.

## 4. Baseline (measured 2026-10-04)

| What | Count |
| --- | --- |
| Files that contain "hestia" (any case) | 1,220 |
| Occurrences of "hestia" | 14,061 |
| `/usr/local/hestia` path references | 1,320 |
| `HESTIA_*` variable uses | ~1,100 |
| "Hestia" in `web/templates` | 61 |
| `msgid` strings with "Hestia" in `web/locale/hestiacp.pot` | 5 (12 lines in total mention it) |
| Links to `hestiacp.com` hosts (excluding `docs/` and `CHANGELOG.md`) | 191 |
| Files linking to `github.com/hestiacp` | 34 |
| `v-*-hestia-*` commands | 9 |

Runtime dependencies on upstream infrastructure found in code:

| Location | Dependency |
| --- | --- |
| `install/hst-install-debian.sh`, `install/hst-install-ubuntu.sh` (`RHOST`) | `apt.hestiacp.com` package source and keyring `hestia-keyring.gpg` |
| `install/hst-install-*.sh`, `bin/v-update-sys-ip` | `ip.hestiacp.com` public IP lookup |
| `func/main.sh` (`HESTIA_GIT_REPO`) | `raw.githubusercontent.com/hestiacp/hestiacp` |
| `bin/v-update-white-label-logo` | downloads the default Hestia logo set from upstream GitHub on reset |
| `bin/v-change-sys-api` | downloads `web/api/index.php` from upstream GitHub |
| `bin/v-change-sys-release` | checks branches on upstream GitHub |
| `src/deb/hestia/postinst`, `func/upgrade.sh` | read the new version from `dpkg -l` for package `hestia` |
| `test/restore.bats` | test archives on `storage.hestiacp.com` |
| `crowdin.yml` + workflow | upstream translation project |

## 5. Naming rules

| Context | Name |
| --- | --- |
| Product name in UI, emails, docs, installer | **ArcPanel** (one word, capital A and P; never "Arc Panel", "Arcpanel" or "ArcPanel CP") |
| Attribution | "Based on HestiaCP" (About page, README, installer summary); "Based on HestiaCP 1.10.5" where a version is shown |
| Debian packages | `arcpanel`, `arcpanel-nginx`, `arcpanel-php`, `arcpanel-web-terminal` |
| Service | `arcpanel` |
| Server path alias | `/usr/local/arcpanel` |
| Environment variable alias | `ARCPANEL` |
| Command aliases | `v-*-hestia-*` → `v-*-arcpanel-*` (same suffix, `hestia` replaced by `arcpanel`) |
| Hosts | `arcpanel.top` (site and installer), `docs.arcpanel.top`, `apt.arcpanel.top`, `ip.arcpanel.top`, `storage.arcpanel.top` |
| Repository | `https://github.com/arc-panel-oss/arcpanel` |

## 6. Work packages

Each work package is one pull request. WP1 and WP5 can start on day 1; WP2 needs WP1's assets; WP3 and WP4 can run in parallel with WP2.

### WP1 — Brand kit (week 1)

1. Draft three directions. Each direction has a wordmark (SVG), a square icon (SVG), a light and dark palette (primary, primary-hover, neutral scale of 10 steps, success, warning, danger, info), and a font pairing (UI font + monospace) available under the SIL Open Font License.
2. Present the three directions side by side on one HTML page; the owner picks one.
3. Commit the chosen direction to `docs/brand/`:
   - `logo.svg`, `logo-dark.svg`, `icon.svg`, `logo-header.svg`
   - PNG exports: `icon-16.png`, `icon-32.png`, `icon-180.png`, `icon-192.png`, `icon-512.png`, `logo.png`
   - `favicon.ico` (16, 32, 48)
   - `tokens.css` — CSS custom properties for both themes
   - `brand.md` — usage rules: clear space, minimum size, colour values, do/don't, naming rules from §5
4. **Acceptance:** primary text/background pairs meet WCAG 2.2 AA (4.5:1) in light and dark themes; the icon is legible at 16 px.

### WP2 — Visible brand (R1, weeks 1–2)

1. **Assets.** Replace `web/images/logo.svg`, `logo.png`, `logo-header.svg`, `favicon.png` and `web/favicon.ico` with WP1 assets. Apply the WP1 primary colour and neutrals in the current theme stylesheet only (no layout changes; E2 owns the redesign).
2. **Name defaults.** The installer writes `APP_NAME='ArcPanel'` through the existing White Label settings. `FROM_NAME` stays empty, which already means "use `APP_NAME`", so a white-labelled name also reaches emails. Templates in `web/templates/` that hard-code "Hestia" read `$_SESSION["APP_NAME"]` instead, so an administrator can still white-label.
3. **Translations.** The repository ships only compiled catalogs (`web/locale/*/LC_MESSAGES/hestiacp.mo`); the `.po` sources live in Crowdin. Update the `msgid` lines in `web/locale/hestiacp.pot`, and rewrite every `.mo` catalog directly: replace "Hestia Control Panel", "HestiaCP" and "Hestia" with "ArcPanel" in both source and translated strings, and keep the translation of the msgid "ArcPanel" as "ArcPanel" (the brand name is not translated). Disable the Crowdin sync workflow and mark `crowdin.yml` as inactive in a comment until ArcPanel has its own translation project.
4. **CLI, installer and emails.** Change user-visible text in `bin/`, `func/` and `install/`: lines that are output (`echo`, `printf`, `v-add-user-notification` arguments, email subject and body). Comments, variable names, function names and paths are not touched. This includes the installer banner, the installer summary (adds "Based on HestiaCP 1.10.5"), the unsupported-OS message, and the upgrade email in `func/upgrade.sh`.
5. **Links.** Replace links to `hestiacp.com`, `www.hestiacp.com`, `docs.hestiacp.com`, `forum.hestiacp.com` and `github.com/hestiacp/hestiacp` with the matching ArcPanel URL from §5. Links with no ArcPanel equivalent yet (forum, donate) are removed; `issues` links point to `github.com/arc-panel-oss/arcpanel/issues`.
6. **Repository documents.** Rewrite `README.md`, `CONTRIBUTING.md`, `SECURITY.md` for ArcPanel; add an "ArcPanel 1.0.0" section at the top of `CHANGELOG.md` and leave the historical entries untouched. Update `package.json` `name` to `arcpanel` and `repository` to the ArcPanel repo. Update `.github/ISSUE_TEMPLATE/*`.
7. **Docs site.** Update the VitePress config in `docs/` (site title, logo, theme colour, base URL `docs.arcpanel.top`, edit links) and replace "Hestia"/"HestiaCP" in page text, except where a page explains the HestiaCP origin. Pages under `docs/docs/community/` that describe third-party Hestia add-ons keep their titles and get a note that they target HestiaCP.
8. **Attribution.** Keep `LICENSE` and every copyright header. Add "Based on HestiaCP" to the README and an About entry in the web UI that shows `ARCPANEL_VERSION` and "Based on HestiaCP `VERSION`".
9. **Acceptance:** the brand check from WP5 passes; screenshots of login, dashboard, About, a notification email and installer output show only ArcPanel names and the attribution line.

### WP3 — Distribution and versioning (R2, weeks 2–4)

#### Packages

1. Rename `src/deb/hestia` → `src/deb/arcpanel` (and the matching nginx, php, web-terminal directories) and set `Package:` to the new names. `arcpanel` declares `Provides: hestia`, `Conflicts: hestia`, `Replaces: hestia`. `arcpanel-nginx`, `arcpanel-php` and `arcpanel-web-terminal` declare the same three fields for their `hestia-*` counterparts and depend on `arcpanel`.
2. Update `src/hst_autocompile.sh` to produce the new package names. File paths inside the packages stay under `/usr/local/hestia`.
3. `arcpanel` `Version:` is `ARCPANEL_VERSION` (`1.0.0`). `arcpanel-nginx` and `arcpanel-php` keep the upstream nginx/PHP versions they bundle.

#### Versioning (see ADR-0006 for the reasoning)

1. `VERSION` in `/usr/local/hestia/conf/hestia.conf` keeps meaning **the upstream HestiaCP base version** (`1.10.5`). Upstream upgrade scripts in `install/upgrade/versions/` keep working when we merge a new upstream release.
2. New file `install/upstream.conf`, shipped in the package, contains `UPSTREAM_VERSION='1.10.5'`. It is updated as part of each upstream merge.
3. New config key `ARCPANEL_VERSION` in `hestia.conf`, written by the installer and by the ArcPanel upgrade routine.
4. `src/deb/arcpanel/postinst` and `func/upgrade.sh` read the new upstream version from `install/upstream.conf` instead of `dpkg -l … hestia`. This is required: with the package renamed, the current `dpkg` lookup returns nothing, and with `1.0.0` it would replay every upstream script from `1.1.0`.
5. ArcPanel upgrade steps live in `install/upgrade/arcpanel/<version>.sh`. A new function in a new file `func/arcpanel-upgrade.sh` runs, in `sort -V` order, every script newer than `ARCPANEL_VERSION` and not newer than the installed `arcpanel` package version, then writes `ARCPANEL_VERSION`. It runs after the upstream routine in `postinst`.
6. The UI, CLI output, update notifications and emails display `ARCPANEL_VERSION`. `v-list-sys-hestia-updates` reports `arcpanel*` packages.

#### APT repository

1. New workflow `.github/workflows/release.yml`, triggered by tags `v*`. The tag must equal the package version with `~` written as `-`: `1.0.0` → `v1.0.0` (stable), `1.0.1~beta1` → `v1.0.1-beta1` (beta). Steps:
   - builds all four packages with `hst_autocompile.sh` for Debian 12, Debian 13, Ubuntu 22.04, Ubuntu 24.04, Ubuntu 26.04 (the releases the installer supports), on `amd64` and `arm64`;
   - generates the repository with `reprepro` (`.github/scripts/build-apt-repo.sh`), component `main`, one distribution per codename read from each build container; stable is published at `https://apt.arcpanel.top/`, pre-releases at `https://apt.arcpanel.top/beta/`;
   - signs with the ArcPanel GPG key from an Actions secret (never committed, never printed in logs);
   - publishes with `rsync` over SSH to the host serving `apt.arcpanel.top`.
2. Public key at `https://apt.arcpanel.top/arcpanel.gpg`; installed to `/usr/share/keyrings/arcpanel-keyring.gpg`.
3. The installer writes `/etc/apt/sources.list.d/arcpanel.list` and never writes `hestia.list`. `RHOST` becomes `apt.arcpanel.top`.
4. A release, key-rotation and revocation runbook is added at `docs/docs/contributing/releases.md`.

#### Installer

1. `https://arcpanel.top/install.sh` serves `install/hst-install.sh` from the latest stable tag. The `release` branch points to the latest stable tag; the installer compares its own version with `src/deb/arcpanel/control` on that branch, and the logo reset and API download read from it. Internal file names (`hst-install*.sh`) stay for upstream compatibility; documentation shows only the public URL.
2. `--with-debs` keeps working for offline installs.
3. The installer exits with a clear message naming `apt.arcpanel.top` and suggesting `--with-debs` when the repository is unreachable.

#### Other upstream endpoints

| Current | New |
| --- | --- |
| `ip.hestiacp.com` | `ip.arcpanel.top` (returns the caller's IP as plain text); fallback `https://api.ipify.org` when the first request fails or times out after 5 s |
| `HESTIA_GIT_REPO` value | `https://raw.githubusercontent.com/arc-panel-oss/arcpanel` (variable name unchanged) |
| Hard-coded upstream GitHub URLs in `v-update-white-label-logo`, `v-change-sys-api`, `v-change-sys-release`, `v-update-sys-hestia-git` | use `HESTIA_GIT_REPO` or the ArcPanel repository URL |
| `storage.hestiacp.com/testing/data/*` in `test/restore.bats` | `storage.arcpanel.top/testing/data/*` (archives copied once) |

**Acceptance:** a clean Debian 12 VM installs from the `beta` suite with `install.sh`, `dpkg -l` lists only `arcpanel*` panel packages, `hestia.conf` contains `VERSION='1.10.5'` and `ARCPANEL_VERSION='1.0.0'`, and a network capture during install and first login shows no request to a `hestiacp.com` host or to `github.com/hestiacp`.

### WP4 — Compatibility layer (R3 option B, weeks 3–4)

1. **Path.** The installer creates the symlink `/usr/local/arcpanel → /usr/local/hestia`.
2. **Environment.** The installer writes `/etc/profile.d/arcpanel.sh` that exports `ARCPANEL=/usr/local/hestia` and `HESTIA` with the same value. The existing `/etc/profile.d/hestia.sh` is kept and still adds the `bin` directory to `PATH`, so it is not added twice. The installer also creates the `/usr/local/arcpanel` symlink, because the package scripts exit early on a fresh install.
3. **Commands.** For each of the nine commands below, add `bin/v-*-arcpanel-*`: a wrapper that `exec`s the original with all arguments, so exit codes, output and logging are identical. The wrappers carry the standard `v-*` header comments so `hst_generate_clidocs.sh` documents them, marked "alias of …".

   | Existing | New alias |
   | --- | --- |
   | `v-add-cron-hestia-autoupdate` | `v-add-cron-arcpanel-autoupdate` |
   | `v-change-sys-hestia-ssl` | `v-change-sys-arcpanel-ssl` |
   | `v-delete-cron-hestia-autoupdate` | `v-delete-cron-arcpanel-autoupdate` |
   | `v-list-sys-hestia-autoupdate` | `v-list-sys-arcpanel-autoupdate` |
   | `v-list-sys-hestia-ssl` | `v-list-sys-arcpanel-ssl` |
   | `v-list-sys-hestia-updates` | `v-list-sys-arcpanel-updates` |
   | `v-update-sys-hestia` | `v-update-sys-arcpanel` |
   | `v-update-sys-hestia-all` | `v-update-sys-arcpanel-all` |
   | `v-update-sys-hestia-git` | `v-update-sys-arcpanel-git` |

   The web UI and other commands keep calling the original names; no sudoers change is needed.

4. **Service.** Ship a native unit `arcpanel.service` (`Type=forking`, `ExecStart`/`ExecStop`/`ExecReload` call the existing `/etc/init.d/hestia`, `Alias=hestia.service`, `WantedBy=multi-user.target`). The SysV script stays for upstream compatibility. Required behaviour: `systemctl {status,restart} arcpanel` and `systemctl {status,restart} hestia` act on the same single unit, and `systemd-sysv-generator` does not create a second `hestia.service`. If the alias approach fails this test on any supported release, the fallback is a native `hestia.service` with `Alias=arcpanel.service`; the externally visible behaviour is the same.
5. **Acceptance:** BATS tests in §8 pass on every supported release.

### WP5 — Brand guard in CI (day 1, finished with WP2)

1. New script `test/brand-check.sh` scans:
   - `web/templates/**`, `web/locale/hestiacp.pot`, `web/inc/mail-wrapper.php`, `docs/**/*.md` (except `docs/docs/community/**`), `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `.github/ISSUE_TEMPLATE/**` — every line;
   - `bin/**`, `func/**`, `install/**` — only lines that produce output: lines containing `echo`, `printf`, `v-add-user-notification`, `subject` or `message`.
2. It fails on the case-sensitive pattern `Hestia` (which matches `HestiaCP` and `Hestia Control Panel`) and on any `hestiacp.com` URL, unless the line matches an entry in `test/brand-allowlist.txt`. Entries have the form `<path-glob> :: <fixed text>` (`*` as text ignores the whole file), each with a comment saying why.
3. Lower-case `hestia` (paths, variables, package internals) is not checked.
4. Runs in `.github/workflows/lint.yml` and as a `lint-staged` hook for staged files.

## 7. Error handling and edge cases

| Situation | Behaviour |
| --- | --- |
| `apt.arcpanel.top` unreachable during install | Installer stops before changing the system, prints the host name and the `--with-debs` alternative. |
| `ip.arcpanel.top` unreachable | Fall back to `api.ipify.org`; if both fail, keep the current behaviour for a failed lookup (use the local interface address and warn). |
| White Label `APP_NAME` changed by the administrator | The UI shows the administrator's name; the About page still shows "ArcPanel" and the attribution. |
| A third-party script checks `dpkg -l hestia` or depends on package `hestia` | Satisfied through `Provides: hestia`. |
| A third-party script calls `/usr/local/hestia/bin/v-*` or `v-update-sys-hestia` | Unchanged; works. |
| Upstream merge brings new strings with "Hestia" | `brand-check.sh` fails in CI; the merger rewrites or allowlists them. |
| Upstream merge brings a new `install/upgrade/versions/<x>.sh` | Runs as today because `VERSION` keeps its upstream meaning; the merger updates `install/upstream.conf`. |
| Signing key leaked | Follow the runbook: revoke, publish a new key, document the manual key replacement (an `arcpanel-keyring` package is backlog). |

## 8. Testing

| Test | Where | Covers |
| --- | --- | --- |
| `test/brand-check.sh` | `lint.yml`, pre-commit | WP2 strings and links, regression after upstream merges |
| Existing BATS suite on a fresh install from the `beta` suite | `pr-docker-bats.yml` / test VMs, Debian 12 and Ubuntu 24.04 | Nothing broke with renamed packages and versioning |
| New `test/arcpanel-compat.bats` | same | `/usr/local/arcpanel` resolves; `$ARCPANEL` set in a login shell; each `v-*-arcpanel-*` alias returns the same output and exit code as its original; `systemctl status arcpanel` and `hestia` show one unit; `dpkg -s hestia` resolves through `Provides` |
| New `test/arcpanel-version.bats` | same | Fresh install has `VERSION='1.10.5'`, `ARCPANEL_VERSION='1.0.0'`; a fake `1.0.1` package runs only `install/upgrade/arcpanel/1.0.1.sh` and no upstream script |
| Install smoke test on all five releases × two architectures | `release.yml` before publishing to `stable` | Packages install from the repo; panel answers on port 8083 |
| No-upstream-traffic check | manual for 1.0.0, captured with `tcpdump` on a test VM | Goal "no runtime traffic to HestiaCP infrastructure" |
| Visual review | manual, screenshots attached to the WP2 PR | Login, dashboard, About, notification email, installer output, docs home |

## 9. Schedule

| Week | Track A | Track B |
| --- | --- | --- |
| 1 | WP1 brand kit (directions, owner choice, assets) | WP5 brand check script + allowlist; WP2 strings, translations, links (no assets needed) |
| 2 | WP2 assets, docs site, repository documents | WP3 package rename, versioning, `release.yml` against a staging host |
| 3 | WP4 compatibility layer | WP3 APT repository, installer, endpoints |
| 4 | Fix-ups from the full test run; tag `v1.0.0-beta.1` | Install tests on all releases; tag `v1.0.0` after the owner's review |

With one developer, Track B follows Track A and Sprint 0 takes about six weeks; WP1 and WP2 still come first.

## 10. Prerequisites owned by people

These are not open design questions; they are actions only the owner can take before the named week:

| Before | Action |
| --- | --- |
| Week 2 | DNS records for `apt`, `ip`, `docs`, `storage` under `arcpanel.top`, and a host with nginx and SSH access for publishing |
| Week 2 | Generate the ArcPanel release GPG key offline; store the signing subkey and the publish SSH key as GitHub Actions secrets |
| Week 3 | Copy the restore test archives to `storage.arcpanel.top` |
| Week 4 | Review and approve the `v1.0.0` tag |

## 11. Out of scope and follow-ups

- **R4 in-place upgrade from HestiaCP** — revisit when there is a real server to migrate; the compatibility layer and `Provides: hestia` already make it easier.
- **`arcpanel-keyring` package** — later backlog.
- **Own Crowdin project** — when the first community translator asks.
- **Deep internal rename (option C in ADR-0006)** — not planned; would need a new ADR.
