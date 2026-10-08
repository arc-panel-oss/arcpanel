# Changelog

All notable changes to ArcPanel are listed here. ArcPanel is based on HestiaCP 1.10.5 and, since 2.0, a hard fork of it ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)).

## 2.0.0 — unreleased

The panel app (Laravel) serves every page at the root URL, and the HestiaCP web interface of 1.x is gone. See the [E8 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-06-004-e8-legacy-retirement-design.md).

### Breaking changes

- **Root URL.** Panel pages moved from `/app/…` to `/…` (for example `/app/web/domains` is now `/web/domains`). For this release, `/app/*` and the 1.x page paths (`/list/web/`, `/edit/user/?user=…`, `/fm/` …) answer with a redirect, so bookmarks keep working; the redirects are removed in 2.1. Custom nginx includes or reverse proxies that route `/app/` must be updated.
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

- Download of the last 5000 lines of a web domain log from the log page.

### Changed

- phpPgAdmin 7.14.6 (`install/deb/pga/phppgadmin`) and the `quoteshellarg` helper (`lib/quoteshellarg`) ship with ArcPanel. Installs and upgrades no longer download them from the HestiaCP GitHub organisation.
