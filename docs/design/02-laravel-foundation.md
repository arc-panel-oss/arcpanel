# 02 — Laravel Foundation (Strangler)

**Epic:** E1 · **Status:** Draft v1.1 (2026-10-05: Laravel 13, `ArcCommand` names, login flow corrected — see the [E1-S1 spec](../features/specs/2026-10-05-001-e1-laravel-foundation-slice1-design.md)); implemented. Since 2.0 (E8, [spec](../features/specs/2026-10-06-004-e8-legacy-retirement-design.md)) the panel app serves the root URL and the legacy app is gone, so the two-app parts (§3.1, §3.3, the legacy side of §5) describe 1.x · **Depends on:** nothing · **Unblocks:** everything else

---

## 1. Goal

Run a Laravel 13 application next to the legacy PHP app, on the same port, with the same login, talking to the same `v-*` backend, so that pages can move one at a time. Deliver Login, Dashboard and Web Domains (list/add/edit) on Laravel as proof, with 2FA, "login as", read-only mode and i18n working.

Non-goals: a JSON-only API, a SPA, changing any `v-*` command, changing the `hestia-nginx` / `hestia-php` packages beyond one config file.

---

## 2. Current state (verified in code)

_State of HestiaCP 1.10.5 before E1; the `web/` paths below are gone since 2.0._

- `src/deb/nginx/nginx.conf`: one `server { listen 8083 ssl; root /usr/local/hestia/web; }` with `location ~ \.php$ → unix:/run/hestia-php.sock`, plus `/fm/` (FileGator), `/_shell/` (web terminal ws proxy), `/rrd/`, `/backup/` internal locations and a deny list for `/(inc|src|locale|templates)`.
- `web/inc/main.php` is the bootstrap of every legacy page: `session_start()`, IP-binding check (`user_combined_ip`), inactivity timeout, user existence re-check via `v-list-user`, admin demotion check, suspended logout, `look` handling, CSRF token, `policies.php` (read-only computation), i18n.
- Session: PHP `files` handler, cookie `HESTIASID` (renamed `ARCPANELSID` in E1-S1), path `/usr/local/hestia/data/sessions`. The web terminal (`web-terminal-session-auth.php`) already opens these files from another process by session id, which proves out-of-process reads work.
- Login (`web/login/index.php`): `v-check-user-password USER PASSWORD_FILE IP`, then `v-list-user USER json` to populate session (`userContext = ROLE`, `userTheme`, `userSortOrder`, `language`), `v-log-user-login` for the auth log; 2FA via `v-check-user-2fa USER CODE` with the password kept in `$_SESSION['login']` between steps; login-as via `?loginas=<user>` setting `$_SESSION['look']`.
- Every mutation: `exec(HESTIA_CMD . "v-... " . quoteshellarg($arg), $output, $return_var)` then `check_return_code($return_var, $output)` which turns a non-zero exit into `$_SESSION['error_msg']`.
- Exit codes: `OK=0, E_ARGS=1, E_INVALID=2, E_NOTEXIST=3, E_EXISTS=4, E_SUSPENDED=5, E_UNSUSPENDED=6, E_INUSE=7, E_LIMIT=8, E_PASSWORD=9, E_FORBIDEN=10, E_DISABLED=11, E_PARSING=12, E_DISK=13, E_LA=14, E_CONNECT=15, E_FTP=16, E_DB=17, E_RRD=18, E_UPDATE=19, E_RESTART=20` (`func/main.sh:123-143`).
- Translations: gettext. The repo holds `web/locale/hestiacp.pot` and compiled `web/locale/<lang>/LC_MESSAGES/hestiacp.mo`; `.po` files come from Crowdin (`crowdin.yml`).

---

## 3. Routing: two apps, one port

### 3.1 NGINX

> **1.x.** Since 2.0 (E8) `src/deb/nginx/nginx.conf` has `root /usr/local/hestia/web-app/public`, `location /` → `index.php` with `SCRIPT_NAME=/index.php`, `/build/`, `/api/` → `lib/api/index.php`, `/images/` → `lib/images`, `/images/custom/` → `data/white-label`, `/error/` → `lib/error`, `/_shell/` and `/backup/`. The `/fm/` and `/rrd/` blocks are gone. The rest of this section is the 1.x layout.

One `location` block is added to `src/deb/nginx/nginx.conf`, before the `\.php$` regex. `^~` makes the prefix match win over regex locations, so `/app/anything.php` never reaches the legacy handler.

```nginx
# ArcPanel (Laravel) — strangler prefix, see docs/adr/0002-url-scheme.md
location ^~ /app/ {
	alias     /usr/local/hestia/web-app/public/;
	index     index.php;
	try_files $uri @arcpanel;

	location ~ ^/app/build/ {
		expires    1y;
		add_header Cache-Control "public, immutable";
		try_files  $uri =404;
	}
}

location @arcpanel {
	include              fastcgi_params;
	fastcgi_param        HTTP_EARLY_DATA $rfc_early_data if_not_empty;
	fastcgi_param        SCRIPT_FILENAME /usr/local/hestia/web-app/public/index.php;
	fastcgi_param        SCRIPT_NAME /app/index.php;
	fastcgi_param        REQUEST_URI $request_uri;
	fastcgi_pass         unix:/run/hestia-php.sock;
	fastcgi_read_timeout 600s;
}
```

Laravel derives its `/app` base URL from `SCRIPT_NAME=/app/index.php`, which nginx passes, so routes carry no prefix (E1-S1 spec §11). In E8 `location /` points at `web-app/public/` with `SCRIPT_NAME=/index.php` and the legacy `root` disappears (10 §6).

`/api/`, `/fm/`, `/_shell/`, `/rrd/`, `/backup/` kept their blocks throughout the migration. E8 removed `/fm/` (FileGator; the 1.x URL redirects to the file manager) and `/rrd/` (graphs come from `v-export-rrd`).

### 3.2 Laravel routes

```text
/login, /login/2fa, /logout                      guest + pending-2fa
/                                                dashboard (role-aware)
/web/domains[/{domain}/edit|/create]             E1 proof pages
/impersonate/{user}, /impersonate/stop           "login as"
/_health                                         200 + version, used by E33 health check and e2e
```

Every later epic adds routes under `/<module>/…`. Until E8 the panel app lived under the `/app/` prefix (1.x); route names follow `<module>.<action>` (`web.domains.index`) so Blade links never hard-code URLs and the E8 prefix removal was a config change. In 2.0, `/app/*` and the 1.x page paths redirect for one release (`LegacyRedirectController`, map in `App\View\LegacyUrls`); the redirects go away in 2.1.

### 3.3 Navigation hand-off

> **1.x.** The hand-off map and `web/inc/arcpanel-routes.php` were removed with `web/` in E8; the 1.x page URLs are now mapped by `App\View\LegacyUrls`.

The legacy top panel (`web/templates/includes/panel.php`) gets its link targets from a small map in `web/inc/arcpanel-routes.php` (**new**, legacy side): `['list/web' => '/app/web/domains', …]`. When a module is migrated, its entry is added, and the legacy `web/list/web/index.php` is replaced by a 3-line 301 stub. Removing the stub is part of E8.

---

## 4. `ArcCommand`

The one way the panel app talks to the system. Lives in `web-app/app/Arc/`.

### 4.1 Contract

```php
namespace App\Arc;

interface ArcCommand
{
    /** Run a v-* command; throws ArcCommandException on non-zero exit. */
    public function run(string $command, array $args = [], Options $options = null): CommandResult;

    /** run() with 'json' appended and the output decoded. */
    public function json(string $command, array $args = []): array;

    /** Fire-and-forget through the queue model: returns immediately. */
    public function schedule(string $command, array $args = []): void;
}

final class CommandResult
{
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $durationMs,
    ) {}
    public function ok(): bool;
    public function lines(): array;
}

final class Options
{
    public int $timeoutSeconds = 60;      // v-backup-user etc. pass more
    public bool $allowFailure = false;    // return result instead of throwing
    public ?string $runAs = null;         // reserved; always root via sudo today
}
```

### 4.2 Argument handling

| Rule                                                                                                                                                                                                                                                                              | Reason                                                                            |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| `$command` must match `^v-[a-z0-9-]+$` **and** be in the generated allow-list `config/arc-commands.php` (built from `ls bin/` at package time)                                                                                                                                    | Prevents calling anything but a known script even if a caller is tricked          |
| Each argument is a `string`, `int`, `Stringable`, or `Secret`; arrays are rejected                                                                                                                                                                                                | No accidental serialization                                                       |
| Arguments are escaped with `escapeshellarg()` (same semantics as legacy `quoteshellarg`), and rejected if they contain `\0` or `\n`                                                                                                                                               | Shell safety; `v-*` scripts read arguments positionally                           |
| Empty string arguments are passed as `''` explicitly                                                                                                                                                                                                                              | Positional CLI contract (e.g. `v-add-web-domain user domain '' no`)               |
| `Secret` arguments are written to a `0600` temp file under `storage/tmp/` and the **path** is passed, when the target command accepts a file (documented per command in `config/arc-commands.php`); otherwise passed inline but masked in every log, exception and job record     | Mirrors the API's `v-make-tmp-file` pattern; keeps passwords out of `ps` and logs |
| Process: `Symfony\Component\Process` with `['/usr/bin/sudo', '/usr/local/hestia/bin/'.$command, ...$args]` (argv form, no shell), `cwd=/`, env `['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'HESTIA' => '/usr/local/hestia', 'LANG' => 'C.UTF-8']` | Same process user and sudo rule as legacy `exec()`, without a shell               |

### 4.3 Error mapping

Non-zero exit becomes `ArcCommandException` with a typed code:

```php
enum ExitCode: int {
    case Args = 1; case Invalid = 2; case NotExist = 3; case Exists = 4;
    case Suspended = 5; case Unsuspended = 6; case InUse = 7; case Limit = 8;
    case Password = 9; case Forbidden = 10; case Disabled = 11; case Parsing = 12;
    case Disk = 13; case LoadAverage = 14; case Connect = 15; case Ftp = 16;
    case Db = 17; case Rrd = 18; case Update = 19; case Restart = 20;
    public function httpStatus(): int;   // 422 Invalid, 404 NotExist, 409 Exists/InUse, 403 Forbidden, 429 Limit, 503 Disabled/LoadAverage, 500 otherwise
    public function userMessageKey(): string;
}
```

The exception handler renders it as the legacy app does: for HTML requests, redirect back with the command's stdout as the error flash (`v-*` scripts print `Error: …` on stderr/stdout); for JSON/Vue island requests, `{"error": {"code": "E_LIMIT", "message": "..."}}` with the mapped status. `web/inc/helpers.php:exit_code_to_http_code` is kept as the reference for the API's existing mapping; UI mapping may be stricter.

### 4.4 Caching and read models

- `v-list-sys-config json` → cached 60 s in Laravel's `file` cache (`storage/framework/cache`), invalidated by `v-change-sys-config-value` calls made through the panel. Exposes `SysConfig::get('POLICY_CSRF_STRICTNESS')`.
- `v-list-user <u> json` → memoized per request (`UserRepository::find()`); many pages need it for package limits and `SUSPENDED`.
- Everything else is read fresh. No write-through caching: the CLI is the source of truth and SSH/API changes must appear immediately.

### 4.5 Fake for tests

`ArcCommandFake implements ArcCommand`:

```php
$fake = ArcCommand::fake();
$fake->on('v-list-web-domains', ['admin', 'json'])->returnsFixture('web-domains/admin.json');
$fake->on('v-add-web-domain')->returnsExit(ExitCode::Exists, "Error: domain example.com exists");
$this->post(route('web.domains.store'), [...]);
$fake->assertRan('v-add-web-domain', fn (array $args) => $args[1] === 'example.com');
$fake->assertNotRan('v-delete-web-domain');
```

Fixtures live in `web-app/tests/Fixtures/commands/<command>/<case>.json` and are recorded from a real VM by `php artisan arcpanel:record-fixtures` (runs the listed commands and stores output). The fake fails the test on any unexpected command, so controllers cannot silently call something new.

### 4.6 Logging

Every call is logged at `debug` with command, masked args, exit code and duration to `storage/logs/arc-command.log` (rotated daily, 7 files). At `info` only failures. Secrets never appear (see 12 §6).

---

## 5. Session bridge and authentication

### 5.1 Principle

The **legacy PHP session is the source of truth** for authentication until E8. Laravel does not re-implement sessions for auth; it reads the same file, under the same cookie, and writes the same keys when it is the one logging the user in. Laravel's own session (cookie `arcpanel_session`, driver `file`, path `storage/framework/sessions`) is used only for flash messages, CSRF (`_token`) and transient UI state. Two cookies, one identity.

### 5.2 `LegacySession` service

```php
final class LegacySession
{
    public function open(): void;        // session_name('ARCPANELSID'); session_save_path(...); session_start()
    public function read(): array;       // copy of $_SESSION
    public function write(array $patch): void;  // merge + session_write_close()
    public function close(): void;       // session_write_close() without changes (releases the file lock)
    public function destroy(): void;     // mirrors legacy destroy_sessions()
    public function id(): string;
}
```

Implementation notes:

- Called from the first middleware (`BridgeLegacySession`) **before** Laravel's `StartSession`; the handler is native PHP `files`, so there is no format translation.
- The file is locked for the duration of `session_start()` → `session_write_close()`. The middleware reads, updates `LAST_ACTIVITY`, and closes **immediately**, so long `v-*` calls never hold the lock (the legacy app holds it for the whole request; the web terminal helper proves out-of-process reads are fine).
- Cookie parameters are read from the running PHP config so they match what `web/inc/main.php` sets (secure, httponly, same-site as configured in `src/deb/php/php.ini`).

### 5.3 `Principal`

The middleware builds an immutable `Principal` from the session and binds it in the container; nothing else reads `$_SESSION`.

```php
final class Principal
{
    public readonly string $loginUser;      // $_SESSION['user']
    public readonly ?string $impersonated;  // $_SESSION['look'] or null
    public readonly Role $loginRole;        // $_SESSION['userContext'] → Role::Admin|Reseller|User (03)
    public readonly Role $effectiveRole;    // $_SESSION['role'] (role of the impersonated user)
    public readonly string $loginShell;
    public readonly string $language;
    public readonly ?string $theme;
    public readonly string $legacyToken;    // $_SESSION['token'] — login fingerprint, passed to v-log-user-logout
    public function effectiveUser(): string;  // impersonated ?? loginUser
    public function isImpersonating(): bool;
    public function isReadOnly(SysConfig $cfg, UserRecord $effective): bool; // §5.6
}
```

### 5.4 Checks replicated from `web/inc/main.php`

The middleware performs, in this order, exactly the legacy checks so that behaviour is identical whichever app serves the page:

1. `user_combined_ip` mismatch and `DISABLE_IP_CHECK != 'yes'` → `v-log-user-logout`, destroy, redirect `/login`.
2. Missing `VERSION` or `user` → destroy, redirect to login (except guest routes).
3. `v-list-user <user> json` fails → destroy, redirect (user deleted).
4. `userContext === 'admin'` but current `ROLE !== 'admin'` → destroy (demotion). E11 adds the same for `reseller`.
5. `SUSPENDED === 'yes'` and `POLICY_USER_VIEW_SUSPENDED !== 'yes'` → destroy with the legacy error message.
6. `INACTIVE_SESSION_TIMEOUT * 60 + LAST_ACTIVITY < now` → `v-log-user-logout`, destroy; otherwise `LAST_ACTIVITY = now`.
7. Refresh `login_shell` and `role` from the (impersonated) user record, as `main.php:100-110` does.

These are unit-tested against fixtures of each session state (Pest), and e2e-tested by logging in through Laravel and loading a legacy page, and vice versa.

### 5.5 Login on Laravel

**Superseded by the [E1-S1 spec](../features/specs/2026-10-05-001-e1-laravel-foundation-slice1-design.md) §6** (2026-10-05). The earlier text here described the legacy flow wrongly: legacy login is `v-get-user-salt` → hash computed in PHP per method (`v-check-user-password … yes` only for yescrypt) → `v-check-user-hash` with the hash in a temp file, plus the `LOGIN_DISABLED` and `LOGIN_USE_IPLIST` checks. `LegacySession::establish()` writes only identity keys; system configuration keys are not copied because `web/inc/main.php` calls `load_hestia_config()` on every request.

In 1.x the cut-over was controlled by the `hestia.conf` key `ARCPANEL_APP_LOGIN` (`yes` by default): when `yes`, `web/login/index.php` redirected guests to `/app/login`; `no` restored the legacy form (spec §7). Password reset and 2FA reset moved to the panel in E7 batch 3. Since 2.0 (E8) the key is gone and `/login` is the only login.

### 5.6 Read-only, demo and protected admin

`Principal::isReadOnly()` mirrors `web/inc/policies.php`:

- effective role is `user`, effective user is `SUSPENDED='yes'` and `POLICY_USER_VIEW_SUSPENDED='yes'`; or
- login role is `admin`, impersonating `ROOT_USER`, and `POLICY_SYSTEM_PROTECTED_ADMIN='yes'`.

Demo mode (`v-change-sys-demo-mode`) is enforced by every `v-*` through `check_hestia_demo_mode`; the UI additionally reads `DEMO_MODE` from `SysConfig` to disable forms. Read-only never relies on the UI alone: a POST in read-only mode is rejected by the `NotReadOnly` middleware with 403 before any command runs.

### 5.7 Impersonation ("login as")

`POST /impersonate/{user}` → `ImpersonationPolicy` (admin: any; reseller: own tree — 03 §6) → `LegacySession::write(['look' => $user])`, plus `v-log-user-login` entries as legacy does for impersonator and target. `POST /impersonate/stop` clears `look`. In 1.x legacy pages read `look` natively, so an admin could switch in Laravel and continue in a legacy page without re-login.

### 5.8 Logout

`POST /logout` → `v-log-user-logout USER TOKEN` → `LegacySession::destroy()` → Laravel session invalidate → redirect `/login`. The 1.x `/logout/` URL redirects to the login page for one release (2.0).

### 5.9 After E8

When no legacy page remains, `LegacySession` stays as the session **handler** (same cookie, same path, same keys) because `web-terminal-session-auth.php` reads it; only what served legacy pages is removed. E26 (passkeys, OIDC, session list) builds on this single handler.

**Done (E8, [spec](../features/specs/2026-10-06-004-e8-legacy-retirement-design.md) §5.2).** The panel app serves the root URL (`SCRIPT_NAME=/index.php`, base URL `/`, routes cached). Login and impersonation always land on the dashboard; the session keys `userSortOrder`, `login`, `failed_twofa` and the FileGator keys `_sf2_attributes` / `_sf2_meta` are no longer written; `token` stays for `v-log-user-logout`, `look` for the web terminal.

---

## 6. Authorization layer

Defined fully in [03](./03-tenancy-and-authorization.md); E1 ships the skeleton:

- `Role` enum (`admin`, `reseller`, `user`, `dnsCluster`) read from `userContext` / `ROLE`.
- Middleware aliases: `auth.arc` (Principal present), `role:admin`, `role:admin,reseller`, `not-readonly`, `effective-user` (resolves `{user}` route parameter to the effective user and authorizes visibility).
- Policies per object type registered in `AuthServiceProvider`, all delegating owner questions to `OwnerScope`. E1 implements `WebDomainPolicy`, `UserPolicy` (for impersonation) and `DashboardPolicy`.
- CSRF: Laravel's `VerifyCsrfToken` for all Laravel forms. In 1.x, links from Laravel into legacy pages that needed `?token=` appended the session `token`; no legacy page remains since 2.0. `POLICY_CSRF_STRICTNESS` (legacy referer check) is also honoured by a `LegacyCsrfStrictness` middleware so admins see one behaviour.

---

## 7. Internationalisation

- Source of truth stays gettext + Crowdin. `php artisan arcpanel:i18n-import` converts `lib/locale/<lang>/LC_MESSAGES/hestiacp.po` (`web/locale` before 2.0; fetched by Crowdin in CI; `.mo` parsed as fallback for local builds) into `web-app/lang/<lang>.json` — Laravel JSON translations keyed by the **English source string**, which is exactly how legacy code calls `_("Add Web Domain")`. Plural forms map to Laravel's `{0}|{1}|[2,*]` syntax.
- Blade uses `{{ __('Add Web Domain') }}`; new strings are added to `hestiacp.pot` by the existing extraction script extended to scan `web-app/resources/views` and `web-app/resources/js`.
- Language is `Principal::$language`; `SetLocale` middleware calls `app()->setLocale()`. RTL languages (`ar`, `fa`, `he`) set `dir="rtl"` in the layout (05 §3).
- The import runs in CI and at package build; generated files are not committed.

---

## 8. Error handling and UX parity

| Situation                  | Legacy                                        | Panel app                                                                                                                       |
| -------------------------- | --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `v-*` fails                | `$_SESSION['error_msg']` shown in a top alert | `ArcCommandException` → flash `error` → same alert component (05)                                                               |
| `v-*` succeeds             | `$_SESSION['ok_msg']`                         | flash `ok` → toast                                                                                                              |
| Validation (missing field) | inline `$_SESSION['error_msg']`               | Laravel FormRequest → inline field errors (05 form component)                                                                   |
| Unknown exception          | PHP error page                                | Laravel handler: 500 page from `lib/error/50x.html` styling (`web/error` before 2.0); stack trace only when `DEBUG_MODE='true'` |
| `DEBUG_MODE='true'`        | debug panel (`debug_panel.php`)               | Laravel debug bar is **not** installed; a small `x-debug-panel` shows the last `ArcCommand` calls instead                       |

---

## 9. Packaging and configuration

- `web-app/` is a standard Laravel 13 skeleton, PHP 8.5, `composer.json` with `"config": {"platform": {"php": "8.5"}}`; `vendor/` built in CI (`composer install --no-dev --prefer-dist --classmap-authoritative`) and shipped inside the `hestia` deb at `/usr/local/hestia/web-app/`.
- Writable paths (`hestiaweb:hestiaweb 0750`; `uploads`/`downloads` `0700`): `web-app/storage/{framework/{cache,sessions,views},logs,tmp,uploads,downloads}`, `web-app/bootstrap/cache`. `uploads` and `downloads` are the File Manager staging areas ([06](./06-file-manager-v2.md) §6); `downloads` is exposed only through an `internal` NGINX location (`/_downloads/`, `X-Accel-Redirect`). Created by `install/hst-install-*.sh` and `install/upgrade/versions/<ver>.sh` (idempotent).
- `/etc/arcpanel/arcpanel.env` (`root:hestiaweb 0640`): `APP_KEY`, `APP_ENV=production`, `APP_DEBUG` (from `DEBUG_MODE`). Generated once by `v-add-sys-arcpanel` (**new**; keeps an existing `APP_KEY`; no `storage:link` since assets are pre-built).
- `v-add-sys-arcpanel` builds the config, event and view caches, and since 2.0 the route cache (in 1.x the `/app` base URL ruled it out, E1-S1 spec §11); `optimize:clear` on downgrade.
- Build artifacts (`public/build/*`) come from the repo's esbuild pipeline (05 §7); no Node at runtime.
- Upstream-diff CI (11 §5) treats `web-app/` as ArcPanel-owned; `web/` was deleted in E8.

---

## 10. Failure modes

| Failure                                                    | Effect                                     | Mitigation                                                                                                                                                                                                                               |
| ---------------------------------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Session file locked by a slow legacy request               | Laravel request waits on `session_start()` | Middleware closes the session as soon as `LAST_ACTIVITY` is written; metrics in debug panel; e2e timing test                                                                                                                             |
| Session written by legacy with a key Laravel does not know | None (ignored)                             | Bridge copies only known keys; unknown keys are preserved untouched because the file is only patched, never rewritten from Laravel's model                                                                                               |
| `v-list-user` slow on large servers                        | Every request pays it                      | Per-request memo; 60 s cache of `v-list-sys-config`; later, `v-list-user` output cached for 10 s keyed by file mtime of `user.conf` (read-only `stat` is allowed for `hestiaweb`? — no: data dir is root-only, so time-based cache only) |
| `sudo` fails (`requiretty`, PATH)                          | All commands fail                          | Same sudoers line as legacy; `Defaults:root !requiretty` already present; `/_health` runs `v-list-sys-config` and reports                                                                                                                |
| Laravel `vendor/` incompatible with bundled PHP 8.5        | Panel app fails to boot                    | CI builds the deb against `hestia-php`; `composer.json` platform pin; smoke test in the install VM                                                                                                                                       |
| Prefix mismatch (`/app` vs root) after E8                  | Broken links                               | All links via route names; base URL from `SCRIPT_NAME`, a single switch; e2e crawls every nav link; `/app/*` redirects for one release                                                                                                   |

---

## 11. Testing

- **Pest unit:** `ArcCommand` escaping, allow-list, error mapping, `Secret` masking; `LegacySession` against fixture session files in every state of §5.4; `Principal` read-only matrix.
- **Pest feature:** login (password, 2FA, disabled login, failed attempts), impersonation, logout, dashboard and web-domain pages with the fake; CSRF enforcement; read-only POST rejection.
- **BATS:** `v-add-sys-arcpanel` idempotency; nginx config test (`hestia-nginx -t`) after install.
- **Playwright (E10):** in 1.x, login on Laravel → open legacy `/list/dns/` → back to `/app/web/domains` with no re-login; login-as from Laravel visible in legacy. Since 2.0: login, login-as, inactivity logout and the redirects of the 1.x URLs, on Debian 12 and Ubuntu 24.04.

---

## 12. Open questions

1. ~~Should `LegacySession::establish()` write all `hestia.conf` keys into the session?~~ **Closed (2026-10-05):** no. Legacy `main.php:25` reloads them on every request.
2. `Secret` via temp file requires the target `v-*` to accept a file path; `v-check-user-password` and `v-add-user` do, `v-change-user-password` does not. Audit the list in `config/arc-commands.php` during E1 and prefer file-accepting commands; inline-with-masking is the fallback.
3. Laravel session driver `file` vs `array` for the panel's own session: `file` is needed for flash messages across the redirect; keep `file`, 2-hour lifetime, garbage collection by Laravel's lottery.
