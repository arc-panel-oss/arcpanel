# E1 Laravel foundation, slice 1 (plumbing + login) — design

**Status:** Approved design (owner, 2026-10-05) · **Epic:** E1, slice S1 · **Base design:** [02 Laravel foundation](../../design/02-laravel-foundation.md) · **Decision records:** [ADR-0001](../../adr/0001-view-layer.md), [ADR-0002](../../adr/0002-url-scheme.md), [ADR-0006](../../adr/0006-rebranding-depth.md) · **Roadmap:** [§3 E1](../../roadmap.md)

## 1. Summary

The first shippable slice of E1. It builds the Laravel app (`web-app/`) next to the legacy `web/` app, the `ArcCommand` service that runs `v-*` commands, the bridge to the legacy PHP session, and moves the login page (password, 2FA, logout) to Laravel behind a configuration switch. It ships in the `arcpanel` package with installer and upgrade support. Dashboard and Web Domains are **not** in this slice; they move to slice S2 together with the E2 component library.

Design document 02 stays the reference for the architecture. This spec records the decisions of the 2026-10-05 brainstorm, the scope of S1 and the corrections to 02 found while reading the legacy code.

## 2. Decisions taken in the brainstorm

| # | Question | Decision |
| --- | --- | --- |
| D1 | Where the first slice stops | **Plumbing + login.** Dashboard and Web Domains move to S2 with E2. |
| D2 | Laravel version | **Laravel 13** (released 2026-03-17, PHP 8.3–8.5, bug fixes until Q3 2027, security fixes until Q1 2028). Laravel 12 no longer receives bug fixes. Supersedes "Laravel 12" in the roadmap and in 02. |
| D3 | Development and test loop | **WSL distribution `arcpanel-test`** (Ubuntu 24.04 with ArcPanel installed). Pest runs with the bundled `arcpanel-php` 8.5; code is deployed into `/usr/local/hestia` by a dev-sync script. |
| D4 | Extras in S1 | **All four:** login cut-over, packaging + installer, i18n import, impersonation endpoints. |
| D5 | Login cut-over style | **Switch, default on.** New `hestia.conf` key `ARCPANEL_APP_LOGIN` (`yes`/`no`); `no` returns to the legacy login without a downgrade. |
| D6 | Naming of new code | **`Arc*` names** for everything the panel app owns (`ArcCommand`, `App\Arc\`, `config/arc-commands.php`, …), see §4. Shared runtime names change only where it is safe: cookie `ARCPANELSID`, command path `/usr/local/arcpanel/bin`. |
| D7 | Deep rename of internals (`/usr/local/hestia`, `/etc/hestiacp`, `HESTIA_*`, `hestiaweb`, socket, gettext domain) | **Wanted, but a separate epic.** It needs ADR-0007 to supersede ADR-0006 decision 1 and its own brainstorm. S1 reads every path from `config/arc.php`, so the deep rename only changes configuration in the panel app. |
| D8 | ADR-0001 (view layer) and ADR-0002 (URL scheme) | **Accepted** (owner, 2026-10-05). |

## 3. Scope

### In scope

1. Laravel 13 skeleton in `web-app/`, PHP 8.5, served under `/app/` by `hestia-nginx` (ADR-0002).
2. `ArcCommand` service with argument escaping, command allow-list, `Secret` arguments, exit-code mapping, logging and a test fake.
3. `LegacySession`, `Principal` and the `BridgeLegacySession` middleware with the seven checks of `web/inc/main.php`.
4. Login, 2FA and logout on Laravel, matching the legacy behaviour in `web/login/index.php`.
5. Cut-over switch `ARCPANEL_APP_LOGIN` and the matching change in `web/login/index.php`.
6. Impersonation endpoints (`POST /app/impersonate/{user}`, `POST /app/impersonate/stop`), admin only.
7. i18n import from the gettext catalogues into Laravel JSON translations.
8. `/app/_health`.
9. Session cookie renamed to `ARCPANELSID` for the legacy app, the panel app and the web terminal.
10. Packaging: `web-app/` in the `arcpanel` deb, `v-add-sys-arcpanel`, a new sudoers file, installer step.
11. `test/dev-sync.sh` for the WSL loop.
12. Documentation updates listed in §13.

### Out of scope

- Dashboard, Web Domains list/add/edit and the E2 component library (slice S2).
- Policies other than `ImpersonationPolicy`; `OwnerScope` and reseller rules (E11).
- Password reset (`web/reset/`) and 2FA reset (`web/reset2fa/`): stay legacy until E7.
- Any UI that calls the impersonation endpoints (the legacy Users page keeps its `?loginas=` link).
- Deep rename of internal identifiers (D7).
- Upgrade from HestiaCP (deferred by ADR-0006).

### Done when

- On a fresh install with `ARCPANEL_APP_LOGIN=yes`, `/login/` redirects to `/app/login`; a user signs in (with and without 2FA) and lands on the same legacy page the legacy login would have chosen, without signing in again.
- Logout from either app ends the session in both.
- `v-change-sys-config-value ARCPANEL_APP_LOGIN no` brings back the legacy login form with no other change.
- The Pest, BATS and smoke suites in §12 pass on `arcpanel-test`.

## 4. Naming

New code owned by ArcPanel uses ArcPanel names. Names shared with the running system follow the table.

| Kind | Name in S1 | Note |
| --- | --- | --- |
| PHP namespace | `App\Arc\` (`web-app/app/Arc/`) | was `App\Hestia\` in 02 |
| Command service | `ArcCommand` (interface), `ProcessArcCommand`, `ArcCommandFake` | was `HestiaCommand` |
| Command exception | `ArcCommandException` | was `HestiaException` |
| Allow-list | `config/arc-commands.php` | generated at build time |
| Paths | `config/arc.php`: `root` = `/usr/local/arcpanel`, `bin_path` = `/usr/local/arcpanel/bin`, `install_dir` = `/usr/local/hestia`, `sessions_path` = `/usr/local/hestia/data/sessions`, `secret_dir` = `/tmp`; the environment file `/etc/arcpanel/arcpanel.env` is named in `bootstrap/app.php` (read before configuration loads) | the only places paths appear in the panel app |
| Command log | `storage/logs/arc-command.log` | |
| Artisan commands | `arcpanel:i18n-import`, `arcpanel:record-fixtures`, `arcpanel:allow-list` | |
| Middleware alias | `auth.arc` | was `auth.hestia` |
| Session cookie | `ARCPANELSID` | was `HESTIASID`; see §8 |
| Panel-app cookie | `arcpanel_session` | flash, CSRF, `pending_2fa` |
| Unchanged (ADR-0006, until the deep-rename epic) | `/usr/local/hestia` as the real path, `hestia.conf`, `/etc/hestiacp`, `HESTIA_*`, user `hestiaweb`, `/run/hestia-php.sock`, gettext domain `hestiacp`, `v-*` command names | |

`LegacySession`, `Principal`, `Role`, `SysConfig`, `ExitCode`, `CommandResult`, `Options` and `Secret` keep the names from 02.

## 5. Components

```text
web-app/
  app/Arc/                 ArcCommand, ProcessArcCommand, ArcCommandFake, CommandResult,
                           Options, Secret, ExitCode, ArcCommandException, SysConfig
  app/Auth/                LegacySession, Principal, Role, LoginService, LoginResult, Landing
  app/Http/Middleware/     BridgeLegacySession, RequirePrincipal, NotReadOnly, SetLocale
  app/Http/Controllers/    LoginController, TwoFactorController, LogoutController,
                           ImpersonationController, HealthController
  app/Policies/            ImpersonationPolicy
  app/Console/Commands/    I18nImport, RecordFixtures, BuildAllowList
  config/arc.php, config/arc-commands.php
  resources/views/         layouts/guest.blade.php, auth/login.blade.php, auth/two-factor.blade.php
  resources/css/arc/       login subset of templates/nocturne/assets/css/nocturne.css (tokens + form + button)
  tests/Unit, tests/Feature, tests/Fixtures/commands/, tests/Fixtures/sessions/
```

| Unit | Does | Used through | Depends on |
| --- | --- | --- | --- |
| `ArcCommand` | runs `sudo <bin_path>/v-*` (argv form, no shell), returns `CommandResult` or throws `ArcCommandException` | `run()`, `json()` | `config/arc.php`, `config/arc-commands.php` |
| `ArcCommandFake` | records calls, returns fixtures, fails the test on any unexpected command | `ArcCommand::fake()` | fixtures |
| `LegacySession` | the only code that touches `$_SESSION`; open, read, patch, establish, destroy | middleware, `LoginService`, controllers | `config/arc.php` |
| `Principal` | immutable view of who is acting, built once per request | container binding | `LegacySession` data |
| `LoginService` | the full login algorithm of §6; no HTTP | `attempt()`, `verifySecondFactor()` | `ArcCommand` |
| `Landing` | the post-login URL for a user record | `Landing::for(array $user)` | nothing |

02 §4 (`ArcCommand` contract, argument rules, error mapping, logging, fake) and §5.2–5.4, §5.6 (`LegacySession`, `Principal`, the seven checks, read-only) apply unchanged apart from the names in §4.

## 6. Login flow

This follows `web/login/index.php` (`authenticate_user()`, lines 115–390). It replaces 02 §5.5, which described the flow wrongly.

1. `GET /app/login`: if a `Principal` exists, redirect to `Landing`. Otherwise render the form. Language follows the legacy rule (lines 435–445): the server `LANGUAGE` from `v-list-sys-config` if it is in `v-list-sys-languages`, else `en`.
2. `POST /app/login {user, password}` (Laravel CSRF) → `LoginService::attempt(user, password, ip, userAgent)`:
   1. `v-get-user-salt USER IP json`. Exit 5 → "Account has been suspended"; exit 1 → "Unsupported hash method"; other failure → "Invalid username or password".
   2. Compute the hash in PHP from `SALT` and `METHOD`: `md5` → `crypt($pw, '$1$'.$salt.'$')`; `sha-512` → `crypt($pw, '$6$rounds=5000$'.$salt.'$')` with `$rounds=5000` removed; `des` → `crypt($pw, $salt)`; `yescrypt` → password to a `Secret` temp file, `v-check-user-password USER FILE IP yes`, hash = first output line.
   3. Hash to a `0600` file in `/tmp/` (`v-*` commands only read secret files below `/tmp/`, see `is_hash_valid` in `func/main.sh`), `v-check-user-hash USER FILE IP`, file deleted in `finally`.
   4. `v-list-user USER json`. Refuse when `LOGIN_DISABLED='yes'` (reason "Login disabled for this user") or when `LOGIN_USE_IPLIST='yes'` and the IP is not in `LOGIN_ALLOW_IPS` (reason "IP address not in allowed list").
   5. Every failure: `sleep(2)`, the generic message, and `v-log-user-login USER IP failed <fingerprint> <user agent> [yes "<reason>"]` with the reason strings of the legacy code. `<fingerprint>` is a 32-hex value kept in the panel-app session for this login attempt (legacy passes the form's `$_POST["token"]`); after a successful login it becomes the legacy session `token`, so the logout line matches the login line in the auth log.
3. If the user has `TWOFA`: store `pending_2fa = {user, expires_at: now + 5 min, attempts: 0}` in the **panel-app** session (the password is never stored; legacy kept it in `$_SESSION['login']`) and redirect to `/app/login/2fa`.
4. `POST /app/login/2fa {code}` → `v-check-user-2fa USER CODE`. Wrong code: `attempts++`, "Invalid or missing 2FA token". More than 3 wrong codes or an expired pending state: pending state cleared, `v-log-user-login … failed …` as legacy, back to `/app/login`.
5. On success, `LegacySession::establish()` writes: `user`, `userContext` (= `ROLE`), `LAST_ACTIVITY`, `user_combined_ip`, `token` (32 hex), `language` (validated against `v-list-sys-languages json`, else `en`), `userTheme` (only when `POLICY_USER_CHANGE_THEME='yes'`), `userSortOrder` (`PREF_UI_SORT` or `name`). Then `session_regenerate_id(true)`.
   System configuration keys are **not** copied: `web/inc/main.php:25` calls `load_hestia_config()` on every legacy request (closes 02 §12 question 1).
6. `v-log-user-login USER IP success <fingerprint> <user agent>`, then redirect to `Landing`. (Legacy reads `$_SESSION['request_uri']` at lines 367–369, but nothing in `web/` ever sets it, so there is no deep-link return to copy.)

`Landing::for()` copies lines 367–390: admin → `/list/user/`; otherwise the first non-zero of `WEB_DOMAINS` → `/list/web/`, `DNS_DOMAINS` → `/list/dns/`, `MAIL_DOMAINS` → `/list/mail/`, `DATABASES` → `/list/db/`, `CRON_JOBS` → `/list/cron/`, `BACKUPS` → `/list/backup/`, else `/error/`.

Logout: `POST /app/logout` → `v-log-user-logout USER <fingerprint>` → `LegacySession::destroy()` → panel-app session invalidated → `/app/login`. The legacy `/login/?logout` and `/logout/` keep working on the same session file.

## 7. Cut-over switch

- New key `ARCPANEL_APP_LOGIN` in `hestia.conf`, set with the existing `v-change-sys-config-value`. `v-list-sys-config` lists its keys explicitly, so it gains this one key in its `json` and `shell` output (an output-only addition, like `ARCPANEL_VERSION` in E9); no other `v-*` command is modified.
- `web/login/index.php`: at the top, after `main.php` is loaded, when `$_SESSION['ARCPANEL_APP_LOGIN'] === 'yes'`, no `$_SESSION['user']` and no `?loginas`, respond `302 Location: /app/login`. The `?loginas` branch, the logged-in branch and `?logout` stay legacy.
- Installer: sets `ARCPANEL_APP_LOGIN='yes'`. There is no upgrade step: ArcPanel 1.0.0 is not released yet (ADR-0006: fresh installs only), so S1 ships in 1.0.0. Servers installed from earlier development builds get the key from `test/dev-sync.sh`.
- Operations: if the panel app does not boot (missing `/etc/arcpanel/arcpanel.env`, broken `vendor/`), `/app/login` returns 50x; the fix is `v-change-sys-config-value ARCPANEL_APP_LOGIN no` over SSH.

## 8. Session cookie `ARCPANELSID`

- `src/deb/php/php.ini`: `session.name = ARCPANELSID`.
- `src/deb/web-terminal/server.js` and `web-terminal-session-auth.php`: read `ARCPANELSID`.
- `LegacySession` reads the name from `ini_get('session.name')`, so it follows `php.ini`.
- Effect: after the upgrade that ships S1, existing sessions are not found and every signed-in user signs in once more. Noted in the release notes.
- These files belong to ArcPanel's own packages (`arcpanel-php`, `arcpanel-web-terminal`), so the change is allowed under ADR-0006.

## 9. Impersonation

- `POST /app/impersonate/{user}` and `POST /app/impersonate/stop`, Laravel CSRF, `auth.arc`. Not `not-readonly`: an administrator looking at the protected system administrator is read-only and must still be able to switch away or stop, as in the legacy app.
- `ImpersonationPolicy`: login role `admin` only in S1 (E11 adds resellers for their own tree). The target must exist (`v-list-user`) and must not be the login user.
- Writes `look` through `LegacySession::write()` and logs exactly as `web/login/index.php:22-60` does. `stop` clears `look`.
- No UI calls these endpoints in S1. Feature tests cover them; S2 and E11 add the UI.

## 10. Internationalisation

- `php artisan arcpanel:i18n-import` reads `web/locale/<lang>/LC_MESSAGES/hestiacp.po` (falls back to the compiled `.mo`) and writes `web-app/lang/<lang>.json`, keyed by the English source string. Plural forms are joined with `|`, which `trans_choice()` selects with the locale's plural rules. Legacy strings with printf placeholders are used as `sprintf(__('Welcome to %s'), $name)` so the msgid matches.
- Runs on the server in `v-add-sys-arcpanel` from the installed catalogues; generated files are git-ignored.
- `SetLocale` uses `Principal::$language`, or the login-page rule of §6 step 1 for guests. `dir="rtl"` for `ar`, `fa`, `he`.
- The existing string extraction is extended to scan `web-app/resources/views`, so new login strings reach `hestiacp.pot` and Crowdin.

## 11. Packaging, installer and development loop

### nginx

`src/deb/nginx/nginx.conf` gets `location ^~ /app/build/` (static assets, `alias` to `web-app/public/build/`) and `location ^~ /app/` (every other request to `web-app/public/index.php`, `SCRIPT_NAME=/app/index.php`). Laravel derives its `/app` base URL from `SCRIPT_NAME`, so routes carry no prefix and no `APP_URL_PREFIX` setting is needed; at 2.0 only nginx changes.

### Sudo

New file `install/common/sudo/arcpanel` (installed to `/etc/sudoers.d/arcpanel`, mode `0440`):

```text
hestiaweb   ALL=NOPASSWD:/usr/local/arcpanel/bin/*
```

The existing `install/common/sudo/hestiaweb` is not changed. sudo accepts the symlinked path (verified by the BATS suite, §12).

### Build

`src/hst_autocompile.sh`, main package section, after `npm run build` (which also builds `web-app/public/build/arc.min.css` from `web-app/resources/css/arc.css`):

1. `composer install --working-dir=web-app --no-dev --no-scripts --prefer-dist --classmap-authoritative`. `--no-scripts` because Laravel's scripts need PHP 8.5, which the build host does not have; the distro `composer` package is installed with the other build dependencies when missing.
2. Copy `web-app` together with `bin func install web`.

The command allow-list and the translations are generated on the server (below), so they always match the installed `bin/` and `web/locale/`. `composer.json` pins `"platform": {"php": "8.5"}`. The bundled `arcpanel-php` already has every extension Laravel 13 needs.

### `v-add-sys-arcpanel` (new, idempotent)

- Creates `web-app/storage/{framework/{cache/data,sessions,views},logs,tmp}` and `web-app/bootstrap/cache`, owner `hestiaweb:hestiaweb`, mode `0750`.
- Writes `/etc/arcpanel/arcpanel.env` (directory `root:hestiaweb 0750`, file `root:hestiaweb 0640`): `APP_KEY` (random on the first run, kept afterwards), `APP_ENV=production`, `APP_DEBUG` from `DEBUG_MODE`, `LOG_LEVEL=info`. It is not under `/usr/local/hestia/conf`, which is `root:root 0750` and closed to `hestiaweb`.
- Installs `/etc/sudoers.d/arcpanel` (checked with `visudo -c`).
- Runs `arcpanel:allow-list` and `arcpanel:i18n-import`, then builds the config, event and view caches as `hestiaweb`. Routes are **not** cached: with a route cache Laravel trims the trailing slash of `/app/` before matching and loses the `/app` base URL, so `/app/` answers 405.
- Called by `install/hst-install-{debian,ubuntu}.sh` after the File Manager step.

### Development loop

`test/dev-sync.sh` (development only, not packaged): from Windows, copies `web-app/`, `web/`, `bin/`, `func/`, `install/`, `src/deb/nginx/nginx.conf`, `src/deb/php/php.ini` and the web terminal files into `/usr/local/hestia` on the WSL distribution `arcpanel-test`, as root-owned files with package modes (a Windows checkout reports mode 0777 and uid 1000, which is `hestiaweb` there) and LF line endings; runs `composer install`, `v-add-sys-arcpanel`, sets `ARCPANEL_APP_LOGIN` when missing and restarts `arcpanel`. Pest runs inside WSL in `/usr/local/hestia/web-app`; `phpunit.xml` points the framework cache paths away from the production caches.

## 12. Error handling and testing

### Error handling

As 02 §4.3 and §8: `ExitCode` → `ArcCommandException` → error flash (HTML) or `{"error":{"code","message"}}` (JSON). Secrets are masked in every log line and exception. `/app/_health` runs `v-list-sys-config json` and returns `{"status":"ok","version":"<ARCPANEL_VERSION>"}` or `503 {"status":"error"}` with no detail.

### Tests

| Level | Covers |
| --- | --- |
| Pest unit | `ArcCommand`: argv passing, allow-list, `\0`/newline rejection, `Secret` masking and file secrets, exit-code mapping, the fake. `LoginService` with `ArcCommandFake` for every branch: 4 hash methods, suspended, unsupported method, wrong password, login disabled, IP not allowed, malformed names, 2FA right / wrong / logging after 3 wrong codes. `PrincipalResolver` with `ArrayLegacySession` for each of the 7 checks, impersonation and the read-only matrix (native PHP sessions cannot start once PHPUnit has printed output; `NativeLegacySession` is covered by the smoke test). `Landing`. `.po` and `.mo` parsing. |
| Pest feature | Login, 2FA (incl. expiry and more than 3 wrong codes), logout over HTTP; CSRF rejection; impersonation start / stop / refusals; read-only POST rejection; `/app/_health`; JSON error rendering. |
| BATS (`test/arcpanel-panel-app.bats`) | `v-add-sys-arcpanel` file modes and kept `APP_KEY`; storage owner; allow-list; sudo through `/usr/local/arcpanel/bin` for `hestiaweb`; `hestia-nginx -t`; `/app/_health`; `/app/` with caches built; legacy `/login/` 302 when `ARCPANEL_APP_LOGIN=yes` and 200 when `no`; `ARCPANELSID` cookie. |
| Smoke (`test/arcpanel-login-smoke.sh` on `arcpanel-test`) | Throw-away user signs in on `/app/login` (wrong password refused), opens legacy `/list/web/` with the same cookie jar without signing in again, `/app/` hands over to the legacy landing page, legacy `/logout/` ends the session, `/app/` then redirects to `/app/login`; auth log has the lines. The web terminal is not installed on `arcpanel-test`, so its `ARCPANELSID` check waits for a full install test. |

## 13. Documentation changes in the same change set

- **ADR-0001, ADR-0002:** status → Accepted (2026-10-05).
- **Rename in docs:** `HestiaCommand` → `ArcCommand` (and `HestiaCommandFake`, `ProcessHestiaCommand`, `HestiaException`, `App\Hestia\`, `hestia-commands.php`, `hestia-command.log`, `hestia:record-fixtures`, `auth.hestia`) in the roadmap, ADR-0001 and `docs/design/*`.
- **02 Laravel foundation:** Laravel 13; §5.5 replaced by a pointer to §6 of this spec; open question 1 closed; cookie `ARCPANELSID`; cut-over switch; paths from `config/arc.php`.
- **Roadmap:** "Laravel 12" → "Laravel 13"; E1 split into S1 (this spec) and S2 (Dashboard + Web Domains with E2); a note under E9 that a deep rename of internals is requested and needs ADR-0007 and its own brainstorm.

## 14. Risks

| Risk | Mitigation |
| --- | --- |
| A login regression locks everyone out of every server | Switch `ARCPANEL_APP_LOGIN` (§7); smoke test in CI on the install VM before release |
| Hash computation differs from legacy for one method | Unit tests per method with fixtures recorded from a real server (`arcpanel:record-fixtures`) |
| Session file locking between the two apps | Middleware closes the session right after the read and `LAST_ACTIVITY` update (02 §5.2) |
| `vendor/` adds size to the deb and needs Composer in the release pipeline | `--no-dev --classmap-authoritative`; `hst_autocompile.sh` installs the distro `composer` package when missing |
| sudo does not accept the symlinked `/usr/local/arcpanel/bin` path | Verified by the BATS suite on Ubuntu 24.04; check again on Debian 12/13 and Ubuntu 26.04 (sudo-rs) |
| Cookie rename signs everyone out once | Only development builds have sessions today (1.0.0 is unreleased) |

## 15. Implementation notes (2026-10-05)

Decisions taken while building S1 that differ from, or add to, the sections above (all reflected there):

- Secret files live in `/tmp/`, not `storage/tmp/` (the `v-*` scripts accept nothing else).
- The panel configuration file is `/etc/arcpanel/arcpanel.env`, not `/usr/local/hestia/conf/arcpanel.env` (unreadable for `hestiaweb`).
- `v-list-sys-config` lists `ARCPANEL_APP_LOGIN`, the only change to an existing command.
- No upgrade step and no version bump: S1 ships in the unreleased 1.0.0.
- Allow-list and translations are generated on the server, the build runs `composer install --no-scripts`.
- No route cache (`/app/` 405 with a cached router under a sub-directory).
- `ArcCommand::schedule()` from 02 §4.1 is not built: nothing in S1 queues a command.
- `/app/` itself redirects to the legacy `/login/`, which picks the landing page; the Laravel dashboard replaces this in S2.
- The panel app's stylesheet bundles the whole Nocturne prototype (`@import` of `templates/nocturne/assets/css/nocturne.css`) until E2 replaces it; fonts fall back to system fonts (no Google Fonts request).
- `config/filesystems.php` turns off Laravel's `storage/{path}` serving routes.
