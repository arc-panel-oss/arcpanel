# E34 REST API v2, batch 6: server and account — design

**Status:** Approved 2026-10-08 · **Epic:** E34 (REST API v2) · **Previous specs:** [batch 1](./2026-10-07-002-e34-api-v2-design.md) (users), [batch 2](./2026-10-07-003-e34-api-v2-batch2-design.md) (§2, D18–D24), [batch 3](./2026-10-08-001-e34-api-v2-batch3-design.md) (tasks), [batch 4](./2026-10-08-002-e34-api-v2-batch4-design.md) (`v2-server`) · **Base specs:** [E7 batch 2](./2026-10-06-002-e7-batch2-pages-design.md), [E7 batch 3](./2026-10-06-003-e7-batch3-pages-design.md), [E5 system packages](./2026-10-06-006-e5-e6b2-system-packages-templates-design.md)

## 1. Summary

The last batch of E34 covers what remains of the panel:

- **Account** (every role, for its own account or its tree): action and login logs, monthly statistics, notifications, SSH keys, access keys, search, and the user tabs that batch 1 left out (name servers, login security, administration).
- **Server** (administrators): services, IP addresses, firewall (rules, ipsets, bans), updates, system packages, server graphs and server settings.

After this batch, everything the panel does is in API v2 except what D23 keeps out (web terminal, impersonation, config file editors, white-label logo, phpMyAdmin single sign-on, live log streams) and the dashboard, which only aggregates data the other endpoints return.

## 2. Decisions

| #   | Question          | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| --- | ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D57 | Sets              | Account endpoints join `v2-accounts` (batch 2, D18); server endpoints join `v2-server` (created by batch 4). No new set.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| D58 | Server settings   | **Read:** each tab answers only the keys of its own allow-list; `PHPMYADMIN_KEY`, passwords, private keys and repository strings with credentials are never returned (a `set` flag says whether a secret exists). **Write:** every tab except D23, and except the API switches (`API_SYSTEM`, `API`, `API_ALLOWED_IP`) and the protection group (`POLICY_SYSTEM_PROTECTED_ADMIN`, `POLICY_SYSTEM_HIDE_ADMIN`, `POLICY_SYSTEM_HIDE_SERVICES`): a key must not be able to switch the API off, open it to more addresses, or hide or unprotect the administrator. Those fields are refused with `403` and stay on the page. Secrets (SMTP relay, backup host, database host, the panel's SSL key) are accepted and never returned. PHP versions and plugins run as the system task: `202` with `sys:ID`.                 |
| D59 | Firewall rule ids | A rule's id is its position: moving a rule swaps it with its neighbour's. `moveFirewallRule` answers the rule with its new id and `Location`. Creates pass the next id (`v-add-firewall-rule … [RULE]`) and refuse an identical rule (same action, protocol, port, address) with `409 E_EXISTS` and `existing` (D21). Rules that the port registry manages (comment `arcpanel-port-…`) can be read and moved, not changed, suspended or deleted (`403`), as on the page.                                                                                                                                                                                                                                                                                                                                              |
| D60 | Log lengths       | `v-list-user-log` and `v-list-user-auth-log` get an optional `[LINES]` argument (default unchanged: 300 and 10 lines), so that the logs answer 100 entries by default and up to 1000 (D20).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| D61 | Services          | The `arcpanel` service can be restarted, never stopped (`403`). Restarting the server needs `confirm_hostname` equal to the host name (`422` otherwise). With `POLICY_SYSTEM_HIDE_SERVICES` on, only a key of the root administrator may use the services endpoints, as only that login may use the page.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| D62 | Access keys       | A key creates keys only with permission sets it holds itself (D22); an administrator's key without sets may give any set the page offers the owner (`ROLE='user'` sets, or every set for the root administrator). The secret is returned once, in the `201`, with `Cache-Control: no-store`; it never appears in lists, logs or the dry run. A key may delete itself (rotation: create the new key, then delete the old one with it). `API_SYSTEM` rules apply as on the page (`1`: only the root administrator has keys).                                                                                                                                                                                                                                                                                            |
| D63 | User tabs         | `PATCH /users/{user}` gains fields, which is additive: `nameservers` (2–8; administrators and resellers for their customers, an administrator for itself; a user cannot change its own, as on the page), `allowed_ips` (list or `null` to drop the restriction), `login_disabled` (administrators, not for themselves), and the administration fields `role`, `owner`, `shell`, `php_cli` (administrators, never on the root administrator, with the page's rules: a reseller with customers cannot be demoted, a user becomes a reseller only when its package allows customers, the owner is the root administrator or a reseller, shells and PHP versions must be installed). `DELETE /users/{user}/two-factor` turns 2FA off; turning it on stays with the user's login, because the panel has no command for it. |
| D64 | Notifications     | Acknowledging and deleting are writes: a read-only key gets `403`. The page lets read-only sessions acknowledge or delete a single notification; this spec records that difference and fixes the page in M1 (both routes move inside `not-readonly`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| D65 | Graphs            | Server graphs answer the series of `v-export-rrd` as JSON (no images): `GET /system/graphs` lists them, `GET /system/graphs/{graph}?period=` returns the data points with the unit. Periods: `daily`, `weekly`, `monthly`, `yearly`, `biennially`, `triennially`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| D66 | Code shape        | API controllers of their own. The checks that live in web controllers move into action classes the pages then call: the restart-server confirmation and protected services (`ServiceActions`), the existence checks and managed-rule filter of the firewall (`FirewallActions`), the scope resolution of statistics (`StatsActions`), the field checks of the settings tabs (`ServerSettingsActions`), and the administration and security tab rules of `UserEditController` (into `UserActions`, batch 1).                                                                                                                                                                                                                                                                                                           |

## 3. Milestones

| #   | Milestone                 | Content    | Done when                                                                                                                                                            |
| --- | ------------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | Account                   | §4.1–§4.4  | Logs with `[LINES]` (BATS), statistics, notifications (with the page fix of D64), SSH keys, search; Pest matrix                                                      |
| M2  | User tabs and access keys | §4.5, §4.6 | D63 fields and 2FA off; access key endpoints with D62; Pest matrix including a key that tries to give a set it does not hold                                         |
| M3  | Services and IP addresses | §5.1, §5.2 | Service and IP endpoints with D61; Pest matrix                                                                                                                       |
| M4  | Firewall                  | §5.3       | Rules (D59), ipsets, bans; Pest matrix                                                                                                                               |
| M5  | Updates, packages, graphs | §5.4–§5.6  | Update and system package endpoints answering `202` for system tasks; graphs (D65); Pest matrix                                                                      |
| M6  | Server settings           | §5.7       | Every tab with D58 allow-lists and refused fields; Pest cases proving that no secret and no `PHPMYADMIN_KEY` is returned and that the API switches are refused       |
| M7  | Docs and smoke            | §7, §8     | Smoke green on `arcpanel-test`; `docs/api/v2/openapi.json` current; documents of §8 updated; implementation notes appended; the E34 status line says E34 is complete |

## 4. Account endpoints (`v2-accounts`)

Writes need a key that is not read-only and accept `?dry_run=1`.

### 4.1 Logs

| Method and path                  | `operationId`       | Notes                                                                                                                                     |
| -------------------------------- | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /users/{user}/logs`         | `listUserLogs`      | Newest first; `limit` 1–1000, default 100 (D20, D60). Viewing others needs `POLICY_USER_VIEW_LOGS` for non-administrators, as on the page |
| `DELETE /users/{user}/logs`      | `clearUserLogs`     | Administrators for anyone; others for themselves when `POLICY_USER_DELETE_LOGS` allows; resellers cannot clear their customers' logs      |
| `GET /users/{user}/auth-logs`    | `listUserAuthLogs`  | As above; hidden in demo mode; the root administrator's only for itself under `POLICY_SYSTEM_PROTECTED_ADMIN`                             |
| `DELETE /users/{user}/auth-logs` | `clearUserAuthLogs` | As `clearUserLogs`                                                                                                                        |

**Schemas:** `LogEntry` (`time`, `level`, `category`, `message`), `AuthLogEntry` (`time`, `ip`, `success`, `user_agent`).

### 4.2 Statistics

| Method and path           | `operationId`   | Notes                                                                                                    |
| ------------------------- | --------------- | -------------------------------------------------------------------------------------------------------- |
| `GET /users/{user}/stats` | `listUserStats` | Monthly rows of the user, newest first                                                                   |
| `GET /stats`              | `listStats`     | Administrators: the server's totals; resellers: their tree (`v-list-reseller-users-stats`); users: `403` |

**Schema `StatsMonth`:** `month` (`YYYY-MM`), `disk_mb` and `bandwidth_mb` (usage and limit), and the ten counters of the Statistics page (`StatsMonth::COUNTERS`) as `web_domains`, `web_ssl`, `web_aliases`, `dns_domains`, `dns_records`, `mail_domains`, `mail_accounts`, `databases`, `cron_jobs`, `backups`, each with its usage and limit (`null` for unlimited).

### 4.3 Notifications and search

| Method and path                        | `operationId`                 | Notes                                                                                                                     |
| -------------------------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `GET /notifications`                   | `listNotifications`           | The key owner's; plain text bodies                                                                                        |
| `POST /notifications/{id}/acknowledge` | `acknowledgeNotification`     | D64                                                                                                                       |
| `POST /notifications/acknowledge`      | `acknowledgeAllNotifications` |                                                                                                                           |
| `DELETE /notifications/{id}`           | `deleteNotification`          |                                                                                                                           |
| `DELETE /notifications`                | `deleteAllNotifications`      |                                                                                                                           |
| `GET /search`                          | `search`                      | `q` (2 characters or more); the objects the principal may see, grouped by type, with their API paths; at most 50 per type |

Search answers objects only (users, web, DNS and mail domains, databases, cron jobs), not the page's navigation entries.

### 4.4 SSH keys

| Method and path                      | `operationId`  | Notes                                                                                        |
| ------------------------------------ | -------------- | -------------------------------------------------------------------------------------------- |
| `GET /users/{user}/ssh-keys`         | `listSshKeys`  | The file manager's own key is hidden                                                         |
| `POST /users/{user}/ssh-keys`        | `addSshKey`    | `key` (one line, at most 16384 characters); a key without comment gets one; duplicates `409` |
| `DELETE /users/{user}/ssh-keys/{id}` | `deleteSshKey` | `id` is the key's comment, URL-encoded                                                       |

Administrators manage any account's keys; resellers and users only their own, as on the page.

### 4.5 User tabs (extends batch 1)

| Method and path                   | `operationId`          | Notes             |
| --------------------------------- | ---------------------- | ----------------- |
| `PATCH /users/{user}`             | `updateUser`           | New fields of D63 |
| `DELETE /users/{user}/two-factor` | `disableUserTwoFactor` | `204`; idempotent |

The `User` schema gains `nameservers`, `allowed_ips`, `login_disabled`, `two_factor` (boolean), `shell` and `php_cli` where batch 1 did not return them yet.

### 4.6 Access keys

| Method and path                          | `operationId`       | Notes                                                                                   |
| ---------------------------------------- | ------------------- | --------------------------------------------------------------------------------------- |
| `GET /users/{user}/access-keys`          | `listAccessKeys`    | Id, permission sets, comment, created; never the secret                                 |
| `POST /users/{user}/access-keys`         | `createAccessKey`   | `permissions` (at least one set, except for an administrator's own key), `comment`; D62 |
| `GET /users/{user}/access-keys/{key}`    | `getAccessKey`      |                                                                                         |
| `DELETE /users/{user}/access-keys/{key}` | `deleteAccessKey`   | A key may delete itself                                                                 |
| `GET /access-key-sets`                   | `listAccessKeySets` | The sets the principal may give, with their API (`1` or `2`) and a one-line description |

The dry run of `createAccessKey` shows the command with the sets and comment; no key is made and no secret is shown.

## 5. Server endpoints (`v2-server`, administrators)

All paths start with `/api/v2/system`. Writes need a key that is not read-only and accept `?dry_run=1`.

### 5.1 Services

| Method and path                    | `operationId`      | Notes                                                                                                                 |
| ---------------------------------- | ------------------ | --------------------------------------------------------------------------------------------------------------------- |
| `GET /services`                    | `listServices`     | Name, system, running, CPU, memory, uptime; `meta`: host name, OS, version, load                                      |
| `GET /services/status/{kind}`      | `getServiceStatus` | `kind` in `cpu`, `mem`, `disk`, `net`, `web`, `dns`, `mail`, `db`; plain text in `data.text`                          |
| `POST /services/{service}/start`   | `startService`     | D61                                                                                                                   |
| `POST /services/{service}/stop`    | `stopService`      | D61                                                                                                                   |
| `POST /services/{service}/restart` | `restartService`   |                                                                                                                       |
| `POST /restart`                    | `restartServer`    | `confirm_hostname`; `204` once the command accepted the restart (the server goes down, so there is no task to follow) |

### 5.2 IP addresses

| Method and path     | `operationId`    | Notes                                                                                |
| ------------------- | ---------------- | ------------------------------------------------------------------------------------ |
| `GET /ips`          | `listIps`        |                                                                                      |
| `POST /ips`         | `createIp`       | `ip`, `netmask`, `interface` (one of the server's), `owner`, `shared`, `name`, `nat` |
| `GET /ips/{ip}`     | `getIp`          |                                                                                      |
| `PATCH /ips/{ip}`   | `updateIp`       | `owner`, `shared`, `name`, `nat`; one command per changed field                      |
| `DELETE /ips/{ip}`  | `deleteIp`       |                                                                                      |
| `POST /ips/refresh` | `refreshIps`     | `v-update-sys-ip`                                                                    |
| `GET /interfaces`   | `listInterfaces` | For the create form's choice                                                         |

### 5.3 Firewall

`FIREWALL_SYSTEM` off answers `404` for every firewall path; bans also need `FIREWALL_EXTENSION`.

| Method and path                                   | `operationId`                                                 | Notes                                                                                                                               |
| ------------------------------------------------- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `GET /firewall/rules`                             | `listFirewallRules`                                           | In order; `managed` marks the port registry's rules                                                                                 |
| `POST /firewall/rules`                            | `createFirewallRule`                                          | `action` (`ACCEPT`, `DROP`), `protocol` (`TCP`, `UDP`, `ICMP`), `port`, `source` (address, network or `ipset:NAME`), `comment`; D59 |
| `GET` / `PATCH` / `DELETE /firewall/rules/{rule}` | `getFirewallRule`, `updateFirewallRule`, `deleteFirewallRule` | D59                                                                                                                                 |
| `POST /firewall/rules/{rule}/move`                | `moveFirewallRule`                                            | `{"direction": "up" \| "down"}`; D59                                                                                                |
| `POST /firewall/rules/{rule}/suspend` `unsuspend` | `suspendFirewallRule`, `unsuspendFirewallRule`                |                                                                                                                                     |
| `GET` / `POST /firewall/ipsets`                   | `listFirewallIpsets`, `createFirewallIpset`                   | `name`, `source`, `ip_version` (`v4`, `v6`), `auto_update`                                                                          |
| `DELETE /firewall/ipsets/{name}`                  | `deleteFirewallIpset`                                         |                                                                                                                                     |
| `GET` / `POST /firewall/bans`                     | `listFirewallBans`, `createFirewallBan`                       | `ip`, `chain` (`SSH`, `WEB`, `FTP`, `DNS`, `MAIL`, `DB`, `ARCPANEL`)                                                                |
| `DELETE /firewall/bans/{chain}/{ip}`              | `deleteFirewallBan`                                           | IPv6 addresses URL-encoded                                                                                                          |

### 5.4 Updates

| Method and path         | `operationId`       | Notes                                                                                  |
| ----------------------- | ------------------- | -------------------------------------------------------------------------------------- |
| `GET /updates`          | `listUpdates`       | Packages with version and state; `meta`: auto-update, e-mail flags                     |
| `POST /updates`         | `runUpdates`        | Optional `package` (`arcpanel`, `arcpanel-nginx`, `arcpanel-php`); `202` with `sys:ID` |
| `PUT /updates/settings` | `setUpdateSettings` | `auto_update`, `send_email`, `send_log`                                                |

### 5.5 System packages

| Method and path           | `operationId`          | Notes                                  |
| ------------------------- | ---------------------- | -------------------------------------- |
| `GET /packages`           | `listSystemPackages`   | The catalog with state, version, users |
| `POST /packages/{slug}`   | `installSystemPackage` | `202` with `sys:ID`                    |
| `DELETE /packages/{slug}` | `removeSystemPackage`  | `202` with `sys:ID`                    |

A busy system task answers `409 E_INUSE` with `existing`, as in batch 4.

### 5.6 Graphs

| Method and path       | `operationId` | Notes |
| --------------------- | ------------- | ----- |
| `GET /graphs`         | `listGraphs`  | D65   |
| `GET /graphs/{graph}` | `getGraph`    | D65   |

### 5.7 Settings

| Method and path                                        | `operationId`             | Notes                                                                                                                                    |
| ------------------------------------------------------ | ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /settings`                                        | `getServerSettings`       | Every tab's allow-listed keys (D58), grouped by tab                                                                                      |
| `PATCH /settings/{tab}`                                | `updateServerSettings`    | `tab` in `general`, `web`, `mail`, `databases`, `backups`, `ssl`, `security`, `plugins`, `white-label`; only changed values run commands |
| `PUT /settings/web/php`                                | `setPhpVersions`          | `versions` (the full list); `202` with `sys:ID`; the default and versions in use are never removed                                       |
| `PUT /settings/plugins/{plugin}`                       | `setPlugin`               | `{"enabled": bool}` for `filemanager`, `web-terminal`, `cgroups`, `quota`, `firewall`; `202` with `sys:ID`                               |
| `PUT /settings/databases/hosts/{type}/{host}/password` | `setDatabaseHostPassword` | `user`, `password`; MySQL only, as on the page                                                                                           |

- The `dns` tab is read-only on the page and here; tabs whose system is not installed (`DNS_SYSTEM`, `MAIL_SYSTEM`, `DB_SYSTEM`) answer `404`.
- `security` accepts the login and policy keys of the page except the fields of D58; sending one answers `403` naming it.
- `white-label` accepts the texts; the logo upload stays on the page (D23). phpMyAdmin single sign-on keys stay on the page (D23).
- A change that fails half-way names the steps that were applied, as in batch 1.

## 6. Errors

No new codes. `403` names a refused field (D58) or a protected object (D59, D61); `409 E_INUSE` names a running system task.

## 7. Testing

- **BATS:** `[LINES]` of the two log commands (default unchanged, the limit honoured).
- **Pest feature**, one file per area (`ApiV2AccountTest`, `ApiV2AccessKeysTest`, `ApiV2ServicesTest`, `ApiV2IpsTest`, `ApiV2FirewallTest`, `ApiV2UpdatesTest`, `ApiV2ServerSettingsTest`): the matrix of admin, reseller A, reseller B and user (server endpoints: `403` for non-administrators); cross-tenant `404` for account endpoints; read-only `403`; dry run; a `v2-read` key refused before any command; the main path with a key holding only `v2-accounts` or `v2-server`; the policy switches of the logs; D59 ids after a move and the managed rules; D61; D62 (a set not held, the secret once, self-delete, no secret in a dry run); D63 rules; D64 on the page and the API; D58 (each refused field, no `PHPMYADMIN_KEY`, no password, `set` flags).
- **Pest unit:** the allow-lists of D58 against `ServerSettings::TABS`; the moved action classes; the Resources.
- The page tests of these modules pass unchanged, except the D64 fix, whose test changes with it.
- **Smoke:** logs and statistics read; a notification acknowledged; an SSH key added and deleted; an access key created with `v2-read` by a `v2-read`+`v2-accounts` key, used once, then deleted by itself; a firewall rule created, moved, deleted; a service restarted (`cron`); settings read with no secret in the answer.

## 8. Documentation changes

- `docs/roadmap.md`: the E34 status line marks E34 complete, with the list of D23 exclusions.
- `docs/api/README.md`: account and server endpoints, D58, D62.
- `docs/design/12-security-model.md`: what a key cannot change (D58) and the key rules (D62).
- `docs/design/13-data-model-reference.md`: the `[LINES]` arguments.
- `AGENTS.md` §2: E34 complete.

## 9. Out of scope

- D23 (web terminal, impersonation, config file editors, white-label logo, phpMyAdmin single sign-on, live log streams), the dashboard, turning 2FA on, the CSR generator (it returns a private key; it stays on the page).
- Backup hosts' own management beyond the backups tab of the settings.

## 10. Risks

| Risk                                                      | Mitigation                                                                                                  |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| A settings answer leaks a secret                          | Allow-lists per tab (D58), never the raw config; a Pest case per tab checks the absence of every secret key |
| A key locks the administrator out or widens its own reach | D58 refuses the API switches and the protection group; D62 keeps keys within their own sets                 |
| Firewall ids change under a client                        | D59 answers the new id on a move; the description of every rule operation says ids are positions            |
| A server restart through the API by mistake               | `confirm_hostname` (D61), dry run, and the operation's description                                          |

## 11. Implementation notes

The work log of each milestone is appended here.

### M1 — Account (2026-10-08)

1. **Endpoints.** `listUserLogs`, `clearUserLogs`, `listUserAuthLogs` and `clearUserAuthLogs` (`Api\V2\LogController`), `listUserStats` and `listStats` (`Api\V2\StatsController`), the five notification operations (`Api\V2\NotificationController`), `search` (`Api\V2\SearchController`) and `listSshKeys`, `addSshKey`, `deleteSshKey` (`Api\V2\SshKeyController`), area `accounts`. Writes are inside `not-readonly`, take `?dry_run=1` and run through `PlanGate` and `PlanRunner`; a user that does not exist answers `404` for administrators too (`TenancyDirectory`), as in the other areas.
2. **Logs (D60).** `bin/v-list-user-log` and `bin/v-list-user-auth-log` take `[LINES]` after `[FORMAT]` (1 to 10000; defaults unchanged: 300 and 10); `test/arcpanel-user-logs.bats` covers the defaults, the limit and the refusals. The API reads `offset + limit + 1` lines, so a page is `limit` entries (1–1000, default 100) after `offset` newer ones (0–8999), newest first. A log's length is not known without reading all of it, so `meta` is `LogListMeta` (`limit`, `offset`, `more`), not `ListMeta`; both operations are documented outside the general pagination rule. `LogPolicy` decides as on the page (`POLICY_USER_VIEW_LOGS`, `POLICY_USER_DELETE_LOGS`, demo mode, `POLICY_SYSTEM_PROTECTED_ADMIN`; resellers read but do not clear their customers' logs). `LogService` gains `latestActions()` and `latestLogins()`; the page keeps its calls and arguments.
3. **Statistics (D66).** The scope resolution of the Statistics page moved into `App\Services\Stats\StatsActions` (`resolve()` and `owners()`); the page calls it, and `StatsTest` passes unchanged. `listStats` answers `403` to users. **Schema `StatsMonth`:** `month`, `package`, `disk_mb` (`used`, `limit`, and the `web`, `mail`, `databases`, `directories` parts), `bandwidth_mb` (`used`, `limit`), `ip_addresses`, `users` (totals only, else null) and the ten counters of `StatsMonth::COUNTERS` as integers. **Differs from §4.2:** the counters have no limit, because the monthly rows of `stats.log` keep a limit for disk and bandwidth only; a `limit` of null would claim "unlimited".
4. **Notifications (D64).** The tray's reading and plain-text conversion moved into `App\Services\Notifications\NotificationService`, which the page and the API use. The acknowledge and delete operations answer `204`; a single notification that does not exist answers `404`; a read-only key gets `403` naming the command. The page's single acknowledge and delete routes moved inside `not-readonly`; `LogsTest` ("refuses tray changes in read-only mode") checks them now.
5. **Search.** `PaletteSearch` exposes `objectRows()` and `userRows()`, which the search page and the palette keep using through `search()`. `GET /search?q=` (2 to 100 characters) answers a list of `SearchGroup` (`type`, `items` of `SearchResult`: `name`, `owner`, `parent`, `path`) in the order users, web domains, DNS zones, DNS records, mail domains, mail accounts, databases, cron jobs; empty types are left out, at most 50 items per type, no pages or actions.
6. **SSH keys.** `SshKeyPolicy` as on the page: administrators any account, resellers and users only their own (a reseller on its customer gets `403`, outside the tree `404`). `addSshKey` takes one public key line (type, base64 key, optional comment without quotes, at most 16384 characters); a missing comment is generated as on the page; a key whose comment or SHA256 fingerprint (`SshKeyService::fingerprint()`, checked against `ssh-keygen -l`) the user has answers `409 E_EXISTS`; `E_PARSING` of the command answers `422 E_VALIDATION`. The `201` carries `Location` of the list, because a single key has no read endpoint. The id of `deleteSshKey` is the comment, URL-encoded (`%20`, `%40`).
7. **Sets (D57).** `v2-accounts` and `v2-write` gain the fifteen commands; the reads (`v-list-user-log`, `v-list-user-auth-log`, the three stats commands, `v-list-user-notifications`, `v-search-object`, `v-search-user-object`, `v-list-user-ssh-key`) join `v2-read`. The routes of `/stats`, `/search` and `/notifications` declare `accounts` (the area loop of `routes/api.php` and `ApiV2Test`).
8. **Docs.** `docs/api/v2/openapi.json` regenerated (`LogListMeta` and the log page parameters in `ApiDocs`); `docs/api/openapi.yaml` (REST API v1) regenerated for the new command headers; `docs/design/13-data-model-reference.md` names the `[LINES]` arguments.
9. **Tests.** `ApiV2AccountTest` (45 cases: the role matrix with cross-tenant `404` for logs, statistics and SSH keys, paging of the logs, the four log policies, clearing logs with the page's rules, `v2-read` refused before any change command, dry runs, keys holding only `v2-accounts`, the notification changes and D64, the search groups, paths and limit, the SSH key duplicates and refusals), `AccountActionsTest` (unit: `StatsActions`, the Resources, `NotificationService`, the fingerprint). `LogsTest`, `StatsTest`, `ShellTest`, `KeysTest` and `SearchCsrTest` pass, `LogsTest` with the D64 change only. On `arcpanel-test`: `arcpanel-user-logs.bats` 6/6, `arcpanel-api-v2.bats` 15/15, and every endpoint called through nginx with a throw-away user and a `v2-accounts` key.
