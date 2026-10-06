# ADR-0002 — URL scheme during the strangler migration

**Status:** Accepted (2026-10-05); the `/app` prefix ended with 2.0 (E8, 2026-10-06, see the note under Consequences) · **Date:** 2026-10-04 · **Epic:** E1, E8

## Context

Legacy pages live at `/list/web/`, `/add/user/`, `/edit/server/` and so on, served from `root /usr/local/hestia/web` by `hestia-nginx` on port 8083. The Laravel app must be reachable on the same host and port with the same session cookie (`HESTIASID` is host-scoped), and must eventually own the root URL.

## Options

| Option                                                                        | Pros                                                                                                                       | Cons                                                                                                                                                                                |
| ----------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A. Prefix `/app/*` for Laravel, legacy keeps everything else; swap at 2.0** | one `location ^~ /app/` block; no legacy URL changes; cookies shared trivially; easy to reason about which app serves what | every link into Laravel changes once more at 2.0 (mitigated: all links are route names, prefix is one config value)                                                                 |
| B. Laravel at `/`, legacy moved under `/legacy/*`                             | final URLs from day one                                                                                                    | legacy pages hard-code absolute paths (`/login/`, `/list/…`, `/js/…`, `/css/…`) in dozens of files; moving them is the work we are trying to avoid                                  |
| C. Per-route takeover at the root (`location = /list/web/` → Laravel)         | final URLs from day one, no legacy moves                                                                                   | one nginx location per migrated page; nginx config churn in a packaged file on every release; error-prone precedence with the `\.php$` regex                                        |
| D. Second port for Laravel                                                    | zero nginx interplay                                                                                                       | separate TLS listener, firewall rules, cookie scoping issues for `HESTIASID` (same host, different port is allowed by browsers but confusing), double the "which port" support load |

## Decision

**Option A.** Laravel is served under `/app/` for the whole migration (`APP_URL_PREFIX=/app`). Legacy navigation points at `/app/...` for migrated modules through `web/inc/arcpanel-routes.php`; migrated legacy entry points become 301 stubs. At E8 the nginx `location /` points to `web-app/public/`, `APP_URL_PREFIX` becomes empty, and permanent redirects map every old legacy path (`/list/web/` → `/web/domains`, …) for one release cycle so bookmarks keep working.

Fixed paths that never move: `/api/` (REST API wire compatibility), `/_shell/` (web terminal WebSocket), `/rrd/` and `/backup/` (internal, `X-Accel-Redirect`), `/fm/` until FileGator is removed.

## Consequences

- One nginx change at E1, one at E8 ([02](../design/02-laravel-foundation.md) §3, [10](../design/10-page-migration-and-retirement.md) §6).
- Deep links shared during the migration (`/app/...`) break at 2.0 unless the redirect table also covers `/app/*` → `/*`; it will.
- The docs site and release notes must describe the 2.0 URL change for people with custom nginx includes or reverse proxies in front of the panel.

**2.0 (E8, [spec](../features/specs/2026-10-06-004-e8-legacy-retirement-design.md) §5).** Implemented as decided, with three details: Laravel derives its base URL from `SCRIPT_NAME` (no `APP_URL_PREFIX` setting was ever needed); old URLs are answered by `LegacyRedirectController` instead of stubs, with 301 for targets that do not depend on the signed-in user and 302 for owner-dependent targets, which a browser must not cache; `/rrd/` was dropped (the panel reads graphs through `v-export-rrd`) and `/fm/` now redirects to the file manager page.
