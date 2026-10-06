# E7 batch 3 — Server settings, services, config editors, updates, guest flows and search — design

**Status:** Approved (2026-10-06) · **Epic:** E7 · **Base design:** [10 Page migration](../../design/10-page-migration-and-retirement.md) §3 (modules 16, 22, 23), §5.1, §5.7 · **Previous specs:** [E7 batch 1](./2026-10-06-001-e7-batch1-pages-design.md) (runners, hand-off), [E7 batch 2](./2026-10-06-002-e7-batch2-pages-design.md) (task pattern, `sys-config` middleware, `apTask`), [E3](./2026-10-05-008-e3-file-manager-design.md) (CodeMirror 6 editor) · **Roadmap:** [§3 E7](../../roadmap.md)

## 1. Summary

The third and last E7 batch moves the remaining legacy pages to the Laravel panel app:

- **Services** (`web/list/server`): the service table with start, stop and restart, the system status reports and the server restart.
- **Server settings** (`web/edit/server`, about 2,000 lines): one page per section, including White Label.
- **Config editors** (`web/edit/server/<service>`): one shared editor island for about 25 services, with the "basic options" helpers of nginx, PHP and MySQL.
- **Updates** (`web/list/updates`, `web/update/hestia`): package updates run in the background.
- **Guest flows** (`web/reset`, `web/reset2fa`): password reset by e-mail and 2FA reset by recovery code.
- **Search** (`web/search`) and the **CSR generator** (`web/generate/ssl`).

Long operations (PHP versions, plugin installs, package updates) run as one **system task** at a time through three new commands. The runner lives in its own transient systemd unit, so it survives the restart of the panel that a `hestia` package update causes. With this batch every legacy page of [10](../../design/10-page-migration-and-retirement.md) §3 has a panel app page; E8 can start.

## 2. Decisions taken in the brainstorm

| #   | Question                   | Decision                                                                                                                                                                                                                                                                                                      |
| --- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Scope and milestones       | **One spec, seven milestones:** M0 foundation, M1 services, M2 server settings and the system task, M3 config editors, M4 updates, M5 guest flows, M6 search, CSR and the final hand-off.                                                                                                                     |
| D2  | Server settings layout     | **One URL per tab** (`/app/server/settings/{tab}`), one FormRequest and one `Step` list per tab, only changed keys are sent. Values are compared with a fresh `v-list-sys-config`, not with session copies as legacy does. White Label is a tab (`white-label`).                                              |
| D3  | Config editors             | **One `service-editor` Vue island** for every whitelisted service, built on the E3 CodeMirror 6 editor. The "basic options" of nginx, PHP and MySQL stay as client-side helpers that rewrite the text, as legacy does; multi-file services (PostgreSQL, BIND, Dovecot) get one tab per file.                  |
| D4  | Long operations            | **System task in the background** (§6): PHP version install and removal, the plugin installs and package updates. One task server-wide; the runner is a transient systemd unit (`systemd-run`), so a restart of `hestia.service` does not kill it. The page polls and survives 502s while the panel restarts. |
| D5  | Protected services         | `hestia` can be restarted but not stopped (single and bulk). Restarting the server needs the hostname typed into the confirmation.                                                                                                                                                                            |
| D6  | White Label logo           | The legacy fields plus "Apply custom logo" and "Restore default logo" (`v-update-white-label-logo`). No upload. The panel shell and the guest pages show `/images/custom/logo-header.svg` when it exists, otherwise the built-in ArcPanel logo.                                                               |
| D7  | Guest flows                | Laravel guest routes with the existing commands (`RKEY`, `RKEYEXP`, `v-change-user-rkey`, `v-change-user-password`, `v-delete-user-2fa`); the mail goes through `PanelCredentialsMailer`. Rate limits per IP and per user, the same answer for every user name, single-use reset codes.                       |
| D8  | Search                     | A results page `/app/search?q=` that reuses `PaletteSearch` with a higher limit per group; the palette gets "View all results".                                                                                                                                                                               |
| D9  | CSR generator              | A "Generate CSR" modal on the SSL tab of a web domain and on the SSL tab of the server settings; the result fills the certificate and key fields and is never logged.                                                                                                                                         |
| D10 | File manager plugin toggle | Kept as legacy until E8: `v-add-sys-filemanager` / `v-delete-sys-filemanager`, which also set `FILE_MANAGER`.                                                                                                                                                                                                 |
| D11 | Updates settings           | The upgrade notification settings (`UPGRADE_SEND_EMAIL`, `UPGRADE_SEND_EMAIL_LOG`) move from the server settings to the Updates page, next to the automatic update switch.                                                                                                                                    |
| D12 | Legacy defects             | Fixed and listed in §13 (SMTP relay and pma alias wiped when their section is hidden, database host password always applied to `localhost root`, ftp/sftp password passed in `argv`, restic re-created on every save, `API_SYSTEM=0` never disabling the API, reusable reset codes).                          |

## 3. Milestones

| #   | Milestone                       | Content                                | Done when                                                                                                                                       |
| --- | ------------------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| M0  | Foundation                      | §5: capabilities, policies, navigation | Authorization unit tests for the four principals; navigation tests per role                                                                     |
| M1  | Services                        | §7                                     | Pest; Playwright restarts `cron`, opens the CPU and disk reports, sees Stop disabled for `hestia`                                               |
| M2  | Server settings and system task | §6, §8                                 | BATS for the task commands; Pest per tab; Playwright changes the time zone and back, toggles a user policy and back, opens every tab            |
| M3  | Config editors                  | §9                                     | Vitest for the basic options helpers; Pest; Playwright changes PHP `memory_limit` through the basic options, saves without restart, restores it |
| M4  | Updates                         | §10                                    | Pest; Playwright lists the packages and toggles automatic updates and back                                                                      |
| M5  | Guest flows                     | §11                                    | Pest (rate limits, same answer, expiry, single use); Playwright requests a code, reads it from the test mailbox, resets the password; 2FA reset |
| M6  | Search, CSR and final hand-off  | §12, §14                               | Pest; Playwright search page and CSR modal; every legacy page of §4 "Done when" 1 answers 302                                                   |

Each milestone ends with a green run and a `feat(e7): …` commit.

## 4. Scope

### In scope

1. Laravel pages for Services (with the status reports), Server settings (ten tabs), Config editors, Updates, password reset, 2FA reset, Search, and the CSR modal.
2. Commands `v-start-sys-task`, `v-run-sys-task`, `v-list-sys-task` and the library `func/systask.sh`.
3. `x-logo` honours a custom White Label logo.
4. Hand-off: `arc_route`, `arc_redirect_if_migrated` (guest pages included), `LegacyRoutes` emptied of the migrated entries, `Navigation`, palette.
5. Tests (Pest, Vitest, BATS, Playwright) and the documentation changes of §16.

### Out of scope

- Moving the panel to the root URL, deleting `web/`, the 2.0 upgrade script (E8).
- Logo upload (D6); new White Label keys.
- The update center redesign: maintenance windows, health checks, rollback (E33).
- Changes to existing upstream commands.
- PostgreSQL host passwords (the legacy page shows them read-only; `v-change-database-host-password` is called for MySQL hosts only, as legacy).

### Done when

1. With `ARCPANEL_APP_PAGES=yes` the legacy pages `/list/server/[?cpu|mem|disk|net|web|dns|mail|db]`, `/edit/server/`, `/edit/server/<service>/`, `/edit/server/whitelabel/`, `/list/updates/`, `/search/?q=`, `/generate/ssl/?domain=`, `/reset/[?action=code|confirm&user=&code=]` and `/reset2fa/` answer 302 to the new pages; with `no` they answer 200.
2. Only administrators reach the server pages; resellers and users get 403, as for the batch 2 server pages.
3. Every legacy action of the six modules is available with the same commands, apart from the deviations of §13.
4. An administrator installs or removes a PHP version and updates a package from the panel, and watches the task through a panel restart.
5. A user who forgot the password resets it from the login page with the code from the e-mail; a user with lost 2FA unlocks the account with the recovery code.
6. The sidebar, the command palette and the legacy menu link to the new pages; `LegacyRoutes` keeps only `web.logs.download`.

## 5. Foundation (M0)

### 5.1 Capabilities

| Capability         | Scope       | Used by                                                       |
| ------------------ | ----------- | ------------------------------------------------------------- |
| `server.services`  | `ADMIN`     | Services page, status reports, start, stop, restart           |
| `server.settings`  | `ADMIN`     | Server settings tabs, the system task of the settings         |
| `server.config`    | `ADMIN`     | Config editors                                                |
| `server.updates`   | `ADMIN`     | Updates page and its task                                     |
| `search.use`       | `ALL_ROLES` | Search page                                                   |
| `web.ssl.generate` | `ALL_ROLES` | CSR modal of a web domain (with `WebDomainPolicy::canManage`) |

`server.view` keeps serving the dashboard widgets.

### 5.2 Policies

| Policy                            | Rules                                                                                                                                                       |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ServerPolicy::viewServices`      | `server.services`; hidden from everyone but ROOT_USER when `POLICY_SYSTEM_HIDE_SERVICES='yes'` (as the navigation does today)                               |
| `ServerPolicy::manageServices`    | `viewServices`, not read-only                                                                                                                               |
| `ServerPolicy::stopService(name)` | `manageServices` and the service is not protected (`hestia`)                                                                                                |
| `ServerPolicy::editSettings`      | `server.settings`, not read-only; `viewSettings` without the read-only check                                                                                |
| `ServerPolicy::protectAdmin`      | `editSettings` and the principal is ROOT_USER (System Protection group of the Security tab)                                                                 |
| `ServerPolicy::editConfig`        | `server.config`, not read-only; `viewConfig` without the read-only check                                                                                    |
| `ServerPolicy::manageUpdates`     | `server.updates`, not read-only; `viewUpdates` without the read-only check                                                                                  |
| `SearchPolicy::use`               | `search.use`                                                                                                                                                |
| `WebDomainPolicy::generateCsr`    | `web.ssl.generate` and `canManage(owner)`, not read-only. Administrators also generate a CSR for the server host name through `ServerPolicy::editSettings`. |

### 5.3 Navigation

- Server group: `server.settings` (`server.settings`, section `server.settings`), `server.services` (`server.services`, section `server.services`; the config editors are inside this section), `server.updates` (`server.updates`, section `server.updates`). The `services` flag (`POLICY_SYSTEM_HIDE_SERVICES`) moves into `ServerPolicy::viewServices`.
- The palette lists the three pages and "Search" for the principals that may open them.
- `LegacyRoutes` loses `server.services`, `server.settings` and `server.updates` when the pages land.

## 6. System task (M2)

### 6.1 Commands

| Command                            | Behaviour                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v-start-sys-task KIND [ARG…]`     | Bash. `KIND` and its arguments are whitelisted: `php ADD DELETE` (comma lists of PHP versions `\d\.\d`, `-` for none, at least one version in total; a version to add must not be installed and one to delete must be installed); `plugin NAME on\|off` (`filemanager`, `web-terminal`, `quota`, `cgroups`, `firewall`); `hestia-update [PACKAGE]` (`hestia`, `hestia-nginx`, `hestia-php`, `hestia-web-terminal`; none means every package). Honours demo mode. Refuses with `E_INUSE` while a task runs. Writes the record and starts `v-run-sys-task ID` in a transient unit `arcpanel-sys-task-<ID>` (`systemd-run --unit … --collect --quiet`, with `setsid` as the fallback where systemd is not PID 1). Prints the task ID. |
| `v-run-sys-task ID`                | Bash, background part. Refuses a task it did not start (`E_FORBIDEN`). Runs the steps of the kind and prints `$ <command>` before each: `php` runs `v-add-web-php V` per version to add, then `v-delete-web-php V` per version to delete; `plugin` runs `v-add-sys-<name>` or `v-delete-sys-<name>`; `hestia-update` runs `v-update-sys-hestia PACKAGE` or `v-update-sys-hestia-all`. Stops at the first failure. Records `STATUS`, `EXIT`, `END`.                                                                                                                                                                                                                                                                                 |
| `v-list-sys-task [LINES] [FORMAT]` | Bash. The record (`ID`, `KIND`, `ARGS`, `STATUS`, `EXIT`, `START`, `END`) and the last `LINES` lines of the log (default 100, at most 1000). A running task whose unit or process is gone is reported, and stored, as `failed`. Without a task, `STATUS` is empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |

`func/systask.sh` holds the helpers (`st_task_conf`, `st_task_get`, `st_task_set`, `st_task_alive`, `st_task_reap`, `st_task_running`) with the semantics of `func/quickinstall.sh`. Files: `$HESTIA/data/sys-task/task.conf` (one line `ID KIND ARGS STATUS PID UNIT EXIT START END`, root `0640`), `task.log` (root `0640`, replaced by each task), `task.lock`.

### 6.2 Panel side

`SystemTaskService` wraps the three commands (`start(kind, args)`, `task()`); `SystemTaskController` answers `GET /app/server/task` (`server.task`, JSON `{ task }`) for principals with `server.settings` or `server.updates`. The `x-ap-task-panel` Blade component shows status, log and the end message and uses `apTask`; `apTask` gains `tolerant: true`, which keeps polling through failed requests and a non-JSON answer (the panel restarting) and reloads the page once when the task ends with `reloadOnEnd: true`. An `E_INUSE` refusal flashes "Another task is running. Wait until it has finished."

## 7. Services (M1)

### 7.1 Routes (`/app/server/services`, names `server.services*`)

| Method and path            | Name                             | Commands                                                                                                   |
| -------------------------- | -------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `GET /`                    | `server.services`                | `v-list-sys-info json`, `v-list-sys-php json`, `v-list-sys-services json`                                  |
| `GET /status/{kind}`       | `server.services.status`         | `v-list-sys-<kind>-status` (`kind` ∈ cpu, mem, disk, net, web, dns, mail, db); plain text in a JSON answer |
| `POST /{service}/{action}` | `server.services.action`         | `v-start-service`, `v-stop-service`, `v-restart-service` (`action` ∈ start, stop, restart)                 |
| `POST /bulk`               | `server.services.bulk`           | the same per service; a protected service in a Stop is a failed item                                       |
| `POST /restart-server`     | `server.services.restart-server` | `v-restart-system yes`; the `hostname` field must equal the host name                                      |

`{service}` must be a name `v-list-sys-services` printed.

### 7.2 Page

Header card: host name, operating system, uptime, load averages, installed PHP versions. Links to the eight status reports open a drawer with the report in a `<pre>`. The table lists Service, Description (`SYSTEM`), State (running/stopped badge), CPU, Memory, Uptime, with row actions Edit config (when §9 knows the service), Start or Stop, Restart; Stop is disabled with the tooltip "This service is required by the panel." for protected services. Bulk Start, Stop, Restart. Toolbar "Restart server" opens a confirmation that asks for the host name. The legacy messages and confirmations are kept.

## 8. Server settings (M2)

### 8.1 Routes (`/app/server/settings`, names `server.settings*`)

| Method and path          | Name                     | Behaviour                                                                 |
| ------------------------ | ------------------------ | ------------------------------------------------------------------------- |
| `GET /`                  | `server.settings`        | redirect to the first tab                                                 |
| `GET /{tab}`             | `server.settings.tab`    | the tab; `dns`, `mail`, `databases` answer 404 when their system is unset |
| `POST /{tab}`            | `server.settings.update` | the tab's FormRequest and steps                                           |
| `POST /web/php`          | `server.settings.php`    | starts the `php` system task                                              |
| `POST /plugins/{plugin}` | `server.settings.plugin` | starts the `plugin` system task (except `app-installer`, a config value)  |
| `POST /white-label/logo` | `server.settings.logo`   | `v-update-white-label-logo` or `v-update-white-label-logo yes yes`        |
| `POST /ssl/csr`          | `server.settings.csr`    | §12.2 for the host name                                                   |

`{tab}` ∈ `general`, `web`, `dns`, `mail`, `databases`, `backups`, `ssl`, `security`, `plugins`, `white-label`.

### 8.2 Tabs

Commands run only for changed values, in the order listed; the first failure stops the tab and the page lists the steps that were applied and the one that failed. Secrets travel as `Secret::file` (one file) or `Secret::directory` (SSL).

| Tab           | Fields and commands                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `general`     | Host name → `v-change-sys-hostname`; time zone (`v-get-sys-timezones json`) → `v-change-sys-timezone`; language (`v-list-sys-languages json`) with "Apply to all users" → `v-change-sys-language LANG [yes]`; theme (`v-list-sys-themes json`) → `THEME`; "Allow users to change theme" → `POLICY_USER_CHANGE_THEME`; debug mode → `DEBUG_MODE`; experimental features → `POLICY_SYSTEM_ENABLE_BACON` (turning it off also sets `POLICY_USER_VIEW_SUSPENDED='no'`). Version and release branch shown read-only.                                                                                                                                                                                                                                                                                                                                                                                              |
| `web`         | Web, proxy and backend systems and the backend pool read-only, each linking to its config editor. With `WEB_BACKEND='php-fpm'`: default PHP version → `v-change-sys-php`; PHP versions as checkboxes (supported versions from `v-list-sys-php json` and `v-list-web-templates-backend json`); a version used by a domain or the default is locked with the reason. "Apply PHP versions" starts the `php` task.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `dns`         | Remote DNS hosts (`v-list-remote-dns-hosts json`), read-only. Shown when `DNS_SYSTEM` is set.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `mail`        | Shown when `MAIL_SYSTEM` is set. Webmail alias (with `WEBMAIL_SYSTEM`) → `v-change-sys-webmail`; SMTP relay on with host, port (default 587), user, password → `v-add-sys-smtp-relay HOST USER <secret file> PORT` (the command rewrites the whole relay, password included, so the password is required whenever the relay is turned on or its host, port or user change; with no change the tab sends nothing); off → `v-delete-sys-smtp-relay`. Links to the antivirus and antispam editors.                                                                                                                                                                                                                                                                                                                                                                                                              |
| `databases`   | Shown when `DB_SYSTEM` is set. phpMyAdmin alias → `v-change-sys-db-alias pma`, phpPgAdmin alias → `v-change-sys-db-alias pga` (each only when its system is installed); phpMyAdmin SSO → `v-add-sys-pma-sso quiet` / `v-delete-sys-pma-sso quiet`; restrict access (needs SSO) → `v-add-sys-pma-restrict quiet` / `v-delete-sys-pma-restrict quiet`. Database hosts (`v-list-database-hosts json`) as a table; each MySQL host has "Change password" → `v-change-database-host-password mysql HOST USER <secret file>`.                                                                                                                                                                                                                                                                                                                                                                                      |
| `backups`     | Local backups on/off → `v-add-backup-host local` / `v-delete-backup-host local`; mode (gzip, zstd) → `BACKUP_MODE`; compression level (1–9, 1–19 with zstd; gzip forces 9 as legacy) → `BACKUP_GZIP`; backup directory read-only. Remote backup: type (ftp, sftp, b2, rclone), fields per type; on → `v-add-backup-host TYPE …` with the password or key as `Secret::file`; a type change deletes the old type first; off → `v-delete-backup-host TYPE`. Stored passwords are never sent to the page; an empty password field keeps the stored one only when nothing else changed. Incremental (restic) on with repository, snapshots, keep daily, weekly, monthly, yearly → `v-add-backup-host-restic …`; a change deletes and adds again, checking both; off → `v-delete-backup-host-restic`.                                                                                                              |
| `ssl`         | Certificate details (`v-list-sys-hestia-ssl json`); certificate and private key → `v-change-sys-hestia-ssl <secret dir with certificate.crt, certificate.key>`. "Generate CSR" (§12.2) for the host name. `hestia-nginx` reloads gracefully; the page reloads after save.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| `security`    | API: `API_SYSTEM` (0 off, 1 ROOT_USER, 2 everyone), `API` (legacy API), allowed IPs (one per line, IP or `allow-all`) → `API_ALLOWED_IP`; any change runs `v-change-sys-api enable` first, or `v-change-sys-api disable` when `API_SYSTEM` becomes 0. Login: `LOGIN_STYLE`, `POLICY_SYSTEM_PASSWORD_RESET`, `INACTIVE_SESSION_TIMEOUT` (≥ 1), `POLICY_CSRF_STRICTNESS`. System Protection (ROOT_USER only): `POLICY_SYSTEM_PROTECTED_ADMIN`, `POLICY_SYSTEM_HIDE_ADMIN`, `POLICY_SYSTEM_HIDE_SERVICES`. Users: `POLICY_USER_EDIT_DETAILS`, `POLICY_USER_EDIT_WEB_TEMPLATES`, `POLICY_USER_EDIT_DNS_TEMPLATES`, `POLICY_USER_VIEW_LOGS`, `POLICY_USER_DELETE_LOGS`, `POLICY_USER_VIEW_SUSPENDED` (with experimental features), `POLICY_BACKUP_SUSPENDED_USERS`, `POLICY_SYNC_ERROR_DOCUMENTS`, `POLICY_SYNC_SKELETON`. Domains: `ENFORCE_SUBDOMAIN_OWNERSHIP`. Every key through `v-change-sys-config-value`. |
| `plugins`     | App installer → `PLUGIN_APP_INSTALLER`; File manager, Web terminal, Resource limits (cgroups), Disk quota, Firewall, each with its state and a button "Install"/"Remove" that starts the `plugin` task.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `white-label` | `APP_NAME`, `TITLE`, `SUBJECT_EMAIL`, `HIDE_DOCS`, `FROM_NAME`, `FROM_EMAIL` → `v-change-sys-config-value`; the current logo; "Apply custom logo" and "Restore default logo" with the hint where the files go (`$HESTIA/web/images/custom/`: `logo.svg`, `logo.png`, `logo-header.svg`, `favicon.png`, `favicon.ico`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |

Every tab is admin-only and read-only in read-only mode (fields disabled, no save button). `SysConfig` is flushed after a save.

## 9. Config editors (M3)

### 9.1 Whitelist

`App\Services\Server\ServiceConfigs` maps a service to its files and the service argument of `v-change-sys-service-config`:

| Service (route)              | Files (read)                                                                      | Save argument                        | Basic options |
| ---------------------------- | --------------------------------------------------------------------------------- | ------------------------------------ | ------------- |
| `nginx`                      | `v-list-sys-nginx-config json` → `config_path`                                    | `nginx`                              | nginx         |
| `apache2`, `httpd`           | `/etc/apache2/apache2.conf`, `/etc/httpd/conf/httpd.conf`                         | `apache2`, `httpd`                   | —             |
| `php`, `php-fpm`, `php5-fpm` | `v-list-sys-php-config json` → `config_path`                                      | `php`                                | php           |
| `mysql`, `mariadb`, `mysqld` | `v-list-sys-mysql-config json` → `config_path`                                    | the route name                       | mysql         |
| `postgresql`                 | `v-list-sys-pgsql-config json` → `config_path`, `pg_hba_path`                     | `postgresql`, `postgresql-hba`       | —             |
| `bind9`                      | `/etc/bind/named.conf`, `/etc/bind/named.conf.options`                            | `bind9`, `bind9-opt`                 | —             |
| `named`                      | `/etc/named.conf`                                                                 | `named`                              | —             |
| `dovecot`                    | `v-list-sys-dovecot-config json` → `config_path`, `config_path1` … `config_path8` | `dovecot`, `dovecot-1` … `dovecot-8` | —             |
| `exim`, `exim4`              | `/etc/exim/exim.conf`, `/etc/exim4/exim4.conf.template`                           | the route name                       | —             |
| `clamav-daemon`              | `v-list-sys-clamd-config plain`                                                   | `clamd`                              | —             |
| `spamassassin`, `spamd`      | `v-list-sys-spamd-config plain`                                                   | the route name                       | —             |
| `proftpd`, `vsftpd`          | `v-list-sys-proftpd-config plain`, `v-list-sys-vsftpd-config plain`               | the route name                       | —             |
| `fail2ban`                   | `/etc/fail2ban/jail.local`                                                        | `fail2ban`                           | —             |
| `ssh`                        | `/etc/ssh/sshd_config`                                                            | `ssh`                                | —             |
| `hestiaweb`                  | `/var/spool/cron/crontabs/hestiaweb`                                              | `hestiaweb` (always restarts)        | —             |
| `cron`, `crond`              | `/etc/crontab`                                                                    | read-only                            | —             |

Content is read with `v-open-fs-config PATH`. `iptables` redirects to the Firewall page and `web-terminal` to the Services page, as legacy.

### 9.2 Routes and island

| Method and path                              | Name                          | Commands                                                                 |
| -------------------------------------------- | ----------------------------- | ------------------------------------------------------------------------ |
| `GET /app/server/services/{service}/config`  | `server.services.config`      | §9.1                                                                     |
| `POST /app/server/services/{service}/config` | `server.services.config.save` | `v-change-sys-service-config <file> ARG yes\|no` per changed file (JSON) |

The `service-editor` island receives the files (`label`, `path`, `content`, `arg`), the basic options kind and the read-only flag; it shows a tab per file, the CodeMirror editor (nginx, ini and plain modes), the basic options panel and the "Restart" checkbox. Saving posts the changed files; the server writes each content to a `Secret::file` (the command copies the file and validates it with the service's own check), and returns the command output on failure ("Error code: %s" when empty). Basic options read the values with the parser of `resources/js/islands/service-editor/options.js` and rewrite the matching lines (nginx `key value;`, ini `key = value`), adding a missing key at the end of the right section.

## 10. Updates (M4)

| Method and path           | Name                           | Commands                                                                                           |
| ------------------------- | ------------------------------ | -------------------------------------------------------------------------------------------------- |
| `GET /app/server/updates` | `server.updates`               | `v-list-sys-hestia-updates json`, `v-list-sys-hestia-autoupdate plain`, `v-list-sys-task 200 json` |
| `POST /update`            | `server.updates.update`        | `v-start-sys-task hestia-update [PACKAGE]`                                                         |
| `POST /autoupdate`        | `server.updates.autoupdate`    | `v-add-sys-hestia-autoupdate` / `v-delete-sys-hestia-autoupdate`                                   |
| `POST /notifications`     | `server.updates.notifications` | `UPGRADE_SEND_EMAIL`, `UPGRADE_SEND_EMAIL_LOG` through `v-change-sys-config-value`                 |

The table lists Package, Description, Version, Status (up to date or "Update available") and an "Update" button per outdated package; "Update all" updates every package. While the task runs, the buttons are disabled and the task panel shows the log; the page reloads when it ends.

## 11. Guest flows (M5)

### 11.1 Routes (middleware `guest.arc`, names `reset.*`)

| Method and path       | Name             | Behaviour                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| --------------------- | ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /reset`          | `reset`          | Username and Email. 404 when `POLICY_SYSTEM_PASSWORD_RESET='no'` (all `reset.*` routes).                                                                                                                                                                                                                                                                                                                                                                       |
| `POST /reset`         | `reset.request`  | When the user exists, is not suspended, `CONTACT` equals the e-mail (case-insensitive) and `RKEYEXP` is older than 15 minutes: generate a 12-character code, store its `password_hash` with `v-change-user-rkey USER <secret file>`, mail it. Always redirect to `reset.code` with "Password reset instructions have been sent to the email address associated with this account."                                                                             |
| `GET /reset/code`     | `reset.code`     | Username (from the query) and Reset Code.                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `GET /reset/confirm`  | `reset.confirm`  | New Password and Confirm Password, with user and code from the query (the link of the e-mail).                                                                                                                                                                                                                                                                                                                                                                 |
| `POST /reset/confirm` | `reset.update`   | Passwords match; `password_verify(code, RKEY)` and `RKEYEXP` within 15 minutes; `v-change-user-password USER <secret file>`; then `v-change-user-rkey USER` with a fresh random hash so the code cannot be reused. Redirect to `login` with "Your password has been changed. You can now log in." Failures: "Invalid username or code", "Code has been expired", "Passwords do not match", logged with `v-log-user-login USER IP failed FP UA yes "<reason>"`. |
| `GET /reset2fa`       | `reset2fa`       | Username and 2FA Reset Code.                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `POST /reset2fa`      | `reset2fa.store` | `hash_equals(TWOFA, code)` → `v-delete-user-2fa USER`, then the "Account Unlocked" page; otherwise "Invalid username or code" and `v-log-user-login … "Failed to enter correct 2FA reset key"`.                                                                                                                                                                                                                                                                |

Rate limits (Laravel `RateLimiter`): 5 requests per 15 minutes per IP and per user name on each POST; over the limit the page answers 429 with "Too many attempts. Try again in %d minutes." Failed attempts sleep 2 s, as the login does.

### 11.2 Mail

`PanelCredentialsMailer::sendPasswordReset(to, name, user, code, language, link)` uses the template `reset_password` (`data/templates/email/[LANG/]reset_password.html`, subject from `<subject>`) or the legacy built-in text, the placeholders `{{name}}`, `{{appname}}`, `{{hostname}}`, `{{user}}`, `{{resetcode}}`, and the sender and transport rules it already has. The link host is the request host (with port when the request came on the panel port), as legacy. The code and the link never reach a log.

### 11.3 Pages

The guest layout of the login page; the login page links "Forgot Password" to `reset` and the 2FA page "Forgot Token" to `reset2fa`.

## 12. Search and CSR (M6)

### 12.1 Search (`GET /app/search?q=`, `search`)

`PaletteSearch::search($query, $limit)` gains a limit per group (palette 8, page 50). The page shows a search field and the groups as tables (label, owner, link); fewer than 2 characters show "Type at least 2 characters". The palette footer gets "View all results" linking to the page with the query; Enter on an empty selection opens it.

### 12.2 CSR generator

The `x-ap-csr-modal` component asks for Domain (prefilled), Aliases, Email, Country (2 letters), State / Province, City / Locality, Organization and key algorithm (`ecdsa-256` default, `ecdsa-384`, `rsa`). It posts to `web.domains.ssl.generate` (`POST /app/web/domains/{user}/{domain}/ssl/generate`, `WebDomainPolicy::generateCsr`) or `server.settings.csr`, which run `v-generate-ssl-cert DOMAIN EMAIL COUNTRY STATE CITY ORG IT ALIASES json KEY_ALGO` and answer JSON `{crt, key, csr}` with `Cache-Control: no-store`. The modal fills the certificate and key fields of the form and shows the CSR with a copy button. The command output is never logged (`Options` without output capture in logs).

## 13. Deviations from legacy

1. The SMTP relay is only changed when the Mail tab is saved and `MAIL_SYSTEM` is set; the pma alias only when the Databases tab is saved. Legacy wiped both when their sections were hidden.
2. "Change password" of a database host applies to that host and its user; legacy always changed `localhost root`.
3. The ftp/sftp password always travels in a file; one legacy path passed it in `argv`.
4. Restic is deleted and added again only when a value changed, and both results are checked.
5. `API_SYSTEM=0` runs `v-change-sys-api disable`; legacy never did.
6. The PHP versions, plugin installs and package updates run in the background with a log.
7. `hestia` cannot be stopped from the panel; restarting the server needs the host name.
8. A reset code is invalidated after use; a successful reset leads to the login page instead of signing in at once; "Please wait 15 minutes" is no longer shown, so the answer does not reveal whether a user exists.
9. The upgrade notification settings live on the Updates page.
10. Saving the server settings applies one tab at a time.

## 14. Hand-off

- `web/inc/arcpanel-routes.php`: `arc_route()` maps `/list/server/`, `/edit/server/`, `/list/updates/`; `arc_redirect_if_migrated()` covers the pages of §4 "Done when" 1, including `/edit/server/<service>/` (whitelisted names), the status reports (`?cpu` …) and the guest pages; `web/reset/index.php` and `web/reset2fa/index.php` call it after `main.php`.
- `web/templates/includes/panel.php` wraps the Server menu links with `arc_route()`.
- `LegacyRoutes` keeps only `web.logs.download`; `users.index`, `packages.index`, `account.edit` and `users.create` entries are removed when the routes exist (they do since E11).

## 15. Testing

| Suite        | Cases                                                                                                                                                                                                                                                                                                                                          |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pest unit    | `Capabilities` entries for every new route; the policies of §5.2 for admin, reseller A, reseller B and user; settings step planning (only changed keys, order, secrets); `ServiceConfigs` whitelist; reset code verification and expiry                                                                                                        |
| Pest feature | Every route for the four principals (403 for non-admins on server pages, 404 for missing systems); command arguments of §7–§12; protected service; partial failure report; task start and `E_INUSE`; reset: same answer for unknown user, wrong e-mail and success, rate limit 429, expired and reused codes, 2FA reset; search page; CSR JSON |
| Vitest       | `apTask` tolerant polling and reload; basic options parser and writer for nginx, PHP and MySQL; `service-editor` changed-files payload                                                                                                                                                                                                         |
| BATS         | `test/arcpanel-sys-task.bats`: argument whitelist, `E_INUSE`, record and log of a failing `php` task (adding an installed version), a `plugin` task that succeeds, reaping of a dead task, a task that survives `systemctl restart hestia`                                                                                                     |
| Playwright   | `server-services.spec.js`, `server-settings.spec.js`, `server-config.spec.js`, `server-updates.spec.js`, `reset.spec.js`, `search.spec.js` (with the CSR modal); axe on every page                                                                                                                                                             |

`test/e2e/prepare.sh` gains `reset-mailbox` (a mailbox on the e2e mail domain as the contact of a throw-away user) and `reset-code` (prints the newest reset code from that mailbox, as root).

## 16. Documentation changes in the same change set

- [13 Data model reference](../../design/13-data-model-reference.md): the system task files and the three commands.
- [10 Page migration](../../design/10-page-migration-and-retirement.md): modules 16, 22 and 23 implemented, progress metric 100 %.
- [Roadmap](../../roadmap.md): E7 status line.
- This spec: "Implementation notes".
- 15 (database schema) does not change.

## 17. Risks

| Risk                                                                       | Mitigation                                                                                                                              |
| -------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| A `hestia` update replaces `web-app/` and restarts the panel during a task | The runner is a transient systemd unit; the page polls JSON, tolerates failures and reloads at the end; BATS restarts `hestia` mid-task |
| Changing the panel certificate reloads `hestia-nginx` mid-request          | `v-change-sys-hestia-ssl` sends `HUP` (graceful reload), so the request finishes; the page reloads after save                           |
| Secrets (SMTP relay, backup hosts, database hosts, restic) leak            | `Secret` files; `ArcCommand` masks; no stored secret is sent back to a form; Pest asserts the arguments are `Secret`s                   |
| Account enumeration through the reset page                                 | One answer for every case, rate limits, single-use codes                                                                                |
| A partially applied settings tab                                           | One tab per save, steps in a fixed order, the applied steps listed                                                                      |
