# E5 system packages and E6 batch 2 templates — design

**Status:** Implemented (2026-10-06, see §15; the server runs wait for E9 R5 M8) · **Epics:** E5, E6 (batch 2) · **Base design:** [08 App Store](../../design/08-app-store.md) §6, [09 Web templates and metadata](../../design/09-web-templates-metadata.md) §7.2 · **Previous specs:** [E6 batch 1](./2026-10-05-005-e6-templates-batch1-design.md) (metadata, per-domain variables, trigger scripts), [E7 batch 3](./2026-10-06-003-e7-batch3-pages-design.md) (system task), [E9 R5](./2026-10-06-005-e9-full-rename-design.md) (names used here) · **Roadmap:** [§3 E5, E6](../../roadmap.md)

## 1. Summary

Admins install the runtimes and tools that non-PHP sites need from the panel instead of over SSH, and the template picker tells everyone whether the runtime of a template is there:

- **System packages (E5):** a whitelist catalog of six entries (Python 3, Go, Ruby, FFmpeg, ImageMagick, build tools) installed from the distribution's apt archive by four new commands and a new kind of the E7 system task, on an admin-only page `/server/packages`.
- **Runtime badges:** the `runtime` of a template's metadata gets `go` and `ruby`, and the card picker shows it green when the runtime is available for the domain owner and red when it is not.
- **Six templates (E6 batch 2):** `django`, `flask-fastapi`, `golang-proxy`, `rails`, `laravel-octane` and `wordpress-secure`, on both NGINX stacks of batch 1.

The names in this spec are those after the E9 R5 rename (`$ARCPANEL`, `arcpanel.conf`, `check_arcpanel_demo_mode`).

## 2. Decisions taken in the brainstorm

| #   | Question                          | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| --- | --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Scope                             | **One spec for E5 and E6 batch 2.** E5 comes first so the batch 2 templates have their runtimes.                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| D2  | Manifest format                   | **JSON**, one file per slug in `install/common/apps/system/<slug>.json`, read in place and validated with jq, like the Docker catalog. Not YAML and not copied into `data/` (08 §6.1 is superseded).                                                                                                                                                                                                                                                                                                                                                      |
| D3  | Catalog                           | `python` (python3, venv, dev headers, pipx), `go`, `ruby` (with bundler), `ffmpeg`, `imagemagick` (already installed by the installer), `build-tools` (build-essential). Dropped from 08 §6.2: OpenJDK (nothing uses it; Debian 12 has 17 only) and the certbot DNS plugins (ArcPanel has its own ACME client).                                                                                                                                                                                                                                           |
| D4  | Redis and Memcached               | **Not in the catalog.** A server-wide instance on localhost is readable by every hosting user. Redis is in the Docker store, run as the user on a port of the user's pool. PHP extensions belong to E19.                                                                                                                                                                                                                                                                                                                                                  |
| D5  | Sources                           | **The distribution's apt archive only.** No snap, no third-party repositories; the installed version is whatever the release ships, and the page shows it. The manifest schema has no `source` key until a package needs one.                                                                                                                                                                                                                                                                                                                             |
| D6  | Differences between releases      | `packages` is the default list; `os` overrides it per release (`debian-12`, `debian-13`, `ubuntu-22.04`, `ubuntu-24.04`, `ubuntu-26.04`). An override of `null` marks the entry as not available on that release.                                                                                                                                                                                                                                                                                                                                         |
| D7  | Long operations                   | **The E7 system task** gets a kind `package SLUG add\|delete`. One task at a time server-wide, the log and the `apTask` polling of the server pages. No new runner (08 §6.2 `apps.pipe` is superseded).                                                                                                                                                                                                                                                                                                                                                   |
| D8  | State                             | **`dpkg-query` is the truth.** `$ARCPANEL/data/sys-packages.conf` records, per slug, the packages that ArcPanel installed itself (packages present before are not recorded). Removal removes only those, with `apt-get remove` and without autoremove.                                                                                                                                                                                                                                                                                                    |
| D9  | Removal guard                     | `v-delete-sys-package` refuses a slug that ArcPanel did not install, and a slug whose runtime is the `runtime` of a web or proxy template used by a web domain, unless `force` is given (CLI only).                                                                                                                                                                                                                                                                                                                                                       |
| D10 | Updates                           | **No update command.** Distribution packages are updated by apt and unattended-upgrades like the rest of the system; the list shows an `UPGRADABLE` flag only.                                                                                                                                                                                                                                                                                                                                                                                            |
| D11 | Page                              | **`/server/packages`**, admin only, Blade and Alpine. Not a "System" tab of the Docker store: the store is a per-user page (`/docker/{user}/store`) and system packages are server-wide.                                                                                                                                                                                                                                                                                                                                                                  |
| D12 | Runtime in the metadata           | **One `runtime` field**, its values extended with `go` and `ruby`; no `requires` array (09 §4.2 is superseded). Categories get `go` and `ruby`.                                                                                                                                                                                                                                                                                                                                                                                                           |
| D13 | Badge semantics                   | Green when available for the **domain owner**, red when not; a hint, never a gate. `php` is always available, `node` when the owner's E13 runtimes are enabled and at least one is installed, `docker` when `DOCKER_SYSTEM` is set, `python`/`go`/`ruby` when a catalog entry that provides it is installed. Admins get a link to `/server/packages`, others "Ask your provider".                                                                                                                                                                         |
| D14 | `redirect-only` and `maintenance` | **Dropped from batch 2.** Redirects exist upstream (`v-add-web-domain-redirect`, the Redirect tab). A maintenance template would replace the app template and lose the domain's variables; a maintenance switch is a later spec. No new variable types are needed in `func/arc-template.sh`.                                                                                                                                                                                                                                                              |
| D15 | Running the apps                  | **The user runs the process**, as with `python-gunicorn` of batch 1: the template proxies to a port of the owner's pool. Each template's `docs` link explains how. A process manager for non-Node apps stays out of scope (roadmap §7).                                                                                                                                                                                                                                                                                                                   |
| D16 | `wordpress-secure` rate limit     | A shared `limit_req_zone` in `/etc/nginx/conf.d/arcpanel-wp.conf`, placed by the installer and `v-add-sys-web-templates-meta`. When the file is missing, the template's trigger drops the `limit_req` line so `nginx -t` keeps passing. The `wp-cron.php` throttle of 09 §7.2 is dropped; the docs recommend the panel's cron instead.                                                                                                                                                                                                                    |
| D17 | Order of work                     | Implementation starts on `develop` right away (maintainer, 2026-10-06), alongside the E9 R5 rename. `develop` installs only from a fresh install of the renamed tree (E9 R5 M8), so the BATS, smoke and Playwright runs of every milestone wait for that server; until then a milestone ends with ShellCheck, `v-check-sys-packages`, Pest and Vitest green and is committed, and its server runs are recorded in the implementation notes when they happen. Existing files under `test/` and `docs/` are edited only after the rename's M7 is committed. |

## 3. Milestones

| #   | Milestone                     | Content    | Done when                                                                                                                                                         |
| --- | ----------------------------- | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | Catalog and commands          | §4, §5     | Six manifests; `func/syspackages.sh` and four commands; `test/arcpanel-sys-packages.bats` green on the test server; the `System packages` CI job green            |
| M2  | System task and page          | §6, §7     | Kind `package` in the system task with BATS cases; `/server/packages` with Pest feature tests for admin, reseller A, reseller B and user; capability entry        |
| M3  | Runtime badges                | §8         | `go` and `ruby` accepted by the metadata validator; `RuntimeAvailability` and the picker badge states covered by Pest                                             |
| M4  | Python templates              | §9.1, §9.2 | `django` and `flask-fastapi` on both stacks with metadata; BATS render cases (`test/arcpanel-templates-batch2.bats`) and `nginx -t` on both stacks                |
| M5  | Go, Ruby and Octane templates | §9.3–§9.5  | `golang-proxy`, `rails`, `laravel-octane` on both stacks with metadata; BATS render cases                                                                         |
| M6  | `wordpress-secure`            | §9.6       | Template on both stacks, the zone file in the installer and in `v-add-sys-web-templates-meta`; BATS cases with and without the zone file; denied paths answer 403 |
| M7  | Documentation and e2e         | §11, §12   | Documents of §12 updated; Playwright installs the fixture package and sees the badge turn green; implementation notes appended                                    |

Each milestone ends with a green run and a commit (`feat(e5): …` for M1–M3, `feat(e6): …` for M4–M6, `docs(e5): …` or `test(e5): …` for M7).

## 4. Catalog

### 4.1 Manifest

`install/common/apps/system/<slug>.json`:

```json
{
	"schema": 1,
	"slug": "python",
	"name": "Python 3",
	"summary": "Python 3 with venv, development headers and pipx.",
	"category": "runtime",
	"packages": ["python3", "python3-venv", "python3-dev", "pipx"],
	"os": {},
	"provides": ["python"],
	"version_cmd": "python3 --version | awk '{print $2}'"
}
```

| Key           | Rule                                                                                                                                                |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `schema`      | `1`                                                                                                                                                 |
| `slug`        | `^[a-z][a-z0-9-]{1,31}$`, equal to the file name                                                                                                    |
| `name`        | 1–40 characters                                                                                                                                     |
| `summary`     | 1–160 characters                                                                                                                                    |
| `category`    | `runtime`, `media` or `tooling`                                                                                                                     |
| `packages`    | 1–10 Debian package names (`^[a-z0-9][a-z0-9+.-]+$`)                                                                                                |
| `os`          | object; keys from the supported releases (`debian-12`, `debian-13`, `ubuntu-22.04`, `ubuntu-24.04`, `ubuntu-26.04`); value a package list or `null` |
| `provides`    | array of runtime ids of §8.1 (`python`, `go`, `ruby`), may be empty                                                                                 |
| `version_cmd` | optional shell line run as root after install; its first output line is the version, otherwise the version of the first package from `dpkg-query`   |

The catalog directory can be replaced with `ARC_SYS_PACKAGES_CATALOG` (tests only, same as `ARC_DOCKER_CATALOG`).

### 4.2 Entries

| Slug          | Category | Packages                                 | Provides |
| ------------- | -------- | ---------------------------------------- | -------- |
| `python`      | runtime  | python3, python3-venv, python3-dev, pipx | `python` |
| `go`          | runtime  | golang-go                                | `go`     |
| `ruby`        | runtime  | ruby, ruby-dev, ruby-bundler             | `ruby`   |
| `ffmpeg`      | media    | ffmpeg                                   | —        |
| `imagemagick` | media    | imagemagick                              | —        |
| `build-tools` | tooling  | build-essential, pkg-config              | —        |

The `System packages` CI job (§11) proves that every name exists on every release; an `os` override is added where one does not.

### 4.3 State

- **Status** of a slug on this server, from `dpkg-query -W -f='${db:Status-Status}'` per package of the release's list: `installed` (all installed), `partial` (some), `missing` (none). `AVAILABLE` is `no` when the release's override is `null`.
- **Record** `$ARCPANEL/data/sys-packages.conf` (root 0640), one line per slug that ArcPanel installed:

```text
SLUG='python' PACKAGES='python3-venv python3-dev pipx' TIME='10:12:01' DATE='2026-10-06'
```

`PACKAGES` holds only the packages that were missing before the install. `SOURCE` in the list is `arcpanel` with a record, `system` when the slug is installed or partial without one, `-` otherwise. Writers hold `flock` on `$ARCPANEL/data/sys-packages.lock`.

## 5. Commands (M1)

Library `func/syspackages.sh`: catalog lookup, release key (`<ID>-<VERSION_ID>` from `/etc/os-release`), package list per release, status, record read and write, `sp_used_by SLUG` (web domains whose web or proxy template has a `runtime` in the slug's `provides`, read through `arc_tpl_meta_file`).

| Command                | Arguments      | Behaviour                                                                                                                                                                                                                                                                                      |
| ---------------------- | -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v-list-sys-packages`  | `[FORMAT]`     | Every catalog entry: `SLUG`, `NAME`, `SUMMARY`, `CATEGORY`, `PROVIDES`, `PACKAGES` (for this release), `AVAILABLE`, `STATUS`, `SOURCE`, `VERSION`, `UPGRADABLE` (`apt list --upgradable`, local cache only), `USED_BY` (number of domains). Formats `json`, `plain`, `csv`, `shell`. No cache. |
| `v-add-sys-package`    | `SLUG`         | Validates the slug and its availability, refuses `installed`; runs `apt-get update` and `apt-get install -y --no-install-recommends` of the missing packages with `DEBIAN_FRONTEND=noninteractive` and `-o DPkg::Lock::Timeout=300`; writes or extends the record; `log_event`.                |
| `v-delete-sys-package` | `SLUG [force]` | Refuses a slug without a record (`E_FORBIDEN`, "not installed by ArcPanel") and, without `force`, a slug with `USED_BY` > 0 (`E_FORBIDEN`, the domains in the message); `apt-get remove -y` of the recorded packages that are still installed; deletes the record; `log_event`.                |
| `v-check-sys-packages` | `[DIR]`        | Validates every manifest of the catalog (or `DIR`) against §4.1; prints one line per problem and exits `E_INVALID` when there is one. Runs in CI without a server.                                                                                                                             |

Errors: unknown slug `E_NOTEXIST`, already installed `E_EXISTS`, not available on this release `E_INVALID`, apt failure `E_UPDATE`. Every changing command calls `check_arcpanel_demo_mode`. A failed apt run prints apt's last lines; when apt says "Unable to locate package" on Ubuntu, the message adds that the `universe` component must be enabled.

## 6. System task (M2)

- `v-start-sys-task package SLUG add|delete` validates like the commands (slug exists, available, not installed for `add`; recorded and not used for `delete`) before the task starts, so the page gets the refusal at once.
- `v-run-sys-task` runs one step: `v-add-sys-package SLUG` or `v-delete-sys-package SLUG`.
- `SystemTaskService` and `apTask` are unchanged; the kind is listed in the header of `v-start-sys-task`.

## 7. Page `/server/packages` (M2)

| Route                                 | Name                     | Middleware                   | Action                                                   |
| ------------------------------------- | ------------------------ | ---------------------------- | -------------------------------------------------------- |
| `GET /server/packages`                | `server.packages.index`  | `sys-config`                 | Card grid from `v-list-sys-packages json`                |
| `POST /server/packages/{slug}/add`    | `server.packages.add`    | `sys-config`, `not-readonly` | Starts the task, redirects back with the task panel open |
| `POST /server/packages/{slug}/delete` | `server.packages.delete` | `sys-config`, `not-readonly` | Same for removal                                         |

- Capability `server.packages` = admin; `ServerPolicy::packages`. Reseller and user get 403. Navigation: "System packages" under Server.
- `App\Services\Server\SystemPackages`: `list()`, `start(slug, action)` through `SystemTaskService`.
- Cards grouped by category: name, summary, status badge (Installed, Partly installed, Not installed, Not available on this release), version, "Provides: Python", "Used by N domains", buttons Install or Remove. A `system` entry shows "Installed by the system" and no Remove. While a task runs the buttons are disabled and `<x-ap-task-panel>` shows the log; the page reloads when the task ends.
- A refusal (`E_FORBIDEN`, `E_INUSE`) is shown as a flash error with the command's message.

## 8. Runtime badges (M3)

### 8.1 Metadata

`func/arc-template.sh` accepts `runtime` in `php`, `node`, `python`, `go`, `ruby`, `docker`, `none`, and the categories get `go` and `ruby` (`ARC_TPL_CATEGORIES`). `TemplateCatalog::categories()` and `runtimeLabel()` follow (Go, Ruby).

### 8.2 `App\Services\WebDomains\RuntimeAvailability`

`for(string $owner): array<string, bool>` with the rules of D13. The system package part comes from one `v-list-sys-packages json` call per request; the Node part from `NodeService::account($owner)`; Docker from `SysConfig`. `none` has no badge.

### 8.3 Picker

`TemplateCatalog::pickerOptions()` takes the availability map and sets `badge` and `badgeTone` (`success` or `danger`) and `badgeHint` ("Installed" or "Not installed on this server"). `x-ap-card-picker` renders the tone; when a missing runtime is picked, the form shows a note under the picker: for admins a link "Install it on the System packages page", for others "Ask your provider to install it". Selection is never blocked.

## 9. Templates (M4–M6)

Each template ships `<name>.tpl` and `<name>.stpl` in `install/deb/templates/web/nginx/php-fpm/` (web template, `nginx-fpm` stack) and in `install/deb/templates/web/nginx/` (proxy template, `nginx-apache` stack), a trigger `<name>.sh` when it has a variable, and `install/deb/templates/web/meta/<name>.json`. The names are added to `ARC_TPL_ARCPANEL`. The structure is that of `python-gunicorn`: the upstream skeleton (logs, force SSL include, dotfile deny, `/error/`, `/vstats/`, the `nginx.conf_*` include) and the application part between `# arc:app-begin` and `# arc:app-end`, which the 503 "App not configured" fallback of batch 1 replaces when a token is left.

| Template           | Category      | Runtime | Variable       |
| ------------------ | ------------- | ------- | -------------- |
| `django`           | python        | python  | `BACKEND_PORT` |
| `flask-fastapi`    | python        | python  | `BACKEND_PORT` |
| `golang-proxy`     | go            | go      | `BACKEND_PORT` |
| `rails`            | ruby          | ruby    | `BACKEND_PORT` |
| `laravel-octane`   | php-framework | php     | `BACKEND_PORT` |
| `wordpress-secure` | php-cms       | php     | —              |

### 9.1 `django`

`/static/` and `/media/` aliased to `%docroot%/static/` and `%docroot%/media/` (STATIC_ROOT and MEDIA_ROOT), 30 days expiry; `/favicon.ico` and `/robots.txt` from the document root; `location /` proxies to `127.0.0.1:%arc_backend_port%` with `Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, `proxy_redirect off`; `client_max_body_size 64m`.

### 9.2 `flask-fastapi`

`/static/` from the document root; `location /` proxies with the forwarding headers, `proxy_http_version 1.1`, the WebSocket upgrade headers, `proxy_buffering off` and `proxy_read_timeout 300s` (server-sent events and streaming responses of ASGI apps).

### 9.3 `golang-proxy`

No static location; `location /` proxies with the forwarding headers and the WebSocket upgrade headers.

### 9.4 `rails`

The document root is the app's `public/` (the docs show the symlink); `try_files $uri @app`; `/assets/` and `/packs/` with `gzip_static on`, `expires max` and `Cache-Control: public, immutable`; `@app` proxies to puma with the forwarding headers and WebSocket upgrade (Action Cable).

### 9.5 `laravel-octane`

The document root is the app's `public/`; dotfiles and `.env` denied; `try_files $uri @octane`; `@octane` proxies to the Octane server with the forwarding headers and WebSocket upgrade. `runtime` is `php`; the Octane server (Swoole, RoadRunner or FrankenPHP) is the user's.

### 9.6 `wordpress-secure`

Based on the upstream `wordpress` template (web stack) and on `default` (proxy stack). Both deny with 403: `xmlrpc.php`, `wp-config.php`, `readme.html`, `license.txt`, `wp-admin/install.php`, `.git` and `.svn`, any `.php` under `wp-content/uploads/`, direct `.php` requests under `wp-includes/` except `wp-includes/js/tinymce/wp-tinymce.php`. `wp-login.php` is rate limited (`limit_req zone=arcpanel_wp_login burst=5 nodelay`).

`/etc/nginx/conf.d/arcpanel-wp.conf` (from `install/deb/nginx/arcpanel-wp.conf`):

```nginx
limit_req_zone   $binary_remote_addr zone=arcpanel_wp_login:10m rate=10r/m;
limit_req_status 429;
```

The installers copy it when NGINX is installed; `v-add-sys-web-templates-meta` copies it when it is missing (so the upgrade step that already runs that command covers existing servers) and reloads NGINX. The trigger `wordpress-secure.sh` removes the `limit_req` line from the rendered config when the file is missing.

## 10. Errors and security

| Case                                            | Behaviour                                                                                                          |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| apt or dpkg locked                              | apt waits up to 300 s, then the command fails with `E_UPDATE` and apt's message                                    |
| No network                                      | `apt-get update` fails; the task ends failed with the log                                                          |
| `universe` disabled on Ubuntu                   | The message names the missing component                                                                            |
| A package removed outside ArcPanel              | Status becomes `partial` or `missing`; `add` installs it again; `delete` removes what is left and drops the record |
| A recorded package that another slug also needs | Removal keeps packages that another record lists                                                                   |
| Arguments                                       | Only slugs from the catalog reach apt; package names come from manifests validated by `v-check-sys-packages`       |
| Who may install                                 | Admin only (capability, policy); the commands run as root through the usual `sudo` rule, no new sudoers lines      |

## 11. Testing

- **BATS `test/arcpanel-sys-packages.bats`** with a fixture catalog (`ARC_SYS_PACKAGES_CATALOG`) whose entry installs one small package: list in every format (`json` parses with `jq`), add, add again (`E_EXISTS`), delete, delete without a record, delete when used (a domain with a fixture template whose runtime the entry provides) with and without `force`, a release override of `null`, `v-check-sys-packages` on valid and broken manifests, demo mode, the task kind `package` through `v-start-sys-task` and `v-list-sys-task`.
- **BATS templates**, a new suite `test/arcpanel-templates-batch2.bats`: each new template renders on both stacks with a port from the owner's pool, `nginx -t` passes, the token is replaced, the 503 fallback without a port; `wordpress-secure` with and without the zone file, and 403 for `xmlrpc.php` and a PHP file under `uploads`.
- **CI job `System packages`** (`.github/workflows/lint.yml`): `v-check-sys-packages`, then a matrix of `debian:12`, `debian:13`, `ubuntu:22.04`, `ubuntu:24.04`, `ubuntu:26.04` containers running `apt-get update` and `apt-cache show` of every package of the release's list (universe enabled on Ubuntu).
- **Pest:** `SystemPackages`; the page and both actions for admin, reseller A, reseller B and user; read-only refusal; refusals from the command shown; the `Capabilities` entry; `RuntimeAvailability` for each runtime; picker options with both badge tones. Fixtures under `tests/Fixtures/commands/v-list-sys-packages/`.
- **Vitest:** no new module (`apTask` is reused).
- **Playwright:** admin opens `/server/packages`, installs the fixture entry, waits for the task, sees Installed; the Web Domains picker shows a Python template red before and green after.

## 12. Documentation changes

- [13 Data model reference](../../design/13-data-model-reference.md): the catalog directory, `data/sys-packages.conf` and its lock, `/etc/nginx/conf.d/arcpanel-wp.conf`, the four commands, the `package` task kind, the new `runtime` and category values.
- [08 App Store](../../design/08-app-store.md) §6 and [09](../../design/09-web-templates-metadata.md) §4.2 and §7.2: status lines pointing at this spec.
- [Roadmap](../../roadmap.md): status lines of E5 and E6.
- No change to [15](../../design/15-database-schema-and-erd.md): no panel database data.
- Implementation notes appended here.

## 13. Out of scope

- Redis, Memcached, OpenJDK, certbot DNS plugins, snap and third-party apt repositories.
- `redirect-only`, `maintenance` and a maintenance switch; variable types other than `port`.
- A process manager for Python, Ruby or Go apps.
- Apache2 templates and the documentation generator (batch 3).
- Package updates from the panel (D10).

## 14. Risks

| Risk                                                            | Mitigation                                                                                               |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| A package name differs on one release                           | CI matrix with `apt-cache show`; `os` overrides                                                          |
| Removing a package breaks something outside ArcPanel            | Only recorded packages are removed, never autoremove; packages listed by another record are kept         |
| Old runtimes on older releases (Go 1.18 on Ubuntu 22.04)        | The page shows the version; the docs say so; third-party sources stay possible later                     |
| The rename session and this work touch the same files           | New files where possible; existing `test/` and `docs/` files after the rename's M7; commits by path only |
| `limit_req` without its zone breaks `nginx -t` for every domain | The trigger drops the line when the zone file is missing; BATS case                                      |

## 15. Implementation notes (2026-10-06)

These notes win over the sections above where they differ.

1. **Commits.** M1 `3dff483`, M2 `81db59d`, M3 `b0647f4`, M4 `56ae76b`, M5 `6aaf97d` (with `068777e`, which restores the executable bit of the M4 and M5 triggers and of the batch 2 suite), M6 `3194c2e`, M7 the documentation and e2e commit.
2. **Routes.** The page is `server.packages` (`GET /server/packages`, as `server.updates` is named) and one action route `server.packages.start` (`POST /server/packages/{slug}/{add|delete}`) instead of two. A slug outside the catalog and an unknown action answer 404. The Remove button is disabled, without a form, while domains need the runtime.
3. **Per-request catalog.** `SystemPackages` is bound per request, so the page and `RuntimeAvailability` run `v-list-sys-packages` once. The Web Domains forms now also run `v-list-user-node-versions` for the domain owner when a template's runtime is `node`; `fakeWebDomains` in the Pest helpers fakes both commands.
4. **Usage scan.** `sp_domain_runtimes` reads the `TPL` (web kind) and `PROXY` (proxy kind) keys of every `web.conf` once per list and resolves each template's `runtime` through `arc_tpl_meta_file`, cached per template.
5. **CI.** The `System packages` job runs `bin/v-check-sys-packages` from the checkout with a stand-in `/etc/arcpanel/arcpanel.conf`; the apt matrix job looks the packages up with `apt-cache show`. The same lookup run in containers on 2026-10-06 found every package on all five releases, so no `os` override is needed. Versions: Go 1.19 (Debian 12), 1.24 (Debian 13), 1.18 (Ubuntu 22.04), 1.22 (24.04), 1.26 (26.04); Ruby 3.0 to 3.3; Python 3.10 to 3.14.
6. **Template deviations.**
   - `django` has no `favicon.ico` and `robots.txt` locations: serving them from the document root and falling back to the app needs a second copy of the proxy block. Django serves them, or `/static/` does.
   - `rails` sets `expires max` on `/assets/` and `/packs/` but no `Cache-Control: immutable` header: an `add_header` in those locations would drop the server's HSTS header.
   - `laravel-octane` sends every `.php` request to the Octane server (`try_files` on a name that never exists), so no PHP source of `public/` is ever served; other files of `public/` are served by NGINX.
   - `wordpress-secure` denies with 403 inside `location /`, before the PHP and static handlers, because NGINX checks the nested regular expressions of the matching prefix location before those of the server. `xmlrpc.php` and `wp-login.php` have exact locations; `.git` and `.svn` keep the 404 of the skeleton's dotfile rule. `v-add-sys-web-templates-meta` removes the zone file again when `nginx -t` fails with it.
7. **Templates are generated** from the batch 1 skeletons (`python-gunicorn` for `django`, `nodejs-proxy-ws` for the WebSocket templates, the upstream `wordpress` and `default` templates for `wordpress-secure`), so the parts outside the app block are those of batch 1.
8. **Verification before M8.** `develop` installs only from the fresh install of E9 R5 M8, and the rename session asked to keep every ArcPanel distribution stopped meanwhile. Run instead, on 2026-10-06:
   - ShellCheck 0.10 on every new and changed shell file (no error, no warning outside the excluded codes).
   - The four commands in a sandbox (`$ARCPANEL` in a temporary folder of the plain WSL Ubuntu 24.04 distribution, with stand-ins for `apt-get`, `apt` and `dpkg-query`): every refusal and exit code, install, removal, a package another record lists, the apt failure with the universe hint, all four formats.
   - `nginx -t` and functional checks of all six templates on both stacks in an `nginx:stable` container (static and media files, proxying with the query string, `try_files` fallbacks, PHP routing, every denied path, the 429 after six `wp-login.php` requests, the variant without the zone file).
   - Pest 826 passed, 1 skipped (PHP 8.5 container); Vitest 231 passed; Pint clean.
   - **Not run yet:** the BATS suites `arcpanel-sys-packages` and `arcpanel-templates-batch2`, the other ArcPanel suites, smoke and `test/e2e/system-packages.spec.js`. They run on the M8 install, and their results go here.
9. **e2e fixture.** `test/fixtures/sys-packages-e2e/arctest-e2e.json` (GNU hello, provides `ruby`) is placed in the shipped catalog by `prepare.sh syspackages` and removed by `syspackages-clean` and `cleanup`; the Rails template's badge shows the change.
