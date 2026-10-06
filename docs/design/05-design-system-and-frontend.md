# 05 — Design System and Frontend

**Epic:** E2 (+ ADR-0001 view layer) · **Status:** Draft v1 · **Depends on:** E1 · **Unblocks:** E3, E4, E7, E11 part 2, E14 UI

---

## 1. Goal

One component library, one shell and one build pipeline for every page the panel app serves, so that each migrated page is cheaper than the one before, task time for the five common tasks drops 40 % and every page scores ≥ 90 on Lighthouse accessibility.

Decision carried from ADR-0001 (recommended): **Blade + Alpine.js for CRUD pages; Vue 3 islands only for highly interactive screens** (File Manager, App Store, Node Apps, service config editors, log viewers). Vue is already a repository dependency; Alpine is already what the legacy pages ship.

Non-goals: a SPA, SSR, a Vite dev server in production, a third-party UI kit.

---

## 2. Current state (grounding)

_State of HestiaCP 1.10.5 before E2. Since 2.0 (E8) `web/`, its bundle and its CSS themes are gone and `build.js` builds only the panel assets._

- Legacy pages hand-write markup in `web/templates/pages/*.php` (78 files) with a shared `header.php`/`footer.php`; behaviour lives in ~30 small Alpine/vanilla modules in `web/js/src/*.js` (`listSorting`, `confirmAction`, `formSubmit`, `cronGenerator`, `rrdCharts`, `webTerminal`, …).
- `build.js` bundles `web/js/src/index.js` → `web/js/dist/main.min.js` with esbuild and compiles `web/css/src/themes/{default,dark,flat,vestia}.css` with Lightning CSS. Four themes, chosen per user (`v-change-user-theme`, `v-list-sys-themes`, custom themes in `web/css/themes/custom/`).
- Dependencies already present: `alpinejs`, `@alpinejs/collapse`, `chart.js`, `@xterm/xterm`, `@fortawesome/fontawesome-free`, `vue` (devDependency for the docs site), `vitest`, `@testing-library/vue`.

---

## 3. Layout, tokens and theming

### 3.1 Design tokens

All visual decisions are CSS custom properties defined once in `web-app/resources/css/tokens.css` and consumed by every component. Nothing in a component uses a literal colour or spacing.

| Group           | Examples                                                                                                                                                                         | Notes                                                   |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| Colour          | `--ap-bg`, `--ap-surface`, `--ap-surface-raised`, `--ap-border`, `--ap-fg`, `--ap-fg-muted`, `--ap-accent`, `--ap-accent-fg`, `--ap-ok`, `--ap-warn`, `--ap-danger`, `--ap-info` | semantic names; palette values live only in theme files |
| Spacing         | `--ap-space-1 … --ap-space-8` (4 px scale)                                                                                                                                       |                                                         |
| Typography      | `--ap-font-sans`, `--ap-font-mono`, `--ap-text-xs … --ap-text-2xl`, `--ap-leading-*`                                                                                             | system font stack; mono for paths, commands, logs       |
| Radius / shadow | `--ap-radius-sm/md/lg`, `--ap-shadow-1/2`                                                                                                                                        |                                                         |
| Motion          | `--ap-ease`, `--ap-duration-fast/base`                                                                                                                                           | disabled under `prefers-reduced-motion`                 |
| Layout          | `--ap-sidebar-w` (260 px), `--ap-topbar-h` (56 px), `--ap-content-max` (1440 px)                                                                                                 |                                                         |

### 3.2 Themes

> **E1-S2 (2026-10-05):** the panel app ships the themes `nocturne` (dark, default), `light` and `auto`. The choice is kept in the cookie `arc_ui`, with defaults mapped from the legacy `THEME`; see the [E1-S2 spec](../features/specs/2026-10-05-002-e1-s2-dashboard-web-domains-design.md) §5.2.
>
> **E8 (2.0):** the CSS themes of the legacy interface are gone. `THEME` holds a panel theme (`nocturne`, `light` or `auto`) and the 2.0.0 upgrade step maps the 1.x names as in the table below; see the [E8 spec](../features/specs/2026-10-06-004-e8-legacy-retirement-design.md) D11.

A theme is a file that assigns palette values to the semantic tokens under `[data-theme="<name>"]`. Light and dark are first-class; the user's choice is stored exactly as today (`THEME` in `user.conf` via `v-change-user-theme`; `POLICY_USER_CHANGE_THEME` respected).

| 1.x theme                              | 2.0 value (set by `install/upgrade/arcpanel/2.0.0.sh`)                          |
| -------------------------------------- | ------------------------------------------------------------------------------- |
| `default`                              | `light`                                                                         |
| `dark`                                 | `nocturne`                                                                      |
| `flat`                                 | `light` (a `flat` value not yet upgraded also selects `data-density="compact"`) |
| `vestia`                               | `light`                                                                         |
| custom (`web/css/themes/custom/*.css`) | `nocturne`; custom CSS themes are not supported since 2.0                       |

`auto` (follow `prefers-color-scheme`) is a theme value; `v-change-user-theme USER auto` works because the CLI validates against `v-list-sys-themes`, which lists `auto`, `light` and `nocturne` since 2.0.

### 3.3 Layout

- App shell: fixed sidebar (collapsible to icons at < 1200 px, off-canvas drawer at < 768 px), top bar (breadcrumb, command palette trigger, impersonation badge, notification tray, user menu), content column capped at `--ap-content-max`.
- Mobile-first tables: at < 768 px `<x-ap-table>` renders each row as a card using the column definitions; no second template.
- RTL: `<html dir="rtl">` for `ar`, `fa`, `he` (from `Principal::$language`, [02](./02-laravel-foundation.md) §7); layout uses logical properties (`margin-inline-start`, `inset-inline-end`) so no RTL stylesheet is needed; icons that imply direction (`chevron`) are mirrored via `[dir="rtl"] .ap-icon--dir { transform: scaleX(-1) }`.
- Density: `data-density="comfortable|compact"` on `<html>`, user preference stored in Laravel's own session (`arcpanel_session`) until a `PREF_UI_DENSITY` key is added to `user.conf` via `v-change-user-config-value`.

> **UI pass (2026-10-06):** what the shell and tables do today.
>
> - From 768 px the sidebar stays in view while the page scrolls (it scrolls on its own when it is taller than the window). The menu button folds it to an icon rail (`--ap-rail-w`, labels as tooltips); the choice is `sidebar: "full" | "rail"` in the cookie `arc_ui` and rendered as `data-sidebar` on `<html>` by `UiPreferences`, so pages never flash. There is no automatic rail below 1200 px. Below 768 px the menu button opens the drawer over a dimmed page; a tap on the page or `Esc` closes it.
> - Phones get a one-row top bar: menu, current page (the last crumb), then icon buttons for search, user switcher, notifications and the account.
> - `<x-ap-table>`: the row-actions column is sticky at the inline end, so a table wider than its card scrolls without hiding the actions. Names, addresses and figures do not wrap. A column marked `hidden` is optional and left out until the viewer picks it in the column chooser. As cards (< 768 px) the first column is the card title, with the row actions beside it.
> - Dropdowns from `<details class="ap-dropdown">` (`resources/js/dropdowns.js`): one open at a time, closed by a click outside or `Esc`; a row menu is placed with fixed coordinates so the table does not clip it, and opens upwards when the window has no room below.
> - `.ap-reveal-group`: fields marked `.ap-reveal` show while the group's switch is on (CSS `:has()`, no script).

---

## 4. Shell and navigation

### 4.1 Role-aware sidebar

The sidebar is **generated**, not written per role. `App\View\Navigation` reads `Capabilities` ([03](./03-tenancy-and-authorization.md) §6) for the login role and emits groups:

```php
// web-app/app/View/Navigation.php
return [
  'group:web'  => ['cap' => 'web.view',     'items' => ['web.domains.index', 'web.templates.index' /* admin */]],
  'group:dns'  => ['cap' => 'dns.view',     ...],
  'group:apps' => ['cap' => 'apps.view',    'items' => ['apps.store', 'apps.mine', 'node.apps.index']],
  'group:server' => ['cap' => 'server.view', 'items' => ['server.settings', 'server.services', 'server.ips', 'server.firewall', 'server.ports', 'server.updates', 'server.logs']],
  'group:customers' => ['cap' => 'users.view', 'items' => ['customers.index', 'packages.index', 'ports.index']], // reseller
];
```

Each item is a **route name**; the renderer drops items whose route does not exist (in 1.x the sidebar grew as E7 progressed, with legacy fallbacks linked through `web/inc/arcpanel-routes.php`, [02](./02-laravel-foundation.md) §3.3; every page is in the panel app since 2.0). The three resulting sidebars match the table in [03](./03-tenancy-and-authorization.md) §9.1.

### 4.2 Top bar

| Element                           | Data                                                                                                                                | Notes                                                                                                                  |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Breadcrumb                        | route hierarchy                                                                                                                     | from route name segments                                                                                               |
| Command palette (`Ctrl+K` / `⌘K`) | `v-search-object USER QUERY json` (admin: `v-search-object` across all; reseller/user: scoped by `OwnerScope`) + static route index | results grouped Users / Web / DNS / Mail / DB / Pages; keyboard first; Alpine component, debounced 150 ms, min 2 chars |
| Impersonation badge               | `Principal::isImpersonating()`                                                                                                      | "Logged in as **user**" + "Return" ([02](./02-laravel-foundation.md) §5.7); for resellers "Acting as reseller"         |
| User switcher (admin / reseller)  | `v-list-users json` filtered by `OwnerScope::visibleUsers()`                                                                        | opens impersonation; typeahead                                                                                         |
| Notification tray                 | `v-list-user-notifications USER json`; `v-acknowledge-user-notification`, `v-delete-user-notification`                              | badge count; polled every 60 s via `fetch` of a JSON route; later hosts the Job drawer (E15)                           |
| User menu                         | theme, language, density, account, logout                                                                                           |                                                                                                                        |

### 4.3 Server health widgets (dashboard)

| Widget                     | Source                                                                                                | Render                                                                      |
| -------------------------- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| CPU / memory / disk / load | `v-list-sys-info json`, `v-list-sys-cpu-status`, `v-list-sys-memory-status`, `v-list-sys-disk-status` | `<x-ap-usage-bar>` tiles                                                    |
| Services                   | `v-list-sys-services json`                                                                            | status chips with restart action (admin)                                    |
| Graphs                     | `v-list-sys-rrd json` + images from `/rrd/` (internal nginx location, unchanged)                      | `<x-ap-rrd-graph>` wraps the existing `rrdCharts.js` logic; period selector |
| Updates                    | `v-list-sys-arcpanel-updates json`                                                                    | badge                                                                       |

### 4.4 Dashboards

| Role     | Content                                                                                                                                                                                                                                                                   |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| admin    | health widgets, users count/suspended, owner tree summary (resellers → customers), recent auth log, expiring SSL (next 14 days across all `v-list-web-domains`), quick actions (add user, add domain, open ports)                                                         |
| reseller | own package vs tree usage (`R_*`, [03](./03-tenancy-and-authorization.md) §4.1), customers count vs `USERS`, suspended customers, ports used/free, expiring SSL in tree, customer notifications, quick create customer                                                    |
| user     | quick actions (the create pages of the hosting sections the sidebar shows, then the file manager; `Navigation::quickActions`), usage bars from `U_*` vs package, domains with SSL state, apps (Node/Docker) status, backups last run, notifications, "Your provider" card |

Replaces `web/list/user/` (admin landing) and the per-user `web/list/web/` landing.

---

## 5. Component library

Blade components live in `web-app/resources/views/components/ap/` and are used as `<x-ap-*>`. Every component: tokens only, keyboard operable, labelled, documented in a living style guide at `/_styleguide` (disabled in production unless `DEBUG_MODE='true'`).

| Component                                                                                                                                                                                                        | Purpose                              | Key props / behaviour                                                                                                                                                                                                                                   |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `x-ap-table`                                                                                                                                                                                                     | data table                           | `:columns`, `:rows`, `sortable`, `filterable`, `selectable` (bulk bar), `column-chooser` (persisted per table id in localStorage), server-side sort/filter via query string for large lists; row actions slot; empty state slot; renders cards < 768 px |
| `x-ap-bulk-bar`                                                                                                                                                                                                  | actions for selected rows            | posts to `<module>.bulk` route with `action` + `ids[]` → replaces `web/bulk/*`                                                                                                                                                                          |
| `x-ap-form`, `x-ap-field`, `x-ap-input`, `x-ap-select`, `x-ap-switch`, `x-ap-password` (strength meter from `check-password-strength`), `x-ap-unlimited-input` (number or "unlimited", from `unlimitedInput.js`) | forms                                | inline errors from `$errors` (FormRequest); `x-ap-field` wires `aria-describedby`; `autoTrimInputs`/`focusFirstInput` ported as Alpine plugins                                                                                                          |
| `x-ap-modal`, `x-ap-drawer`                                                                                                                                                                                      | dialogs                              | focus trap, `Esc`, returns focus; `confirmAction.js` becomes `x-ap-confirm`                                                                                                                                                                             |
| `x-ap-toast`                                                                                                                                                                                                     | flash `ok`/`error`                   | ARIA live region; auto-dismiss 6 s for ok, sticky for error                                                                                                                                                                                             |
| `x-ap-alert`, `x-ap-badge`, `x-ap-chip`                                                                                                                                                                          | status                               | semantic colours only                                                                                                                                                                                                                                   |
| `x-ap-empty`, `x-ap-skeleton`                                                                                                                                                                                    | empty and loading states             | skeleton mirrors table/card shapes                                                                                                                                                                                                                      |
| `x-ap-usage-bar`                                                                                                                                                                                                 | quota and resource usage             | value/limit/unlimited; warn ≥ 80 %, danger ≥ 95 %                                                                                                                                                                                                       |
| `x-ap-wizard`                                                                                                                                                                                                    | multi-step forms                     | create-customer, create Node app, install Docker app; step validation via FormRequest per step, state in Laravel session                                                                                                                                |
| `x-ap-tabs`                                                                                                                                                                                                      | tab panels (`tabPanels.js`)          | URL hash sync                                                                                                                                                                                                                                           |
| `x-ap-code`, `x-ap-log`                                                                                                                                                                                          | monospace blocks, streaming log view | `x-ap-log` consumes the WebSocket transport ([07](./07-node-platform.md) §6.4)                                                                                                                                                                          |
| `x-ap-cron-builder`                                                                                                                                                                                              | cron schedule                        | port of `cronGenerator.js`                                                                                                                                                                                                                              |
| `x-ap-card-picker`                                                                                                                                                                                               | template / app selection             | used by [09](./09-web-templates-metadata.md) and [08](./08-app-store.md)                                                                                                                                                                                |
| `x-ap-rrd-graph`                                                                                                                                                                                                 | RRD graphs                           | from `rrdCharts.js`                                                                                                                                                                                                                                     |
| `x-ap-island`                                                                                                                                                                                                    | Vue mount point                      | `<x-ap-island name="file-manager" :props="[...]"/>` → `<div data-island="file-manager" data-props="…json…">`                                                                                                                                            |

### 5.1 Alpine conventions

- One Alpine component per behaviour, registered in `web-app/resources/js/alpine/<name>.js` with `Alpine.data('apTable', …)`; no inline `x-data="{...}"` with logic longer than one expression.
- Server is the source of truth: Alpine never holds data that is not also in the DOM or a `data-*` attribute; forms submit normally (progressive enhancement) and are upgraded to `fetch` for bulk actions and the palette.
- Legacy modules are ported one by one into Alpine components with the same names, so behaviour stays recognisable to contributors.

### 5.2 Vue islands

- One island = one entry point in `web-app/resources/js/islands/<name>/main.ts`, exporting `mount(el, props)`; the loader in `app.js` scans `[data-island]`, dynamically imports the chunk and mounts.
- Islands talk to Laravel through JSON routes under `/<module>/…/api/*`, for example `/files/{user}/api/list` (CSRF via `X-XSRF-TOKEN` cookie), and to streaming endpoints through the WebSocket transport.
- Shared Vue pieces (table, dialog, toast, code editor wrapper) in `resources/js/islands/_shared/` use the same tokens as the Blade components; Vue never renders navigation or anything owner-scoped on its own.
- Islands planned: `file-manager` (E3), `app-store` (E4/E5), `node-apps` (E14), `service-editor` (E7; CodeMirror), `log-viewer` (E7/E18), `rrd` is **not** an island (Alpine + chart.js suffices).

---

## 6. Accessibility

- Keyboard: every interactive element reachable and operable; visible focus ring (`--ap-focus`); skip link; roving tabindex in tables and palette.
- ARIA: tables with `aria-sort`, dialogs with `role="dialog" aria-modal`, toasts in `aria-live="polite"` (errors `assertive`), usage bars `role="meter"`.
- Contrast: tokens validated at build (`scripts/check-contrast.js` fails on < 4.5:1 for text pairs in every theme).
- Lighthouse: the Playwright suite ([11](./11-testing-and-ci.md) §4) runs `lighthouse` on every top-level route for each role and fails < 90 accessibility.
- Reduced motion honoured; no information conveyed by colour alone (status chips carry text/icon).

---

## 7. Build pipeline

Extend `build.js`; no second toolchain.

| Step                    | Input                                                                | Output                                                                                                                                                                                                                        |
| ----------------------- | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tokens + components CSS | `web-app/resources/css/{tokens,base,components}.css`, `themes/*.css` | `web-app/public/build/app.[hash].css`, `themes/<name>.[hash].css`; the legacy `web/css/themes/*.min.css` were built until E8                                                                                                  |
| App JS (Alpine)         | `web-app/resources/js/app.js`                                        | `public/build/app.[hash].js` (esbuild, `format: esm`, `splitting: true`)                                                                                                                                                      |
| Islands                 | `resources/js/islands/*/main.ts` (one entry each)                    | `public/build/islands/<name>.[hash].js` + shared chunks; Vue in a shared vendor chunk; `vue` moves from devDependencies to dependencies                                                                                       |
| Externals               | `chart.js/auto`, `@xterm/*`, `alpinejs`                              | unchanged separate bundles (as today)                                                                                                                                                                                         |
| Manifest                | all of the above                                                     | `public/build/manifest.json` → `@apAsset('app.js')` Blade directive resolves hashed names; `Cache-Control: immutable` from the nginx block in [02](./02-laravel-foundation.md) §3.1                                           |
| i18n for JS             | `web-app/lang/<lang>.json`                                           | strings a script needs are declared in `resources/js/i18n-keys.json`; `<x-ap-i18n>` in the layout emits `window.__ap.i18n = {...}` for the current locale; JS calls `__('Delete')`. No full catalog is shipped to the browser |

- `npm run build` runs all of it; `npm run dev` adds `--watch` (esbuild) — no dev server, files are served by the VM's `arcpanel-nginx`.
- Tests: Vitest + `@testing-library/vue` for islands and shared Vue pieces; Alpine components get Vitest DOM tests with `alpinejs` loaded in jsdom; snapshot tests for Blade components via Pest (`view()->render()`).
- Lint stays Prettier + Biome; `biome.json` gains the `web-app/resources/js` path.
- Package build: `npm ci && npm run build` runs in CI before `src/arcpanel-build.sh` so the deb ships `public/build/`; `public/build/` is git-ignored.

---

## 8. Page anatomy (how a CRUD page is assembled)

```text
resources/views/web/domains/index.blade.php
  <x-ap-page title="{{ __('Web Domains') }}" :actions="[...]">
    <x-ap-table id="web-domains" :columns="$columns" :rows="$domains" selectable sortable filterable>
      @slot('row-actions') ... @endslot
      @slot('empty') <x-ap-empty icon="globe" :cta="route('web.domains.create')"/> @endslot
    </x-ap-table>
    <x-ap-bulk-bar :actions="['suspend','unsuspend','rebuild','delete']" :route="route('web.domains.bulk')"/>
  </x-ap-page>
```

Controller passes already owner-filtered rows and a `can` map; the view contains no authorization logic ([03](./03-tenancy-and-authorization.md) §7.3).

---

## 9. Failure modes

| Failure                                               | Mitigation                                                                                                                                             |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Custom third-party theme breaks after token migration | Custom CSS still loads last; migration note lists renamed selectors; `v-list-sys-themes` unchanged                                                     |
| Island chunk fails to load (cache, CSP)               | `<x-ap-island>` renders a server-side fallback slot (read-only table or "open legacy page" link) and logs to console; CSP allows `'self'` scripts only |
| Large lists (10k DNS records) slow in Alpine          | `x-ap-table` switches to server-side paging above 500 rows via query string; islands use virtual scrolling                                             |
| JS disabled                                           | CRUD pages still submit; only palette, bulk selection and islands degrade                                                                              |
| Translation missing for a new string                  | `__()` falls back to English; CI extracts strings to `arcpanel.pot` and fails if a view uses a string not in the template                              |

---

## 10. Testing

- Pest view tests render every component with representative props (and RTL).
- Vitest for islands; axe-core assertions in Playwright on every route; Lighthouse a11y ≥ 90 gate.
- Visual regression (Playwright screenshots) for the style guide page in light/dark/compact — reviewers approve diffs.
- Manual usability test of the five roadmap tasks against the legacy UI, recorded once per quarter.

---

## 11. Open questions

1. **Closed (E1-S2 D9):** inline SVG sprite from the Nocturne prototype; no Font Awesome in the panel app. Original question: Font Awesome 7 (already a dependency) vs an inline SVG sprite: recommendation — inline SVG sprite generated from the subset of FA icons actually used (smaller, themable, no font flash); keep FA for the legacy pages until E8.
2. Should the command palette include actions ("Add domain …") in addition to objects? Yes if cheap: actions are route-index entries filtered by `Capabilities`.
3. **Closed for now (E1-S2 D8):** density and theme live in the cookie `arc_ui`, because `v-change-user-config-value` cannot add new keys. Original question: Density preference persistence in `user.conf` (`PREF_UI_DENSITY`) requires an upstream-visible key; acceptable since `source_conf` ignores unknown keys, but decide at E2 start.
