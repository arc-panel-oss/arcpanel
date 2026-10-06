# E7 batch 2 — Firewall, IPs, keys, logs, statistics, terminal and Quick Install pages — design

**Status:** Implemented (2026-10-06, see §16) · **Epic:** E7 · **Base design:** [10 Page migration](../../design/10-page-migration-and-retirement.md) §3 (modules 14, 15, 17–21), §5.2–§5.6 · **Previous specs:** [E7 batch 1](./2026-10-06-001-e7-batch1-pages-design.md) (shared runners, owner helpers, hand-off), [E11](./2026-10-05-003-e11-reseller-design.md) (owner scoping, D6 access keys), [E12](./2026-10-05-004-e12-port-registry-design.md) (port firewall rules), [E13](./2026-10-05-006-e13-node-manager-design.md) (task runner pattern) · **Roadmap:** [§3 E7](../../roadmap.md)

## 1. Summary

The second E7 batch moves seven legacy modules to the Laravel panel app: Firewall (rules, IPset lists, ban list), IP addresses, Access keys and SSH keys, Logs (action log, login history, notifications), Statistics and the server graphs (Task Monitor), the Web Terminal page and Quick Install Apps. Every page is owner-scoped for admin, reseller and user, follows the batch 1 pattern (thin controllers, one service method per command, `BulkRunner`, `OwnerList`, `ARCPANEL_APP_PAGES` hand-off) and keeps the legacy commands, argument order and message strings, except for the legacy defects listed in §12.

Two areas get new commands: Quick Install runs in the background through six additive commands that follow the E13 task pattern (options travel in a root-only file, never in `argv` or the log), and a reseller's statistics are summed over its tree by `v-list-reseller-users-stats`.

Server settings, services, config editors and updates, the guest flows (password and 2FA reset) and search stay for batch 3.

## 2. Decisions taken in the brainstorm

| #   | Question                     | Decision                                                                                                                                                                                                                                                                                                                    |
| --- | ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Scope and milestones         | **Seven modules, seven milestones:** M0 foundation, M1 Firewall, M2 IPs, M3 Access keys and SSH keys, M4 Logs and notifications, M5 Statistics and server graphs, M6 Terminal and Quick Install. Server settings and the guest flows go to batch 3.                                                                         |
| D2  | Quick Install                | **New CLI commands with a background task** (§10.1) instead of porting the installer classes into the panel (differs from 10 §5.3). The panel never runs installer code; options and passwords reach the runner through a `Secret` file. The installer library stays in `web/src` until E8 moves it out of `web/`.          |
| D3  | Server graphs                | **Chart.js from `v-export-rrd` JSON** through the existing `x-ap-rrd-graph` component and the `shell.rrd` endpoint, as legacy 1.10 does; no PNG route (differs from 10 §5.4). Administrators only.                                                                                                                          |
| D4  | Statistics per role          | Admin: the server totals (`v-list-users-stats`) or one user. Reseller: the sum of its tree (`v-list-reseller-users-stats`) or one customer. User: itself.                                                                                                                                                                   |
| D5  | Logs                         | Admin reads and deletes the logs of every user. A reseller reads its own logs and, read-only, its customers' logs. A user reads its own logs unless `POLICY_USER_VIEW_LOGS='no'`. Non-admins delete only their own logs, and only unless `POLICY_USER_DELETE_LOGS='no'`.                                                    |
| D6  | Notifications                | **No separate page.** The tray gains "Mark all read" and "Delete all notifications".                                                                                                                                                                                                                                        |
| D7  | Access keys                  | ROOT_USER may use every permission set and manage the keys of other users; everyone else creates keys for itself with `ROLE='user'` sets only. `API_SYSTEM` gates the module (0 off, 1 ROOT_USER only, 2 everyone). The secret is shown once. Tree-scoped keys (`SCOPE`, `reseller-billing`) stay deferred to E29 (E11 D6). |
| D8  | SSH keys                     | Each account manages its own keys; administrators manage any user's keys. Resellers use "login as". Access keys and SSH keys are **two separate pages** (`/app/access-keys`, `/app/ssh-keys`).                                                                                                                              |
| D9  | Port registry firewall rules | Rules whose comment matches `arcpanel-port-<port>-<user>` carry a "Managed by Ports" badge and a link to the Ports page; they cannot be edited, suspended or deleted from the Firewall page (moving stays allowed, it does not touch `FW_RULE`).                                                                            |
| D10 | IP addresses                 | Administrators only. The owner picker lists every panel account (users and resellers); no reseller IP allocation logic.                                                                                                                                                                                                     |
| D11 | Web Terminal                 | `/app/terminal` reuses the xterm bundle and the `/_shell/` WebSocket. Shown when `WEB_TERMINAL='true'` and the login shell is not `nologin`; **hidden while a reseller acts as a customer** (the legacy session keeps the reseller's shell).                                                                                |
| D12 | Navigation                   | Server group (admin): IP Addresses, Firewall, Task Monitor. Account group: Logs, Statistics, Access keys, SSH keys. Web terminal stays in Hosting. Quick Install opens from the web domain edit page ("Quick Install App"). Legacy links switch through `ARCPANEL_APP_PAGES`.                                               |
| D13 | Tests                        | Pest feature tests for admin, reseller A, reseller B and user on every new route; BATS for the new commands; Playwright per module; the Quick Install e2e installs **DokuWiki** (no database, quick).                                                                                                                       |
| D14 | Reseller statistics (design) | A reseller's totals come from one additive command, `v-list-reseller-users-stats RESELLER`, which sums the `stats.log` files of the tree in shell. Calling `v-list-user-stats` per customer from the panel would cost one sudo call per customer on every page view.                                                        |
| D15 | Capabilities (design)        | Every module gets its own capabilities (§5.1); the terminal stops borrowing `files.view`, and the server pages stop sharing `server.view`.                                                                                                                                                                                  |

## 3. Milestones

| #   | Milestone                    | Content                                                               | Done when                                                                                                                              |
| --- | ---------------------------- | --------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| M0  | Foundation                   | §5: capabilities, policies, navigation, `sys-config` route middleware | Authorization unit tests for the four principals; navigation tests per role                                                            |
| M1  | Firewall                     | §6: rules, IPset lists, ban list, managed rules                       | Pest for four principals; Playwright add, edit, move, bulk suspend and delete a rule, add and delete an IP list, ban and unban         |
| M2  | IP addresses                 | §7                                                                    | Pest; Playwright list, edit name and NAT, refresh                                                                                      |
| M3  | Access keys and SSH keys     | §8                                                                    | Pest including `API_SYSTEM` 0/1/2 and the one-time secret; Playwright create, view and delete an access key, add and delete an SSH key |
| M4  | Logs and notifications       | §9.1–§9.3                                                             | Pest for the view and delete rules of D5; Vitest for the tray; Playwright log, login history, delete own log                           |
| M5  | Statistics and server graphs | §9.4–§9.5, `v-list-reseller-users-stats`                              | Pest per role; BATS for the new command; Playwright statistics and Task Monitor                                                        |
| M6  | Terminal and Quick Install   | §10                                                                   | BATS for the Quick Install commands; Pest; Vitest for the task panel; Playwright installs DokuWiki and opens it over HTTP              |

Each milestone ends with a green run and a `feat(e7): …` commit.

## 4. Scope

### In scope

1. Laravel pages for Firewall (rules, IPset lists, ban list), IP addresses, Access keys, SSH keys, action log, login history, Statistics, Task Monitor, Web Terminal and Quick Install, owner-scoped.
2. Tray actions "Mark all read" and "Delete all notifications".
3. Commands `v-list-quick-install-apps`, `v-list-quick-install-app-options`, `v-add-web-domain-app`, `v-start-user-quick-install`, `v-run-user-quick-install`, `v-list-user-quick-install-task`, `v-list-reseller-users-stats`, and the library `func/quickinstall.sh`.
4. Hand-off: `arc_route`, `arc_redirect_if_migrated`, legacy menu links, `LegacyRoutes`, `Navigation`, palette pages.
5. Tests (Pest, Vitest, BATS, Playwright) and the documentation changes of §14.

### Out of scope

- Server settings, services, config editors, updates, White Label, backup host settings (batch 3).
- Password reset and 2FA reset guest flows, search (batch 3).
- Tree-scoped access keys and the `reseller-billing` permission set (E29).
- Reseller IP allocation.
- Moving the Quick Install library out of `web/` (E8) and new installers.
- Log streaming over WebSocket (E18).
- Changes to existing upstream commands.

### Done when

1. With `ARCPANEL_APP_PAGES=yes` the legacy pages `/list/firewall/`, `/add/firewall/`, `/edit/firewall/?rule=`, `/list/firewall/ipset/`, `/add/firewall/ipset/`, `/list/firewall/banlist/`, `/add/firewall/banlist/`, `/list/ip/`, `/add/ip/`, `/edit/ip/?ip=`, `/list/rrd/`, `/list/log/[?user=]`, `/list/log/auth/`, `/list/stats/[?user=]`, `/list/terminal/`, `/list/access-key/[?key=]`, `/add/access-key/`, `/list/key/`, `/add/key/` and `/add/webapp/?domain=[&app=]` answer 302 to the new pages; with `no` they answer 200.
2. Admin, reseller and user reach exactly the pages and owners of §5; another tree answers 404.
3. Every legacy action of the seven modules is available with the same command calls, apart from the deviations of §12.
4. A user installs DokuWiki from the panel, watches the task log and opens the site.
5. A reseller sees the summed statistics of its tree.
6. The sidebar, the command palette and the legacy menu link to the new pages.

## 5. Foundation (M0)

### 5.1 Capabilities

`Capabilities::MATRIX` gains (scopes as in batch 1: `ADMIN` admin only, `OWN` admin all and reseller/user self, `ALL_ROLES` admin all, reseller tree, user self):

| Capability                               | Scope       | Used by                                                        |
| ---------------------------------------- | ----------- | -------------------------------------------------------------- |
| `server.firewall`                        | `ADMIN`     | Firewall pages                                                 |
| `server.ips`                             | `ADMIN`     | IP pages                                                       |
| `server.rrd`                             | `ADMIN`     | Task Monitor page and `shell.rrd`                              |
| `logs.view`, `logs.delete`               | `ALL_ROLES` | action log, login history (replaces the navigation-only entry) |
| `stats.view`                             | `ALL_ROLES` | Statistics (replaces the navigation-only entry)                |
| `access-keys.view`, `access-keys.manage` | `OWN`       | Access keys                                                    |
| `ssh-keys.view`, `ssh-keys.manage`       | `OWN`       | SSH keys                                                       |
| `terminal.use`                           | `ALL_ROLES` | Web Terminal (was `files.view`)                                |
| `web.apps.install`                       | `ALL_ROLES` | Quick Install                                                  |

### 5.2 Policies

All policies read `Capabilities` and `OwnerScope` and refuse changes in read-only mode; no view, Alpine module or service compares user names.

| Policy                        | Rules                                                                                                                                                                                                                                                                                                                                                                                 |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `FirewallPolicy`              | `viewAny`, `manage` (`server.firewall`); `change(rule)` is false for a managed rule (D9)                                                                                                                                                                                                                                                                                              |
| `IpPolicy`                    | `viewAny`, `manage` (`server.ips`)                                                                                                                                                                                                                                                                                                                                                    |
| `LogPolicy`                   | `view(owner)`: `logs.view` within scope; a non-admin acting role also needs `POLICY_USER_VIEW_LOGS != 'no'`. `delete(owner)`: admin within scope; otherwise only the effective user's own log and `POLICY_USER_DELETE_LOGS != 'no'`. `viewAuth(owner)`: as `view`, not in demo mode, and the ROOT_USER's history only for ROOT_USER itself when `POLICY_SYSTEM_PROTECTED_ADMIN='yes'` |
| `StatsPolicy`                 | `view(owner)`: `stats.view` within scope; `overall()`: admin; `tree()`: reseller acting as itself                                                                                                                                                                                                                                                                                     |
| `AccessKeyPolicy`             | `available(owner)`: `API_SYSTEM >= 1` and (owner is ROOT_USER or `API_SYSTEM >= 2`); `view/manage(owner)`: `access-keys.*` within the `OWN` scope; `allowedSets(owner)`: every set for ROOT_USER, `ROLE='user'` sets otherwise                                                                                                                                                        |
| `SshKeyPolicy`                | `view/manage(owner)`: `ssh-keys.*` within the `OWN` scope                                                                                                                                                                                                                                                                                                                             |
| `TerminalPolicy`              | `use()`: `terminal.use`, `WEB_TERMINAL='true'`, login shell not `nologin`, not read-only, not a reseller acting as a customer                                                                                                                                                                                                                                                         |
| `WebDomainPolicy::installApp` | `web.apps.install` and `canManage(owner)`, not read-only                                                                                                                                                                                                                                                                                                                              |

`ServerPolicy::viewHealth` keeps serving the dashboard; `shell.rrd` switches to `server.rrd`.

### 5.3 Navigation and middleware

- `Navigation`: `server.ips`, `server.firewall` and `server.rrd` use their new capabilities and a `section`; the Account group gains `access-keys.index` ("Access keys", `requires` an available API) and `ssh-keys.index` ("SSH keys"); `terminal.index` uses `terminal.use` and is left out while a reseller acts as a customer (already the rule for legacy links).
- Route middleware `sys-config:KEY[=value]` answers 404 when the `hestia.conf` key is empty or differs (`sys-config:FIREWALL_SYSTEM`, `sys-config:WEB_TERMINAL=true`), using the `Navigation` rule.
- The palette lists the new pages for the principals that may open them.

## 6. Firewall (M1)

### 6.1 Routes (`/app/server/firewall`, names `server.firewall*`, middleware `sys-config:FIREWALL_SYSTEM`)

| Method and path                              | Name                                  | Commands                                                                                        |
| -------------------------------------------- | ------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `GET /`                                      | `server.firewall`                     | `v-list-firewall json`                                                                          |
| `GET /create`, `POST /`                      | `server.firewall.create/store`        | `v-list-firewall-ipset json`; `v-add-firewall-rule ACTION IP PORT PROTOCOL COMMENT`             |
| `GET /{rule}/edit`, `POST /{rule}/edit`      | `server.firewall.edit/update`         | `v-list-firewall-rule RULE json`; `v-change-firewall-rule RULE ACTION IP PORT PROTOCOL COMMENT` |
| `POST /{rule}/move/{direction}`              | `server.firewall.move`                | `v-move-firewall-rule RULE up\|down`                                                            |
| `POST /{rule}/suspend`, `/{rule}/unsuspend`  | `server.firewall.suspend/unsuspend`   | `v-suspend-firewall-rule RULE`, `v-unsuspend-firewall-rule RULE`                                |
| `DELETE /{rule}`                             | `server.firewall.destroy`             | `v-delete-firewall-rule RULE`                                                                   |
| `POST /bulk`                                 | `server.firewall.bulk`                | `delete`, `suspend`, `unsuspend` per rule (managed rules are skipped and reported)              |
| `GET /ipsets`                                | `server.firewall.ipsets`              | `v-list-firewall-ipset json`                                                                    |
| `GET /ipsets/create`, `POST /ipsets`         | `server.firewall.ipsets.create/store` | `v-add-firewall-ipset NAME DATASOURCE IPVER AUTOUPDATE`                                         |
| `DELETE /ipsets/{name}`, `POST /ipsets/bulk` | `server.firewall.ipsets.destroy/bulk` | `v-delete-firewall-ipset NAME`                                                                  |
| `GET /bans`                                  | `server.firewall.bans`                | `v-list-firewall-ban json` (needs `FIREWALL_EXTENSION`)                                         |
| `GET /bans/create`, `POST /bans`             | `server.firewall.bans.create/store`   | `v-add-firewall-ban IP CHAIN`                                                                   |
| `DELETE /bans`, `POST /bans/bulk`            | `server.firewall.bans.destroy/bulk`   | `v-delete-firewall-ban IP CHAIN`; the item value is `CHAIN:IP`, split at the first colon        |

`{rule}` is `\d{1,9}`, `{direction}` is `up|down`, `{name}` is `[\w.-]{1,255}`.

### 6.2 Pages

- **Rules:** columns Pos (links to edit), Action (DROP danger, ACCEPT ok badge), Comment, Protocol, Port, IP Address, Status; managed rules show "Managed by Ports" with a link to `server.ports`. Row actions: Move up (not on the first row, confirm "Are you sure you want to move rule #%s up?"), Move down (not on the last row), Edit, Suspend/Unsuspend, Delete, with the legacy confirmations. Toolbar: "Add Rule", "IPset IP Lists", "Banned IP Addresses" (only with `FIREWALL_EXTENSION`). Bulk Delete, Suspend, Unsuspend.
- **Add / edit rule:** Action (DROP default, ACCEPT), Protocol (TCP, UDP, ICMP), Port (hint "(Ranges and lists are acceptable)", placeholder "All ports: 0, Range: 80-82, List: 80,443,8080,8443"; spaces become commas, repeated commas collapse, outer commas are trimmed; "0" is valid), IP Address / IPset IP List (a select fills the field with `ipset:<name>` from active IPv4 lists; "(Support CIDR format)"), Comment (optional, 255). Blank fields give `Field "%s" can not be blank.` Success "Rule has been created successfully." / "Changes have been saved." A managed rule's edit page is read-only and explains why.
- **IPset lists:** columns IP List Name, Auto Update (Enabled/Disabled), IP Version, Date, Time; delete with "Are you sure you want to delete IP list %s?"; empty state "There are currently no IP lists defined." The add form has IP List Name, Data Source "(URL, script or file)" with the legacy helper select (blacklist script and the IPverse country lists), IP Version (IPv4/IPv6), Auto Update (Yes/No). Success "IP list has been created successfully."
- **Ban list:** columns IP Address, Chain, Date, Time; delete with "Are you sure you want to delete IP address %s?"; empty state "There are currently no banned IP addresses." The add form has IP Address "(Support CIDR format)" and Banlist (SSH, WEB, FTP, DNS, MAIL, DB, HESTIA). Success "IP address has been banned successfully."

## 7. IP addresses (M2)

### 7.1 Routes (`/app/server/ips`, names `server.ips*`)

| Method and path                     | Name                      | Commands                                                                                                                                                                |
| ----------------------------------- | ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /`                             | `server.ips`              | `v-list-sys-ips json`                                                                                                                                                   |
| `GET /create`, `POST /`             | `server.ips.create/store` | `v-list-sys-interfaces json`; `v-add-sys-ip IP NETMASK INTERFACE OWNER STATUS NAME NAT`                                                                                 |
| `GET /{ip}/edit`, `POST /{ip}/edit` | `server.ips.edit/update`  | `v-list-sys-ip IP json`; only for changed values: `v-change-sys-ip-status IP shared\|dedicated`, `v-change-sys-ip-owner`, `v-change-sys-ip-name`, `v-change-sys-ip-nat` |
| `DELETE /{ip}`, `POST /bulk`        | `server.ips.destroy/bulk` | `v-delete-sys-ip IP`                                                                                                                                                    |
| `POST /refresh`                     | `server.ips.refresh`      | `v-update-sys-ip`                                                                                                                                                       |

`{ip}` is `[0-9A-Fa-f:.]{2,45}`.

### 7.2 Pages

- **List:** columns IP Address (with "→ NAT" when set), Netmask, Interface, Status, Domains, Owner; row actions Edit, Delete ("Are you sure you want to delete IP address %s?"); toolbar "Add IP Address" and "Refresh IP Addresses"; bulk Delete.
- **Add:** IP Address, Netmask, Interface (select), Shared (checked by default), Assigned User (shown when not shared; every account from `UserDirectory`), Assigned Domain (optional), NAT IP Association (optional). Status is `shared` when the box is checked, else `dedicated`. Success "IP address {%s} has been created successfully." with a link to the edit page.
- **Edit:** IP, netmask and interface read-only; the steps of §7.1 run in that order and only for changed values; "Changes have been saved."

## 8. Access keys and SSH keys (M3)

### 8.1 Access key routes (`/app/access-keys`, names `access-keys.*`)

| Method and path        | Name                  | Commands                                                               |
| ---------------------- | --------------------- | ---------------------------------------------------------------------- |
| `GET /`                | `access-keys.index`   | redirects to the effective user's list (admin: `?owner=`)              |
| `GET /{user}`          | `access-keys.list`    | `v-list-access-keys USER json`                                         |
| `GET /{user}/create`   | `access-keys.create`  | `v-list-apis json` filtered by `allowedSets`                           |
| `POST /{user}`         | `access-keys.store`   | `v-add-access-key USER SETS COMMENT json`, then renders §8.2 "created" |
| `GET /{user}/{key}`    | `access-keys.show`    | `v-list-access-key KEY json`; 404 when `USER` differs                  |
| `DELETE /{user}/{key}` | `access-keys.destroy` | ownership checked as above, then `v-delete-access-key KEY`             |
| `POST /{user}/bulk`    | `access-keys.bulk`    | `delete` per key, each checked as above                                |

`{key}` is `[A-Za-z0-9]{20}`. Every route answers 404 when `AccessKeyPolicy::available(owner)` is false.

### 8.2 Access key pages

- **List:** columns Access Key (links to the detail page), Comment, Date, Time, sorted by date and time; delete with "Are you sure you want to delete access key %s?"; toolbar "Add Access Key"; bulk Delete. Administrators get the owner filter.
- **Add:** one checkbox per allowed permission set (label is the set name), Comment (optional, 255). No set: `Field "Permissions" can not be blank.`; a set outside `allowedSets`: "API %s not allowed".
- **Created:** the store action answers 200 with the key page, the secret and the warning "Warning! Last chance to save secret key!", plus a `history.replaceState` to the list URL so a reload does not post again. The secret is never written to the session, a flash or a log.
- **Detail:** Access Key ID, Permissions (list), Comment. `AccessKeyService` drops `SECRET_ACCESS_KEY` from every record it returns except the store result.

### 8.3 SSH key routes and page (`/app/ssh-keys`, names `ssh-keys.*`)

| Method and path       | Name               | Commands                                                        |
| --------------------- | ------------------ | --------------------------------------------------------------- |
| `GET /`               | `ssh-keys.index`   | redirects to the effective user's list (admin: `?owner=`)       |
| `GET /{user}`         | `ssh-keys.list`    | `v-list-user-ssh-key USER json` (empty output is an empty list) |
| `POST /{user}`        | `ssh-keys.store`   | duplicate check, then `v-add-user-ssh-key USER KEY`             |
| `DELETE /{user}/{id}` | `ssh-keys.destroy` | `v-delete-user-ssh-key USER ID`                                 |

The page lists SSH ID and SSH Key (truncated, full key on hover) and has an "Add SSH Key" drawer with one textarea. The duplicate check is legacy's: the key splits on spaces into type, body and ID; a missing ID gets `md5(time())`; an existing ID or body gives "SSH Key already exists." Success "SSH Key has been created successfully."; delete confirms "Are you sure you want to delete SSH key %s?". The file manager key `filemanager.ssh.key` is neither listed nor counted.

## 9. Logs, notifications, statistics and graphs (M4, M5)

### 9.1 Log routes (`/app/logs`, names `logs.*`)

| Method and path       | Name                | Commands                                                              |
| --------------------- | ------------------- | --------------------------------------------------------------------- |
| `GET /`               | `logs.index`        | redirects to the effective user's log (admin and reseller: `?owner=`) |
| `GET /{user}`         | `logs.show`         | `v-list-user-log USER json`                                           |
| `DELETE /{user}`      | `logs.destroy`      | `v-delete-user-log USER`                                              |
| `GET /{user}/auth`    | `logs.auth`         | `v-list-user-auth-log USER json`                                      |
| `DELETE /{user}/auth` | `logs.auth.destroy` | `v-delete-user-auth-log USER`                                         |

### 9.2 Log pages

- **Action log:** newest first; columns Level (Information, Warning, Error badge), Date, Time, Category, Message; toolbar owner filter (admin, reseller), "Login History" (not in demo mode), "Refresh", "Delete" (confirm "Are you sure you want to delete the logs?") when `LogPolicy::delete` allows. An unreadable log shows one row "Unable to load logs".
- **Login history:** columns Status (Success/Failed), Date, Time, IP Address, Browser; same toolbar without "Login History".

### 9.3 Notification tray

`POST /_notifications/acknowledge` (`shell.notifications.acknowledge-all`) runs `v-acknowledge-user-notification USER all`; `DELETE /_notifications` (`shell.notifications.destroy-all`) runs `v-delete-user-notification USER all`. Both use the `not-readonly` middleware. The tray shows "Mark all read" when something is unread and "Delete all notifications" when there are more than two notifications.

### 9.4 Statistics (`GET /app/stats`, `stats.index`, `?owner=`)

| Principal | Without owner                                                 | With owner                                |
| --------- | ------------------------------------------------------------- | ----------------------------------------- |
| admin     | "Overall Statistics": `v-list-users-stats json`               | `v-list-user-stats OWNER json`            |
| reseller  | "Overall Statistics": `v-list-reseller-users-stats SELF json` | `v-list-user-stats OWNER json`, tree only |
| user      | `v-list-user-stats SELF json`                                 | 404 for anyone else                       |

The owner picker lists every user (without ROOT_USER when `POLICY_SYSTEM_HIDE_ADMIN='yes'`) for admins and the tree for resellers. One card per month, newest first, titled "Oct 2026": Bandwidth (used / limit), Disk (used / quota, with the Web, Databases, Mail and User Directory parts), IP Addresses (admins, or when `IP_OWNED != 0`), and the counts Web Domains, SSL Domains, Web Aliases, DNS Zones, DNS Records, Mail Domains, Mail Accounts, Databases, Cron Jobs, Backups. Sizes use the panel's size formatter (MB values).

`v-list-reseller-users-stats RESELLER [FORMAT]` (new): `RESELLER` must be a reseller; reads `data/users/<u>/stats.log` of the reseller and its customers (`tenancy_customers`), sums every numeric key per `DATE`, keeps the latest `TIME`, sets `PACKAGE` to the reseller's package and `U_USERS` to the number of accounts with a row for that month, and prints the rows in the `v-list-users-stats` columns (`json`, `plain`, `csv`, `shell`). Read-only, no log entry.

### 9.5 Task Monitor (`GET /app/server/rrd`, `server.rrd`)

`v-list-sys-rrd json` gives the graphs; each renders `x-ap-rrd-graph` with service `RRD`, or `net_<RRD>` for `TYPE='net'`, as legacy does. The page has the period selector Daily, Weekly, Monthly, Yearly, Biennially, Triennially (`?period=`, default `daily`), passed to every graph; the component gains the two long periods. `shell.rrd` accepts only services listed by `v-list-sys-rrd`.

## 10. Web Terminal and Quick Install (M6)

### 10.1 Quick Install commands

| Command                                                     | Behaviour                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v-list-quick-install-apps [FORMAT]`                        | PHP. Every installer under `web/src/app/WebApp/Installers/`: `NAME`, `GROUP`, `VERSION`, `THUMBNAIL` (file name next to the installer), `PHP_VERSIONS` (supported and installed, comma-separated), `INSTALLABLE` (`yes` when one PHP version fits). Formats `json`, `shell`.                                                                                                                                                                                |
| `v-list-quick-install-app-options USER DOMAIN APP [FORMAT]` | PHP. The form of one installer for a domain the user owns: `APP`, `VERSION`, `ROOT_CLEAN` (`yes`/`no`, `AppWizard::isDomainRootClean`) and `FIELDS`, a list of `KEY`, `TYPE` (`text`, `password`, `boolean`, `select`), `LABEL`, `VALUE`, `PLACEHOLDER`, `OPTIONS` (list of `VALUE`, `LABEL`) and `REQUIRED` (`yes` when there is no default and the key is not `database_*`, as `v-quick-install-app`).                                                    |
| `v-add-web-domain-app USER DOMAIN APP OPTIONS_FILE`         | PHP, synchronous. Reads a JSON object of option values from `OPTIONS_FILE`, merges the defaults, checks the required fields ("Missing required field: KEY"), runs `AppWizard::execute()` as `v-quick-install-app install` does, and prints progress lines without option values. A failing sub-command is reported by its command name only, never with its arguments. Logs `v-log-action USER Info Web "Installed APP on DOMAIN"`.                         |
| `v-start-user-quick-install USER DOMAIN APP OPTIONS_FILE`   | Bash. Checks the user (unsuspended), the domain (owned, unsuspended), the app name (`^[A-Za-z][A-Za-z0-9]{0,63}$`, installer present), the options file (regular file, at most 64 KiB, a JSON object), demo mode; refuses with `E_INUSE` while the user has a running install; copies the options to `data/users/<user>/quick-install.options` (root, `0600`); writes the task record; starts `v-run-user-quick-install` with `setsid`; prints the task ID. |
| `v-run-user-quick-install ID USER DOMAIN APP`               | Bash, background part: refuses a task it did not start, runs `v-add-web-domain-app` with the staged file, removes the file on every exit path (`trap`), records `STATUS`, `EXIT`, `END`.                                                                                                                                                                                                                                                                    |
| `v-list-user-quick-install-task USER [LINES] [FORMAT]`      | Bash. The record (`ID`, `DOMAIN`, `APP`, `STATUS`, `EXIT`, `START`, `END`) and the last `LINES` (default 100, at most 1000) lines of the log; a running task whose process is gone is reported and stored as `failed`.                                                                                                                                                                                                                                      |

`func/quickinstall.sh` holds the task helpers (`qi_task_conf`, `qi_task_get`, `qi_task_set`, `qi_task_reap`, `qi_task_running`) with the E13 semantics. A small PHP bootstrap shared by the three PHP commands (`func/quickinstall.php`) loads `web/inc/vendor/autoload.php` and `web/src/init.php`, defines `HESTIA_DIR_BIN` and `HESTIA_CMD`, and fills the `$_SESSION` keys the installer classes read (`user`, `userContext='user'`, `look=''`, `WEB_SYSTEM`, `WEB_BACKEND` and the rest of `v-list-sys-config`).

Files: `data/users/<user>/quick-install-task.conf` (one line `ID DOMAIN APP STATUS PID EXIT START END`, root `0640`), `quick-install-task.log` (root `0640`, replaced by each install), `quick-install-task.lock`, and the transient `quick-install.options` (root `0600`, removed when the runner ends).

### 10.2 Quick Install routes (inside `web/domains/{user}/{domain}`, middleware `effective-user`)

| Method and path       | Name                        | Commands                                                                                  |
| --------------------- | --------------------------- | ----------------------------------------------------------------------------------------- |
| `GET /install`        | `web.domains.install`       | `v-list-quick-install-apps json`                                                          |
| `GET /install/{app}`  | `web.domains.install.form`  | `v-list-quick-install-app-options USER DOMAIN APP json`, `v-list-user-quick-install-task` |
| `POST /install/{app}` | `web.domains.install.start` | `v-start-user-quick-install USER DOMAIN APP <secret file>` (`not-readonly`)               |
| `GET /install-task`   | `web.domains.install.task`  | `v-list-user-quick-install-task USER 200 json` (JSON)                                     |

### 10.3 Quick Install pages

- **Picker:** title "Quick Install App"; `x-ap-card-picker` cards with the thumbnail (copied by `build.js` to `public/build/quick-install/<App>/<file>`), name, "Version: %s"; not installable cards are disabled with "Unable to install %s, required php version is not available." The web domain edit page gets a "Quick Install App" button.
- **Form:** title "Install %s"; the "Data Loss Warning!" alert when `ROOT_CLEAN='no'` ("Your web folder already has files uploaded to it. The installer will overwrite your files and/or the installation might fail." / "Please make sure ~/web/%s/public_html is empty!"); one field per `FIELDS` entry (`x-ap-input`, `x-ap-password` with generator, `x-ap-checkbox`, `x-ap-select`), labels as `v-list-…-options` gives them. Submit posts the values as one JSON `Secret::file`.
- **Task panel:** after the start, the form page shows the task (status, log tail polled every 2 s by the `apTask` Alpine module) and disables the submit button while it runs; done shows "%s installed successfully." with a link to the site; failed shows the log and "Unable to install %s." An `E_INUSE` refusal flashes "Another installation is running for this account."

### 10.4 Web Terminal (`GET /app/terminal`, `terminal.index`, `sys-config:WEB_TERMINAL=true`)

The page renders the terminal container and loads an `apTerminal` module that imports `@xterm/xterm` and `@xterm/addon-webgl` from the panel bundle (the packages the legacy bundle already uses), opens `wss://<host>/_shell/` and keeps the legacy messages "Connection error." and "Connection closed.". `TerminalPolicy::use` decides access; the server side does not change.

## 11. Hand-off

- `web/inc/arcpanel-routes.php`: `arc_route()` maps `/list/firewall/`, `/list/ip/`, `/list/rrd/`, `/list/log/`, `/list/stats/`, `/list/terminal/`, `/list/access-key/`, `/list/key/`; `arc_redirect_if_migrated()` covers every page of §4 "Done when" 1, with the admin `?user=` owner for logs, statistics, access keys and SSH keys.
- `web/templates/includes/panel.php` wraps the terminal, log and statistics menu links with `arc_route()`.
- `LegacyRoutes` loses `server.ips`, `server.firewall`, `server.rrd`, `logs.index`, `stats.index`, `terminal.index` and `web.apps.install`.

## 12. Deviations from legacy

1. The firewall bulk bar offers Suspend and Unsuspend (the legacy controller handled them, the select did not).
2. `move/firewall` direction and the RRD service are whitelisted.
3. Ban list bulk items are `CHAIN:IP`, split at the first colon, so IPv6 addresses work.
4. "Refresh IP Addresses" is a button that needs no selection.
5. Access key bulk deletes honour the administrator's target user; the detail page never shows the secret; a failed delete returns to the access key list (legacy went to `/list/key/`).
6. The file manager SSH key is neither listed nor counted.
7. Deleting the login history does not log a new login line (legacy never did: `look` is always set) and is refused in read-only mode.
8. Statistics owner values are escaped.
9. Quick Install runs in the background with a visible log instead of blocking the request for up to five minutes.

## 13. Testing

| Suite        | Cases                                                                                                                                                                                                                                                                                                                                              |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pest unit    | `Capabilities` entries for every new route; the policies of §5.2 for admin, reseller A, reseller B and user; port-string normalisation; ban item parsing; SSH duplicate check; statistics month cards; Quick Install field mapping                                                                                                                 |
| Pest feature | Every route for the four principals (404 outside the tree, 403 inside it); command arguments as §6–§10; managed rules locked; `API_SYSTEM` 0/1/2; the secret only in the store response; log view and delete rules with both policies; reseller statistics through the new command; `E_INUSE`; terminal hidden for a reseller acting as a customer |
| Vitest       | tray "Mark all read" and "Delete all"; `apTask` polling and stop; `apTerminal` messages with a fake WebSocket                                                                                                                                                                                                                                      |
| BATS         | `test/arcpanel-quick-install.bats`: list apps and options as JSON, DokuWiki install through start/run/list, `E_INUSE`, options file removed after success and failure, no option value in the task log or `system.log`, domain of another user refused; `v-list-reseller-users-stats` sums a two-customer tree and refuses a non-reseller          |
| Playwright   | `firewall.spec.js`, `ips.spec.js`, `keys.spec.js`, `logs.spec.js`, `stats.spec.js` (including Task Monitor), `quick-install.spec.js` (DokuWiki on the e2e site, then HTTP 200 on the site), terminal page when `WEB_TERMINAL='true'` on the server; axe on every page                                                                              |

`test/e2e/prepare.sh` gains the steps the specs need (a throw-away firewall rule and IP list, an SSH key, an empty `public_html` for the Quick Install site).

## 14. Documentation changes in the same change set

- [13 Data model reference](../../design/13-data-model-reference.md): the Quick Install task files and the seven commands.
- [10 Page migration](../../design/10-page-migration-and-retirement.md): status line and modules 14, 15, 17–21 marked as implemented by this spec.
- [Roadmap](../../roadmap.md): E7 status line.
- This spec: "Implementation notes".
- 15 (database schema) does not change: no new entity or table.

## 15. Risks

| Risk                                                                     | Mitigation                                                                                                                                             |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Installer classes leak option values (passwords) in exceptions or output | `v-add-web-domain-app` prints its own progress lines, reports failing commands by name only; BATS greps the log and `system.log` for the test password |
| Installers read `$_SESSION` keys the CLI bootstrap does not set          | The bootstrap copies every `v-list-sys-config` key, as `v-quick-install-app` does; DokuWiki e2e and BATS run the real installer                        |
| Deleting a managed firewall rule from the Firewall page breaks `FW_RULE` | `FirewallPolicy::change` refuses it in single and bulk actions; Pest proves it                                                                         |
| The terminal opens for the wrong account during "login as"               | `TerminalPolicy` hides it for resellers acting as customers; administrators keep the legacy behaviour                                                  |
| `API_SYSTEM` semantics differ between list, add and delete in legacy     | One `AccessKeyPolicy::available` rule for every access key route                                                                                       |

## 16. Implementation notes (2026-10-06)

These notes win over the sections above where they differ.

1. **Commits.** M0 `dac9399`, M1 Firewall `e509986`, M2 IPs `f73165e`, M3 keys `0e2350b`, M4 logs `9c65047`, M5 statistics and graphs `1093c8b`, M6 terminal and Quick Install `8d8b393`, then these notes.
2. **Shared code.** Route middleware `sys-config` (`App\Http\Middleware\RequireSysConfig`); the trait `App\Policies\Concerns\ReachesOwner`, which makes the `Self` scope of `OWN` capabilities effective for owners other than the effective user (access keys, SSH keys); `CommandResult::errorMessage()`; the Blade component `x-ap-owner-switch`, the owner picker of pages that belong to one account (keys, logs, statistics); the Alpine modules `apTask` and `apTerminal`.
3. **Firewall (M1).** The rule list is ordered by position. Managed rules in a bulk selection are reported as failed items ("Managed by Ports") instead of refusing the whole request. Single suspend, unsuspend and delete get the toasts "Rule has been suspended.", "Rule has been unsuspended." and "Rule has been deleted." (legacy showed none). `v-add-firewall-ipset` ignores lists with fewer than five entries; the e2e list file therefore holds five addresses.
4. **IP addresses (M2).** The e2e edits the assigned domain and refreshes the list; it does not change the NAT address, because the test server has one IP and its web domains use that NAT address. Pest covers the NAT step.
5. **Access keys (M3).** `v-list-access-key` prints the secret; `AccessKeyService` drops it from every record but the one `v-add-access-key` returns. The page shown after creation is sent with `Cache-Control: no-store`.
6. **SSH keys (M3).** `v-list-user-ssh-key` prints the key comment as `ID` and the fingerprint as `KEY`. The duplicate check compares IDs and also compares the key body with the fingerprints, as legacy does; the body never matches a fingerprint. A user without `authorized_keys` gets no output, which the page reads as no keys. After a refused add the drawer opens again with the key.
7. **Logs (M4).** An administrator acting as a user keeps the administrator's log rights, as legacy does through `userContext`. The tray actions use the `not-readonly` middleware.
8. **Statistics and graphs (M5).** Statistics live on one route, `stats.index?owner=`. A reseller acting as a customer sees the customer's months. The `v-list-sys-rrd` fixture used `RRD: net_eth0`; the server prints `RRD: eth0` with `TYPE: net`, and the fixture now matches that. `shell.rrd` needs `server.rrd` and answers 404 for services that `v-list-sys-rrd` does not list. The period links are a segment of links (`.ap-segment a`).
9. **Quick Install (M6).**
   - `func/quickinstall.php` loads `web/inc/vendor` and `web/src`.
   - Its context class `QiHestiaApp` maps a NAT address back to the local address. Installers that post their setup form to the domain (DokuWiki, WordPress …) then reach the server; on `arcpanel-test` the domain's only IP has a NAT address the server cannot reach.
   - The installer classes report failed sub-commands with `trigger_error()` and the whole command line; those lines are dropped and only the command name is printed. `ProcessFailedException` is reported by command name as well.
   - An installer without a fitting PHP version makes the options and add commands exit 2 with the legacy message; the picker shows the app disabled.
   - Exit codes: `E_INUSE` is 7, `E_FORBIDEN` is 10.
   - When "database create" is unchecked, the database name, user and password become required. The legacy check had this condition inverted.
   - `build.js` copies the installer logos to `public/build/quick-install/<App>/`.
   - An empty optional password is left out, so the installer generates one.
   - The three PHP commands are in `.prettierignore`, like `v-quick-install-app`.
   - `arcpanel-test` had PHP 8.5 only, and DokuWiki supports up to 8.4. `v-add-web-php 8.4` was run on the test server. The BATS suite skips when DokuWiki cannot be installed.
10. **Web terminal (M6).** xterm.js and its WebGL addon are chunks of the panel bundle. The stylesheet arrives as text (esbuild loader `.css: text`) and is added to the page once. `arcpanel-test` has no terminal service, so the e2e checks that xterm draws, and reads the legacy "Connection error." from the terminal buffer.
11. **Spec of E11.** `resellers.spec.js` expected no "Logs" link while a reseller acts as a customer (a legacy page then); Logs is a panel app page now and the link is shown.
12. **Test data.** `test/e2e/prepare.sh` gained: the firewall reset and the IP list file; `API_SYSTEM=2` with access key clean-up, restored by `cleanup`; `ssh-key`; `terminal-on` and `terminal-off`; `quickinstall`, `quickinstall-http` and `quickinstall-clean`.
13. **Tests.** On `arcpanel-test`:
    - Pest: 702 passed.
    - Vitest: 207 passed (34 files).
    - BATS: 43/43 (`arcpanel-panel-app.bats` 32, `arcpanel-stats.bats` 4, `arcpanel-quick-install.bats` 7, which installs DokuWiki and finds the wiki over HTTP).
    - `test/arcpanel-login-smoke.sh`: all checks passed.
    - Playwright: `firewall`, `ips`, `keys`, `logs`, `stats`, `quick-install`, `shell`, `web-domains`, `resellers` and `accessibility` (axe and Lighthouse ≥ 90) pass. Three tests failed in the first full run and pass after fixes to the specs themselves: the drawer close in `keys`, the tray locator in `logs`, and the expectation above in `resellers`.
