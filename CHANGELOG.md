# Changelog

All notable changes to ArcPanel are listed here. ArcPanel is based on HestiaCP 1.10.5 and, since 2.0, a hard fork of it ([ADR-0007](docs/adr/0007-full-rename-and-hard-fork.md)).

## 2.0.0 — unreleased

The panel app (Laravel) serves every page at the root URL, and the HestiaCP web interface of 1.x is gone. See the [E8 spec](docs/features/specs/2026-10-06-004-e8-legacy-retirement-design.md).

### Breaking changes

- **Root URL.** Panel pages moved from `/app/…` to `/…` (for example `/app/web/domains` is now `/web/domains`). For this release, `/app/*` and the 1.x page paths (`/list/web/`, `/edit/user/?user=…`, `/fm/` …) answer with a redirect, so bookmarks keep working; the redirects are removed in 2.1. Custom nginx includes or reverse proxies that route `/app/` must be updated.
- **`web/` removed.** The directory `/usr/local/hestia/web` no longer exists. What the commands still use moved to `/usr/local/hestia/lib`: Quick Install installers (`lib/quickinstall`), 2FA helpers (`lib/2fa`), the mail wrapper (`lib/mail-wrapper.php`, `$SENDMAIL`), the API script (`lib/api/index.php`, still at the URL `/api/`), translations (`lib/locale`), default logos (`lib/images`) and nginx error pages (`lib/error`). Scripts of your own that call `web/inc/mail-wrapper.php` must call `lib/mail-wrapper.php`.
- **White Label files** go to `/usr/local/hestia/data/white-label/` (was `web/images/custom/`); they are still served under `/images/custom/`.
- **RRD data** lives in `/usr/local/hestia/data/rrd/` (was `web/rrd/`).
- **FileGator removed.** The file manager of the panel replaced it in 1.x; `v-add-sys-filemanager` and `v-delete-sys-filemanager` now only turn `FILE_MANAGER` on and off.
- **Themes.** The CSS themes of the HestiaCP interface (`dark`, `default`, `flat`, `vestia` and custom themes in `web/css/themes/custom`) are gone. `THEME` now holds a panel theme: `nocturne`, `light` or `auto`. Custom CSS themes are not supported any more; the panel's look follows the design tokens of `web-app/resources/css`.
- **Switches removed.** `ARCPANEL_APP_PAGES` and `ARCPANEL_APP_LOGIN` no longer exist; there is no legacy interface to switch back to.
- **Fresh installs only.** There is no upgrade from ArcPanel 1.x or from HestiaCP to 2.0; install 2.0 on a new server and move the users with backups ([ADR-0007](docs/adr/0007-full-rename-and-hard-fork.md)).
- **One version number.** `VERSION` in `hestia.conf` is the ArcPanel version; `ARCPANEL_VERSION` and `install/upstream.conf` are gone. `v-list-sys-info` drops the `HESTIA` column. Upgrade steps of later releases go into `install/upgrade/versions/<version>.sh`, and the HestiaCP steps up to 1.10.5 are removed.

### Added

- Download of the last 5000 lines of a web domain log from the log page.

### Changed

- phpPgAdmin 7.14.6 (`install/deb/pga/phppgadmin`) and the `quoteshellarg` helper (`lib/quoteshellarg`) ship with ArcPanel. Installs and upgrades no longer download them from the HestiaCP GitHub organisation.
