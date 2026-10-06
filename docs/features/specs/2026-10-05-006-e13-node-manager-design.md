# E13 — Node Manager — design

**Status:** Implemented (2026-10-05, see §13) · **Epic:** E13 · **Base design:** [07 Node platform](../../design/07-node-platform.md) §4 · **Previous specs:** [E11](./2026-10-05-003-e11-reseller-design.md), [E12](./2026-10-05-004-e12-port-registry-design.md), [E6 batch 1](./2026-10-05-005-e6-templates-batch1-design.md) · **ADRs:** [0001 view layer](../../adr/0001-view-layer.md) · **Roadmap:** [§3 E13](../../roadmap.md)

## 1. Summary

E13 gives every user a private Node.js toolchain in `~/.nvm`. The panel installs runtimes itself: root downloads the official tarball into a shared cache, verifies the release signature and checksum, and the tarball is unpacked as the user into the nvm directory layout. Users pick a default version and manage global npm packages (pnpm, yarn and pm2 among them) from a new Node.js page. A `developer` package and three package keys decide who may use it. `~/.nvm` is the only state, so backup, restore and rebuild need no hooks and no upstream command changes. E14 (Node apps with pm2) builds on it.

Design document 07 §4 stays the reference. This spec records the decisions of the 2026-10-05 brainstorm, the scope of E13 and where it differs from 07.

## 2. Decisions taken in the brainstorm

| #   | Question                       | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| --- | ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Scope                          | **All of E13** (CLI, cache, package keys, `developer` package, Laravel service and policy, Node.js page) in one spec, four milestones (§3), each a tested commit.                                                                                                                                                                                                                                                                                                                                                                                                                      |
| D2  | How runtimes are installed     | **Root cache + unpack.** Root downloads `node-vX.Y.Z-linux-<arch>.tar.xz`, `SHASUMS256.txt` and `SHASUMS256.txt.sig` into `$HESTIA/data/cache/node/vX.Y.Z/`, verifies the signature with `gpgv` against a pinned keyring and the tarball against the checksum, then `user_exec tar -x` unpacks it into `~/.nvm/versions/node/vX.Y.Z/`. The default is written to `~/.nvm/alias/default`. `nvm install` is never called; nvm (pinned release) is installed only so that `nvm use` works in the user's shell. Differs from 07 §4.3, which runs `nvm install` against a `file://` mirror. |
| D3  | pnpm and yarn                  | **Ordinary global packages** (`npm i -g pnpm`), with quick-add buttons on the page. No `v-change-user-node-pm`, no `PM` field: Node 25 and later no longer ship corepack. E14 chooses the install command from the app's lockfile. Differs from 07 §4.3 and answers 07 §10 question 4.                                                                                                                                                                                                                                                                                                 |
| D4  | Long operations                | **Detached task + polling.** `v-start-user-node-task` runs one whitelisted action in the background (`setsid`, output to a log file) and returns at once; the page polls `v-list-user-node-task` every 2 s and shows the log tail. One Node task per user at a time. E15 (Job Center) replaces the runner later with the same contract (status + log). Differs from 07 §6.1 (`node.pipe` processed by a cron job every minute).                                                                                                                                                        |
| D5  | Package updates and audit      | **`npm outdated` only**, run on demand as a task and cached 6 hours. No `npm audit`: global installs have no lockfile and the audit adds little. Differs from 07 §4.3 and §4.4.                                                                                                                                                                                                                                                                                                                                                                                                        |
| D6  | Where the state lives          | **`~/.nvm` only.** No `node.conf`: the list commands read `~/.nvm/versions/node/`, `~/.nvm/alias/default` and `lib/node_modules/*/package.json` as the user. The home directory travels with `v-backup-user`, and `v-rebuild-user` does not touch it, so E13 needs no hook and changes no upstream file. Differs from 07 §4.1 and §7.                                                                                                                                                                                                                                                  |
| D7  | View layer                     | **Vue 3 island** as ADR-0001 decides. E13 is the first island and builds the shared island infrastructure (esbuild entry with SFC compilation, mount by `data-island`, shared HTTP, toast and i18n helpers) that E3, E4 and E14 reuse.                                                                                                                                                                                                                                                                                                                                                 |
| D8  | Release verification           | **Signature and checksum.** `SHASUMS256.txt.sig` is checked with `gpgv` against `$HESTIA/data/cache/node/release-keys.gpg`, built at setup from the armored keys of the Node.js releasers shipped in `install/common/node/release-keys/`. 07 checks the checksum only.                                                                                                                                                                                                                                                                                                                 |
| D9  | Architectures and versions     | `x64` and `arm64` (`uname -m`). Majors 18 and later are listed; EOL majors are flagged and installable only when `NODE_ALLOWED` names them.                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| D10 | Package keys                   | `NODE`, `NODE_VERSIONS` and `NODE_ALLOWED` live in the E11 package sidecar `data/packages/<package>.tenancy`, like `PORTS` (E12 D5). `NODE_APPS` is added by E14. A reseller's own packages cannot grant `NODE='yes'` when the reseller's package has `NODE='no'`, nor a `NODE_VERSIONS` above the reseller's.                                                                                                                                                                                                                                                                         |
| D11 | Package changed to `NODE='no'` | Installed runtimes stay and keep working; every install, add or update command refuses with `E_DISABLED`; the page becomes read-only. Delete actions stay allowed so a user can free disk space.                                                                                                                                                                                                                                                                                                                                                                                       |

## 3. Milestones

| #   | Milestone                | Content                                                                                                                        | Done when                                                                                       |
| --- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| M1  | Runtimes                 | §5.1–5.4: `func/node.sh`, cache, keyring, releases, nvm, version commands, package keys, `developer` package, `v-add-sys-node` | runtime cases of `test/arcpanel-node.bats` pass on `arcpanel-test`                              |
| M2  | Packages and tasks       | §5.5–5.6: global package commands, outdated check, task runner                                                                 | package and task cases of `test/arcpanel-node.bats` pass                                        |
| M3  | Panel service and policy | §6: `NodeService`, records, `NodePolicy`, capabilities, JSON routes, `NODE*` in the Packages form                              | Pest unit and feature tests for admin, reseller A, reseller B, user and a `NODE='no'` user pass |
| M4  | Island and Node.js page  | §7: island infrastructure, `/app/node`, navigation, documentation                                                              | Done criteria of §4                                                                             |

## 4. Scope

### In scope

1. `func/node.sh`, the commands of §5, the cache directory and keyring, the daily releases refresh, the `hestia.conf` keys.
2. Package keys `NODE`, `NODE_VERSIONS`, `NODE_ALLOWED`; `install/common/packages/developer.pkg` and `developer.tenancy`; `v-add-sys-node` run by the installers, the upgrade step and `test/dev-sync.sh`.
3. `NodeService`, `NodeRuntime`, `NodePackage`, `NodeTask`, `NodePolicy`, capabilities, routes, navigation entry, `NODE*` fields in the Packages form.
4. Vue island infrastructure and the Node.js page `/app/node`.
5. BATS, Pest, Vitest and Playwright tests; the documentation changes of §12.

### Out of scope

- pm2 daemons, Node apps, ports and templates (E14).
- A "Node.js runtimes" toggle in Backup Exclusions: that page is legacy and moves in E7. `~/.nvm` is backed up with the home directory, which makes restores complete and offline-safe.
- `npm audit` (D5), private npm registries, per-user `.npmrc` management.
- Automatic cache cleanup: `v-delete-sys-node-cache` is CLI only.
- Any change to the legacy `web/` app or to upstream commands.

### Done when

1. A user on the `developer` package opens `/app/node`, enables Node.js, installs Node 22 and Node 24, and makes 24 the default.
2. From the page, the user installs `pm2` and `yarn` into Node 24 and watches the task log live.
3. `node -v` in the Web Terminal prints v24; `nvm use 22` works in the same shell.
4. After `v-rebuild-user`, and after backup → delete user → restore, the page shows the same two runtimes and two packages.
5. A user on the `default` package (`NODE='no'`) has no Node.js entry in the navigation and the routes answer 403; a fourth install with `NODE_VERSIONS='3'` fails with `E_LIMIT` and the page says why.
6. Two users install the same version at the same time: the tarball is downloaded once and both installs succeed.

## 5. CLI and data model (M1, M2)

### 5.1 Files

| Location                                                 | Content                                                                                                                                                                          | Owner and mode                          |
| -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- |
| `~/.nvm/`                                                | nvm (release pinned in `func/node.sh` with its sha256), `versions/node/vX.Y.Z/`, `alias/default`                                                                                 | user                                    |
| `~/.bashrc`                                              | guarded block `# >>> arcpanel nvm >>>` … `# <<< arcpanel nvm <<<` that exports `NVM_DIR` and sources `nvm.sh`                                                                    | user                                    |
| `$HESTIA/data/cache/node/`                               | `nvm-<ver>.tar.gz`; `vX.Y.Z/node-vX.Y.Z-linux-<arch>.tar.xz`, `SHASUMS256.txt`, `SHASUMS256.txt.sig`; `index.json`; `schedule.json`; `release-keys.gpg`; `.lock`                 | root, `0755` / `0644`                   |
| `install/common/node/release-keys/*.asc`                 | armored public keys of the Node.js releasers (from `nodejs/release-keys`)                                                                                                        | repository                              |
| `$HESTIA/data/users/<user>/node-task.conf`               | one line: `ID ACTION ARGS STATUS PID EXIT START END` (`STATUS` = `running`, `done`, `failed`)                                                                                    | root, `0640`                            |
| `$HESTIA/data/users/<user>/node-task.log`                | output of the current or last task; replaced by every new task                                                                                                                   | root, `0640`                            |
| `$HESTIA/data/users/<user>/node-outdated-<version>.json` | output of `npm outdated -g --json` for one runtime, with its time of creation in the file's mtime                                                                                | root, `0640`                            |
| `data/packages/<package>.tenancy`                        | `NODE` (`yes`/`no`, default `no`), `NODE_VERSIONS` (integer or `unlimited`, default `0`), `NODE_ALLOWED` (`lts`, `*` or a comma list of majors, default `lts`)                   | E11 sidecar                             |
| `hestia.conf`                                            | `NODE_RELEASES_URL` (default `https://nodejs.org/dist`), `NODE_SCHEDULE_URL` (default the `nodejs/Release` `schedule.json`), `NODE_NVM_URL` (default the GitHub release archive) | written by `v-add-sys-node` when absent |

`NODE_ALLOWED='lts'` means every major that `schedule.json` lists as active or maintenance LTS today. `*` means every listed major, EOL ones included. A list names majors exactly.

### 5.2 Library `func/node.sh`

`node_arch` (`x64`/`arm64` or `E_NOTEXIST`), `node_cache_lock` / `node_cache_unlock` (`flock` on `cache/node/.lock`), `node_cache_fetch VERSION` (download into a temp directory, verify signature and checksum, move into place; nothing is kept when a check fails), `node_resolve SPEC` (`lts`, a major or `X.Y.Z` → the newest matching version in `index.json`), `node_allowed USER MAJOR`, `node_versions USER` (directory names under `~/.nvm/versions/node/`, read as the user), `node_default USER`, `node_user_run USER VERSION CMD…` (`user_exec` with `HOME`, `NVM_DIR`, `PATH` set to the runtime's `bin`, `umask 022`, `nice -n 10`, `ionice -c3`, `timeout`), `node_pkg_spec_valid SPEC` (npm names and scoped names with an optional version or range; URLs, `file:`, `git+` and paths are refused), `node_shell_block_add` / `node_shell_block_remove`, `node_json_escape`. Path-escape checks follow `v-add-user-composer` (`readlink -m … | grep "^$HOMEDIR/$user/"`) for `~/.nvm` and its sub-directories.

### 5.3 Package keys and the `developer` package

- `func/tenancy.sh` learns the three keys: defaults, validation (`NODE` yes/no, `NODE_VERSIONS` integer or `unlimited`, `NODE_ALLOWED` `lts`, `*` or `^[0-9]+(,[0-9]+)*$`), and the JSON output of `v-list-package-tenancy` and of the package JSON built by `func/tenancy.sh` (next to `PORTS`).
- `v-change-package-tenancy PACKAGE KEY VALUE` accepts them. For a reseller's own package it refuses `NODE='yes'` when the reseller's package has `NODE='no'`, and a `NODE_VERSIONS` above the reseller's (`E_LIMIT`, naming the key).
- `install/common/packages/developer.pkg` (copy of `default.pkg` with `SHELL='bash'`) and `developer.tenancy` (`NODE='yes'`, `NODE_VERSIONS='3'`, `NODE_ALLOWED='lts'`, `PORTS='10'`). `v-add-sys-node` copies both into `data/packages/` when absent.

### 5.4 Runtime commands (all new)

| Command                                   | Behaviour                                                                                                                                                                                                                                                                                                                                                                            |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `v-add-sys-node`                          | idempotent setup: `hestia.conf` keys, `data/cache/node/`, `release-keys.gpg` from the shipped keys, the daily `v-update-sys-node-releases` line in the `hestiaweb` crontab (same method as `v-add-sys-port-registry`), the `developer` package                                                                                                                                       |
| `v-update-sys-node-releases`              | downloads `index.json` and `schedule.json` into the cache (atomic replace); a failed download keeps the old files and exits with `E_CONNECT`                                                                                                                                                                                                                                         |
| `v-list-sys-node-releases [FORMAT]`       | one row per major ≥ 18: `MAJOR`, `LATEST`, `LTS` (codename or empty), `STATUS` (`current`, `active`, `maintenance`, `eol`), `EOL` (date), `CACHED` (`yes` when the latest tarball is in the cache)                                                                                                                                                                                   |
| `v-delete-sys-node-cache [VERSION]`       | removes one cached version or all tarballs; installed runtimes are not affected                                                                                                                                                                                                                                                                                                      |
| `v-add-user-nvm USER`                     | requires `NODE='yes'` (`E_DISABLED`) and an unsuspended user; fetches the pinned nvm archive into the cache (sha256 checked), unpacks it into `~/.nvm` as the user, adds the shell block. Running it again repairs the shell block and returns `0`                                                                                                                                   |
| `v-delete-user-nvm USER`                  | removes `~/.nvm` and the shell block (E14 adds the "no apps left" check)                                                                                                                                                                                                                                                                                                             |
| `v-add-user-node-version USER SPEC`       | requires nvm, `NODE='yes'`, the major allowed by `NODE_ALLOWED` (`E_FORBIDEN`) and fewer installed runtimes than `NODE_VERSIONS` (`E_LIMIT`); resolves `SPEC`, fetches into the cache under the lock, unpacks as the user into a temp directory inside `~/.nvm` and renames it into place. The first runtime becomes the default. Installing an installed version returns `E_EXISTS` |
| `v-delete-user-node-version USER VERSION` | removes the runtime; when it was the default, the newest remaining runtime becomes the default (none left: `alias/default` is removed)                                                                                                                                                                                                                                               |
| `v-change-user-node-default USER VERSION` | the runtime must be installed; writes `alias/default`                                                                                                                                                                                                                                                                                                                                |
| `v-list-user-node-versions USER [FORMAT]` | live: `VERSION`, `MAJOR`, `DEFAULT`, `LTS`, `STATUS`, `EOL`, `SIZE` (MB), `PACKAGES` (count); plus the totals `ENABLED` (nvm present), `ALLOWED`, `LIMIT`, `NODE` (package key)                                                                                                                                                                                                      |

### 5.5 Global package commands (all new)

| Command                                          | Behaviour                                                                                                                                                                                        |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `v-add-user-npm-package USER VERSION SPEC`       | `NODE='yes'`, runtime installed, `SPEC` valid; `npm install -g --no-fund --no-audit SPEC` as the user under that runtime, 15-minute timeout; removes the runtime's outdated cache                |
| `v-delete-user-npm-package USER VERSION NAME`    | `npm uninstall -g NAME`; `npm` and `corepack` cannot be removed (`E_FORBIDEN`)                                                                                                                   |
| `v-update-user-npm-packages USER VERSION`        | `NODE='yes'`; `npm update -g`; removes the outdated cache                                                                                                                                        |
| `v-list-user-npm-packages USER VERSION [FORMAT]` | `NAME`, `VERSION`, `LATEST` and `OUTDATED` (from the outdated cache when younger than 6 hours, else empty), `CHECKED` (time of the cache or empty); read from `lib/node_modules/**/package.json` |
| `v-check-user-npm-outdated USER VERSION`         | `npm outdated -g --json` as the user (exit code 1 means "outdated found" and is not an error); writes `node-outdated-<version>.json`                                                             |

### 5.6 Task runner (all new)

| Command                                       | Behaviour                                                                                                                                                                                                                                                                                                                                                                                                                   |
| --------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v-start-user-node-task USER ACTION [ARGS…]`  | `ACTION` is one of `add-version` (`SPEC`), `add-package` (`VERSION SPEC`), `update-packages` (`VERSION`), `check-outdated` (`VERSION`). Validates the arguments as the target command does, refuses with `E_INUSE` while the user has a running task, writes `node-task.conf`, starts the target command with `setsid` and every descriptor redirected (`</dev/null >node-task.log 2>&1`), prints the task `ID` and returns |
| `v-list-user-node-task USER [LINES] [FORMAT]` | the task record and the last `LINES` (default 100, maximum 1000) lines of the log; a `running` task whose `PID` is gone is reported and stored as `failed`                                                                                                                                                                                                                                                                  |

The background wrapper records `EXIT`, `END` and the final `STATUS` when the target command ends. Every task command logs with `log_event`; installs and removals add `v-log-action` with category `Node.js`.

## 6. Laravel side (M3)

### 6.1 Services and records

- `App\Services\Node\NodeService`: `overview()`, `releases()`, `packages()`, `task()`, `enable()`, `disable()`, `startInstall()`, `startAddPackage()`, `startUpdatePackages()`, `startCheckOutdated()`, `setDefault()`, `deleteVersion()`, `deletePackage()`. One method per command through `ArcCommand`.
- `NodeRuntime`, `NodeRelease`, `NodePackage`, `NodeTask`: typed rows from the `json` outputs.
- `PackageService` and the package form read and write the three keys through `v-change-package-tenancy`.

### 6.2 Authorization

| Unit           | Change                                                                                                                                                               |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Capabilities` | `node.view` (admin All, reseller Tree, user Self), `node.manage` (same scopes)                                                                                       |
| `NodePolicy`   | `view`: target in scope. `manage`: target in scope, not read-only, and the target's package has `NODE='yes'`; delete actions need only scope and not read-only (D11) |
| Scoping        | the page works on the effective user (impersonation of S1/E11); objects outside the tree → 404, refusals inside → 403                                                |
| Navigation     | "Node.js" in the "My hosting" group when the effective user's package has `NODE='yes'` or Node.js is enabled for it                                                  |

### 6.3 Routes

`GET /app/node` (page), and JSON under `/app/node/api`: `GET overview`, `GET releases`, `GET versions/{version}/packages`, `GET task`, `POST enable`, `DELETE enable`, `POST versions` (start install task), `POST versions/{version}/default`, `DELETE versions/{version}`, `POST versions/{version}/packages` (start add task), `POST versions/{version}/packages/update` (start update task), `POST versions/{version}/packages/outdated` (start check task), `DELETE versions/{version}/packages/{name}`. `{name}` accepts scoped names (`@scope/name`, URL-encoded). The `effective-user` and `not-readonly` middleware apply as in S2. Errors use the JSON error contract of `ArcCommand`.

## 7. Node.js page (M4)

### 7.1 Island infrastructure

- `build.js` gains a Vue entry (`web-app/resources/js/islands/*.js`) compiled with SFC support, production Vue build without the template compiler (CSP-safe).
- `islands/loader.js` mounts each registered island on `[data-island="<name>"]` with the element's `data-props` JSON.
- `islands/shared/`: `http.js` wrapper (CSRF header, JSON error contract), toast bridge to the shell's toasts, `t()` from the page's i18n bundle. Blade component `<x-ap-island name="…" :props="…" />`.

### 7.2 Page `/app/node`

- **Header:** package state (enabled or disabled by package, `n / limit` runtimes, allowed majors) and "Enable Node.js" or "Remove Node.js".
- **Runtimes card:** version, LTS codename, status badge (EOL warning), default badge, size, package count; actions "Make default" and "Uninstall".
- **Install dialog:** majors grouped LTS / current / EOL, filtered by `NODE_ALLOWED`; disabled with the reason when `NODE_VERSIONS` is reached.
- **Packages tab per runtime:** name, version, latest; add form (name with optional version); quick-add buttons pnpm, yarn, pm2; "Check for updates" and "Update all"; remove per row.
- **Task panel:** action, status, live log tail (poll every 2 s while running), result toast; buttons that start a task are disabled while one runs.
- "Open terminal" link when the web terminal is installed (`WEB_TERMINAL` in `v-list-sys-config`).
- Read-only state when the package has `NODE='no'` (D11) or the session is read-only.

## 8. Error handling

| Code         | Cause                                                                                   | Panel message                                                    |
| ------------ | --------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| `E_DISABLED` | package has `NODE='no'`                                                                 | "Node.js is not included in your package"                        |
| `E_LIMIT`    | `NODE_VERSIONS` reached; reseller package above the reseller's keys                     | names `NODE_VERSIONS` and the limit                              |
| `E_FORBIDEN` | major not in `NODE_ALLOWED`; removing `npm`/`corepack`; path outside the home directory | the reason in one line                                           |
| `E_EXISTS`   | runtime already installed                                                               | "Node X.Y.Z is already installed"                                |
| `E_NOTEXIST` | runtime, package or nvm missing; unsupported architecture                               | names the object                                                 |
| `E_INUSE`    | a task is running                                                                       | "Another Node.js task is running"                                |
| `E_CONNECT`  | download failed                                                                         | "Could not download from <host>" with the cached state           |
| `E_INVALID`  | bad spec, bad package name, signature or checksum mismatch                              | field error, or "The download failed verification" for checksums |

A failed or interrupted install leaves no partial runtime: unpacking happens in a temp directory inside `~/.nvm` that is renamed into place only after `tar` succeeds, and is removed otherwise (disk quota errors included).

## 9. Install, upgrade and development

- The installers and `test/dev-sync.sh` run `v-add-sys-node` after `v-add-sys-port-registry`.
- The pending ArcPanel upgrade step (`install/upgrade/arcpanel/1.1.0.sh`) runs it.
- New commands are reachable through the existing sudoers rule (all of `bin/`) and are added to the panel's command allow-list.

## 10. Testing

| Level                          | Covers                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BATS `test/arcpanel-node.bats` | setup idempotent; keyring built; releases listed with LTS and EOL; nvm enable twice; install `lts`, a major and an exact version from a local `file://` mirror; `NODE='no'`, `NODE_ALLOWED`, `NODE_VERSIONS`; tampered checksum file refused and nothing installed; two parallel installs of one version; default moves on delete; shell block present and `node -v` in a login shell; packages add, list, update, outdated, delete, invalid specs; task lock, task log and dead task detection; rebuild and backup → restore keep the state; reseller package caps |
| Pest unit                      | `Capabilities` entries for every new route; `NodePolicy` matrix (admin, reseller, customer, other user, `NODE='no'`, read-only); `NodeService` argument order with the fake; record parsing                                                                                                                                                                                                                                                                                                                                                                         |
| Pest feature                   | every route × admin, reseller A, reseller B, user of A → 200/403/404; validation of spec and package names; Packages form with the `NODE*` fields                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Vitest                         | island loader, the task polling composable, the package spec validator                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Playwright                     | Done when 1, 2 and 5; axe and Lighthouse accessibility ≥ 90 on `/app/node`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

## 11. Risks

| Risk                                             | Mitigation                                                                                                                           |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| nodejs.org unreachable                           | cached versions keep installing; `E_CONNECT` names the host; `NODE_RELEASES_URL` points at a mirror                                  |
| Releaser keys rotate                             | keys are files in the repository, rebuilt into the keyring by `v-add-sys-node` on every upgrade; a failed signature names the key id |
| Disk quota full while unpacking                  | temp directory removed, quota error reported                                                                                         |
| Package install scripts run user code            | they run as the user, the same trust as PHP-FPM and SSH today; URLs, git and file specs are refused                                  |
| A user replaces `~/.nvm` parts with symlinks     | every read and write runs as the user and path-escape checks guard the directories root touches                                      |
| Shell start gets slower when `nvm.sh` is sourced | documented; only users who enabled Node.js get the block                                                                             |

## 12. Documentation changes in the same change set

- **07 Node platform:** §4 marked as superseded by this spec where D2–D6 differ; status line.
- **13 Data model reference:** the cache, task files, package keys and `hestia.conf` keys.
- **ADR-0001:** first island noted under consequences.
- **Roadmap:** E13 points to this spec and gets its status line.

## 13. Implementation notes (2026-10-05)

Decisions taken while building E13 that differ from, or add to, the sections above:

- **Package defaults (D10):** `NODE_VERSIONS` defaults to `3`, not `0`: with `0`, turning `NODE` on in a package would allow nothing until a second field is changed. `NODE` still defaults to `no`, so no account gets Node.js by accident.
- **Download locations:** `NODE_RELEASES_URL`, `NODE_SCHEDULE_URL` and `NODE_NVM_URL` are optional overrides read from `hestia.conf`; `v-add-sys-node` does not write them, the defaults live in `func/node.sh`. BATS points them at `file://` mirrors.
- **Release keys (D8):** the keys ship as one armored bundle, `install/common/node/release-keys.asc`: the 29 keys of `keys.list` in `nodejs/release-keys`, current and former releasers, so that older majors verify too. `v-add-sys-node` imports them into a GnuPG keybox, `data/cache/node/release-keys.gpg`, which `gpgv` reads.
- **nvm:** release `0.40.8`, pinned with its sha256 in `func/node.sh`; only `nvm.sh`, `nvm-exec`, `bash_completion` and `LICENSE.md` are unpacked into `~/.nvm`. An nvm the user installed by hand is kept.
- **User side:** every read and write in a home directory runs through `user_exec` with `env -i`, `HOME`, `PATH` (runtime first) and `NVM_DIR`, in the home directory. Tarballs reach the user's `tar` on standard input, so the cache needs no permissions for users. npm runs with `--prefix` of the runtime, `nice`, `ionice` and a 15-minute `timeout`.
- **Tasks (D4):** the runner is two commands: `v-start-user-node-task` checks the arguments, takes `data/users/<user>/node-task.lock` and starts `v-run-user-node-task` with `setsid`; the runner refuses a task id that `v-start-user-node-task` did not record and writes the result under the same lock. A running task is alive when its PID still runs `v-run-user-node-task`. `v-delete-user-nvm` and `v-delete-user-node-version` refuse while a task runs (`E_INUSE`).
- **Routes (§6.3):** the page and its JSON routes carry the account: `/app/node/{user}` and `/app/node/{user}/api/…` through `effective-user`, so administrators and resellers open the page of an account in their scope directly; `/app/node` redirects to the effective account. The user detail page links to it.
- **Sidebar (§6.2):** `NodeAccess` reads the account's package with `v-list-user USER json` and its `NODE` key with `v-list-package-tenancy PACKAGE json`, once per request; administrators always see the entry.
- **Island (§7.1):** `scripts/esbuild-vue.js` compiles `<script setup>` components with their templates at build time, so the page loads Vue's runtime-only build; `<style>` blocks are refused. The island gets its strings translated in its props, so `i18n-keys.json` (strings of every page) is unchanged. Shared parts: `islands/shared/ApDialog.vue`, `ApIcon.vue`, `ApBadge.vue`, `strings.js`, `task-poller.js`; one CSS utility, `.ap-row-end`. The page renders the runtime table on the server too, so it reads without scripts.
- **Root-owned homes (fix, 2026-10-06):** a user with a `nologin` shell has an SFTP jail, and `v-add-user-sftp-jail` makes `/home/<user>` owned by root, so the user cannot create or remove a folder at the top of the home and enabling Node.js failed with "nvm could not be unpacked". `node_home_dir_add` and `node_home_dir_delete` in `func/node.sh` let root create `~/.nvm` (and, for E14 and E4, `~/.pm2`, `~/.config` and `~/apps`) owned by the user, and remove it after the user has emptied it, when the home is not the user's; the user cannot place a link in a home it cannot write, and everything inside the folder still runs as the user. A link in place of the folder is refused (`E_FORBIDEN`). BATS covers it with a `nologin` user in `test/arcpanel-node.bats`, `test/arcpanel-nodeapps.bats` and `test/arcpanel-docker.bats`; the suites had only used `bash` users.
- **Done when 3:** `node -v` and `nvm use` are checked in a login shell, which reads the same `~/.bashrc` as the Web Terminal (BATS); the terminal's WebSocket is not driven by a test.
- **Tests:** on `arcpanel-test` (Ubuntu 24.04, x64): BATS `test/arcpanel-node.bats` 33 of 33 (first run downloads Node 22 and 24 into `/root/arcpanel-node-mirror`, later runs are offline except the npm cases), `test/arcpanel-tenancy.bats` and `test/arcpanel-ports.bats` 42 of 42; Pest 303; Vitest 127; Playwright 26 of 26 with `test/e2e/node.spec.js` (Done when 1, 2 and 5 on the new `arce2edev` user of the developer package), axe on `/app/node` for user, developer and administrator in both themes, Lighthouse accessibility 100 on every Node.js page. arm64 is supported by the code but was not run.
