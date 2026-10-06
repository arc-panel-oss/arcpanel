# 10 — Page Migration, Legacy Retirement and Branding

**Epics:** E7, E8, E9 · **Status:** Draft v1; modules 8–12 (Databases, DNS, Mail, Cron, Backups) implemented by the [E7 batch 1 spec](../features/specs/2026-10-06-001-e7-batch1-pages-design.md), modules 14, 15 and 17–21 (Firewall, IPs, Access keys and SSH keys, Logs, Statistics, Web Terminal, Quick Install) by the [E7 batch 2 spec](../features/specs/2026-10-06-002-e7-batch2-pages-design.md), modules 16, 22 and 23 (Server settings, services, config editors, updates; password and 2FA reset; search and the CSR generator) by the [E7 batch 3 spec](../features/specs/2026-10-06-003-e7-batch3-pages-design.md), so every legacy page has a panel app page; the specs win where they differ · **Depends on:** E1, E2, E11 (owner model) · **Unblocks:** 2.0 release, E26

---

## 1. Goal

Move every remaining legacy page to the panel app in dependency order with one repeatable recipe, so that each module costs about a week, every page is reseller-aware on first delivery, and at the end `web/` can be deleted, Laravel moved to the root URL and ArcPanel 2.0 released with a verified upgrade path from 1.10.5. E9 applies ArcPanel branding through the existing White Label system.

---

## 2. Current state (grounding)

- Legacy pages: `web/list/*` (22 modules incl. `index.php`, `terminal`, `web-log`, `notifications`), `web/add/*` (12), `web/edit/*` (11), `web/bulk/*` (13), plus `web/delete|suspend|unsuspend|start|stop|restart|schedule|download|copy|move|search|generate|update/*` single-action endpoints. Views in `web/templates/pages/*.php` (78).
- Each page is a flat script: `include main.php` → `exec(HESTIA_CMD …)` → `include templates/pages/x.php`. Bulk actions loop a `v-*` per selected id.
- Quick Install Apps: PSR-4 classes in `web/src/app/WebApp/` (`InstallerInterface`, `BaseSetup`, `AppWizard`, `Installers/<App>/`), own `composer.json`, invoked from `web/add/webapp/` and `bin/v-quick-install-app`.
- Web terminal page (`web/list/terminal`) is a static page whose JS (`webTerminal.js`) opens `wss://host/_shell/`.
- RRD graphs are PNGs served from the internal `/rrd/` location; statistics from `v-list-users-stats` / `v-list-user-stats`; server config editors use `v-list-sys-<svc>-config` + `v-change-sys-service-config` + `v-open-fs-config`.

---

## 3. Module order

Dependency order from the roadmap; each module = list + add + edit + single actions + bulk. Q1 modules (Login, Dashboard, Web Domains) are E1/E2 deliverables and are listed for completeness.

| #   | Quarter | Module                                              | Legacy dirs                                                                                                      | Notes                                                                                                                                      |
| --- | ------- | --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | Q1      | Login / 2FA / logout                                | `login`, `logout`                                                                                                | E1                                                                                                                                         |
| 2   | Q1      | Dashboard                                           | `list/user` (admin landing)                                                                                      | E2                                                                                                                                         |
| 3   | Q1      | Web domains                                         | `list/web`, `add/web`, `edit/web`, `bulk/web`, `list/web-log`                                                    | E1 proof; template card picker ([09](./09-web-templates-metadata.md))                                                                      |
| 4   | Q2      | Users + Packages                                    | `list/user`, `add/user`, `edit/user`, `bulk/user`, `list/package`, `add/package`, `edit/package`, `bulk/package` | delivered inside E11 part 2 (Customers, Packages)                                                                                          |
| 5   | Q2      | Ports                                               | — (new)                                                                                                          | E12 UI                                                                                                                                     |
| 6   | Q2      | Files                                               | `/fm/`                                                                                                           | E3, implemented; menu switched by `ARCPANEL_APP_PAGES`, `/fm/` until E8                                                                    |
| 7   | Q2      | Node (versions, apps)                               | — (new)                                                                                                          | E13/E14                                                                                                                                    |
| 8   | Q3      | Databases                                           | `list/db`, `add/db`, `edit/db`, `bulk/db`                                                                        | E7 batch 1, implemented; phpMyAdmin SSO kept                                                                                               |
| 9   | Q3      | DNS                                                 | `list/dns`, `add/dns`, `edit/dns`, `bulk/dns`                                                                    | E7 batch 1, implemented; zones, records, DNSSEC public view                                                                                |
| 10  | Q3      | Mail                                                | `list/mail`, `add/mail`, `edit/mail`, `bulk/mail`                                                                | E7 batch 1, implemented; domains, accounts, DKIM DNS hints                                                                                 |
| 11  | Q3      | Cron                                                | `list/cron`, `add/cron`, `edit/cron`, `bulk/cron`                                                                | E7 batch 1, implemented; `x-ap-cron-builder`                                                                                               |
| 12  | Q3      | Backups                                             | `list/backup`, `edit/backup`, `bulk/backup`, `bulk/restore`, `schedule/*`, `download/backup`                     | E7 batch 1, implemented; tar, restic, exclusions, snapshot browser                                                                         |
| 13  | Q3      | Apps (Docker store)                                 | — (new)                                                                                                          | E4                                                                                                                                         |
| 14  | Q4      | Firewall                                            | `list/firewall`, `add/firewall`, `edit/firewall`, `bulk/firewall`                                                | E7 batch 2, implemented; rules, IPset lists, ban list; port registry rules locked; rules, ipset, bans                                      |
| 15  | Q4      | IP addresses                                        | `list/ip`, `add/ip`, `edit/ip`, `bulk/ip`                                                                        | E7 batch 2, implemented                                                                                                                    |
| 16  | Q4      | Server: settings, services, config editors, updates | `edit/server`, `list/server`, `list/updates`, `bulk/service`, `start\|stop\|restart/*`                           | E7 batch 3, implemented; one page per settings tab, `service-editor` island, PHP versions, plugins and updates as a background system task |
| 17  | Q4      | Access keys / API                                   | `list/access-key`, `add/access-key`, `bulk/access-key`, `list/key`, `add/key` (SSH keys)                         | E7 batch 2, implemented; tree-scoped keys wait for E29; role-aware permission sets ([03](./03-tenancy-and-authorization.md) §8)            |
| 18  | Q4      | Logs, auth log, notifications                       | `list/log`, `list/notifications`                                                                                 | E7 batch 2, implemented; notifications stay in the tray                                                                                    |
| 19  | Q4      | Statistics / RRD                                    | `list/stats`, `list/rrd`                                                                                         | E7 batch 2, implemented; Chart.js from `v-export-rrd`, reseller totals by `v-list-reseller-users-stats`                                    |
| 20  | Q4      | Web Terminal page                                   | `list/terminal`                                                                                                  | E7 batch 2, implemented                                                                                                                    |
| 21  | Q4      | Quick Install Apps                                  | `add/webapp`                                                                                                     | E7 batch 2, implemented; CLI commands run the installers in the background                                                                 |
| 22  | Q4      | Account / password & 2FA reset                      | `edit/user` (self), `reset`, `reset2fa`                                                                          | E7 batch 3, implemented; the account page came with E11, the guest flows are panel app pages with rate limits                              |
| 23  | Q4      | Search, misc single actions                         | `search`, `generate`, `update`, `copy`, `move`                                                                   | E7 batch 3, implemented; search page, CSR dialog in the SSL tabs, the rest absorbed into modules                                           |

---

## 4. The page recipe

For every legacy module, in this order. The checklist is a PR template (`.github/PULL_REQUEST_TEMPLATE/migration.md`).

1. **Inventory** — grep the legacy dir for `v-` to list the commands and their argument order; list the `$_SESSION` policy keys and `$read_only` uses; note bulk actions in `web/bulk/<x>/index.php`.
2. **Service** — `App\Services\<Module>Service` with one method per command, typed arguments, returning DTOs parsed from `json`; secrets through `Secret`. Fixtures recorded for tests ([02](./02-laravel-foundation.md) §4.5).
3. **Policy** — `<Object>Policy` methods for every action, all via `Capabilities` + `OwnerScope` ([03](./03-tenancy-and-authorization.md) §7). Register.
4. **Routes** — `routes/web/<module>.php`: `index`, `create`, `store`, `edit`, `update`, `destroy`, `suspend`, `unsuspend`, `bulk`, plus module-specific actions; names `<module>.<action>`; middleware `auth.arc`, `effective-user`, `not-readonly` on mutations.
5. **FormRequests** — validation mirrors the legacy `index.php` checks (required fields, formats); authorization delegated to the policy; messages reuse existing translation strings.
6. **Controller** — thin: FormRequest → service → flash → redirect. No `exec`, no `$_SESSION`.
7. **Views** — Blade with `x-ap-*` components only ([05](./05-design-system-and-frontend.md) §5); row actions and bulk bar; owner column for admin/reseller lists; every legacy string kept verbatim so translations apply.
8. **Bulk** — `POST <module>.bulk {action, ids[]}` → service loops the command, collects per-id results, flashes a summary (`n ok, m failed`); replaces `web/bulk/<x>`. _(E7 batch 1: every module uses the shared `App\Services\Support\BulkRunner`; controllers check every item with `ChecksBulkItems` first.)_
9. **i18n** — new strings added to `web/locale/hestiacp.pot` by the extraction script; no hard-coded English outside `__()`.
10. **Tests** — Pest feature tests per route × three principals (admin, reseller A, reseller B / user) with the fake; Playwright happy path per module; a11y gate.
11. **Hand-off** — _(E1-S2: Web Domains uses the `ARCPANEL_APP_PAGES` switch and `302` redirects through `arc_redirect_if_migrated()` instead of 301 stubs; stubs and deletions move to E8.)_ add the entry to `web/inc/arcpanel-routes.php`; replace `web/<kind>/<x>/index.php` with the 301 stub:

    ```php
    <?php header(
    	"Location: /app/<module>" .
    		(isset($_GET["domain"]) ? "/" . rawurlencode($_GET["domain"]) . "/edit" : ""),
    	true,
    	301,
    );
    exit();
    ```

    Delete `web/templates/pages/<x>*.php`. Keep single-action endpoints (`web/delete/<x>` …) as stubs pointing at the new module index (they are GET links with tokens in legacy; the new app uses POST forms).

12. **Metric** — CI recomputes the migration metric (§8).

### 4.1 Per-module command inventory

From the legacy pages (verified by grep); the service for each module wraps exactly these.

| Module                 | Read                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Write                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Web domains            | `v-list-web-domains`, `v-list-web-domain`, `v-list-web-domain-ssl`, `v-list-web-stats`, `v-list-web-templates(-backend/-proxy)`, `v-list-user-ips`, `v-list-dns-domain`, `v-list-mail-domain`, `v-list-web-domain-accesslog/-errorlog`                                                                                                                                                                                                                                               | `v-add-web-domain`, `v-add-domain`, `v-change-web-domain-{ip,tpl,proxy-tpl,backend-tpl,docroot,sslcert,stats,ftp-path,ftp-password}`, `v-add/delete-web-domain-{alias,ftp,proxy,redirect,ssl,ssl-force,ssl-hsts,stats,stats-user}`, `v-add/delete-letsencrypt-domain`, `v-add/delete-fastcgi-cache`, `v-add/delete-dns-on-web-alias`, `v-change-dns-domain-ip`, `v-rebuild-mail-domain`, `v-suspend/unsuspend/delete-web-domain`, `v-rebuild-web-domain`, `v-purge-nginx-cache`, `v-dump-site` |
| Users / packages       | `v-list-users`, `v-list-user`, `v-list-user-packages`, `v-list-sys-languages`, `v-list-sys-themes`, `v-list-sys-shells`, `v-list-reseller-users`, `v-list-reseller-packages`                                                                                                                                                                                                                                                                                                         | `v-add-user`, `v-add-reseller-user`, `v-change-user-{password,contact,name,language,theme,shell,package,role,ns,php-cli,sort-order,owner}`, `v-suspend/unsuspend/delete-user`, `v-rebuild-user`, `v-add/delete-user-2fa`, `v-add/copy/rename/delete-user-package`, `v-change-user-package`                                                                                                                                                                                                     |
| Databases              | `v-list-databases`, `v-list-database`, `v-list-database-hosts`, `v-list-sys-config` (`DB_PMA_ALIAS`)                                                                                                                                                                                                                                                                                                                                                                                 | `v-add-database`, `v-change-database-{user,password,owner}`, `v-suspend/unsuspend/delete-database`, `v-rebuild-database`, `v-add-database-temp-user` (SSO)                                                                                                                                                                                                                                                                                                                                     |
| DNS                    | `v-list-dns-domains`, `v-list-dns-domain`, `v-list-dns-records`, `v-list-dns-record`, `v-list-dnssec-public-key`, `v-list-dns-templates`, `v-list-user-ns`                                                                                                                                                                                                                                                                                                                           | `v-add-dns-domain`, `v-add/change/delete-dns-record`, `v-change-dns-domain-{ip,tpl,ttl,soa,exp,dnssec}`, `v-suspend/unsuspend/delete-dns-domain`, `v-suspend/unsuspend-dns-record`, `v-rebuild-dns-domain`                                                                                                                                                                                                                                                                                     |
| Mail                   | `v-list-mail-domains`, `v-list-mail-domain`, `v-list-mail-accounts`, `v-list-mail-account`, `v-list-mail-domain-dkim-dns`, `v-list-mail-domain-ssl`, `v-list-user-ips`                                                                                                                                                                                                                                                                                                               | `v-add-mail-domain`, `v-add/delete-mail-domain-{antispam,antivirus,dkim,catchall,reject,smtp-relay,ssl,webmail}`, `v-change-mail-domain-rate-limit`, `v-add-mail-account`, `v-add/delete-mail-account-{alias,forward,fwd-only,autoreply}`, `v-change-mail-account-{password,quota,rate-limit}`, suspend/unsuspend/delete for both                                                                                                                                                              |
| Cron                   | `v-list-cron-jobs`, `v-list-cron-job`                                                                                                                                                                                                                                                                                                                                                                                                                                                | `v-add-cron-job`, `v-change-cron-job`, `v-suspend/unsuspend/delete-cron-job`, `v-add/delete-cron-reports`                                                                                                                                                                                                                                                                                                                                                                                      |
| Backups                | `v-list-user-backups`, `v-list-user-backup`, `v-list-user-backup-exclusions`, `v-list-user-backups-restic`, `v-list-user-backup-restic`, `v-list-user-files-restic`                                                                                                                                                                                                                                                                                                                  | `v-schedule-user-backup(-download)`, `v-schedule-user-restore`, `v-delete-user-backup`, `v-update-user-backup-exclusions`, `v-schedule-user-backup-restic`, `v-schedule-user-restore-restic`, `v-delete-user-backup-restic`, `v-restore-*-restic`                                                                                                                                                                                                                                              |
| Firewall               | `v-list-firewall`, `v-list-firewall-rule`, `v-list-firewall-ban`, `v-list-firewall-ipset`, `v-list-sys-ports` (E12)                                                                                                                                                                                                                                                                                                                                                                  | `v-add/change/delete/move-firewall-rule`, `v-suspend/unsuspend-firewall-rule`, `v-add/delete-firewall-ban`, `v-add/change/delete/update-firewall-ipset`, `v-add/delete-sys-firewall`, `v-stop-firewall`                                                                                                                                                                                                                                                                                        |
| IPs                    | `v-list-sys-ips`, `v-list-sys-ip`, `v-list-sys-interfaces`                                                                                                                                                                                                                                                                                                                                                                                                                           | `v-add-sys-ip`, `v-change-sys-ip-{status,owner,name,nat}`, `v-delete-sys-ip`, `v-update-sys-ip`                                                                                                                                                                                                                                                                                                                                                                                                |
| Server                 | `v-list-sys-config`, `v-list-sys-info`, `v-list-sys-{cpu,memory,disk,network,web,dns,mail,db}-status`, `v-list-sys-services`, `v-list-sys-php`, `v-list-sys-{nginx,php,mysql,pgsql,dovecot,clamd,spamd,vsftpd,proftpd}-config`, `v-open-fs-config`, `v-list-sys-hestia-ssl`, `v-list-backup-host(-restic)`, `v-list-database-hosts`, `v-list-remote-dns-hosts`, `v-get-sys-timezone(s)`, `v-list-sys-hestia-updates`, `v-list-sys-hestia-autoupdate`, `v-list-web-templates-backend` | `v-change-sys-config-value`, `v-change-sys-{hostname,timezone,language,php,webmail,db-alias,api,hestia-ssl,port,release,service-config}`, `v-add/delete-sys-{firewall,quota,cgroups,filemanager,web-terminal,pma-sso,pma-restrict,smtp-relay}`, `v-add/delete-web-php`, `v-add/delete-backup-host(-restic)`, `v-change-database-host-password`, `v-update-white-label-logo`, `v-start/stop/restart-service`, `v-restart-system`, `v-update-sys-hestia`, `v-add/delete-cron-hestia-autoupdate`  |
| Access keys / SSH keys | `v-list-access-keys`, `v-list-access-key`, `v-list-apis`, `v-list-user-ssh-key`                                                                                                                                                                                                                                                                                                                                                                                                      | `v-add-access-key`, `v-add-reseller-access-key`, `v-delete-access-key`, `v-add/delete-user-ssh-key`                                                                                                                                                                                                                                                                                                                                                                                            |
| Logs / notifications   | `v-list-user-log`, `v-list-user-auth-log`, `v-list-user-notifications`                                                                                                                                                                                                                                                                                                                                                                                                               | `v-delete-user-log`, `v-delete-user-auth-log`, `v-acknowledge-user-notification`, `v-delete-user-notification`, `v-add-user-notification`                                                                                                                                                                                                                                                                                                                                                      |
| Statistics / RRD       | `v-list-users-stats`, `v-list-user-stats`, `v-list-sys-users`, `v-list-sys-rrd`, `v-export-rrd`                                                                                                                                                                                                                                                                                                                                                                                      | —                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Quick Install          | `v-list-web-domain`, `v-list-sys-php` + PHP installer classes                                                                                                                                                                                                                                                                                                                                                                                                                        | `v-quick-install-app`, installer-specific (`v-add-database`, `v-change-web-domain-tpl`, `v-add-user-wp-cli`, …)                                                                                                                                                                                                                                                                                                                                                                                |

New ArcPanel modules (Ports, Node, Apps, Templates) have their inventories in [04](./04-port-registry.md), [07](./07-node-platform.md), [08](./08-app-store.md), [09](./09-web-templates-metadata.md).

---

## 5. Special cases

### 5.1 Server settings and service config editors

- `edit/server` becomes a tabbed page (`x-ap-tabs`): Basic · Web · DNS · Mail · Database · Backups · Security/Policies · Plugins · White Label · SSL. Each tab is its own FormRequest and only sends changed keys through `v-change-sys-config-value` or the dedicated command (`v-change-sys-hostname`, …). Policy toggles from [03](./03-tenancy-and-authorization.md) §4.4 live on the Security tab. Admin-only; `POLICY_SYSTEM_PROTECTED_ADMIN` honoured by `isReadOnly()`.
- Config editors: `service-editor` Vue island (CodeMirror, nginx/ini/yaml modes) loads `v-list-sys-<svc>-config json` to discover files, `v-open-fs-config FILE` for content, saves with `v-change-sys-service-config SERVICE CONFIG_FILE [RESTART]` (content staged exactly as in [06](./06-file-manager-v2.md) §4.2, since the command takes a path). Shows `<svc> -t` output on syntax error (the command returns it on stdout). Restart checkbox → `v-restart-service`.
- Services page: `v-list-sys-services json` → table with CPU/mem, start/stop/restart (`bulk/service` → bulk route).
- Updates page is migrated as-is in E7 (`v-list-sys-hestia-updates`, autoupdate toggle) and redesigned later by E33.

### 5.2 Web Terminal page

`/app/terminal` renders `<div class="js-web-terminal">` and loads the existing xterm bundles; the WebSocket stays `wss://host/_shell/` → `127.0.0.1:8085`, authenticated by the `ARCPANELSID` cookie — nothing changes on the server. Shown only when `WEB_TERMINAL='true'` and the effective user has a login shell (`Principal::$loginShell !== 'nologin'`). The same transport is extended for log streaming by [07](./07-node-platform.md) §6.4.

### 5.3 Quick Install Apps

- `web/src/app/WebApp/` moves to `web-app/app/QuickInstall/` (namespace `App\QuickInstall`), classes unchanged except namespace and the `HESTIA_CMD` calls inside `BaseSetup`/`InstallationTarget`, which are rewritten to use `ArcCommand`. `web/src/composer.json` dependencies merge into `web-app/composer.json`.
- `bin/v-quick-install-app` keeps working: it invokes PHP with the installer classes; its include path is updated to the new location by the 2.0 upgrade script (this is an ArcPanel-owned legacy bridge; the command's interface is unchanged).
- UI: `/app/web/domains/{domain}/install` → `x-ap-card-picker` of installers (`InstallerInfo` icons served from `public/build/quick-install/`), options form generated from `getConfig('form')`, submit runs the installer; long installs run via the queue (`apps.pipe`) until E15.

### 5.4 Statistics and RRD

RRD PNGs continue to be served from the internal `/rrd/` location via `X-Accel-Redirect` from a Laravel route that applies the policy (admin only for server graphs). Statistics pages render `v-list-users-stats`/`v-list-user-stats` with `x-ap-usage-bar`; reseller view aggregates the tree ([03](./03-tenancy-and-authorization.md) §9.2).

### 5.5 Logs, auth log, notifications

Straight list pages with filters; `POLICY_USER_VIEW_LOGS` / `POLICY_USER_DELETE_LOGS` from `SysConfig` gate the actions. Notifications also feed the tray ([05](./05-design-system-and-frontend.md) §4.2).

### 5.6 Access keys

Permission-set list from `v-list-apis json`; resellers see only sets with `ROLE` ∈ {`reseller`,`user`}; users only `user`. Secret shown once after creation (`v-add-access-key` output), never stored by Laravel.

### 5.7 Guest flows

Password reset (`web/reset`) and 2FA reset (`web/reset2fa`) migrate last: `v-check-user-password`-free flows using `v-list-user` + `RKEY` emails via `mail-wrapper.php` equivalent (`App\Mail` using the `USE_SERVER_SMTP` settings from `SysConfig`).

---

## 6. 2.0 cut-over (E8)

Preconditions: every module in §3 migrated (metric = 100 %), FileGator removed ([06](./06-file-manager-v2.md) §7), E14 landed, e2e suite green on all CI targets.

1. **Routing** — `src/deb/nginx/nginx.conf`: `root /usr/local/hestia/web-app/public;`, `location / { try_files $uri /index.php?$query_string; }`, `location ~ ^/index\.php` → FPM; keep `/api/` (`alias /usr/local/hestia/web/api/`, PHP via FPM — the legacy API script stays, see [01](./01-architecture-overview.md) §9), `/_shell/`, `/rrd/`, `/backup/`, `/_downloads/`; remove `/fm/`, `/app/` prefix block, legacy deny lists. Laravel's base URL follows `SCRIPT_NAME` (now `/index.php`); all links are route-named so nothing else changes. `/app/*` → 301 to `/*` for one release.
2. **Delete legacy** — `web/*` except `web/api/`, `web/inc/` pieces the API needs (`helpers.php`, `vendor/`, `quoteshellarg`), `web/images/` (White Label logo paths used by `v-update-white-label-logo`), `web/css/themes/` (custom themes dir referenced by `v-list-sys-themes`), `web/error/`, `web/rrd/` (RRD output dir). `web/templates/`, `web/js/`, `web/locale/*.mo` (translations now generated), `web/src/` go. `web/inc/arcpanel-routes.php` goes.
3. **Session** — `LegacySession` becomes the only session handler; the per-request key fallbacks are removed ([02](./02-laravel-foundation.md) §5.9). `web-terminal-session-auth.php` unchanged.
4. **Installer** — `install/hst-install-*.sh`: remove FileGator steps, add `v-add-sys-arcpanel`, keep every other step; new `--filemanager` flag semantics unchanged (toggles `FILE_MANAGER`). `install/upgrade/versions/2.0.0.sh`: run `v-add-sys-arcpanel`, remove `web/fm`, regenerate nginx config, re-apply the `v-change-user-role` patch, seed `data/ports/`, copy `developer.pkg`/`reseller.pkg`, generate template sidecars, `php artisan optimize`, restart `hestia-nginx`/`hestia-php`.
5. **Packaging** — `src/deb/hestia/`: version `2.0.0`, ship `web-app/` with `vendor/` and `public/build/`; `src/deb/nginx/` new config; `hestia-php` unchanged. `src/hst_autocompile.sh` gains the `npm run build` + `composer install` steps.
6. **Custom themes** — migration note in `docs/docs/server-administration/customisation.md`: token names, removed selectors, how to port a `web/css/themes/custom/*.css`; `v-refresh-sys-theme` unchanged.
7. **Docs & release notes** — `CHANGELOG.md` 2.0.0 section (breaking: UI paths, FileGator, `/app` prefix removed), docs site pages for Ports, Node, Apps, Resellers, Templates; API docs unchanged.
8. **Upstream-diff check** — CI job ([11](./11-testing-and-ci.md) §5) must be green: only additive `bin/`/`func/`/`install/` changes plus the recorded patch list.
9. **Verification** — fresh install on Debian 12/13, Ubuntu 24.04 → BATS + e2e; in-place upgrade from a 1.10.5 VM snapshot with fixtures (users, reseller tree created via 1.x-era commands, domains with custom `nginx.conf_*`, restic backups, custom theme) → BATS + e2e + manual checklist (login, login-as, 2FA, read-only, terminal, downloads).
10. **Rollback** — documented: `apt install hestia=1.10.5-* hestia-nginx=…` from the local cache, restore `/usr/local/hestia/conf` from `HESTIA_BACKUP`, `v-rebuild-all`. Data files added by 2.0 (`data/ports`, `OWNER` keys, sidecars) are ignored by 1.10.5 and may stay.

---

## 7. Branding (E9)

- **White Label first:** `v-update-white-label-logo` and the custom images dir (`web/images/custom/`) are kept; the installer places ArcPanel's logo set there by default (`logo.svg`, `logo-header.svg`, `logo.png`, `favicon.*`), so an admin can still override. App name, page title, sender name/address/subject come from the existing `hestia.conf` White Label keys shown on the Server → White Label tab; defaults set to "ArcPanel" by the installer.
- New icon set (sidebar SVG sprite, [05](./05-design-system-and-frontend.md) §11) and login page artwork.
- `README.md`, `docs/` site title/logo (`docs/.vitepress/config.js`), installer banner (`install/hst-install.sh` welcome text), `web/error/*.html`.
- **No rename** of `HESTIA_*` constants, `/usr/local/hestia`, `hestia-*` packages or `v-*` names (roadmap §7, O5). Upstream attribution and GPL notices retained; "Hestia Control Panel"/"HestiaCP" names are not used for the product (upstream trademark rules in README).

---

## 8. Progress metric

`scripts/migration-metric.php` (run in CI, printed in the PR summary and committed to `docs/design/.metric.json`):

- **Legacy routes** = directories under `web/{list,add,edit,bulk,delete,suspend,unsuspend,start,stop,restart,schedule,download,copy,move,search,generate,update}/` whose `index.php` is **not** a 301 stub (detected by a `// arcpanel-stub` marker comment).
- **Laravel routes** = `php artisan route:list --json` entries under the web middleware group, excluding `_health` and `_styleguide`.
- Metric = Laravel ÷ (Laravel + legacy); the Q3 exit criterion is ≥ 70 %, E8 requires 100 % (legacy = 0).

---

## 9. Failure modes

| Failure                                                 | Mitigation                                                                                                                                                                                                |
| ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A legacy page is stubbed before parity (missing action) | PR checklist requires the command inventory diff to be empty; Playwright happy path per module; keep the legacy page one release behind the stub only if parity gap found (revert the stub, not the page) |
| Translation regressions                                 | strings copied verbatim; CI compares `.pot` before/after and lists removed msgids                                                                                                                         |
| Bookmarks / docs links to legacy URLs                   | 301 stubs for one release; docs site search updated                                                                                                                                                       |
| Upgrade breaks custom themes                            | migration note; theme still loads; style guide screenshot in the release notes                                                                                                                            |
| `v-quick-install-app` path change                       | upgrade script updates the include path; BATS test runs one installer                                                                                                                                     |
| In-place upgrade leaves `web/` remnants                 | `2.0.0.sh` deletes by explicit list and logs leftovers; `v-run-sys-doctor` (E17) later checks                                                                                                             |

---

## 10. Testing

Pest per module (three principals), Playwright per module, upgrade-path e2e (1.10.5 snapshot → 2.0), fresh-install e2e, metric script unit test, nginx `-t` in CI for the final config, `php artisan route:list` snapshot to catch accidental route removals.

---

## 11. Open questions

1. Keep `web/api/index.php` as a script (recommended) or port to a controller before 2.0? Script; revisit with E29.
2. Should 2.0 ship `/app/*` → `/*` redirects at all, given `/app` only ever existed in pre-releases? Yes, cheap, one release.
3. Single-action GET endpoints (`web/delete/web/?domain=…&token=…`) are linked from emails in a few places (backup download). Audit email templates in `install/common/templates/email/` during Q4 and point them at the new POST-less download routes (`/app/backups/{file}/download` with signed URLs).
