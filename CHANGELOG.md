# Changelog

All notable changes to ArcPanel are listed here. ArcPanel is based on HestiaCP 1.10.5 and, since 2.0, a hard fork of it ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)).

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
