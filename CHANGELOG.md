# Changelog

All notable changes to ArcPanel are listed here. ArcPanel is based on HestiaCP 1.10.5 and, since 2.0, a hard fork of it ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)).

## Unreleased

### Added

- **Webmail and database tools on their own ports.** The webmail client answers on port 8086 and phpMyAdmin and phpPgAdmin on port 8087 of every address of the server, with the panel certificate, so "Open Webmail" and the database buttons work without DNS for each mail domain and also when the panel is opened by IP address. The ports are separate origins from the panel. Change or turn them off under Server → Settings (Mail Server, Databases), in `PATCH /api/v2/system/settings/mail` and `/databases` (`webmail_port`, `webmail_link`, `db_admin_port`) or with `v-change-sys-webmail-port`, `v-change-sys-db-admin-port` and `v-change-sys-webmail-link`. Installs and upgrades open them in the firewall only when the tool is installed and the port is free ([runbook](https://github.com/arc-panel-oss/docs/blob/main/operations/tool-ports.md)).
- **Webmail sign-on.** "Open Webmail (signed in)" on a mail account opens Roundcube without the mailbox password, for whoever may change that password. A one-time ticket, bound to the browser's address, becomes a session password of 8 hours that Dovecot accepts from the loopback address only; it ends when the password, the mailbox, its domain or its user changes, and every sign-on is in the user's action log.
- **IPv6 in the firewall.** The firewall runs on nftables: one table, `inet arcpanel`, filters IPv4 and IPv6 and is replaced in one step, checked first, so a rule set nftables refuses never replaces the one that runs. A port closed in the panel is now closed over IPv6 too; rules, bans and IP lists accept IPv6 addresses and networks, the IP list page offers IPv6 country lists, and Fail2Ban bans IPv6 addresses. `0.0.0.0/0` and `::/0` mean every address of both versions; ICMPv6 neighbour discovery is always allowed. See the [E40 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-19-002-e40-nftables-firewall-design.md).
- `v-check-firewall` and `GET /api/v2/system/firewall/status` (`getFirewallStatus`) tell whether the loaded rules are still the panel's (`ok`, `drift`, `missing`); the Firewall page warns and offers a reload when they are not.
- `data/firewall/custom.nft`: nftables commands loaded together with the panel's table.
- **Object storage.** Accounts can keep S3-compatible buckets on the server, served by Silo, the community-maintained fork of MinIO, installed from checked packages under Server → Object storage (`v-add-sys-silo`) and published on a hostname of its own with Let's Encrypt (`v-change-sys-silo-endpoint`). Packages say whether an account may use it, how many buckets it may have, how much their quotas may add up to and whether user backups include the objects (`OBJECT_STORAGE`, `OBJECT_STORAGE_BUCKETS`, `OBJECT_STORAGE_QUOTA`, `OBJECT_STORAGE_BACKUP`); resellers stay within their own package. The Object storage page lists the buckets with quota, usage and versioning, adds S3 keys for every bucket of the account or one bucket, read and write or read only, with an optional expiry, and shows a new key's secret once; it can also be saved in the secret store. Buckets are suspended, restored and deleted with their account, and user backups keep their settings. REST API v2 answers `/users/{user}/buckets`, `/users/{user}/s3-keys`, `/buckets` and `/system/object-storage`, with the permission set `v2-storage`. See the [E44 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-19-003-e44-object-storage-silo-design.md).
- **S3 backup host.** Incremental backups can go to an S3-compatible bucket (AWS, Cloudflare R2, Wasabi, the object storage of another ArcPanel server): "S3-compatible bucket" under Server → Settings → Backups, or `v-add-backup-host-s3`. The connection is kept as an rclone remote of root; the form shows the endpoint, region and access key ID, never the secret.
- Records of backup runs: every run of a tar or restic backup (nightly, requested in the panel or the API, or started on the server), failed ones included with the exit code and reason, the last 30 per user. `v-list-user-backup-runs` and `v-list-sys-backup-runs` list them, and REST API v2 answers `GET /users/{user}/backup-runs` and, for administrators and resellers, `GET /backup-runs` with `updated_since`. See the [Fleet PD1–PD4 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md).
- The REST API v2 document names the permission set of each operation (`x-arcpanel-permission-set`), the values of `Task.status`, the 409 answers of `createBackup`, `restoreBackup` and `createNodeApp`, how a Let's Encrypt request reports a failure and renews a certificate, and how `setNodeAppEnv` keeps values.
- The one-off task of app processes is a task of `GET /tasks/{id}` and `listUserTasks` (`app-process:USER:ID`), and `startAppProcessTask` sends `Link` to it.
- `createNodeApp` answers a domain that already has a Node.js app with `409 E_EXISTS` and `existing`, the path of that app.

### Changed

- "Open Webmail" uses `http://` while a mail domain has no SSL when it points at `webmail.<domain>` (`WEBMAIL_LINK=domain`), as the 1.x pages did; before, it always used `https://`.
- The panel no longer sends the `XSRF-TOKEN` cookie; its scripts read the CSRF token from the page.
- The 2.3.0 upgrade moves the firewall from iptables and ipset to nftables (`FIREWALL_SYSTEM='nftables'`, unit `arcpanel-firewall.service` instead of `arcpanel-iptables.service`). When the new rules cannot be loaded, the server keeps its iptables rules and the upgrade warns. The `arcpanel` package depends on `nftables`; `ipset` is no longer installed. The firewall service is listed as `nftables`; `iptables` still works to start, stop and restart it. A `data/firewall/custom.sh` that calls `iptables` still runs, but its rules cannot open ports the new table drops: the upgrade warns, and `custom.nft` takes such rules.
- Deleting an IP list that an active rule uses answers `E_INUSE` (`409` in REST API v2) and names the rule.
- The firewall rule list shows `0.0.0.0/0` and `::/0` as "Any address, IPv4 and IPv6", and the rule form says that both mean every address of both versions.
- A queued task whose command fails and asks to run again (Let's Encrypt while DNS propagates) is retried at most `QUEUE_MAX_ATTEMPTS` times, 3 by default, and then fails; before, it was retried every five minutes without end. Every failed queue task carries the reason from its log in `error`.
- The REST API v2 document declares `Link` on every `202` that starts a task and `Location` only where the answer names a resource, as the panel answers. Before, it declared `Location` on the backup, snapshot, malware scan, Docker engine and PHP operations, which send only `Link`.

### Fixed

- The ban list of the panel and REST API v2 accepts the `RECIDIVE` chain of Fail2Ban.
- An administrator who changes the app process settings of a package on the package form saves them; before, only a reseller's changes were saved.
- REST API v2 names the fields of refused app process settings of a package as the request does (`app_processes.max`), not with the form's names.
- A backup with only remote backup hosts that fails to upload exits with the error code of the upload instead of 0.

- A change of the server configuration made outside the panel (for example `v-add-sys-api-ip` or `v-change-sys-config-value` over SSH) applies to the panel and REST API v2 at the next request. Before, the panel kept its cached copy of `arcpanel.conf` for up to 60 seconds, so API v2 could still refuse a newly allowed address with `E_API_DISABLED`. The commands that write `arcpanel.conf` now write a stamp that the panel compares with its copy.
- The description of the permission set `v2-apps` on the Access keys page and in `GET /api/v2/access-key-sets` names app processes, which the set has granted since 2.1.3.
- The package form no longer shows an internal reference in the hint of Ports.

### Changed

- The Add process page of an account without web domains links to the Add Web Domain page.

## 2.2.0 — 2026-10-17

The REST API v2 additions that ArcPanel Fleet needs to operate many servers (E43), all additive. See the [E43 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md).

### Added

- **Server identifier.** Every server gets a random `SERVER_ID` at install or upgrade, kept while the machine stays the same and renewed when it is cloned with a new machine identity (`v-update-sys-server-id`, checked daily and at boot). `GET /api/v2/me` answers it as `server_id`, with the calling key's `key_expires_at` and `key_allowed_ips`.
- **Monitoring keys.** The permission set `v2-observe` is `v2-read` without file contents, the secret store and restic file listings, for a client that watches a server without reading files or secrets.
- **Key restrictions.** An access key can be limited to up to 20 addresses or networks and given an expiry when it is created, on the Access keys page, with `createAccessKey` (`allowed_ips`, `expires_at`) and with `v-add-access-key`. Both APIs enforce them: an expired key fails authentication, a call from another address is refused.
- **Cross-user lists.** `GET /api/v2/web-domains`, `/databases`, `/cron-jobs`, `/node-apps` and `/backups` list the objects of every user for administrators, or of a reseller and its users for resellers, paged, with `updated_since` for incremental indexing (`v-list-sys-web-domains`, `-databases`, `-cron-jobs`, `-node-apps`, `-backups`). Cron jobs, backups and Node.js apps answer their `user`.
- **Request journal.** Every REST API v2 write of a key is journaled for 7 days without its body, and `GET /api/v2/requests/{id}` tells a client whose answer was lost what became of the request with that `X-Request-Id`. A write keeps running when its client disconnects.
- **Service logs.** `GET /api/v2/system/services/{service}/logs` and `v-list-sys-service-log` give administrators the last lines (up to 500) of a service's systemd journal.

### Changed

- The live fields of a Node.js app in REST API v2 (`runtime`, `status`, `online`, `reason`, `pid`, `cpu`, `memory_mb`, `restarts`, `uptime_seconds`) are documented as nullable: the cross-user list answers them as `null`, because it reads the app records without asking pm2. The per-user endpoints answer them as before.

## 2.1.3 — 2026-10-17

The supply-chain and authorization parts of the security audit remediation (E39 W3 and W4), and app processes for Laravel Octane and other long-running app servers (E42). See the [W3 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md) and the [W4 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-16-002-e39-w4-authorization-and-tenancy-design.md).

### Breaking changes

- **REST API v1 is off on new installs.** Turn it on with "REST API v1" on the Security tab or `v-change-sys-api enable v1`; servers that upgrade keep it as it was. REST API v2 is the supported API.
- **Reseller packages keep to the reseller's plan.** The shell of a reseller's package comes from the new setting "Shells resellers may give in their packages" (`nologin` by default), the templates are the reseller's own while users may not choose templates, and limits with units (`512M`, `1G`, `50%`) are compared correctly. Packages that already go beyond the plan keep working; the upgrade log lists them, and an edit is refused until they fit.
- **arcpanelweb runs only the listed commands through `sudo`.** The panel's command allow-list is written to `/etc/sudoers.d/arcpanelweb` at install and upgrade; `v-get-user-secret`, `v-change-sys-release`, `v-add-sys-arcpanel` and `v-update-sys-sudoers` are root-only.

### Security

- Everything ArcPanel downloads during the build, the installation and upgrades is fetched over HTTPS and checked against a pinned SHA-256, and APT repository keys are checked against pinned fingerprints. The engine packages are rebuilt from verified sources and get a new revision. rclone is installed from a pinned release instead of a remote install script. The phpMyAdmin single sign-on verifies the panel's certificate. The upgrade reports any APT key on the server that ArcPanel does not expect.
- Another administrator can no longer change the account or anything of the root administrator when "Restrict access to read-only for other administrators" is on; with the hidden administrator, such a change answers "not found". A suspended reseller or administrator only reads, also while it acts as a customer. Only an administrator links a firewall rule to a port.
- Access keys are generated from `/dev/urandom`. REST API v1 compares keys strictly, answers every authentication failure the same way and limits failed attempts per address.
- **Rotate your access keys.** Keys made before 2.1.3 came from a weak generator: create new keys and delete the old ones, and review the keys of the root administrator on its Access keys page. Run `v-add-sys-pma-sso` again to renew the phpMyAdmin single sign-on key.

### Added

- **App processes** (the App processes page for users whose package grants them, a card on the dashboard and a Processes tab on the web domain). Long-running app servers run as the hosting user, each in its own systemd unit inside the user's slice: they restart after a crash and a reboot, stop while the user is suspended, and are kept by `v-rebuild-user`, backups and restores. Presets for Laravel Octane (Swoole, RoadRunner, FrankenPHP), Laravel queue workers, Horizon and the scheduler, Gunicorn, Uvicorn, Celery, Go binaries, Puma and Sidekiq, plus custom commands; start, stop, restart and reload, logs, environment values and tasks (`composer install` and the like) in the panel. Packages grant them with `APP_PROCESSES` and limit their number, memory and CPUs (`APP_PROCESSES_MAX`, `APP_PROCESSES_MEMORY`, `APP_PROCESSES_CPUS`).
- **REST API v2.** 15 operations for app processes (tag "App processes"), in the `apps` area and its permission set `v2-apps`.

### Changed

- The web terminal ticket key is written on fresh installs, so the web terminal starts after an install with `--webterminal yes`.

### Fixed

- A package upgrade now runs `v-add-sys-app-processes` and `v-add-sys-arcpanel`: the panel's command allow-list and the `sudo` rules of `arcpanelweb` follow the installed commands, the framework caches are rebuilt, and app processes are set up on servers that upgrade, not only on fresh installs.

## 2.1.2 — 2026-10-15

The first published 2.1 release: it ships the changes of 2.1.1 and 2.1.0 below, for amd64 only.

### Fixed

- `v-add-user` sets the modes of the user's `web/` and `conf/` folders whatever the umask of the caller; a root shell with umask 077 left them closed to the user and the web server.
- Pruning the `php.ini` backups of a PHP version no longer parses the output of `ls`.
- The package build installs its build tools without debconf questions; the time zone question of `tzdata` stopped the Ubuntu 22.04 build of 2.1.0 and 2.1.1.

## 2.1.1 — 2026-10-13 (not published)

The tag `v2.1.1` exists, but its release build did not finish and no package was published; its changes ship in 2.1.2.

### Breaking changes

- **arm64 is not supported for now.** Packages are built for amd64 only, and the installers stop on an arm64 (aarch64) server before they change anything. Debian 12 / 13 and Ubuntu 22.04 / 24.04 / 26.04 LTS on amd64 stay supported.

## 2.1.0 — 2026-10-13 (not published)

The tag `v2.1.0` exists, but its release build did not finish and no package was published; its changes ship in 2.1.1.

Two features for PHP sites: the PHP Manager and a malware scan for PHP files. See the [E41 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-12-001-e41-php-manager-design.md) and the [E25 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-12-002-e25-malware-scan-design.md).

### Breaking changes

- **Old PHP versions.** PHP 5.6 to 7.3 can no longer be installed; versions already installed keep running and can be removed. `--multiphp yes` of the installer installs PHP 7.4 to 8.5.
- **PHP commands refuse more.** `v-add-web-php` refuses a version that the PHP repository does not provide and answers `E_EXISTS` for one that is installed. `v-delete-web-php` refuses a version that is not installed, is used by a web domain or is the default; when it removes a version, it purges all its packages (extensions included) and its `php.ini` backups.
- **One `php.ini` per version.** `v-change-sys-service-config php` is refused: it wrote one file over every `php.ini`. Edit `php.ini` per version and SAPI on the PHP page or with `v-change-sys-php-ini`. The configuration editors of the php and php-fpm services lead to the PHP page.

### Added

- **PHP Manager** (Server → PHP). A catalog of PHP versions with release and end-of-life dates and status badges; extensions installed and removed per version; `php.ini` for FPM and CLI edited per version with validation, backups and rollback; PHP-FPM profiles (`default`, `small`, `medium`, `large` and custom ones) applied by the package and chosen per web domain on its new PHP tab. Packages say which PHP versions and profiles their users may use (`PHP_VERSIONS`, `PHP_PROFILES`, `PHP_DEFAULT_PROFILE`; by default every installed version that is not end of life and the `default` profile).
- **Malware scan** (plugin `malware-scan`, off by default). A scanner for PHP webshells with 26 rules, run as the hosting user at low priority, on demand and every night (incremental, at 03:40). Findings are reported, never quarantined on their own; users and administrators quarantine, restore or ignore them on the Malware scan pages and are notified of new findings. Turn it on in Server settings → Plugins.
- **REST API v2.** 23 operations for the PHP Manager (`/system/php`, `/users/{user}/web-domains/{domain}/php`) and 11 for the malware scan in the new `security` area, with the permission set `v2-security` (also part of `v2-read` and `v2-write`).

### Changed

- `v-list-sys-php-versions` lists the status, dates, availability and number of extensions of each version, and PHP versions may have two-digit parts (for example 8.10).
- Users and resellers see only the backend templates of the PHP versions their package allows.
- Every upgrade runs `v-add-sys-php-profiles`: it rewrites pool templates you have not changed, and the pools of existing web domains stay the same. Custom backend templates without the `%php_profile%` placeholder ignore profiles, and the PHP tab of the domain says so.

### Not changed

- The redirects of `/app/*` and of the 1.x page paths (see 2.0.0) still answer in this release.

## 2.0.0 — 2026-10-12

The panel app (Laravel) serves every page at the root URL, and the HestiaCP web interface of 1.x is gone. See the [E8 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-06-004-e8-legacy-retirement-design.md).

### Breaking changes

- **Root URL.** Panel pages moved from `/app/…` to `/…` (for example `/app/web/domains` is now `/web/domains`). `/app/*` and the 1.x page paths (`/list/web/`, `/edit/user/?user=…`, `/fm/` …) answer with a redirect, so bookmarks keep working; the redirects will be removed in a later release, announced in this changelog. Custom nginx includes or reverse proxies that route `/app/` must be updated.
- **`web/` removed.** The directory `/usr/local/arcpanel/web` no longer exists. What the commands still use moved to `/usr/local/arcpanel/lib`: Quick Install installers (`lib/quickinstall`), 2FA helpers (`lib/2fa`), the mail wrapper (`lib/mail-wrapper.php`, `$SENDMAIL`), the API script (`lib/api/index.php`, still at the URL `/api/`), translations (`lib/locale`), default logos (`lib/images`) and nginx error pages (`lib/error`). Scripts of your own that call `web/inc/mail-wrapper.php` must call `lib/mail-wrapper.php`.
- **White Label files** go to `/usr/local/arcpanel/data/white-label/` (was `web/images/custom/`); they are still served under `/images/custom/`.
- **RRD data** lives in `/usr/local/arcpanel/data/rrd/` (was `web/rrd/`).
- **FileGator removed.** The file manager of the panel replaced it in 1.x; `v-add-sys-filemanager` and `v-delete-sys-filemanager` now only turn `FILE_MANAGER` on and off.
- **Themes.** The CSS themes of the HestiaCP interface (`dark`, `default`, `flat`, `vestia` and custom themes in `web/css/themes/custom`) are gone. `THEME` now holds a panel theme: `nocturne`, `light` or `auto`. Custom CSS themes are not supported any more; the panel's look follows the design tokens of `web-app/resources/css`.
- **Switches removed.** `ARCPANEL_APP_PAGES` and `ARCPANEL_APP_LOGIN` no longer exist; there is no legacy interface to switch back to.
- **ArcPanel names everywhere** ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md), [full rename spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-06-005-e9-full-rename-design.md)). Every internal name changed, and the 1.x aliases are gone:
  - paths: `/usr/local/hestia` → `/usr/local/arcpanel`, `/etc/hestiacp/hestia.conf` → `/etc/arcpanel/arcpanel.conf`, `conf/hestia.conf` → `conf/arcpanel.conf`, `/var/log/hestia` → `/var/log/arcpanel`;
  - shell: `$HESTIA` → `$ARCPANEL`, `HESTIA_*` → `ARCPANEL_*`, `check_hestia_demo_mode` → `check_arcpanel_demo_mode`;
  - commands: `v-*-hestia-*` → `v-*-arcpanel-*`; installer `hst-install*.sh` → `arcpanel-install*.sh`; build script `src/hst_autocompile.sh` → `src/arcpanel-build.sh` (flag `--arcpanel`);
  - packages `arcpanel`, `arcpanel-nginx`, `arcpanel-php`, `arcpanel-web-terminal` (they conflict with the `hestia*` packages); users `arcpanelweb` and `arcpanelmail`, group `arcpanel-users`; services `arcpanel`, `arcpanel-web-terminal`, `arcpanel-iptables`;
  - runtime identifiers: firewall chain `ARCPANEL`, API response header `ArcPanel-Exit-Code`, DNS cluster values `arcpanel` and `arcpanel-zone`, Roundcube password driver `arcpanel`, gettext domain `arcpanel`, PHP namespace `ArcPanel\`.
- **Backups.** Archives keep their objects in `./arcpanel/`. Archives of HestiaCP and ArcPanel 1.x (`./hestia/`) and of VestaCP (`./vesta/`) still restore; the administrator's cron jobs are moved to the new paths.
- **Third-party tools.** WHMCS and Blesta modules, scripts and API clients written for HestiaCP must use the new paths and command names, and read `ArcPanel-Exit-Code`; the API request format is unchanged.
- **Fresh installs only.** There is no upgrade from ArcPanel 1.x or from HestiaCP to 2.0; install 2.0 on a new server and move the users with backups ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)).
- **One version number.** `VERSION` in `arcpanel.conf` is the ArcPanel version; `ARCPANEL_VERSION` and `install/upstream.conf` are gone. `v-list-sys-info` drops the `HESTIA` column. Upgrade steps of later releases go into `install/upgrade/versions/<version>.sh`, and the HestiaCP steps up to 1.10.5 are removed.

### Added

- **New panel.** Every page runs in the panel app, with a new design system, the `nocturne`, `light` and `auto` themes, and sidebar hubs that group related pages in tabs: databases, cron, DNS, mail, backups with a restic snapshot browser, firewall, IP addresses, access and SSH keys, logs, statistics and graphs, the web terminal, services, server settings and configuration editors, updates, search and the CSR generator.
- **Resellers.** A third role between administrator and user: a reseller owns a tree of customers inside the limits of its own package.
- **Port registry.** The administrator assigns ports to users; apps bind only the ports they were given, and public ports open the matching firewall rule.
- **Node.js.** Node.js versions and global packages per user, and Node.js apps run by a per-user pm2 behind a web domain.
- **Docker apps** from a curated catalog, every container run as the hosting user.
- **File Manager v2**, every file operation run as the user.
- **System packages** from the distribution archive (Python 3, Go, Ruby, FFmpeg, ImageMagick, build tools) on the admin page Server → Packages, with runtime badges in the template picker.
- **Web templates.** Template metadata, template variables per web domain and sixteen new templates (among them Python, Go, Ruby, Laravel Octane and hardened WordPress).
- **Secret store.** Encrypted secrets per user, referenced as `{{secret:NAME}}` in the environments of Node.js and Docker apps instead of plain values.
- **REST API v2** under `/api/v2/` with permission sets per key, and OpenAPI documents for API v1 and v2 (`docs/api/`).
- Download of the last 5000 lines of a web domain log from the log page.

### Security

- Hardening from an internal security review: commands that run as root no longer follow paths that users control; login and two-factor attempts are rate limited; password reset links are built from the panel's configuration; changing a password ends the sessions opened before it; sensitive changes to one's own account ask for the password again (sudo mode); the web terminal opens only with a short-lived, single-use ticket signed by the panel.

### Changed

- phpPgAdmin 7.14.6 (`install/deb/pga/phppgadmin`) and the `quoteshellarg` helper (`lib/quoteshellarg`) ship with ArcPanel. Installs and upgrades no longer download them from the HestiaCP GitHub organisation.
