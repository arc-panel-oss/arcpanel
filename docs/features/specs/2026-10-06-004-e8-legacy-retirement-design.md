# E8 — Legacy retirement and the 2.0 cut-over — design

**Status:** Approved (2026-10-06) · **Epic:** E8 · **Base design:** [10 Page migration](../../design/10-page-migration-and-retirement.md) §6, [02 Laravel foundation](../../design/02-laravel-foundation.md) §5.9, [11 Testing and CI](../../design/11-testing-and-ci.md) §5 · **Decisions this spec builds on:** [ADR-0002](../../adr/0002-url-scheme.md) (URL scheme), [ADR-0006](../../adr/0006-rebranding-depth.md) (fresh installs only from HestiaCP, `ARCPANEL_VERSION`, `install/upgrade/arcpanel/`) · **Previous specs:** [E3](./2026-10-05-008-e3-file-manager-design.md) (FileGator replacement), [E7 batch 3](./2026-10-06-003-e7-batch3-pages-design.md) (last migrated pages) · **Roadmap:** [§3 E8](../../roadmap.md)

## 1. Summary

E7 gave every legacy page a panel app page. E8 removes the legacy side:

- The panel app moves from `/app/` to the root URL. Old `/app/*` URLs and legacy page URLs answer with a 301 for one release.
- The whole `web/` directory is deleted from the repository and from servers. The pieces that commands still use (Quick Install, 2FA helpers, the mail wrapper, the API script, locales, logos, RRD data, nginx error pages) move to a new `lib/` directory (`$HESTIA/lib`) or to `$HESTIA/data/`.
- FileGator is removed; the two file manager commands only toggle `FILE_MANAGER`.
- The installer, the package build and the version (`2.0.0`) change accordingly, and an ArcPanel upgrade step takes a 1.x server to 2.0.
- CI gains the upstream-diff check, and a merge helper keeps upstream merges cheap after the deletions.

This differs from [10](../../design/10-page-migration-and-retirement.md) §6 in three places:

1. `web/` is deleted completely instead of keeping `web/api`, pieces of `web/inc`, `web/images` and `web/rrd` in place.
2. The upgrade path is ArcPanel 1.x → 2.0 (ADR-0006: no in-place upgrade from HestiaCP), so the step lives in `install/upgrade/arcpanel/2.0.0.sh`, not in `install/upgrade/versions/`.
3. The release itself (tag, APT publication) waits for the E9 release infrastructure.

## 2. Decisions taken in the brainstorm

| #   | Question                                   | Decision                                                                                                                                                                                                                                                                                                                                                              |
| --- | ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Scope                                      | **E8 only:** root URL, legacy deletion, FileGator removal, installer and packaging, version `2.0.0`, the 2.0.0 upgrade step, the upstream-diff CI job, CHANGELOG and upgrade notes. E5 (apt/snap apps), E6 batch 2, the release infrastructure (E9) and the CI OS matrix (E10) stay separate.                                                                         |
| D2  | Upgrade path                               | **ArcPanel 1.x → 2.0** through `install/upgrade/arcpanel/2.0.0.sh`, tested with BATS and by a real in-place upgrade of a snapshot of the test server. No upgrade from HestiaCP (ADR-0006).                                                                                                                                                                            |
| D3  | Old URLs                                   | Panel routes move from `/app/…` to `/…`. For one release, `/app/*` → 301 `/*` and legacy page URLs (`/list/web/`, `/edit/user/?user=…`) → 301 to the matching panel route, through one `LegacyRedirects` controller ported from `web/inc/arcpanel-routes.php`.                                                                                                        |
| D4  | What happens to `web/`                     | **The whole directory goes.** Code that commands still need moves with `git mv` to `lib/` (runtime `$HESTIA/lib`); runtime data moves to `$HESTIA/data/` (§6). The upstream files that reference the old paths are changed and recorded as exceptions (§11). No `$HESTIA/web` symlink.                                                                                |
| D5  | Upstream merges after the deletion         | A list of retired paths (`.arcpanel/retired-paths`) and `scripts/upstream-merge-cleanup.sh`, run after `git merge upstream`, drop upstream changes to retired files. Moves are plain renames, so git's rename detection carries upstream changes into `lib/`. The upstream-diff CI job flags new references to `$HESTIA/web/` (§10).                                  |
| D6  | FileGator                                  | `v-add-sys-filemanager` and `v-delete-sys-filemanager` are rewritten (recorded exception) to only set `FILE_MANAGER` to `true` / `false`; the delete command also removes a leftover `$HESTIA/web/fm`. Their callers (installers, `func/upgrade.sh`, `func/syshealth.sh`) need no change to the call lines. `install/deb/filemanager/` and the `/fm/` nginx block go. |
| D7  | `ARCPANEL_APP_PAGES`, `ARCPANEL_APP_LOGIN` | **Removed.** The upgrade step deletes both keys, `v-list-sys-config` stops printing them, the installers stop writing them, and the login and landing code drops the branches.                                                                                                                                                                                        |
| D8  | Verification                               | Fresh install on a clean WSL Ubuntu 24.04 distribution plus an in-place upgrade of a snapshot of `arcpanel-test`, each with BATS, the smoke script, the full Playwright suite and `hestia-nginx -t`; Pest and Vitest on the workstation. The Debian 12/13 matrix in CI stays with E10/E9.                                                                             |
| D9  | Documentation                              | CHANGELOG 2.0.0 (breaking changes), upgrade and rollback notes, a theme note, updates to 01, 02, 10, 13, ADR-0002, roadmap, README and AGENTS. Docs site pages for Ports, Node, Apps, Resellers and Templates are a separate docs task.                                                                                                                               |
| D10 | Leftovers absorbed                         | The web log download moves to the panel app (the last `LegacyRoutes` entry); legacy links in notifications and installer messages are fixed; session code that only served legacy pages goes (02 §5.9); the legacy deny lists in nginx go; the API stays a plain script (moved, §6.4).                                                                                |
| D11 | Themes                                     | Legacy CSS themes are dropped; the panel themes (`nocturne`, `light`, `auto`) become the values of `THEME`. `v-list-sys-themes` lists them; the upgrade step maps old values (`dark` → `nocturne`, `default` / `vestia` / `flat` → `light`, anything else → `nocturne`). Custom CSS themes are no longer supported (CHANGELOG, theme note).                           |
| D12 | Mail wrapper                               | `lib/mail-wrapper.php` is a standalone rewrite with the same command line (`-s SUBJECT EMAIL [NOTIFY]`, body on stdin) and the same behaviour as legacy `send_email()`. It loads neither the legacy bootstrap nor Laravel, so notifications still go out when the panel app is broken.                                                                                |

## 3. Milestones

| #   | Milestone                            | Content       | Done when                                                                                                                                                                                                                         |
| --- | ------------------------------------ | ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M0  | Snapshot                             | §9.1          | `arcpanel-test` exported as a WSL image before any E8 change is synced to it                                                                                                                                                      |
| M1  | Root URL                             | §5            | Pest and Vitest green; BATS proves the 301s and the absence of the switches; the Playwright suite runs on root URLs and is green on `arcpanel-test`                                                                               |
| M2  | `lib/` and the deletion of `web/`    | §6            | `web/` is gone; `grep` finds no `$HESTIA/web/`, `/usr/local/hestia/web/` or `../web/` outside the files listed as unchanged in §6.2; BATS for 2FA, Quick Install, mail wrapper, languages, themes, White Label, API and RRD green |
| M3  | FileGator, installer, packaging      | §7            | The package builds with `src/hst_autocompile.sh`; `hestia-nginx -t` passes; `test/shellcheck.sh`, `test/brand-check.sh` and `npm run lint` green; BATS for the file manager commands green                                        |
| M4  | Upgrade step                         | §8            | BATS: a fake 1.x install with legacy data runs only `2.0.0.sh`, ends in the 2.0 layout, and a second run changes nothing                                                                                                          |
| M5  | Verification                         | §9            | The upgraded snapshot and the fresh install both pass BATS, smoke and the full Playwright suite, plus the manual checklist                                                                                                        |
| M6  | Upstream-diff CI, merge helper, docs | §10, §11, §13 | CI job green on `develop`; merge helper tried on a scratch merge; documents of §13 updated; implementation notes appended to this spec                                                                                            |

Each milestone ends with a green run of the suites it touches and one commit (`feat(e8): …`, `docs(e8): …`).

## 4. Scope

### In scope

- Everything in §5–§11.
- Rewriting the BATS, Pest and Playwright cases that use `/app/` URLs or the two switches.

### Out of scope

- Publishing the 2.0 release (tag, APT suite, signing): waits for the E9 release infrastructure.
- CI install tests on Debian 12/13 and arm64 (E10, E9).
- E5 system apps, E6 batch 2 and 3 templates, docs site pages for the new features.
- Porting the API script to a controller (E29).
- Upgrade from HestiaCP 1.10.5 servers (ADR-0006, R4).
- Renaming the `LegacySession` / `NativeLegacySession` classes: they describe the session format, which stays.

### Done when

- No file of `web/` exists in the repository or under `$HESTIA` on an upgraded or freshly installed server.
- Every page answers at its root URL; `/app/*` and the legacy URLs of §5.3 answer 301.
- FileGator is installed neither by the installer, nor by upgrades, nor by the health check.
- Notifications, 2FA, Quick Install, the API, White Label logos, RRD graphs and the language list work from the new paths (BATS).
- `ARCPANEL_VERSION='2.0.0'` after a fresh install and after the upgrade; `VERSION` keeps the upstream base.
- The upstream-diff job passes and the exception list in 01 §3.2 matches §11.
- The documents of §13 are updated.

## 5. Root URL (M1)

### 5.1 nginx (`src/deb/nginx/nginx.conf`)

- `root /usr/local/hestia/web-app/public;` and `index index.php;`.
- `location / { try_files $uri /index.php?$query_string; }` and `location = /index.php` → PHP-FPM with `SCRIPT_FILENAME /usr/local/hestia/web-app/public/index.php` and `SCRIPT_NAME /index.php` (Laravel derives its base URL from it); `fastcgi_read_timeout 600s` as today.
- `location ^~ /build/` → `web-app/public/build/`, 7-day cache.
- Kept: `/_arc_files/` (internal), `/backup/` (internal), `/_shell/`.
- Moved (§6): `location ^~ /api/` → `lib/api/index.php` through PHP-FPM; `location ^~ /images/custom/` → `data/white-label/`; `location = /favicon.ico` → `lib/images/favicon.ico`; `location ^~ /rrd/` (internal) → `data/rrd/`; `error_page` → `lib/error/`.
- Removed: the `/app/` and `/app/build/` blocks (replaced by the redirects of §5.3), `/fm/`, the generic `location ~ \.php$`, the deny list for `inc|src|locale|templates`, the Quick Install image rule.
- Any other path ending in `.php` is handled by Laravel (404), never by a PHP file on disk.

### 5.2 Panel app

- Vite and the esbuild panel output use `/build/` as base (`build.js`, the asset helper of `web-app`).
- `Landing::APP` becomes `/`; the branches on `ARCPANEL_APP_PAGES` and `ARCPANEL_APP_LOGIN` in `Landing`, `LoginResponder` and the login page go.
- The style guide eyebrow text loses the `/app` prefix.
- `App\View\LegacyRoutes` is deleted once the web log download exists (§5.4); navigation and the palette have no legacy links left.
- Session code that only served legacy pages is removed (02 §5.9): for example the clean-up of leftovers of a legacy login attempt in `LoginService` and the legacy CSRF token handed to links into legacy pages. Keys that the web terminal or the API still read stay. M1 lists every removal in the implementation notes. `LegacySession` stays the only session handler, with the same cookie, path and serialization; `web-terminal-session-auth.php` is unchanged.

### 5.3 Redirects (`LegacyRedirects`)

A controller whose routes are registered after every other route, for one release (removed in 2.1):

- `GET /app` and `GET /app/{path?}` → 301 to `/{path}`, query string kept.
- `GET` on a legacy page path → 301 to the panel route. The table and the query-string handling are ported from `arc_route()` and `arc_redirect_if_migrated()` of `web/inc/arcpanel-routes.php` (for example `/edit/web/?domain=x` → `web.domains.edit`), plus `/login/` → `login`, `/logout/` → the logout page, `/fm/` → `files.index`, `/reset/` and `/reset2fa/` → the guest flows.
- Unknown legacy paths, and non-GET requests to legacy paths, answer 404.
- Targets are built with `route()` and validated parameters, never by concatenating request input into a URL.

### 5.4 Web log download

`GET /web/domains/{domain}/logs/{type}/download` (`web.logs.download`, `type` ∈ `access`, `error`) streams the output of `v-list-web-domain-accesslog` / `v-list-web-domain-errorlog` as `<domain>.<type>-log.txt`, owner-scoped like the log viewer. Capability entry, policy and Pest tests for the four principals.

### 5.5 Links in messages

- Installer welcome notification: `/add/user/` → `/users/create`, `/add/web/` → `/web/domains/create`, in both installers.
- Every `href="/…"` in notification and e-mail text under `bin/`, `func/` and `install/` is listed in M1 and pointed at a panel route; the list goes into the implementation notes.

## 6. `lib/` and the deletion of `web/` (M2)

### 6.1 Layout

| From                                                                                                                                                                                                                                                                                                                                | To                                         | Notes                                                                                                                                                                |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `web/inc/composer.json`, `web/src/composer.json` (and locks)                                                                                                                                                                                                                                                                        | `lib/composer.json`, `lib/composer.lock`   | One Composer project: the union of both `require` lists at their current versions, `autoload` `Hestia\` → `quickinstall/app/`. `lib/vendor/` is built, not committed |
| `web/src/init.php`, `web/src/app/`                                                                                                                                                                                                                                                                                                  | `lib/quickinstall/`                        | `init.php` loads `../vendor/autoload.php`                                                                                                                            |
| `web/inc/2fa/`                                                                                                                                                                                                                                                                                                                      | `lib/2fa/`                                 | loads `../vendor/autoload.php`                                                                                                                                       |
| `web/inc/mail-wrapper.php`                                                                                                                                                                                                                                                                                                          | `lib/mail-wrapper.php`                     | rewritten, §6.3                                                                                                                                                      |
| `web/api/index.php`, `web/inc/helpers.php`                                                                                                                                                                                                                                                                                          | `lib/api/index.php`, `lib/api/helpers.php` | §6.4                                                                                                                                                                 |
| `web/locale/`                                                                                                                                                                                                                                                                                                                       | `lib/locale/`                              | including `hestiacp.pot`, `hst_scan_i18n.sh` (now scans `web-app/` and `lib/`) and `hst_convert_po2mo.sh`                                                            |
| `web/images/logo.svg`, `logo.png`, `logo-header.svg`, `favicon.png`, `web/favicon.ico`                                                                                                                                                                                                                                              | `lib/images/`                              | shipped defaults                                                                                                                                                     |
| `web/images/custom/` (runtime)                                                                                                                                                                                                                                                                                                      | `$HESTIA/data/white-label/`                | files placed by the admin; not in the package                                                                                                                        |
| `web/rrd/` (runtime)                                                                                                                                                                                                                                                                                                                | `$HESTIA/data/rrd/`                        | moved by the upgrade step                                                                                                                                            |
| `web/error/`                                                                                                                                                                                                                                                                                                                        | `lib/error/`                               | static pages for nginx when PHP-FPM is down                                                                                                                          |
| `web/robots.txt`                                                                                                                                                                                                                                                                                                                    | —                                          | `web-app/public/robots.txt` already exists                                                                                                                           |
| everything else: `list/`, `add/`, `edit/`, `bulk/`, `delete/`, `suspend/`, `unsuspend/`, `start/`, `stop/`, `restart/`, `schedule/`, `download/`, `copy/`, `move/`, `search/`, `generate/`, `update/`, `reset/`, `reset2fa/`, `login/`, `logout/`, `index.php`, `templates/`, `js/`, `css/`, `webfonts/`, the rest of `inc/`, `fm/` | deleted                                    |                                                                                                                                                                      |

### 6.2 Reference changes

| File                                                                                                                                | Change                                                                                                              |
| ----------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `func/main.sh`                                                                                                                      | `RRD=$HESTIA/data/rrd`, `SENDMAIL=$HESTIA/lib/mail-wrapper.php`; `HESTIA_THEMES` and `HESTIA_THEMES_CUSTOM` removed |
| `func/upgrade.sh`                                                                                                                   | `send_mail` path; the FileGator version block only calls the rewritten commands                                     |
| `func/internal/sanitize_html.php`, `func/quickinstall.php`, `func/quickinstall.sh`                                                  | `lib/vendor` and `lib/quickinstall` paths                                                                           |
| `bin/v-add-user-2fa`, `bin/v-check-user-2fa`                                                                                        | `lib/2fa/`                                                                                                          |
| `bin/v-quick-install-app`, `bin/v-list-quick-install-apps`                                                                          | `lib/vendor`, `lib/quickinstall`                                                                                    |
| `bin/v-add-sys-dependencies`                                                                                                        | installs the Composer packages in `lib/` (one project instead of two)                                               |
| `bin/v-change-sys-api`                                                                                                              | `lib/api/index.php`; the re-download URL points at `lib/api/index.php`                                              |
| `bin/v-change-sys-language`, `bin/v-change-user-language`, `bin/v-list-sys-languages`                                               | `lib/locale/`                                                                                                       |
| `bin/v-list-sys-themes`                                                                                                             | prints `nocturne`, `light`, `auto` (D11)                                                                            |
| `bin/v-update-white-label-logo`                                                                                                     | custom files from `data/white-label/`, defaults in `lib/images/`, download URLs to `lib/images/…`                   |
| `bin/v-update-web-domain-stat`                                                                                                      | logo from `lib/images/logo.svg`                                                                                     |
| `bin/v-update-sys-hestia-git`                                                                                                       | builds `lib/` instead of `web/`, makes `lib/mail-wrapper.php` executable                                            |
| `bin/v-change-sys-config-value`                                                                                                     | the FileGator `configuration.php` branch goes                                                                       |
| `bin/v-list-sys-config`                                                                                                             | stops printing `ARCPANEL_APP_PAGES` and `ARCPANEL_APP_LOGIN` (ArcPanel additions, D7)                               |
| `bin/v-add-sys-arcpanel`, `web-app/app/Console/Commands/I18nImport.php`                                                             | the i18n import reads `lib/locale`                                                                                  |
| `web-app/app/View/WhiteLabel.php`                                                                                                   | checks `data/white-label/logo-header.svg`; the URL `/images/custom/logo-header.svg` is unchanged                    |
| `web-app/app/View/UiPreferences.php`                                                                                                | `THEME` values that are panel themes are used as they are; legacy names keep their mapping as a fallback            |
| `install/hst-install-debian.sh`, `install/hst-install-ubuntu.sh`                                                                    | `send_mail` path, `THEME` default `nocturne`, links (§5.5), FileGator and switch lines (§7)                         |
| `src/hst_autocompile.sh`, `build.js`, `package.json`, `test/dev-sync.sh`, `test/check_php.sh`, `test/brand-check.sh`, `crowdin.yml` | paths (§6.5, §7)                                                                                                    |

Left unchanged:

- `install/upgrade/versions/*.sh`: upstream steps for versions up to 1.10.5, never run on ArcPanel.
- `bin/v-restart-proxy`: it checks for `web/inc/nginx_proxy`, a flag file that nothing creates, so the check stays false.

Both are listed in `test/upstream-diff.sh` as ignored for the old-path grep (§10.2).

### 6.3 Mail wrapper

Same contract as legacy: `lib/mail-wrapper.php -s SUBJECT EMAIL [NOTIFY]`, body on stdin, exit `3` with the usage text when arguments are missing, silent exit when the fourth argument is `no`.

Behaviour copied from `send_email()` and `mail-wrapper.php` of 1.x:

- Settings from `v-list-sys-config json` through `sudo`: `FROM_EMAIL`, `FROM_NAME`, `APP_NAME`, `USE_SERVER_SMTP`, `SERVER_SMTP_*`.
- From address: `FROM_EMAIL`, else `noreply@<hostname>`; with server SMTP on and a valid `SERVER_SMTP_ADDR`, that address. From name: `FROM_NAME`, else `APP_NAME`.
- Hostname detection as `get_hostname()` of 1.x.
- PHPMailer, HTML body from `nl2br()` of stdin, UTF-8, SMTP authentication when `USE_SERVER_SMTP='true'`.
- No translation: callers pass the final text.
- The SMTP password is never printed, also not on failure.

### 6.4 API

`lib/api/index.php` keeps its behaviour and its URL. It loads `__DIR__ . '/../vendor/autoload.php'` and `__DIR__ . '/helpers.php'` instead of paths relative to `DOCUMENT_ROOT`. `v-change-sys-api` enables and disables it as before.

### 6.5 Build

`build.js` copies the Quick Install logos from `lib/quickinstall/app/WebApp/Installers` (today from `web/src`). The legacy bundle (`web/js`, `web/css/src/themes`) is removed from `build.js` and `package.json`.

## 7. FileGator, installer and packaging (M3)

- `bin/v-add-sys-filemanager`: validates, sets `FILE_MANAGER='true'` with `v-change-sys-config-value`, logs. No download, no Composer, no `web/fm`. The `quiet` argument is accepted as before.
- `bin/v-delete-sys-filemanager`: sets `FILE_MANAGER='false'`, removes `$HESTIA/web/fm` if it exists, logs.
- `install/deb/filemanager/` is deleted; the `FILE_MANAGER` default is unchanged (`true` unless the installer gets `--filemanager no`).
- Installers: no `ARCPANEL_APP_PAGES` / `ARCPANEL_APP_LOGIN` lines; `THEME` default `nocturne`; `lib/` owned like `web/` was (`root:root`, `mail-wrapper.php` executable).
- `src/hst_autocompile.sh`: copies `bin func install lib web-app`, runs `composer install --no-dev` in `lib/` and in `web-app/`, `npm run build` for the panel only, `chmod +x lib/mail-wrapper.php`.
- `src/deb/arcpanel/control`: `Version: 2.0.0`. `ARCPANEL_VERSION='2.0.0'` follows from the package version as today.
- `test/dev-sync.sh`: syncs `lib/` (without `vendor/`, then runs Composer there), drops the `$HESTIA/web` sync, makes the new mail wrapper executable.

## 8. Upgrade step `install/upgrade/arcpanel/2.0.0.sh` (M4)

Idempotent; every step checks before it acts and logs what it did.

1. Create `$HESTIA/data/rrd` and `$HESTIA/data/white-label`; move the content of `$HESTIA/web/rrd/` and `$HESTIA/web/images/custom/` when present.
2. Map `THEME` in `hestia.conf` and the `THEME` of every user (D11) with `v-change-sys-config-value` and `v-change-user-theme`.
3. Delete `ARCPANEL_APP_PAGES` and `ARCPANEL_APP_LOGIN` from `hestia.conf`.
4. Remove `$HESTIA/web/fm` with `v-delete-sys-filemanager`, then set `FILE_MANAGER` back to its value before the step.
5. Delete `$HESTIA/web` by an explicit list of the 1.x entries; anything left over is logged and kept, and the directory itself is removed only when empty.
6. Run `v-update-white-label-logo` to refresh the default logos from the new paths.
7. Run `php artisan optimize` for the panel app and restart `hestia` (nginx and PHP-FPM pick up the `nginx.conf` shipped by the package).

### 8.1 Rollback

Documented in the upgrade notes: install the 1.x packages from the APT cache, move `data/rrd` back to `web/rrd` and `data/white-label` to `web/images/custom`, restore `conf/` from `HESTIA_BACKUP`, set `ARCPANEL_APP_PAGES` back to `yes`, restart `hestia`. The restored `conf/` brings back the old system `THEME`; users whose `THEME` was mapped see the nocturne theme on 1.x until they pick another one (cosmetic only).

## 9. Verification (M5)

### 9.1 Snapshot (M0)

Before the first E8 sync, `arcpanel-test` is exported with `wsl --export`. In M5 the image is imported as `arcpanel-upgrade`, a 2.0.0 package built from `develop` is installed over it, and the suites run there.

### 9.2 Runs

| Target                                  | Runs                                                                                                                                                                         |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Workstation                             | `npm run lint`, `test/shellcheck.sh`, `test/brand-check.sh`, Pest, Vitest                                                                                                    |
| `arcpanel-test` (dev-sync)              | `bats test/arcpanel-*.bats`, smoke, full Playwright suite                                                                                                                    |
| `arcpanel-upgrade` (snapshot + package) | the same as `arcpanel-test`, plus the manual checklist: login, login-as, 2FA, read-only, terminal, downloads, notification mail, White Label logo, RRD graphs, old bookmarks |
| Clean WSL Ubuntu 24.04 (fresh install)  | install from the built package, then the same as `arcpanel-test`                                                                                                             |

## 10. Upstream merges and CI (M6)

### 10.1 Retired paths and merge helper

- `.arcpanel/retired-paths`: one glob per line (`web/list/**`, `web/templates/**`, `install/deb/filemanager/**`, …), followed by a comment block that names the moves (`web/src/** → lib/quickinstall/**`, …).
- `scripts/upstream-merge-cleanup.sh`, run after `git merge upstream/<tag>`:
  - resolves modify/delete conflicts on retired paths with `git rm`;
  - lists upstream changes that git did not carry to `lib/` (files under moved globs that reappeared under `web/`);
  - exits non-zero while such files exist, so they are ported by hand.

### 10.2 Upstream-diff job

A new CI job runs `test/upstream-diff.sh`:

- It compares `bin/`, `func/`, `install/` and `src/` with the upstream tag of `install/upstream.conf`. Every modified upstream file must be on the exception list (§11, mirrored in `test/upstream-exceptions.txt`). Added files pass.
- It fails on any new reference to `$HESTIA/web/`, `/usr/local/hestia/web/` or `../web/` outside the files left unchanged in §6.2.

## 11. Recorded exceptions (01 §3.2)

Added to the existing list, all for E8:

- `func/main.sh` (paths), `func/upgrade.sh`, `func/internal/sanitize_html.php`.
- `bin/v-add-sys-filemanager`, `bin/v-delete-sys-filemanager` (D6).
- `bin/v-add-user-2fa`, `bin/v-check-user-2fa`, `bin/v-quick-install-app`, `bin/v-list-quick-install-apps`, `bin/v-add-sys-dependencies`, `bin/v-change-sys-api`, `bin/v-change-sys-language`, `bin/v-change-user-language`, `bin/v-list-sys-languages`, `bin/v-list-sys-themes`, `bin/v-update-white-label-logo`, `bin/v-update-web-domain-stat`, `bin/v-update-sys-hestia-git`, `bin/v-change-sys-config-value`.
- `install/hst-install-debian.sh`, `install/hst-install-ubuntu.sh`, `src/hst_autocompile.sh`, `src/deb/nginx/nginx.conf`.
- The deletion of `web/` (with the moves to `lib/`) and of `install/deb/filemanager/`.

## 12. Testing

| Suite      | New or changed cases                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BATS       | `test/arcpanel-e8.bats`: the root URL answers with the login page; `/app/web/domains` → 301 `/web/domains`; `/list/web/` → 301; `/edit/web/?domain=x` → 301 to the edit route; `/fm/` → 301; no `ARCPANEL_APP_*` keys; `v-add-user-2fa` and `v-check-user-2fa` round trip; `v-list-quick-install-apps json` lists the installers; `v-list-sys-languages` lists `en`; `v-list-sys-themes`; `v-update-white-label-logo` with a custom logo in `data/white-label`; `v-change-sys-api` enable and disable plus one API call; the mail wrapper with a fake `sendmail` (headers, body, `NOTIFY=no`, missing arguments); the file manager commands only toggle the key; RRD files are written under `data/rrd`; the upgrade step on a fake 1.x layout and its second run. Existing suites that test the switches or `/app/` URLs are updated |
| Pest       | The redirect controller (table, query strings, 404 for unknown paths); the web log download for admin, reseller A, reseller B and user, with a cross-tenant 404; `Landing`; `UiPreferences` with panel and legacy theme names; the `WhiteLabel` path; the `Capabilities` coverage test                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| Vitest     | Modules that build URLs use the new asset base                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| Playwright | Every spec moved to root URLs; one flow from an old bookmark (`/app/db`) to the Databases page; the web log download                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| CI         | The upstream-diff job; `hestia-nginx -t` on the shipped config in the Docker BATS job                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |

## 13. Documentation changes in the same change set

- `docs/design/01-architecture-overview.md`: the §3.2 exceptions (§11), the runtime layout (`lib/`, `data/rrd`, `data/white-label`), request routing at the root URL.
- `docs/design/02-laravel-foundation.md`: §5.9 marked done; base URL `/`.
- `docs/design/10-page-migration-and-retirement.md`: §6 status and the three deviations of §1.
- `docs/design/13-data-model-reference.md`: moved paths, removed keys `ARCPANEL_APP_PAGES` / `ARCPANEL_APP_LOGIN`, `THEME` values.
- `docs/adr/0002-url-scheme.md`: status note that the `/app` prefix ended with 2.0.
- New `CHANGELOG.md` with a 2.0.0 section: breaking changes (root URL, FileGator removed, custom CSS themes dropped, `web/` paths moved, switches removed), upgrade and rollback notes, theme note.
- `docs/roadmap.md` E8 status line; `README.md` and `AGENTS.md` (repository map without `web/`, with `lib/`).

## 14. Risks

| Risk                                                                    | Mitigation                                                                                          |
| ----------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| The rewritten mail wrapper differs from 1.x (sender, SMTP, `NOTIFY`)    | Capture a 1.x mail through a fake `sendmail` before the rewrite and compare in BATS                 |
| A reference to an old `web/` path is missed                             | The M2 grep, the CI grep of §10.2, BATS for every moved piece                                       |
| Future upstream merges touch moved or retired files                     | Rename detection, `upstream-merge-cleanup.sh`, the upstream-diff job                                |
| Bookmarks and documents point at legacy URLs                            | 301 redirects for one release                                                                       |
| The upgrade deletes files an admin added under `web/`                   | Deletion by explicit list; leftovers logged and kept                                                |
| Composer conflict when the two `composer.json` files are merged         | Both use Symfony 8.1; the lock is regenerated once and a Quick Install BATS case runs one installer |
| The upgrade test drifts from reality because `arcpanel-test` was synced | The M0 snapshot is taken before the first E8 sync                                                   |
