# E1 slice 2 — design system, shell, Dashboard and Web Domains — design

**Status:** Implemented (2026-10-05, see §14) · **Epics:** E1 slice S2 + E2 (full component library) · **Base designs:** [02 Laravel foundation](../../design/02-laravel-foundation.md), [03 Tenancy and authorization](../../design/03-tenancy-and-authorization.md), [05 Design system and frontend](../../design/05-design-system-and-frontend.md), [10 Page migration](../../design/10-page-migration-and-retirement.md) · **Previous slice:** [E1-S1](./2026-10-05-001-e1-laravel-foundation-slice1-design.md) · **Roadmap:** [§3 E1, E2](../../roadmap.md)

## 1. Summary

S2 builds the whole E2 design system (tokens, themes, every `x-ap-*` component, the role-aware shell, command palette, notification tray, style guide), the new Dashboard for admin and user, and the complete Web Domains module (list, add, edit, single and bulk actions, logs) on Laravel. A minimal authorization frame (`Capabilities`, `OwnerScope`, `WebDomainPolicy`) follows the interfaces of 03 so that E11 only adds the reseller branch. A new switch `ARCPANEL_APP_PAGES` moves users from the legacy pages to the new ones and back.

Design documents 03, 05 and 10 stay the reference. This spec records the decisions of the 2026-10-05 brainstorm, the scope of S2 and where it differs from those documents.

## 2. Decisions taken in the brainstorm

| # | Question | Decision |
| --- | --- | --- |
| D1 | Which sub-project after S1 | **E1-S2 + E2**, Track A of the roadmap. |
| D2 | How far Web Domains goes | **Full module:** list, add, edit (every section of `web/edit/web`), single actions, bulk, access/error log. |
| D3 | Authorization before E11 | **Minimal frame:** `Capabilities`, `OwnerScope`, `WebDomainPolicy` with the 03 interfaces; only admin and user are filled in. |
| D4 | Depth of E2 | **Full library** of 05 §5, command palette, notification tray, `/app/_styleguide`, contrast check, Lighthouse gate. |
| D5 | Cut-over | **Switch like the login:** `ARCPANEL_APP_PAGES` (`yes`/`no`) in `hestia.conf`; legacy files stay until E8. |
| D6 | Delivery | **One spec, five milestones** (§3), each a tested commit. |
| D7 | Edit page save model | **One form per tab** (General, SSL, Redirect, Advanced, FTP, Statistics); each tab posts to its own route and runs only its own commands. Supersedes the single save bar of the Nocturne prototype. |
| D8 | UI preferences (theme, density) | **Cookie `arc_ui`**, defaults derived from the legacy `THEME`. Not written to `user.conf`: `v-change-user-config-value` cannot add a new key (it runs `v-rebuild-user` and then only updates existing keys). Closes 05 §11 question 3 for now. |
| D9 | Icons and fonts | **Inline SVG sprite** from the Nocturne prototype; no Font Awesome in the panel app (closes 05 §11 question 1). Inter and JetBrains Mono bundled as woff2 from `@fontsource`; no Google Fonts request. |
| D10 | Template picker | **`x-ap-select`** for web/backend/proxy templates in S2. `x-ap-card-picker` is built and in the style guide, and is wired to Web Domains when E6 delivers template metadata. |

## 3. Milestones

| # | Milestone | Content | Done when |
| --- | --- | --- | --- |
| M1 | Design system and shell | §5 and §6: tokens, themes, fonts, icons, all components, Alpine modules, shell, palette, notification tray, style guide, build manifest, contrast check | Style guide renders every component in `nocturne`, `light`, compact and RTL; login page uses the new bundle; Pest view + Vitest tests pass |
| M2 | Dashboard | §7, admin and user | `/app/` shows the role's dashboard; feature tests for both roles |
| M3 | Web Domains list, add, actions, bulk | §8.1, §8.2, §8.4 | CRUD without edit works for admin (all users) and user (self); cross-user tests pass |
| M4 | Web Domain edit and logs | §8.3, §8.5 | Every tab saves with the same commands and effects as the legacy page |
| M5 | Cut-over | §9, BATS, smoke, Playwright, Lighthouse | Done criteria of §4 |

## 4. Scope

### In scope

1. Design tokens, themes `nocturne` (dark, default), `light`, `auto`; density `comfortable`/`compact`; RTL with logical properties.
2. Every component of 05 §5, each with Pest view tests and a style guide entry.
3. Role-aware shell: sidebar, top bar (breadcrumb, palette, impersonation badge, user switcher, notification tray, user menu).
4. Build pipeline of 05 §7 (hashed assets, manifest, `@apAsset`, JS i18n keys, contrast check).
5. `Capabilities`, `OwnerScope`, `EffectiveUser` middleware, `WebDomainPolicy`, `ServerPolicy` (read only, for dashboard widgets).
6. `DashboardService`, `WebDomainService` and their DTOs, fixtures and tests.
7. Dashboard for admin and user.
8. Web Domains: list, add, edit (six tabs), suspend, unsuspend, rebuild, purge cache, delete, bulk, access and error log.
9. Switch `ARCPANEL_APP_PAGES`, legacy redirects and menu links, new `Landing`.
10. Playwright e2e with axe and Lighthouse on `arcpanel-test`.

### Out of scope

- Reseller role, owner tree, reseller dashboard (E11). The reseller row of `Capabilities` exists and denies everything.
- Template metadata and the card picker on Web Domains (E6).
- Users, Packages and every other module page (E11 part 2, E7). Sidebar items for them link to the legacy pages.
- Persisting UI preferences in `user.conf`.
- Live log follow over WebSocket (E14 transport).
- Quick Install Apps (`add/webapp`), linked from the edit page to the legacy page as today.
- Deleting legacy files (E8).

### Done when

- On a fresh install with `ARCPANEL_APP_PAGES=yes`, admin and user sign in and land on `/app/`; legacy `/list/web/`, `/add/web/`, `/edit/web/?domain=…` and `/list/web-log/?domain=…` redirect to the new pages.
- Admin lists web domains of every user, filters by owner and edits any of them without impersonation; a user sees and changes only their own domains, and a request for another user's domain returns 404.
- Every edit tab produces the same `v-*` calls as the legacy page for the same change (fixture-backed tests).
- `v-change-sys-config-value ARCPANEL_APP_PAGES no` brings back the legacy pages and landing with no other change.
- Lighthouse accessibility ≥ 90 on `/app/`, the Web Domains list, add and edit pages, for both roles and both themes.
- Pest, Vitest, BATS, smoke and Playwright suites pass on `arcpanel-test`.

## 5. Design system

### 5.1 CSS structure

`templates/nocturne/assets/css/nocturne.css` is split, not imported:

```text
web-app/resources/css/
  tokens.css        --ap-* semantic tokens (05 §3.1), no palette values
  base.css          reset, typography, focus ring, skip link, layout primitives
  components.css    one section per x-ap-* component, tokens only
  themes/nocturne.css, themes/light.css, themes/auto.css
  fonts.css         @font-face for the bundled woff2 files
  app.css           entry: imports the files above
```

`arc.css` and its `@import` of the prototype are removed; the login pages use `app.css`. A small Node script, `scripts/check-tokens.js`, fails the build when `components.css` contains a literal colour or a pixel spacing value outside `tokens.css` and the theme files.

### 5.2 Themes and preferences

- `<html data-theme="nocturne|light|auto" data-density="comfortable|compact" dir="ltr|rtl">`, rendered by the server, so the page never flashes.
- Value source, in order: cookie `arc_ui` (JSON `{theme, density}`, `SameSite=Lax`, one year, not `HttpOnly` so Alpine can update it), else the mapping of the legacy `THEME` from the `Principal`: `dark` → `nocturne`; `default`, `vestia` → `light`; `flat` → `light` + `compact`; anything else → `nocturne`.
- `POLICY_USER_CHANGE_THEME='no'` hides the theme choice and ignores the cookie's theme.
- `auto` follows `prefers-color-scheme` through a media query inside `themes/auto.css`.
- Legacy custom themes (`web/css/themes/custom/*.css`) target legacy selectors and are not loaded by the panel app. E8 gets a migration note.

### 5.3 Fonts and icons

- `@fontsource/inter` (400, 500, 600, 700) and `@fontsource/jetbrains-mono` (400, 500, 600) as dependencies; `build.js` copies the latin and latin-ext woff2 files to `public/build/fonts/`. Licence SIL OFL 1.1, noted in the third-party licences file.
- `resources/views/components/icons.blade.php` grows into the full Nocturne sprite; `<x-ap-icon name="globe"/>` renders `<svg class="ic"><use href="#i-globe"/></svg>`. Icons that imply direction carry `ap-icon--dir` and are mirrored in RTL.

### 5.4 Components

All components of 05 §5 live in `resources/views/components/ap/` and are used as `<x-ap-*>`. Class names follow the prototype (`ap-btn`, `ap-table`, `ap-usage` …). Additions to the 05 table that the pages need:

| Component | Purpose |
| --- | --- |
| `x-ap-page` | title, breadcrumb, header actions, flash toasts, main landmark |
| `x-ap-button` | variants primary, ghost, danger; `href` renders a link; `form-action` renders a one-button POST form with CSRF and optional `confirm` |
| `x-ap-icon` | sprite reference (§5.3) |
| `x-ap-card` | section card with head, title, icon and actions slot |
| `x-ap-textarea`, `x-ap-checkbox`, `x-ap-radio-group` | form fields with the `x-ap-field` wiring |
| `x-ap-meter` | dashboard resource tile (wraps `x-ap-usage-bar` with label and value) |

Every component: tokens only, keyboard operable, labelled, ARIA as in 05 §6, translated strings through `__()`.

### 5.5 Alpine modules

`resources/js/app.js` starts Alpine with `@alpinejs/collapse` and registers one module per behaviour from `resources/js/alpine/`:

`apTable` (sort, filter, column chooser persisted per table id in `localStorage` with try/catch, selection), `apBulkBar`, `apModal` (focus trap, Esc, focus return), `apConfirm`, `apToast`, `apTabs` (URL hash sync), `apPalette`, `apNotifications`, `apUserMenu` (theme, density → cookie `arc_ui`), `apSidebar` (collapse, off-canvas), `apPassword` (strength from `check-password-strength`), `apUnlimited`, `apCronBuilder`, `apRrdGraph` (chart.js), `apLog`, `apWizard`, `apCardPicker`, `apIsland` loader, plus the `autoTrim` and `focusFirst` plugins.

Server stays the source of truth (05 §5.1). Forms submit without JavaScript; only the palette, notifications, bulk selection and islands need it.

### 5.6 Shell

- **Sidebar** generated by `App\View\Navigation` from `Capabilities` for the login role. Items are route names; an item whose route does not exist yet resolves through `LegacyRoutes` to the legacy URL (`/list/dns/` …). Groups and order follow 05 §4.1 and 03 §9.1. Active item from the current route name.
- **Top bar:** breadcrumb from route name segments; palette trigger; impersonation badge with "Return" (posts to `impersonate.stop` from S1); user switcher for admin (typeahead over `v-list-users json`, starts impersonation through `impersonate.start`); notification tray; user menu.
- **Command palette** (`Ctrl+K` / `⌘K`): `GET /app/_palette?q=` returns JSON groups. Objects from `v-search-object QUERY json`, filtered by `OwnerScope::visibleUsers()`; pages and actions from a static route index filtered by `Capabilities`. Debounce 150 ms, minimum 2 characters, keyboard first.
- **Notification tray:** `GET /app/_notifications` (`v-list-user-notifications USER json`), polled every 60 s while the tab is visible; acknowledge (`v-acknowledge-user-notification`) and delete (`v-delete-user-notification`) as POST routes.
- **User menu:** theme, density, language (`v-change-user-language`, then `LegacySession::write('language')`), "Account" (link to legacy `/edit/user/`), logout.
- **Style guide:** `GET /app/_styleguide`, only when `DEBUG_MODE='true'` (404 otherwise), renders every component with sample props in every theme, density and RTL.

### 5.7 Build

`build.js` grows the 05 §7 steps: `app.css` and theme files through Lightning CSS, `app.js` through esbuild (`format: esm`, `splitting: true`), font copy, `public/build/manifest.json` with hashed names. `@apAsset('app.js')` resolves names from the manifest. `<x-ap-i18n>` emits only the keys listed in `resources/js/i18n-keys.json` for the current locale. `scripts/check-contrast.js` checks every text/background token pair in every theme and fails below 4.5:1. `npm run build` runs everything; `npm run dev` adds `--watch`.

## 6. Authorization frame

Follows 03 §6–7 with S2 limits.

| Unit | Does | S2 limit |
| --- | --- | --- |
| `App\Policies\Capabilities` | the permission matrix as data, `allows(Role, capability, ?Closure scope)` | only `dashboard.view`, `server.view`, `web.view`, `web.create`, `web.update`, `web.delete`, `web.templates.edit`, `users.view` (admin, for the user switcher and owner filter); reseller row denies all; unknown capability denies |
| `App\Auth\OwnerScope` | `visibleUsers()`, `canSee()`, `canManage()`, `assertVisible()` (404) | admin: `null` (all), user: `[self]`; `tree()` throws `LogicException` until E11 |
| `EffectiveUser` middleware (`effective-user`) | reads `{user}`, calls `assertVisible`, binds `EffectiveUser` | used by every route with `{user}` |
| `WebDomainPolicy` | `viewAny`, `view`, `create`, `update`, `updateTemplates`, `delete`, `suspend` | `updateTemplates` also requires admin or `POLICY_USER_EDIT_WEB_TEMPLATES='yes'`; every mutation also requires `!isReadOnly()` |
| `ServerPolicy` | `viewHealth`, `viewServices` | admin only |

Views receive a `can` map from the controller and never compare user names (03 §7.3).

## 7. Dashboard

`GET /app/` → `DashboardController`, role chosen from the `Principal`. `DashboardService` collects data; slow aggregates are cached in the Laravel file cache.

| Role | Widget | Source | Cache |
| --- | --- | --- | --- |
| admin | CPU, memory, disk, load tiles | `v-list-sys-info json`, `v-list-sys-memory-status`, `v-list-sys-disk-status`, `v-list-sys-cpu-status` | 30 s |
| admin | Service chips (status only; link to legacy `/list/server/`) | `v-list-sys-services json` | 30 s |
| admin | RRD graphs with period selector | `v-list-sys-rrd json` + `/rrd/` images | none |
| admin | Users and suspended users | `v-list-users json` | 60 s |
| admin | Recent auth log (10 lines) | `v-list-user-auth-log admin json` | none |
| admin | SSL expiring within 14 days, all users | `v-list-users json` → `v-list-web-domains USER json` → `v-list-web-domain-ssl USER DOMAIN json` for SSL domains | 5 min |
| admin | Updates badge | `v-list-sys-hestia-updates json` | 1 h |
| admin | Quick actions | add user (legacy), add domain, open Web Domains | — |
| user | Usage bars `U_*` vs package limits | `v-list-user USER json` | none |
| user | Domains with SSL state | `v-list-web-domains USER json` | none |
| user | Last backup | `v-list-user-backups USER json` | none |
| user | Notifications | `v-list-user-notifications USER json` | none |

A widget whose command fails renders an `x-ap-alert` inside its card; the rest of the dashboard still renders. While impersonating, the dashboard is the user dashboard of the effective user.

## 8. Web Domains

Commands, argument order and validation follow the legacy files named in each section. `WebDomainService` has one method per command and returns DTOs (`WebDomainRecord`, `WebDomainSsl`, `WebLogLines`).

### 8.1 List — `GET /app/web/domains` (legacy `web/list/web`, `templates/pages/list_web.php`)

- User: `v-list-web-domains USER json`. Admin: the same for every user of `visibleUsers()` (from `v-list-users json`), merged, with an Owner column and an owner filter (`?owner=`).
- Columns: domain, owner (admin), IP, web template, backend template (when `WEB_BACKEND` is set), proxy template (when `PROXY_SYSTEM` is set), SSL (with Let's Encrypt badge), cache, disk, bandwidth, status. IPs mapped to NAT addresses through `v-list-sys-ips json` as legacy does.
- `x-ap-table` with sort, filter and column chooser; server paging above 500 rows.
- Row actions: edit, logs, suspend or unsuspend, rebuild, purge cache (only when nginx cache is on), delete (confirm).
- Empty state with a link to create.

### 8.2 Add — `GET /app/web/domains/create`, `POST /app/web/domains` (legacy `web/add/web`)

- Fields: domain, IP (`v-list-user-ips`), aliases, "create DNS zone" (`v_dns`), "create mail domain" (`v_mail`); with `updateTemplates`: web template, backend template, proxy template, proxy extensions.
- `StoreWebDomainRequest` copies the legacy checks (domain format, lowercase, `www.` handling of aliases, IP in the user's list). Commands in legacy order: `v-add-web-domain`, then `v-add-dns-domain` and `v-add-mail-domain` when asked, then template changes.
- Admin creates for another user through the owner field (`users.view`); the domain belongs to that user.
- Success redirects to the edit page with a toast.

### 8.3 Edit — `GET /app/web/domains/{user}/{domain}/edit` (legacy `web/edit/web`, `templates/pages/edit_web.php`)

One page, `x-ap-tabs` with URL hash sync. Each tab is a separate `<form>` posting to `POST /app/web/domains/{user}/{domain}/{tab}` with its own FormRequest and service method. A tab compares the submitted values with the current `v-list-web-domain USER DOMAIN json` record and runs only the commands whose value changed, in the legacy order. After a failure, already-applied commands stay applied, the error toast names the failed step and the tab re-renders from fresh data.

| Tab | Fields | Commands |
| --- | --- | --- |
| General | aliases, IP, custom document root (target domain + folder) | `v-add-web-domain-alias` / `v-delete-web-domain-alias` (with `v-add-dns-on-web-alias` / `v-delete-dns-on-web-alias` when the DNS zone exists), `v-change-web-domain-ip` (+ `v-change-dns-domain-ip`, `v-rebuild-mail-domain` as legacy), `v-change-web-domain-docroot` |
| SSL | SSL on/off, Let's Encrypt, key type, certificate, key, CA, force SSL, HSTS | `v-add-letsencrypt-domain` / `v-delete-letsencrypt-domain`, `v-add-web-domain-ssl` / `v-change-web-domain-sslcert` / `v-delete-web-domain-ssl` (files through `Secret`), `v-add\|delete-web-domain-ssl-force`, `v-add\|delete-web-domain-ssl-hsts` |
| Redirect | on/off, target (`www.` / bare / custom), status code | `v-add-web-domain-redirect`, `v-delete-web-domain-redirect` |
| Advanced | web, backend and proxy templates, proxy on/off, proxy extensions, nginx FastCGI cache and duration | `v-change-web-domain-tpl`, `v-change-web-domain-backend-tpl`, `v-add\|delete-web-domain-proxy`, `v-change-web-domain-proxy-tpl`, `v-add\|delete-fastcgi-cache` — tab shown only with `updateTemplates` |
| FTP | accounts: add, change password, change path, delete; "send credentials to" e-mail | `v-add-web-domain-ftp`, `v-change-web-domain-ftp-password`, `v-change-web-domain-ftp-path`, `v-delete-web-domain-ftp`; the credentials e-mail uses the legacy text, sender and subject keys (`FROM_EMAIL`, `FROM_NAME`, `SUBJECT_EMAIL`, `APP_NAME`) — tab shown only when `FTP_SYSTEM` is set |
| Statistics | type, protect with login, username, password | `v-add\|change\|delete-web-domain-stats`, `v-add\|delete-web-domain-stats-user` |

Passwords (FTP, statistics) and private keys never leave the server in a response and go to commands through `Secret` files. The page header shows SSL state, links to the site, the document root in the file manager (legacy `/fm/` until E3) and Quick Install Apps (legacy page, when `PLUGIN_APP_INSTALLER` allows). `Restart` steps (`v-restart-web`, `v-restart-proxy`, `v-restart-dns`) run once at the end of a tab when a command asked for it, as legacy does.

### 8.4 Single and bulk actions

- `POST …/{domain}/suspend`, `…/unsuspend`, `…/rebuild`, `…/purge-cache`, `DELETE …/{domain}` → `v-suspend-web-domain`, `v-unsuspend-web-domain`, `v-rebuild-web-domain`, `v-purge-nginx-cache`, `v-delete-web-domain`, each with `restart=no` and one restart round at the end, as `web/bulk/web`.
- `POST /app/web/domains/bulk {action, items[]}` where an item is `user/domain`; every item passes `assertVisible` and the policy before any command runs. Actions per role follow `web/bulk/web` (admin: delete, rebuild, suspend, unsuspend, purge; user: delete, suspend, unsuspend, purge). The toast reports `n done, m failed` and lists failures.

### 8.5 Logs — `GET /app/web/domains/{user}/{domain}/logs/{access|error}` (legacy `web/list/web-log`)

`v-list-web-domain-accesslog` / `v-list-web-domain-errorlog USER DOMAIN json`, rendered with `x-ap-log` (last lines, monospace, refresh button), download links to the legacy download endpoint. Live follow is out of scope.

## 9. Cut-over switch `ARCPANEL_APP_PAGES`

- New key in `hestia.conf`, set with `v-change-sys-config-value`. `v-list-sys-config` gains it in its `json` and `shell` output, the same output-only change as `ARCPANEL_APP_LOGIN` in S1. No other `v-*` command changes.
- Installer sets `yes`; `test/dev-sync.sh` adds the key when missing.
- `web/inc/arcpanel-routes.php` (new, legacy side) holds the map of migrated legacy paths to `/app` URLs and two helpers:
  - `arc_route(string $legacyPath): string` — returns the new URL when the switch is on and the path is migrated, else the legacy path. `web/templates/includes/panel.php` uses it for the Web menu entry and the logo link.
  - `arc_redirect_if_migrated(string $legacyPath, array $query = [])` — called at the top of `web/list/web/index.php`, `web/add/web/index.php`, `web/edit/web/index.php` and `web/list/web-log/index.php`; sends `302` (not `301`, so turning the switch off works immediately in browsers) to the new page, mapping `?domain=` and the effective user.
- `Landing::for()` returns `/app/` for every role when `ARCPANEL_APP_PAGES='yes'`, else the S1 behaviour.
- With `no`, the legacy pages and landing work as in S1, and the new `/app/` pages remain reachable by URL.

## 10. Error handling

- `ArcCommandException` → error toast in the page or tab that ran it (HTML) or `{"error":{"code","message"}}` (JSON routes: palette, notifications). Exit codes map as in 02 §4.3.
- Objects outside `OwnerScope` → 404. Policy refusals inside scope → 403 page in the shell.
- A failing dashboard widget renders its own alert; the page still renders.
- No JavaScript: every form still submits; palette, notifications, bulk selection and column chooser are missing.
- Secrets masked in logs and exceptions (S1 rules).

## 11. Testing

| Level | Covers |
| --- | --- |
| Pest unit | `Capabilities` (matrix, unknown capability denied, reseller denied); `OwnerScope` for admin and user; `Navigation` per role; theme mapping; `WebDomainService` methods with `ArcCommandFake` and recorded fixtures; each edit tab's change planning (no change → no command; each field → its commands in legacy order) |
| Pest feature | every route × admin, user A, user B with the fake: list scoping, admin owner filter, add, each edit tab, single actions, bulk (incl. an item of another user → 404 and nothing runs), logs; read-only refusal on every mutation; `effective-user` 404; palette and notification JSON; style guide 404 when `DEBUG_MODE` is off |
| Pest view | every `x-ap-*` component with representative props, in LTR and RTL |
| Vitest (jsdom) | each Alpine module in §5.5 |
| Build checks | `check-contrast.js`, `check-tokens.js` |
| BATS (`test/arcpanel-panel-app.bats`, extended) | `ARCPANEL_APP_PAGES` in `v-list-sys-config`; legacy `/list/web/`, `/add/web/`, `/edit/web/`, `/list/web-log/` give `302` to `/app/…` when `yes` and `200` when `no` |
| Smoke (`test/arcpanel-login-smoke.sh`, extended) | sign in → `/app/` dashboard → add a domain → enable SSL without Let's Encrypt (self-signed fixture) → suspend → unsuspend → delete, all through HTTP |
| Playwright (`test/e2e/`, against `arcpanel-test`) | happy paths for admin and user (dashboard, add, edit General and SSL, bulk suspend, delete), palette, theme switch; axe on every page; Lighthouse accessibility ≥ 90 for both roles and both themes |

## 12. Documentation changes in the same change set

- **Roadmap:** E1 slices: S2 points to this spec; E2 notes that the full library ships with S2.
- **05 Design system:** §3.2 theme names `nocturne`/`light`/`auto` and the cookie (D8); §11 questions 1 and 3 closed by D9 and D8.
- **10 Page migration:** §4 step 11 notes that S2 uses the `ARCPANEL_APP_PAGES` switch and `302` redirects instead of `301` stubs; stubs and deletions move to E8.
- **03 Tenancy:** §7.1 notes that `OwnerScope::tree()` lands with E11.

## 13. Risks

| Risk | Mitigation |
| --- | --- |
| Edit tabs differ from legacy behaviour (SSL, Let's Encrypt, aliases with DNS) | Fixtures recorded from `arcpanel-test`; one test per branch of `web/edit/web/index.php`; switch `ARCPANEL_APP_PAGES` to fall back |
| Admin list across all users is slow on servers with many users | One `v-list-web-domains` per user, cached per request; server paging above 500 rows; measured on `arcpanel-test` with 50 users in the smoke script |
| Dashboard SSL scan is expensive | 5-minute cache; only SSL-enabled domains are queried |
| Full component library delays the pages | M1 ships before pages start; components without a page in S2 are kept small and covered by the style guide only |
| Cookie-based theme differs between browsers | Accepted; defaults follow the legacy `THEME`; `user.conf` persistence revisited in E7 |
| Fonts add size to the deb | woff2 latin and latin-ext subsets only |

## 14. Implementation notes (2026-10-05)

Decisions taken while building S2 that differ from, or add to, the sections above:

- **Components:** the files live in `resources/views/components/ap/` and are registered as `<x-ap-NAME>` aliases in `AppServiceProvider` (Blade would otherwise expect `<x-ap.NAME>`). Extra building blocks: `x-ap-row-check`, `x-ap-tab-panel`, `x-ap-pager`, `x-ap-shell` and `x-ap-i18n`. Dropdowns (row actions, column chooser, user switcher) are `<details>`, so they open without JavaScript.
- **Tokens:** the spacing scale gains half steps (`--ap-space-0-5` … `--ap-space-9`) so the prototype's rhythm survives the split. Density tokens are `--ap-control-h`, `--ap-control-h-sm`, `--ap-row-h`, `--ap-card-pad` and `--ap-content-gap`. `check-tokens.js` checks literal colours and pixel values in `margin`, `padding` and `gap`. Two palette values changed to pass `check-contrast.js`: Nocturne `--ap-fg-subtle` is now `#838c9e` and light `--ap-ok` is now `#1a7a44`.
- **Fonts:** besides latin and latin-ext, the Vietnamese subset ships too. The SIL OFL licence files are copied next to the fonts. See `web-app/THIRD-PARTY-NOTICES.md`.
- **Capabilities:** `dns.view`, `mail.view`, `db.view`, `cron.view`, `backups.view`, `files.view`, `logs.view`, `stats.view` and `account.view` exist for navigation only (legacy pages). Policies use the acting role, which is the effective user's role while impersonating, as the legacy menu does. `updateTemplates` checks the login role, as `web/edit/web` checks `userContext`.
- **Dashboard:** server graphs come from `v-export-rrd` through `GET /app/_rrd` (chart.js), as the legacy `rrdCharts.js` does today, not from `/rrd/` images. `v-list-sys-{cpu,memory,disk}-status` print usable reports with a non-zero exit code on some systems, so their exit code is ignored.
- **Web domains:**
  - The add form passes aliases as the `ALIASES` argument of `v-add-web-domain`, which replaces the default `www.` alias.
  - Logs use the plain output of `v-list-web-domain-{access,error}log`, because their JSON output does not escape backslashes.
  - The private key is never rendered. An empty key field keeps the stored key when a certificate is replaced.
  - Turning off Let's Encrypt also removes forced HTTPS, as the legacy page does.
  - Single actions (suspend, unsuspend, rebuild, purge, delete) take the bulk path: `RESTART=no`, then `v-restart-web`, `-proxy`, `-dns` and `-web-backend`.
- **Cut-over:**
  - Legacy pages redirect only `GET` and `HEAD` requests, so a legacy form that is already open still posts.
  - The legacy CSRF check needs a `Referer` header, which browsers send.
  - The panel app reads the switch through `SysConfig`, which is cached for `ARC_SYS_CONFIG_TTL` seconds (60 by default). A change shows on the legacy side at once and in the panel app within that time. The smoke script forgets the cache after switching.
  - `web/inc/main.php` loads `inc/arcpanel-routes.php`. `web/login/index.php` sends a logged-in session to `/app/` when the switch is on.
- **Measured on `arcpanel-test` (WSL, 53 users with one domain each):**
  - The admin Web Domains list takes about 1.8 s (one `v-list-web-domains` per user).
  - The admin dashboard takes 2.6 s cold and 0.2 s with its caches warm.
- **Tests (all green on `arcpanel-test`):**
  - Pest: 187 tests.
  - Vitest: 117 tests in 23 files.
  - BATS `test/arcpanel-panel-app.bats`: 15 tests.
  - Smoke script `test/arcpanel-login-smoke.sh`: 27 checks.
  - Playwright `test/e2e`: 14 tests, with no axe violations. Lighthouse accessibility is 100 on the dashboard, list, add and edit pages for both roles and both themes.
  - Run the browser suite with `E2E_BASE_URL=https://<server>:8083 npm run test:e2e`. It prepares and removes its own users through `test/e2e/prepare.sh`.
