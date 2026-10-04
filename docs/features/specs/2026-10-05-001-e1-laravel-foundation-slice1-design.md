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
10. Packaging: `web-app/` in the `arcpanel` deb, `v-add-sys-arcpanel`, a new sudoers file, installer and ArcPanel upgrade step.
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
| Paths | `config/arc.php`: `root` = `/usr/local/arcpanel`, `bin_path` = `/usr/local/arcpanel/bin`, `sessions_path` = `/usr/local/hestia/data/sessions`, `env_file` = `/usr/local/hestia/conf/arcpanel.env` | the only place paths appear in the panel app |
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
   3. Hash to a `0600` file in `storage/tmp/`, `v-check-user-hash USER FILE IP`, file deleted in `finally`.
   4. `v-list-user USER json`. Refuse when `LOGIN_DISABLED='yes'` (reason "Login disabled for this user") or when `LOGIN_USE_IPLIST='yes'` and the IP is not in `LOGIN_ALLOW_IPS` (reason "IP address not in allowed list").
   5. Every failure: `sleep(2)`, the generic message, and `v-log-user-login USER IP failed <fingerprint> <user agent> [yes "<reason>"]` with the reason strings of the legacy code. `<fingerprint>` is the panel app's CSRF token for the form, as legacy passes `$_POST["token"]`.
3. If the user has `TWOFA`: store `pending_2fa = {user, expires_at: now + 5 min, attempts: 0}` in the **panel-app** session (the password is never stored; legacy kept it in `$_SESSION['login']`) and redirect to `/app/login/2fa`.
4. `POST /app/login/2fa {code}` → `v-check-user-2fa USER CODE`. Wrong code: `attempts++`, "Invalid or missing 2FA token". More than 3 wrong codes or an expired pending state: pending state cleared, `v-log-user-login … failed …` as legacy, back to `/app/login`.
5. On success, `LegacySession::establish()` writes: `user`, `userContext` (= `ROLE`), `LAST_ACTIVITY`, `user_combined_ip`, `token` (32 hex), `language` (validated against `v-list-sys-languages json`, else `en`), `userTheme` (only when `POLICY_USER_CHANGE_THEME='yes'`), `userSortOrder` (`PREF_UI_SORT` or `name`). Then `session_regenerate_id(true)`.
   System configuration keys are **not** copied: `web/inc/main.php:25` calls `load_hestia_config()` on every legacy request (closes 02 §12 question 1).
6. `v-log-user-login USER IP success <fingerprint> <user agent>`, then redirect to `Landing`. (Legacy reads `$_SESSION['request_uri']` at lines 367–369, but nothing in `web/` ever sets it, so there is no deep-link return to copy.)

`Landing::for()` copies lines 367–390: admin → `/list/user/`; otherwise the first non-zero of `WEB_DOMAINS` → `/list/web/`, `DNS_DOMAINS` → `/list/dns/`, `MAIL_DOMAINS` → `/list/mail/`, `DATABASES` → `/list/db/`, `CRON_JOBS` → `/list/cron/`, `BACKUPS` → `/list/backup/`, else `/error/`.

Logout: `POST /app/logout` → `v-log-user-logout USER <fingerprint>` → `LegacySession::destroy()` → panel-app session invalidated → `/app/login`. The legacy `/login/?logout` and `/logout/` keep working on the same session file.

## 7. Cut-over switch

- New key `ARCPANEL_APP_LOGIN` in `hestia.conf`, set with the existing `v-change-sys-config-value`. No `v-*` command is modified.
- `web/login/index.php`: at the top, after `main.php` is loaded, when `$_SESSION['ARCPANEL_APP_LOGIN'] === 'yes'`, no `$_SESSION['user']` and no `?loginas`, respond `302 Location: /app/login`. The `?loginas` branch, the logged-in branch and `?logout` stay legacy.
- Installer: sets `ARCPANEL_APP_LOGIN='yes'`. ArcPanel upgrade step (`install/upgrade/arcpanel/<version>.sh`): sets it to `yes` only when the key is missing, so an admin's `no` survives later upgrades.
- Operations doc: if the panel app does not boot (missing `arcpanel.env`, broken `vendor/`), `/app/login` returns 50x; the fix is `v-change-sys-config-value ARCPANEL_APP_LOGIN no` over SSH.

## 8. Session cookie `ARCPANELSID`

- `src/deb/php/php.ini`: `session.name = ARCPANELSID`.
- `src/deb/web-terminal/server.js` and `web-terminal-session-auth.php`: read `ARCPANELSID`.
- `LegacySession` reads the name from `ini_get('session.name')`, so it follows `php.ini`.
- Effect: after the upgrade that ships S1, existing sessions are not found and every signed-in user signs in once more. Noted in the release notes.
- These files belong to ArcPanel's own packages (`arcpanel-php`, `arcpanel-web-terminal`), so the change is allowed under ADR-0006.

## 9. Impersonation

- `POST /app/impersonate/{user}` and `POST /app/impersonate/stop`, Laravel CSRF, `auth.arc` + `not-readonly`.
- `ImpersonationPolicy`: login role `admin` only in S1 (E11 adds resellers for their own tree). The target must exist (`v-list-user`) and must not be the login user.
- Writes `look` through `LegacySession::write()` and logs exactly as `web/login/index.php:22-60` does. `stop` clears `look`.
- No UI calls these endpoints in S1. Feature tests cover them; S2 and E11 add the UI.

## 10. Internationalisation

- `php artisan arcpanel:i18n-import` reads `web/locale/<lang>/LC_MESSAGES/hestiacp.po` (falls back to the compiled `.mo`) and writes `web-app/lang/<lang>.json`, keyed by the English source string. Plurals map to Laravel's `{0}|{1}|[2,*]` form.
- Runs at package build; generated files are git-ignored.
- `SetLocale` uses `Principal::$language`, or the login-page rule of §6 step 1 for guests. `dir="rtl"` for `ar`, `fa`, `he`.
- The existing string extraction is extended to scan `web-app/resources/views`, so new login strings reach `hestiacp.pot` and Crowdin.

## 11. Packaging, installer and development loop

### nginx

`src/deb/nginx/nginx.conf` gets the `location ^~ /app/` and `@arcpanel` blocks from 02 §3.1, with `SCRIPT_FILENAME` pointing at `/usr/local/hestia/web-app/public/index.php`. `APP_URL_PREFIX=/app`.

### Sudo

New file `install/common/sudo/arcpanel` (installed to `/etc/sudoers.d/arcpanel`, mode `0440`):

```text
hestiaweb   ALL=NOPASSWD:/usr/local/arcpanel/bin/*
```

The existing `install/common/sudo/hestiaweb` is not changed. Whether sudo matches the symlinked path is verified by a BATS test (§12); if it does not, `config/arc.php` `bin_path` falls back to `/usr/local/hestia/bin` and the new sudoers file is dropped.

### Build

`src/hst_autocompile.sh`, main package section, after `npm run build`:

1. `composer install --no-dev --prefer-dist --classmap-authoritative --working-dir=web-app`
2. `php web-app/artisan arcpanel:allow-list` (from `ls bin/`) and `php web-app/artisan arcpanel:i18n-import`
3. Copy `web-app` together with `bin func install web`.

Composer must be available on the build host and in the GitHub Actions release workflow. `composer.json` pins `"platform": {"php": "8.5"}`.

The PHP extensions Laravel 13 needs (`ctype`, `dom`, `fileinfo`, `filter`, `mbstring`, `openssl`, `session`, `tokenizer`, `xml`) are checked against the `arcpanel-php` configure flags. Any missing one is added there in this slice.

### `v-add-sys-arcpanel` (new, idempotent)

- Creates `web-app/storage/{framework/{cache,sessions,views},logs,tmp}` and `web-app/bootstrap/cache`, owner `hestiaweb:hestiaweb`, mode `0750`.
- Writes `/usr/local/hestia/conf/arcpanel.env` (`root:hestiaweb 0640`) once: `APP_KEY` (random), `APP_ENV=production`, `APP_DEBUG` from `DEBUG_MODE`, `APP_URL_PREFIX=/app`. An existing file keeps its `APP_KEY`.
- Runs `artisan optimize`.
- Called by `install/hst-install-{debian,ubuntu}.sh` and by the ArcPanel upgrade step, which also installs the sudoers file and sets `ARCPANEL_APP_LOGIN` (§7).

### Development loop

`test/dev-sync.sh` (development only, not packaged): from Windows, syncs `web-app/`, `web/`, `bin/`, `func/`, `src/deb/nginx/nginx.conf`, `src/deb/php/php.ini` and the web terminal files into `/usr/local/hestia` on the WSL distribution `arcpanel-test`; runs `composer install` and `v-add-sys-arcpanel` there; restarts `arcpanel`. Pest runs inside WSL with `/usr/local/hestia/php/bin/php`.

## 12. Error handling and testing

### Error handling

As 02 §4.3 and §8: `ExitCode` → `ArcCommandException` → error flash (HTML) or `{"error":{"code","message"}}` (JSON). Secrets are masked in every log line and exception. `/app/_health` runs `v-list-sys-config json` and returns `{"status":"ok","version":"<ARCPANEL_VERSION>"}` or `503 {"status":"error"}` with no detail.

### Tests

| Level | Covers |
| --- | --- |
| Pest unit | `ArcCommand`: escaping, allow-list, `\0`/newline rejection, `Secret` masking, exit-code mapping. `LoginService` with `ArcCommandFake` for every branch: 4 hash methods, suspended, unsupported method, wrong password, login disabled, IP not allowed, 2FA right / wrong / expired / more than 3 attempts. `LegacySession` against fixture session files for each of the 7 checks. `Principal` read-only matrix. `Landing`. i18n import of a sample `.po` (plurals included). |
| Pest feature | Login → 2FA → logout over HTTP; CSRF rejection; impersonation allowed for admin, refused for user, refused in read-only mode; `/app/_health`. |
| BATS | `v-add-sys-arcpanel` twice gives the same result and keeps `APP_KEY`; `hestia-nginx -t` passes; sudo through `/usr/local/arcpanel/bin` works for `hestiaweb`; legacy `/login/` returns 302 when `ARCPANEL_APP_LOGIN=yes` and 200 when `no`. |
| Smoke (script on `arcpanel-test`) | Sign in on `/app/login`, open legacy `/list/web/` with the same cookie jar without signing in again; sign out through legacy `/logout/`, then `/app/` redirects to `/app/login`; web terminal still authenticates with `ARCPANELSID`. |

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
| `vendor/` adds size to the deb and needs Composer in the release pipeline | `--no-dev --classmap-authoritative`; Composer step added to the release workflow in this slice |
| sudo does not accept the symlinked `/usr/local/arcpanel/bin` path | BATS test; fallback in §11 |
| Cookie rename signs everyone out once | Release note |
