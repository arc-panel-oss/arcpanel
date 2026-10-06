# E9 R5 — Full rename of internal names and hard fork — design

**Status:** Approved (2026-10-06) · **Epic:** E9 (milestone R5) · **Decision:** [ADR-0007](../../adr/0007-full-rename-and-hard-fork.md) (supersedes decisions 1, 2 and 5 of [ADR-0006](../../adr/0006-rebranding-depth.md)) · **Builds on:** [rebranding spec](./2026-10-04-001-arcpanel-rebranding-design.md), [E8 spec](./2026-10-06-004-e8-legacy-retirement-design.md)

## 1. Summary

ArcPanel 2.0 drops every internal HestiaCP name and stops merging upstream:

- Paths, configuration files, variables, functions, commands, packages, system users, services, the gettext domain, PHP namespaces and runtime identifiers get ArcPanel names (§5).
- The upstream-merge machinery goes: upstream upgrade steps up to 1.10.5, the upstream-diff check, the retired-paths list and merge helper, `install/upstream.conf`. The option B compatibility layer goes as well (§6).
- `VERSION` becomes the ArcPanel version. 2.0 is fresh-install only; the 1.x → 2.0 upgrade step of E8 is removed (§7).
- The rename is made by a reviewed mapping and a script, then by manual fixes per layer, and verified on freshly installed servers (§8, §10).

Attribution stays: copyright files, the "Based on HestiaCP" line, the credits to HestiaCP and VestaCP (GPLv3).

The inventory of 2026-10-06 found about 14,400 occurrences in 1,152 tracked files: bin 9,045, install 1,819, test 975, docs 820, func 659, src 509, lib 223, web-app 112, .github 61.

## 2. Decisions

| #   | Question                         | Decision                                                                                                                                                                                                                                                                                               |
| --- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| D1  | Depth                            | **Everything internal** (ADR-0007 C2). Only attribution, input formats of other products (§5.9) and names owned by third parties (§5.10) keep "hestia" or "vesta".                                                                                                                                     |
| D2  | Compatibility                    | **None.** No `/usr/local/hestia` symlink, no `v-*-hestia-*` aliases, no `hestia` service alias, no `Hestia-Exit-Code` next to the new header.                                                                                                                                                          |
| D3  | Installed base                   | **Fresh installs only.** No in-place rename of existing servers. `install/upgrade/arcpanel/2.0.0.sh` and the "Upgrading from 1.x" / "Rolling back" parts of CHANGELOG 2.0.0 are removed. Test servers are reinstalled.                                                                                 |
| D4  | Upstream                         | **Hard fork.** No upstream merges. HestiaCP security advisories are read and ported by hand (release checklist).                                                                                                                                                                                       |
| D5  | Version                          | `VERSION` in `arcpanel.conf` = ArcPanel version (`2.0.0`). `ARCPANEL_VERSION`, `install/upstream.conf`, `UPSTREAM_VERSION`, `ARCPANEL_BASE_VER` are removed. Later upgrade steps go into `install/upgrade/versions/<version>.sh`, run by `func/upgrade.sh`. `func/arcpanel-upgrade.sh` merges into it. |
| D6  | Release                          | Part of **2.0**, together with E8.                                                                                                                                                                                                                                                                     |
| D7  | Method                           | A committed mapping file and a rename script (§8) make the mechanical change in one commit per layer. Manual fixes follow. No hand edits of the mechanical part.                                                                                                                                       |
| D8  | Backup format                    | New backups use `./arcpanel/` inside the archive. `v-restore-user` and the restic restore commands detect `./arcpanel/`, `./hestia/` (HestiaCP and ArcPanel 1.x) and `./vesta/`, and convert paths of the last two.                                                                                    |
| D9  | Conflicts with HestiaCP packages | `src/deb/*/control` keeps `Conflicts: hestia, hestia-nginx, …` (both stacks bind the same ports and users) but drops `Provides` and `Replaces`.                                                                                                                                                        |
| D10 | Brand check                      | Inverted: it fails on **any** `hestia`, `hst_`, `hst-` or `vesta` in tracked text files outside a short allowlist (attribution, §5.9, §5.10, vendored trees). It replaces the upstream-diff job in CI.                                                                                                 |

## 3. Milestones

| #   | Milestone                               | Content                                           | Done when                                                                                                                                                                                      |
| --- | --------------------------------------- | ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M0  | Baseline                                | §10.1                                             | E8 is closed and pushed; a fresh install of `develop` on a clean WSL Ubuntu 24.04 distribution records the BATS, smoke and Playwright numbers                                                  |
| M1  | Remove merge machinery and compat layer | §6, §7                                            | The files of §6 are gone; `VERSION` is the ArcPanel version; the 9 wrappers and `arcpanel-compat.bats` are gone; BATS version and upgrade cases rewritten and green on the test server         |
| M2  | Mapping and rename script               | §8                                                | `scripts/rename/mapping.tsv` reviewed; `scripts/rename/apply.sh --dry-run` lists every file and path change; a test proves the order rules of §8.2 on fixtures                                 |
| M3  | Shell layer                             | §5.1–§5.7 for `bin/`, `func/`, `install/`, `lib/` | Mechanical commit plus manual fixes; `test/shellcheck.sh` green; `git grep` finds no old name in these trees outside the allowlist                                                             |
| M4  | Packaging, build and CI                 | §5.4, §9                                          | `src/` renamed; packages build as `arcpanel*`; CI workflows renamed; `arcpanel-nginx -t` passes                                                                                                |
| M5  | Panel app                               | §5.8                                              | `web-app/` uses the new names; Pest and Vitest green                                                                                                                                           |
| M6  | Runtime identifiers and formats         | §5.6, §5.9                                        | Firewall chain, API header, JSON keys, DNS cluster values, markers, task kind renamed; backup detection of D8 in place with BATS cases for all three formats                                   |
| M7  | Tests, docs, brand check                | §11, §12                                          | BATS suites, e2e setup, `dev-sync.sh` renamed; documents of §12 updated; the inverted brand check is green and runs in CI                                                                      |
| M8  | Verification                            | §10.2                                             | A fresh install from the built debs passes all ArcPanel BATS suites, the upstream `test.bats` (same failures as M0 or fewer), smoke and the full Playwright suite; the manual checklist passes |

M3 to M6 leave the tree in a state that does not install until all four are done. They are committed one by one, but only M8 verifies them on a server.

## 4. Scope

### In scope

- Every tracked text file except the vendored trees (`install/deb/pga/phppgadmin/`, `lib/quoteshellarg/` keeps its code but gets the new namespace, `test/test_helper/`).
- File and directory names (74 tracked names contain hestia or hst).
- The 43 binary `hestiacp.mo` catalogues are renamed, never edited.

### Out of scope

- In-place migration of installed servers (D3).
- The release infrastructure of E9 (APT host, signing key, DNS).
- Replacing phpPgAdmin with Adminer (E9 R6).
- Rewording comments beyond the name change.

### Done when

- `git grep -iE 'hestia|hst_|hst-|vesta'` finds only allowlisted lines.
- A fresh install shows no old name in paths, `ps`, `systemctl`, logs, package names, CLI output or the UI.
- M8 passes.

## 5. Mapping

The authoritative list is `scripts/rename/mapping.tsv` (M2). This section fixes the names.

### 5.1 Paths and files on the server

| Old                                                                    | New                                                                            |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `/usr/local/hestia`                                                    | `/usr/local/arcpanel` (a real directory; the option B symlink goes)            |
| `/etc/hestiacp/hestia.conf`, `local.conf`, `hooks/`                    | `/etc/arcpanel/arcpanel.conf`, `local.conf`, `hooks/` (next to `arcpanel.env`) |
| `$ARCPANEL/conf/hestia.conf`                                           | `$ARCPANEL/conf/arcpanel.conf`                                                 |
| `/var/log/hestia`                                                      | `/var/log/arcpanel`                                                            |
| `/etc/profile.d/hestia.sh` (and `arcpanel.sh` of option B)             | `/etc/profile.d/arcpanel.sh` only                                              |
| `/etc/sudoers.d/hestiaweb` (and `arcpanel` of option B)                | `/etc/sudoers.d/arcpanelweb` only                                              |
| `/etc/cron.d/hestia-{proc,ssl,sftp,quota-reboot}`                      | `/etc/cron.d/arcpanel-{proc,ssl,sftp,quota-reboot}`                            |
| `/etc/logrotate.d/hestia`                                              | `/etc/logrotate.d/arcpanel`                                                    |
| `nginx/sbin/hestia-nginx`, `php/sbin/hestia-php`                       | `nginx/sbin/arcpanel-nginx`, `php/sbin/arcpanel-php`                           |
| `/run/hestia-{php.sock,php.pid,nginx.pid,firewall.lock}`               | `/run/arcpanel-{php.sock,php.pid,nginx.pid,firewall.lock}`                     |
| `/etc/init.d/hestia`, `arcpanel.service` with `Alias=hestia.service`   | `arcpanel.service` with its own start script, no alias                         |
| `hestia-web-terminal.service`, `hestia-iptables.service`               | `arcpanel-web-terminal.service`, `arcpanel-iptables.service`                   |
| `/etc/netplan/60-hestia.yaml`, `sshd_config.d/hestia-kex.conf`         | `60-arcpanel.yaml`, `arcpanel-kex.conf`                                        |
| Apache `hestia-status`, `hestia-event.conf`; `conf.d/hestia.conf`      | `arcpanel-status`, `arcpanel-event.conf`; `conf.d/arcpanel.conf`               |
| fail2ban action, filter and jail `hestia`, `hestia-iptables`           | `arcpanel`, `arcpanel-iptables`                                                |
| `/root/hst_backups`, `hst_install_backups`, `hst_nginx_backups`        | `/root/arcpanel_backups`, `arcpanel_install_backups`, `arcpanel_nginx_backups` |
| `/tmp/hestiacp-src`, `/tmp/hestia-*`, `/var/lib/hestiacp-build-chroot` | `/tmp/arcpanel-src`, `/tmp/arcpanel-*`, `/var/lib/arcpanel-build-chroot`       |
| `$PMA/hestia-sso.php`, `hestia-sso.inc.php`                            | `arcpanel-sso.php`, `arcpanel-sso.inc.php`                                     |
| `~/.ssh/hst-filemanager-key`                                           | `~/.ssh/arcpanel-filemanager-key`                                              |

### 5.2 Configuration and variables

- `$HESTIA` → `$ARCPANEL` and `HESTIA_<X>` → `ARCPANEL_<X>` for every name of the inventory (`HESTIA_INSTALL_DIR`, `HESTIA_BACKUP`, `HESTIA_CMD`, `HESTIA_PHP`, `HESTIA_RESERVED_CONF_KEYS`, …).
  - Collisions are resolved first, by hand (§8.2): `HESTIA_INSTALL_VER` and `ARCPANEL_INSTALL_VER` become one `ARCPANEL_INSTALL_VER`; `HESTIA_VERSION` and `ARCPANEL_VERSION` become `VERSION`.
- Build variables: `BUILD_DIR_HESTIA*` → `BUILD_DIR_ARCPANEL*`.
- Lower-case variables: `hst_*` → `arc_*`; `hestia_<x>` → `arcpanel_<x>`.
- The keys inside `arcpanel.conf` keep their names; none of them contains "HESTIA" today. The `VERSION` change is D5.
- `ARCPANELSID` and `arcpanel_session` stay as they are.
- The panel app: `config/arc.php` defaults to `/usr/local/arcpanel` for every path, and `ProcessArcCommand` passes `ARCPANEL` in the environment.

### 5.3 Functions, commands and scripts

- `check_hestia_demo_mode` → `check_arcpanel_demo_mode` (442 call sites), `is_hestia_package` → `is_arcpanel_package`, `syshealth_adapt_hestia_nginx_listen_ports` → `syshealth_adapt_arcpanel_nginx_listen_ports`, PHP `hst_add_history_log` → `arc_add_history_log`.
- The 9 `v-*-hestia-*` commands become `v-*-arcpanel-*`. Their option B wrappers are deleted first (M1), so the renamed files take their place.
- Task kind `hestia-update` → `arcpanel-update`.
- Repository scripts:
  - `install/hst-install{,-debian,-ubuntu}.sh` → `install/arcpanel-install{,-debian,-ubuntu}.sh`; the published installer URL changes accordingly.
  - `src/hst_autocompile.sh` → `src/arcpanel-build.sh`, its flag `--hestia` → `--arcpanel`.
  - `src/hst_bootstrap_install.sh` → `src/arcpanel-bootstrap-install.sh`.
  - `src/hst_generate_clidocs.sh` → `src/arcpanel-clidocs.sh`.
  - `lib/locale/hst_scan_i18n.sh`, `hst_convert_po2mo.sh` → `arc-scan-i18n.sh`, `arc-convert-po2mo.sh`.

### 5.4 Packages, users and services

- **Packages:** `arcpanel`, `arcpanel-nginx`, `arcpanel-php`, `arcpanel-web-terminal`. Build directories and `*.deb` globs follow. Control files keep `Conflicts` with the `hestia*` packages and drop `Provides` / `Replaces` (D9).
- **npm package:** `@hestiacp/web-terminal-ws` → `@arcpanel/web-terminal-ws`.
- **System users:** `hestiaweb` → `arcpanelweb`, `hestiamail` → `arcpanelmail`.
  - Group `hestia-users` → `arcpanel-users`.
  - The nginx and php-fpm users, the sudoers rule, the crontab path (`/var/spool/cron/crontabs/arcpanelweb`) and `WEB_USER` in `arcpanel.env` follow.
- **Services:** the service `hestia` → `arcpanel`; `ServerPolicy::PROTECTED_SERVICES` and `PanelUpdates::PACKAGES` follow.

### 5.5 PHP and translations

- **Namespaces:** `Hestia\` (Quick Install, `lib/quickinstall/composer.json` PSR-4) → `ArcPanel\`; classes `HestiaApp`, `HestiaCommandResult` → `ArcPanelApp`, `ArcPanelCommandResult`.
- **Vendored helper:** `Hestiacp\quoteshellarg` → `ArcPanel\quoteshellarg` (vendored copy plus 3 consumers; the README records the change).
- **phpMyAdmin SSO:** class `Hestia_API` → `ArcPanel_API`.
- **Constants:** `HESTIA_CMD` and `HESTIA_DIR_BIN` → `ARCPANEL_CMD` and `ARCPANEL_DIR_BIN`.
- **gettext domain:** `hestiacp` → `arcpanel`. The catalogue files become `lib/locale/arcpanel.pot` and `*/LC_MESSAGES/arcpanel.mo` (renamed; the `.pot` is regenerated).
  - Consumers follow: `I18nImport`, `GettextCatalogTest`, `crowdin.yml`, the scan scripts.
  - `msgid "HESTIA"` (firewall chain label) → `"ARCPANEL"`.

### 5.6 Runtime identifiers

| Old                                                                                                                                         | New                                                            | Consumers                                                                          |
| ------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Firewall chain `HESTIA`, `fail2ban-HESTIA`, rule `COMMENT='HESTIA'`, port var `hestiaport`                                                  | `ARCPANEL`, `fail2ban-ARCPANEL`, `COMMENT='ARCPANEL'`          | `v-add-firewall-chain`, `v-change-sys-port`, jail.local, `FirewallService::CHAINS` |
| API response header `Hestia-Exit-Code`                                                                                                      | `ArcPanel-Exit-Code`                                           | `lib/api/index.php` (external clients: CHANGELOG)                                  |
| JSON key `HESTIA` of `v-list-sys-info` (base version)                                                                                       | removed (D5; `VERSION` is the ArcPanel version)                | `DashboardService`, fixture                                                        |
| JSON object `HESTIA` of `v-list-sys-arcpanel-ssl`                                                                                           | `ARCPANEL`                                                     | `ServerSettings`, test                                                             |
| `DNS_CLUSTER_SYSTEM` values `hestia`, `hestia-zone`                                                                                         | `arcpanel`, `arcpanel-zone`                                    | the remote DNS commands, `DnsService`                                              |
| Temporary MySQL users `hestia_sso_<rand>`, SSO parameter `hestia_token`, `%API_HESTIA_PORT%`                                                | `arcpanel_sso_<rand>`, `arcpanel_token`, `%API_ARCPANEL_PORT%` | `v-add-database-temp-user`, `v-add-sys-pma-sso`, `DbAdminLinks`                    |
| Roundcube password driver `hestia` (file, class `rcube_hestia_password`, `password_hestia_*`)                                               | `arcpanel` (`rcube_arcpanel_password`, `password_arcpanel_*`)  | `install/common/roundcube/`                                                        |
| Markers `# Hestia SFTP Chroot`, `# Dovecot conf file modified by Hestia #`, `# Configuration added by Hestia`, `//Add Hestia SSO code here` | same text with `ArcPanel`                                      | writers and readers of the inventory §11                                           |
| openssl section `hestia_openssl_sect`                                                                                                       | `arcpanel_openssl_sect`                                        | installers                                                                         |

### 5.7 Comments and banners

- The `# Hestia #` section banners (about 630) and comments that use the name for the product say `ArcPanel`.
- Comments that cite upstream history ("ported from HestiaCP …", issue links) keep the name and are allowlisted.

### 5.8 Panel app

These change in `web-app/`:

- `config/arc.php` defaults.
- `ProcessArcCommand` environment.
- The command names in `PanelUpdates`, `ServerSettings` and `DashboardService`.
- The firewall chain, the DNS value, the SSO link and the task kind.
- `ServiceConfigs` (`arcpanelweb`).
- The two Blade hints that show `/usr/local/hestia` paths.
- Fixtures: directory `v-list-sys-hestia-updates` → `v-list-sys-arcpanel-updates`, and JSON keys.
- Pest cases that assert the old names.

"Based on HestiaCP" in the shell and the guest layout stays.

### 5.9 Input formats that keep the old names

- **Backup archives of HestiaCP and ArcPanel 1.x** (`./hestia/` subdirectories) and **VestaCP** (`./vesta/`). They are detected and restored (D8). The cron path rewrite of `v-restore-user` changes from `s/vesta/hestia/` to map `/usr/local/vesta` and `/usr/local/hestia` to `/usr/local/arcpanel`.
- **Remote test archives** on `storage.arcpanel.top` keep their names and content.
- **Import** of cPanel and DirectAdmin backups is unaffected apart from the credits.

### 5.10 Names owned by third parties

These keep their names; they are allowlisted:

- The SnappyMail plugin `change-password-hestia` and its `driver_hestia_*` and `hestia_host` / `hestia_port` keys.
- The legacy Rainloop `plugin-hestia-change-password.ini`.
- The phpPgAdmin version string `7.14.6-hestiacp`.
- The PrestaShop download parameter `source=hestiacp`.
- Upstream issue links.

## 6. Removed

- `install/upgrade/versions/*.sh` (95 upstream steps up to 1.10.5) and the code of `func/upgrade.sh` that selects them by the upstream `VERSION`. The directory stays for ArcPanel steps after 2.0.
- `install/upgrade/arcpanel/` (1.1.0.sh, 2.0.0.sh, README.txt), `func/arcpanel-upgrade.sh` (merged into `func/upgrade.sh`), `install/upstream.conf`.
- `test/upstream-diff.sh`, `test/upstream-exceptions.txt`, `test/upstream-base.txt`, `.arcpanel/retired-paths`, `scripts/upstream-merge-cleanup.sh`, the `upstream-diff` job of `lint.yml`.
- The option B compatibility layer:
  - the `/usr/local/arcpanel` symlink lines;
  - the second profile script and sudoers rule;
  - `Alias=hestia.service`;
  - the 9 `v-*-arcpanel-*` wrappers;
  - `test/arcpanel-compat.bats`.
- The Crowdin workflow condition `hestiacp/hestiacp` (the workflow stays inactive until ArcPanel has its own Crowdin project).

## 7. Versioning and upgrades after 2.0

- The installers write `VERSION='2.0.0'` and `RELEASE_BRANCH`.
- `v-list-sys-info` prints `VERSION`, `RELEASE`, `ARCH`, `OS`. `v-list-sys-config` drops `ARCPANEL_VERSION`.
- `func/upgrade.sh` runs `install/upgrade/versions/<v>.sh` for every `<v>` greater than `VERSION` and not greater than the package version. That is the upstream mechanism, without the hardcoded path, which now comes from `$ARCPANEL`.
- The release workflow compares the tag with `src/deb/arcpanel/control` and the installers (`ARCPANEL_INSTALL_VER`).

## 8. Rename tooling (M2)

### 8.1 Files

- `scripts/rename/mapping.tsv`: one rule per line: `kind` (`path`, `text`), old, new, scope (glob list). Comment lines say why.
- `scripts/rename/keep.txt`: allowlisted fixed strings and globs (attribution, §5.9, §5.10, vendored trees). The brand check reads the same file after M7.
- `scripts/rename/apply.sh [--dry-run]`:
  - renames files with `git mv` first;
  - then rewrites text files in place, rules in file order;
  - skips binary files and `keep.txt` matches;
  - prints a summary per rule (files, lines).

### 8.2 Order rules

1. **Longer names before their prefixes:** `/etc/hestiacp` before `hestiacp` before `hestia`; `HestiaCP` before `Hestia`; `hestia-nginx`, `hestia-php`, `hestia-users`, `hestia-web-terminal`, `hestiaweb`, `hestiamail` before `hestia`; `HESTIA_` before `HESTIA`.
2. **Collisions are fixed by hand before the script runs:** the version variables, the `HESTIA`/`ARCPANEL` JSON keys, the option B wrappers, profile script and sudoers rule, and the `Provides` lines.
3. **Case is kept:** `hestia` → `arcpanel`, `Hestia` → `ArcPanel`, `HESTIA` → `ARCPANEL`.
4. **Known bugs that a rename would carry along are fixed in M1:**
   - `mkdir -p HESTIA_BACKUP` without `$` in `src/deb/nginx/preinst`;
   - the misdirected upstream issue link in the awstats prerotate script;
   - the Roundcube driver `@author` line.

## 9. Packaging, build and CI (M4)

- `src/deb/hestia/` is already `src/deb/arcpanel/`. Control files follow D9, maintainer scripts the new paths and users, `src/deb/nginx/hestia` becomes the `arcpanel` start script used by `arcpanel.service`.
- `src/arcpanel-build.sh` builds `arcpanel_*`, `arcpanel-nginx_*`, `arcpanel-php_*`, `arcpanel-web-terminal_*`. The `configure` prefixes and the PHP-FPM socket follow §5.1.
- **Workflows:**
  - `pr-docker-bats.yml`: container `arcpanel-ci`, `.github/docker/arcpanel-ci.Dockerfile`, `/arcpanel-git`, the new installer and build names.
  - `release.yml`: the installer name.
  - `lint.yml`: drops `upstream-diff`, keeps the brand check (inverted).
  - The bug report template path.

## 10. Verification

### 10.1 Baseline (M0)

A clean WSL Ubuntu 24.04 distribution gets `develop` at the end of E8 through the regular installer with locally built debs. The ArcPanel BATS suites, the upstream `test.bats`, the smoke script and Playwright run there. The numbers go into the implementation notes.

### 10.2 Final run (M8)

The same, with the renamed debs, on a new clean distribution, then:

- `git grep` gate (§4, done when); `ps -ef`, `systemctl list-units`, `ls /usr/local /etc /var/log /etc/cron.d /etc/sudoers.d` and `dpkg -l | grep -i hestia` show no old name;
- backup and restore of a user, and restore of the HestiaCP and VestaCP sample archives (`test/restore.bats`);
- firewall with fail2ban ban and unban; the API with an access key (header name); phpMyAdmin SSO; Roundcube password change; DNS cluster sync between two renamed servers if a second distribution is available, else BATS only;
- the web terminal; Quick Install of WordPress.

Pest and Vitest run on the workstation.

## 11. Testing

| Layer      | What                                                                                                                                                                                              |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Rename     | Fixture test for `apply.sh`: order rules, case, keep list, binary skip                                                                                                                            |
| BATS       | All suites with new paths and names; new cases: backup format detection (arcpanel/hestia/vesta), `VERSION` handling and the upgrade-step selection, header `ArcPanel-Exit-Code`, chain `ARCPANEL` |
| Pest       | Updated fixtures and assertions; a test that `config/arc.php` has no hestia path                                                                                                                  |
| Playwright | Unchanged flows on the renamed install                                                                                                                                                            |
| CI         | Inverted brand check; ShellCheck; Prettier; Biome; markdownlint                                                                                                                                   |

## 12. Documentation changes

- **ADR-0007** (new) and the status line of ADR-0006.
- **AGENTS.md:**
  - §2 and §4: names and the hard fork.
  - §5: rule 2 "additive changes only" and rule 6 "internal names stay Hestia" are replaced by "ArcPanel owns every file; keep `v-*` conventions; no old names".
  - §6, §8: commands, paths and the installer name.
- **CLAUDE.md:** wording that mentions HestiaCP internals.
- **README.md:** compatibility names, repository map, install command, "do not rename HestiaCP internals".
- **Design documents:**
  - **01:** architecture, §3.2 replaced by the hard-fork rules.
  - **02**, **11** and **12:** paths, users, the upstream-diff job, the brand check.
  - **13:** every path and the `arcpanel.conf` name.
  - **15:** paths, if any.
- **Roadmap:** O5 dropped, E9 R3 and R5 status, risks.
- **CHANGELOG 2.0.0:**
  - breaking changes for the new names;
  - the removal of the upgrade-from-1.x and rollback parts;
  - the note for third-party tools (WHMCS/Blesta modules, API clients, scripts).
- **Implementation notes** in this spec when the work lands.

## 13. Risks

| Risk                                                                          | Mitigation                                                                                                                   |
| ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| A missed reference breaks a rarely used command                               | `git grep` gate; ShellCheck; full BATS including upstream `test.bats`; manual checklist §10.2                                |
| Order mistakes create names like `arcpanelcp`                                 | Order rules §8.2 with a fixture test; a gate that greps for `arcpanelcp`, `ArcPanelCP`, `ARCPANELCP` and `arcpanel_arcpanel` |
| Sed expressions that contain paths change their meaning                       | Review every changed line that contains `sed` or a regex (list from `apply.sh`)                                              |
| A mismatch between components (chain name, driver name, task kind, DNS value) | Each is listed in §5.6 with all its consumers and has a BATS or Pest case                                                    |
| Restores of existing HestiaCP, VestaCP or ArcPanel 1.x backups fail           | D8 detection plus `test/restore.bats` on the sample archives                                                                 |
| Upstream security fixes are missed after the hard fork                        | Release checklist item: read HestiaCP advisories and releases for each ArcPanel release                                      |
| Third-party tools stop working                                                | CHANGELOG breaking-change note; the API keeps its request format, only the header name changes                               |
| Long-lived broken state between M3 and M8                                     | Do M3 to M8 in one stretch on `develop` without other epics in between; no push until M8 passes, unless the maintainer asks  |
