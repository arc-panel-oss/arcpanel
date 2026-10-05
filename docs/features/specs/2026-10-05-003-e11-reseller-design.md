# E11 — Roles: admin / reseller / user — design

**Status:** Approved design (owner, 2026-10-05) · **Epic:** E11 (part 1 + part 2) · **Base design:** [03 Tenancy and authorization](../../design/03-tenancy-and-authorization.md) · **Decision record:** [ADR-0004](../../adr/0004-reseller-quota.md) · **Previous slices:** [E1-S1](./2026-10-05-001-e1-laravel-foundation-slice1-design.md), [E1-S2](./2026-10-05-002-e1-s2-dashboard-web-domains-design.md) · **Roadmap:** [§3 E11](../../roadmap.md)

## 1. Summary

E11 adds the **reseller** role: a user that owns a set of customers and manages them inside its own package limits, without admin help and without seeing other tenants. It ships the data model and `v-*` commands, the reseller branch of the authorization frame that S2 left empty, the Users and Packages modules on `/app`, a reseller dashboard and the "Your provider" card for customers.

Design document 03 stays the reference. This spec records the decisions of the 2026-10-05 brainstorm, the scope of E11 and where it differs from 03, most importantly the storage of the tenancy data (D2).

## 2. Decisions taken in the brainstorm

| # | Question | Decision |
| --- | --- | --- |
| D1 | Scope | **All of E11** (03 part 1 + part 2) in one spec, five milestones (§3), each a tested commit. |
| D2 | Where `OWNER` and the reseller package keys live | **Sidecar files**, not `user.conf` or `.pkg`. Upstream commands rewrite both files with a fixed key list: `v-add-user` and `v-change-user-package` rewrite `user.conf`, `v-add-user-package` rewrites `.pkg`. A key added there is lost at the next package change. Per user: `data/users/<user>/tenancy.conf` (`OWNER`, and `R_*` for resellers). Per package: `data/packages/<package>.tenancy` (`USERS`, `RESELLER_PACKAGES`, `RESELLER_VISIBLE`, `RESELLER_OWNER`; E12 adds `PORTS`). Supersedes 03 §4.1–4.2. |
| D3 | Page structure | **One Users module (`/app/users`) and one Packages module (`/app/packages`)**, scoped by role. Resellers see the Users module labelled "Customers". No separate `/app/customers` route (differs from 03 §9.2). |
| D4 | Reseller "login as" | **Only on `/app`.** Stored in a separate session key `arc_look` that the legacy app does not read; re-checked on every request (target in the reseller's tree and `ROLE='user'`). While a reseller impersonates, the sidebar shows only items that have an `/app` page. Admin impersonation keeps the legacy `look` key of S1. |
| D5 | Legacy `web/` | **Reference only.** No changes, redirects or patches. A reseller who opens a legacy page is treated as a plain user (it sees only itself), which is safe. |
| D6 | API access keys (03 §8) | **Deferred** to the Laravel API (E7 Access keys / E29). The legacy API is unchanged: a reseller's key acts only on the reseller itself, so there is no escalation path. |
| D7 | Quota formula (clarifies ADR-0004) | For each total key `WEB_DOMAINS`, `DNS_DOMAINS`, `MAIL_DOMAINS`, `MAIL_ACCOUNTS`, `DATABASES`, `CRON_JOBS`, `DISK_QUOTA`, `BANDWIDTH`: **reseller's own usage `U_X` + Σ customers' package limit X + new package's X ≤ reseller's package X**. A reseller limit of `unlimited` skips the key; a finite reseller limit refuses a package with `unlimited` for that key. In addition, customers + 1 ≤ `USERS`. Per-domain keys (`WEB_ALIASES`, `DNS_RECORDS`) are not totals and are not checked at assignment. |
| D8 | Packages created by resellers | Stored as `<reseller>_<name>` (packages share one namespace), shown without the prefix to the reseller. Every limit ≤ the reseller's own limit. Only with `POLICY_RESELLER_CREATE_PACKAGES='yes'`. |
| D9 | Multi-level resellers | **No.** An owner is `ROOT_USER` or a reseller whose own owner is `ROOT_USER`. |
| D10 | `ROLE='reseller'` | One-line change in `is_role_valid` (`func/main.sh`) to accept `reseller`. A deliberate exception to the "additive only" rule, to be sent upstream. No patch file (differs from 03 §4.1). |

## 3. Milestones

| # | Milestone | Content | Done when |
| --- | --- | --- | --- |
| M1 | CLI and data model | §5 | `test/arcpanel-tenancy.bats` passes on `arcpanel-test` |
| M2 | Authorization | §6; existing S2 pages scoped to the reseller tree | Pest feature tests with admin, reseller A, reseller B and user pass on the S2 routes |
| M3 | Users module | §7 | CRUD, wizard, login-as and bulk work for admin and reseller |
| M4 | Packages module | §8 | Admin CRUD, reseller packages, live quota feedback |
| M5 | Dashboards and finish | §9, §11, §12 | Done criteria of §4 |

## 4. Scope

### In scope

1. `func/tenancy.sh` and the commands of §5, sidecar files, example reseller package, daily counters job, policy keys in `v-list-sys-config`.
2. Reseller row of `Capabilities`, new capabilities, `TenancyDirectory`, `OwnerScope::tree()`, `UserPolicy`, `PackagePolicy`, reseller impersonation, role-aware navigation.
3. Users module: list, tree view, detail, create wizard, edit tabs, single and bulk actions, "My account".
4. Packages module: list, add, edit, copy, delete, reseller package fields, reseller-created packages.
5. Reseller dashboard and the "Your provider" card.
6. BATS, Pest, Vitest and Playwright tests; documentation updates of §12.

### Out of scope

- Port registry and the Ports page (E12).
- API access key scopes and the `reseller-billing` permission set (D6).
- Reseller White Label, hard quota (`POLICY_RESELLER_HARD_QUOTA`).
- Statistics, Backups and Logs pages filtered by tree (E7).
- Any change to the legacy `web/` app (D5).

### Done when

- An admin converts a user to a reseller with a package where `USERS > 0`.
- The reseller onboards a customer (user, package, first web domain with DNS and mail, database) through the wizard, without admin help.
- The reseller logs in as that customer on `/app`, manages the customer's web domains and returns to its own account.
- With `USERS='2'`, a third customer is refused with an error that names `USERS`; a package that does not fit is refused with the name of the first exceeded key.
- Reseller A cannot read or change anything of reseller B or B's customers through any `/app` route or JSON endpoint (palette, notifications included): every attempt returns 404.
- Sidecars survive `v-change-user-package`, `v-rebuild-user` and package rename.
- Pest, Vitest, BATS, smoke and Playwright suites pass on `arcpanel-test`.

## 5. CLI and data model (M1)

### 5.1 Files

| File | Keys | Notes |
| --- | --- | --- |
| `$HESTIA/data/users/<user>/tenancy.conf` | `OWNER`; for resellers `R_USERS`, `R_DISK`, `R_BANDWIDTH`, `R_WEB_DOMAINS`, `R_DNS_DOMAINS`, `R_MAIL_DOMAINS`, `R_MAIL_ACCOUNTS`, `R_DATABASES`, `R_CRON_JOBS` | Absent file or empty `OWNER` = owned by `ROOT_USER`. `R_*` = sum over the tree including the reseller's own `U_*`; display only. Removed with the user directory by `v-delete-user`. Mode `640`, owner root |
| `$HESTIA/data/packages/<package>.tenancy` | `USERS` (integer, `unlimited`, default `0`), `RESELLER_PACKAGES` (comma list or `*`), `RESELLER_VISIBLE` (`yes`/`no`, default `no`), `RESELLER_OWNER` (reseller name, only for reseller-created packages) | Absent file = defaults. Mode `644` like `.pkg` |
| `install/common/packages/reseller.pkg` + `reseller.tenancy` | example: generous limits, `USERS='25'`, `RESELLER_PACKAGES='*'` | installed by the installer and the upgrade script when missing |

`hestia.conf` gains `POLICY_RESELLER_CREATE_PACKAGES` (default `no`) and `POLICY_RESELLER_LOGIN_AS` (default `yes`). `v-list-sys-config` prints both in its `json` and `shell` output (the same output-only change as `ARCPANEL_APP_LOGIN` in S1).

### 5.2 Library `func/tenancy.sh`

Helpers used by all commands below: `tenancy_get_owner USER`, `tenancy_set_owner USER OWNER`, `tenancy_is_reseller USER`, `tenancy_customers RESELLER`, `tenancy_pkg_get PACKAGE KEY`, `tenancy_pkg_set PACKAGE KEY VALUE`, `tenancy_assignable_packages RESELLER`, `tenancy_check_quota RESELLER PACKAGE [USER]` (prints the first exceeded key, returns `E_LIMIT`). Values are written with the same quoting as `update_object_value`; keys are validated against a fixed list.

### 5.3 Commands (all new)

| Command | Behaviour |
| --- | --- |
| `v-add-reseller-user RESELLER USER PASSWORD EMAIL [PACKAGE] [NAME] [LASTNAME]` | `RESELLER` has `ROLE='reseller'` and is not suspended; package is assignable (§5.4); quota fits (D7); then `v-add-user`, `tenancy_set_owner`, `v-update-reseller-counters RESELLER`. Rolls back with `v-delete-user` if setting the owner fails |
| `v-change-reseller-user-package RESELLER USER PACKAGE` | `USER` is owned by `RESELLER`; package assignable; quota fits after removing the current package; then `v-change-user-package` and counters |
| `v-list-reseller-users RESELLER [FORMAT]` | users with `OWNER=RESELLER`; same columns as `v-list-users` plus `OWNER`; formats `json`, `plain`, `csv`, `shell` |
| `v-list-user-owners [FORMAT]` | every user with `ROLE`, `OWNER` (resolved, never empty) and `SUSPENDED` |
| `v-change-user-owner USER OWNER` | `USER` is not an admin and not a reseller; `OWNER` is `ROOT_USER` or a reseller owned by `ROOT_USER` (D9); quota of the new owner fits; counters of both owners updated; logged as `Security` |
| `v-check-reseller-quota RESELLER PACKAGE [USER]` | exit `0` when it fits; `E_LIMIT` and the first exceeded key (`USERS`, `WEB_DOMAINS`, …) on stdout otherwise; with `USER`, that customer's current package is left out (package change) |
| `v-update-reseller-counters RESELLER\|all` | recomputes `R_*`; with `all`, reports owners that no longer exist or are no longer resellers |
| `v-list-reseller-packages RESELLER [FORMAT]` | packages listed in the reseller's `RESELLER_PACKAGES` (or, for `*`, every package with `RESELLER_VISIBLE='yes'`) plus packages with `RESELLER_OWNER=RESELLER`; same columns as `v-list-user-packages` plus `RESELLER_OWNER` |
| `v-list-package-tenancy [PACKAGE] [FORMAT]` | tenancy keys of one or all packages, with defaults filled in |
| `v-change-package-tenancy PACKAGE KEY VALUE` | sets `USERS`, `RESELLER_PACKAGES` or `RESELLER_VISIBLE`; validates the value |
| `v-delete-package-tenancy PACKAGE` · `v-rename-package-tenancy OLD NEW` | keep the sidecar in step with `v-delete-user-package` and `v-rename-user-package` |
| `v-add-reseller-package RESELLER TMPFILE NAME` | `POLICY_RESELLER_CREATE_PACKAGES='yes'`; every limit in `TMPFILE` ≤ the reseller's own (`unlimited` only where the reseller has `unlimited`); then `v-add-user-package TMPFILE RESELLER_NAME` and `RESELLER_OWNER=RESELLER` |
| `v-change-reseller-package RESELLER TMPFILE PACKAGE` | same checks for a package with `RESELLER_OWNER=RESELLER`, then `v-add-user-package … yes` and `v-update-user-package` |

### 5.4 Rules shared by the commands

- A package is **assignable** by a reseller when it is listed by `v-list-reseller-packages`.
- `v-change-user-role USER reseller` works through D10. The panel only offers it when the user's package has `USERS > 0`; the command itself does not check this (the example package and admin docs explain it).
- Changing a reseller back to `user` and deleting a reseller require an empty tree. The panel enforces it. Through the CLI, orphans are treated as owned by `ROOT_USER` and reported by `v-update-reseller-counters all`.
- Restore does not bring back `tenancy.conf` (backups copy `user.conf` only). A restored customer is owned by `ROOT_USER` until an admin runs `v-change-user-owner`. Recorded as a risk (§13).
- `v-add-sys-tenancy` (new, idempotent) writes the policy defaults, installs the example package when missing and adds the daily `v-update-reseller-counters all` line to the `hestiaweb` crontab. The installers, the upgrade step `install/upgrade/arcpanel/1.1.0.sh` and `test/dev-sync.sh` run it.
- New commands are added to the panel app's allow-list (`config/arc-commands.php`) and to the sudoers file of S1.

## 6. Authorization (M2)

| Unit | Change |
| --- | --- |
| `App\Tenancy\TenancyDirectory` (new) | reads `v-list-user-owners json` once per request; `ownerOf()`, `customersOf()`, `isReseller()`, `roleOf()` |
| `App\Auth\OwnerScope` | reseller: `visibleUsers()` = itself + its customers; `tree()` implemented; `canManage()` excludes the principal itself for destructive user actions (delete, suspend, change package, change role, change owner); the reseller edits itself through "My account" |
| `App\Policies\Capabilities` | reseller row filled; new capabilities: `users.view`, `users.create`, `users.update`, `users.delete`, `users.suspend`, `users.change-package`, `users.change-owner` (admin), `users.change-role` (admin), `users.admin-fields` (admin: shell, PHP CLI), `packages.view`, `packages.create`, `packages.update`, `packages.delete`, `impersonate`, `account.update` (self, every role). `web.*` for reseller = `Tree`. `server.*` stays admin-only; `dns-cluster` has no capability |
| `UserPolicy`, `PackagePolicy` (new) | three-line methods as in 03 §7.2; `PackagePolicy` lets a reseller update or delete only packages with `RESELLER_OWNER` = itself, and create only with `POLICY_RESELLER_CREATE_PACKAGES='yes'` |
| `ImpersonationPolicy` | admin unchanged; reseller when `POLICY_RESELLER_LOGIN_AS='yes'`, the target is in its tree and the target's `ROLE` is `user` |
| `ImpersonationController` | admin writes `look` (S1); reseller writes `arc_look` |
| `PrincipalResolver` | reads `arc_look` only for a reseller login, re-checks the tree and role on every request, and clears the key when the check fails |
| `App\View\Navigation` | reseller sidebar: Dashboard · Customers (`users.index`) · Packages · My hosting (Web on `/app`, other modules as legacy links like S2) · My account; while a reseller impersonates, items without an `/app` route are hidden; badge "Viewing as <customer>" with Return |
| Web Domains (S2) | no page changes: the owner column and `?owner=` filter switch from "admin" to `users.view`; scoping comes from `OwnerScope` |
| Palette and notifications | palette filters through `visibleUsers()` (reseller tree); notifications stay per effective user |

## 7. Users module (M3)

Routes under `/app/users`, controllers in `App\Http\Controllers\Users`, `UserService` with one method per command and DTOs (`UserRecord`, `UserUsage`).

### 7.1 List — `GET /app/users`

- Admin: every user (`v-list-users json` + `v-list-user-owners json`), columns user, name, email, role, owner, package, usage, status; filters role and owner; tab "Tree" (admin → resellers → customers, plus users owned by `ROOT_USER`).
- Reseller: its customers (`v-list-reseller-users json`), columns user, name, email, package, usage bars, status.
- Row actions: open, edit, login as, suspend or unsuspend, delete (confirm; refused for a reseller with customers).
- Bulk: suspend, unsuspend, delete; admin also "suspend reseller and customers".

### 7.2 Detail — `GET /app/users/{user}`

Contact, package, status, `U_*` usage against limits, the user's web domains (`WebDomainService`), counts of DNS, mail, databases and cron jobs. For a reseller: allocation table (D7, per key: allocated / limit) and its customers.

### 7.3 Create — `GET /app/users/create`, `POST /app/users`

`x-ap-wizard`, one server-validated step at a time, state kept in the Laravel session:

1. **Account:** username, password (strength meter), email, first and last name, language.
2. **Package:** admin: every package; reseller: `v-list-reseller-packages`; live check through `GET /app/users/quota?package=` (`v-check-reseller-quota`).
3. **First web domain** (optional): domain, "create DNS zone", "create mail domain", as the S2 add form.
4. **Database** (optional): name, user, password (`v-add-database`).
5. **Review:** summary and "send welcome e-mail" (credentials text through `PanelCredentialsMailer`).

Admin also chooses role and owner (`ROOT_USER` or a reseller). Commands: reseller `v-add-reseller-user`; admin `v-add-user`, then `v-change-user-role` and `v-change-user-owner` when chosen; then the optional domain and database. When a later step fails, earlier steps stay applied, the toast names the failed step and the page goes to the user's detail.

### 7.4 Edit — `GET /app/users/{user}/edit`

One form per tab, each running only the commands of changed values (S2 D7):

| Tab | Fields | Commands | Who |
| --- | --- | --- | --- |
| General | first and last name, email, password, language | `v-change-user-name`, `v-change-user-contact`, `v-change-user-password`, `v-change-user-language` | admin, reseller (tree), self |
| Package | package, with quota feedback | admin `v-change-user-package`; reseller `v-change-reseller-user-package` | admin, reseller (customers) |
| Nameservers | NS list | `v-change-user-ns` | admin, reseller (tree), self |
| Security | disable login, IP allow-list, reset 2FA | `v-change-user-config-value` (`LOGIN_DISABLED`, `LOGIN_USE_IPLIST`, `LOGIN_ALLOW_IPS`), `v-delete-user-2fa` | admin; self for IP allow-list as legacy |
| Administration | role, owner, shell, PHP CLI | `v-change-user-role`, `v-change-user-owner`, `v-change-user-shell`, `v-change-user-php-cli` | admin |

Role changes: `user → reseller` only with a package where `USERS > 0`; `reseller → user` only with an empty tree. The protected system administrator stays read-only as in S1.

### 7.5 My account — `GET /app/account`

The edit page for the principal itself with the tabs allowed by `account.update`. Replaces the S2 "Account" legacy link.

### 7.6 Actions

`POST /app/users/{user}/suspend`, `…/unsuspend`, `DELETE /app/users/{user}`, `POST /app/users/bulk {action, items[]}`, `POST /app/impersonate/{user}` (S1 route, now also for resellers). Every item passes `assertVisible` and the policy before any command runs. After every mutation by a reseller, `v-update-reseller-counters RESELLER` runs once.

## 8. Packages module (M4)

Routes under `/app/packages`, `PackageService`, DTO `PackageRecord` (limits + tenancy keys).

- **List:** admin: every package with "used by N users", `RESELLER_VISIBLE` and owner (reseller-created). Reseller: assignable packages (read-only) and its own packages (editable), shown without the prefix.
- **Add / edit / copy / delete:** fields follow the legacy package form: web, backend, proxy and DNS templates; limits with an "unlimited" toggle (`apUnlimited`); rate limit; NS; shell; backups and incremental backups; CPU, memory and swap limits. Admin form has a "Reseller" section: `USERS`, `RESELLER_PACKAGES` (multi-select or `*`), `RESELLER_VISIBLE`. Save: `v-add-user-package` (or `… yes` and `v-update-user-package` on edit) and `v-change-package-tenancy`; rename: `v-rename-user-package` + `v-rename-package-tenancy`; delete: `v-delete-user-package` + `v-delete-package-tenancy`, refused while a user has the package; copy: `v-copy-user-package` + tenancy copy.
- **Reseller packages:** `v-add-reseller-package` / `v-change-reseller-package`; the form shows the reseller's own limit next to every field and refuses values above it.
- `system` package stays hidden and protected as in legacy.

## 9. Dashboards (M5)

- **Reseller:** allocation tiles per key (allocated / limit, D7) and usage `R_*`; customers vs `USERS`; suspended customers; SSL expiring within 14 days across the tree (S2 admin widget, tree-scoped, 5-minute cache); recently created customers; quick action "New customer"; then the "My hosting" part of the user dashboard for the reseller's own account.
- **User owned by a reseller:** card "Your provider" with the reseller's name and contact e-mail (only those two fields).
- Admin dashboard: users widget shows resellers and customers counts.

## 10. Error handling

- Objects outside the tree → 404; policy refusals inside the tree → 403.
- `E_LIMIT` from a quota check → field error naming the key ("Your plan has no room for WEB_DOMAINS").
- Wizard failures as in §7.3.
- A stale `arc_look` (customer re-owned or deleted) silently returns the reseller to its own account with a toast.
- Secrets (passwords) go to commands through `Secret` files and never appear in responses or logs.

## 11. Testing

| Level | Covers |
| --- | --- |
| BATS `test/arcpanel-tenancy.bats` | role `reseller`; reseller package; two customers, third refused with `USERS`; package not assignable refused; quota key refused; change package within quota; owner change incl. D9 refusal; counters; sidecars survive `v-change-user-package`, `v-rebuild-user`, package rename and delete; reseller packages (policy off refused, limit above own refused); cleanup |
| Pest unit | `Capabilities` (every named route has an entry, reseller row), `OwnerScope` with two fixture trees, `TenancyDirectory`, `UserPolicy`, `PackagePolicy`, `ImpersonationPolicy`, quota display planner, edit-tab change planning, navigation per role and while impersonating |
| Pest feature | every route × admin, reseller A, reseller B, user (customer of A) → 200/403/404 as the matrix; wizard paths; bulk with a foreign item → 404 and nothing runs; `arc_look` start, stop and invalidation |
| Vitest | wizard and quota feedback Alpine modules |
| Smoke (`test/arcpanel-login-smoke.sh`, extended) | reseller signs in, creates a customer, logs in as it, returns |
| Playwright | reseller onboarding, login-as and return, cross-tenant negatives, admin tree view, provider card; axe; Lighthouse accessibility ≥ 90 on Users, Packages and the reseller dashboard |

## 12. Documentation changes in the same change set

- **03 Tenancy:** §4 uses the sidecar files (D2); §5 command table as §5.3; §8 marked deferred (D6); §9.2 routes as D3; §7.1 `tree()` implemented.
- **ADR-0004:** status Accepted with the D7 formula.
- **13 Data model reference:** the two sidecar files.
- **Roadmap:** E11 points to this spec.

## 13. Risks

| Risk | Mitigation |
| --- | --- |
| Sidecar out of step with packages renamed or deleted from the CLI | `v-update-reseller-counters all` reports sidecars without a package; the panel always runs the tenancy command next to the package command |
| Restore loses ownership | documented; admin re-owns with `v-change-user-owner`; a restore hook is a follow-up |
| Change to `is_role_valid` lost on upstream merge | BATS test `v-change-user-role x reseller`; upstream PR |
| Admin Users list slow with many users | one `v-list-users json` + one `v-list-user-owners json`; usage comes from the list output |
| Reseller sees legacy pages | legacy treats `userContext='reseller'` as a plain user: only its own objects (D5) |
