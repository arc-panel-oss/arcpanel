# Changelog

All notable changes to ArcPanel are listed here. ArcPanel is based on HestiaCP; the upstream base version is in `install/upstream.conf`.

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

### Upgrading from 1.x

The package upgrade runs `install/upgrade/arcpanel/2.0.0.sh`, which:

1. moves `web/rrd/` to `data/rrd/` and `web/images/custom/` to `data/white-label/`;
2. keeps the API disabled if `v-change-sys-api disable` had disabled it;
3. maps `THEME` of the server and of every user: `dark` → `nocturne`, `default`, `vestia` and `flat` → `light`, anything else → `nocturne`;
4. removes `ARCPANEL_APP_PAGES` and `ARCPANEL_APP_LOGIN` from `hestia.conf`;
5. deletes the 1.x entries of `web/` by name; files you added there stay, and the upgrade log names them;
6. applies the White Label logos, rebuilds the panel app caches and restarts the panel.

The new `nginx.conf` ships with `arcpanel-nginx` 1.30.5-2, which `arcpanel` 2.0.0 requires (`Breaks: arcpanel-nginx (<< 1.30.5-2)`), so `apt upgrade` updates both.

### Rolling back to 1.x

1. Install the 1.x packages from the APT cache: `apt install arcpanel=1.* arcpanel-nginx=1.30.5-1*`.
2. Move `data/rrd/` back to `web/rrd/` and `data/white-label/` to `web/images/custom/`.
3. Restore `/usr/local/hestia/conf/` from the backup in `/root/hst_backups/` made by the upgrade.
4. Run `v-change-sys-config-value ARCPANEL_APP_PAGES yes` and `systemctl restart arcpanel`.

Users whose theme was mapped see the `nocturne` theme on 1.x until they pick another one.

### Added

- `test/upstream-diff.sh` and the `Upstream diff` CI job: upstream files change only by the exceptions recorded in `test/upstream-exceptions.txt`, and no code references the removed `web/` directory.
- `.arcpanel/retired-paths` and `scripts/upstream-merge-cleanup.sh` for upstream merges after the removal of `web/`.
- Download of the last 5000 lines of a web domain log from the log page.

### Changed

- phpPgAdmin 7.14.6 (`install/deb/pga/phppgadmin`) and the `quoteshellarg` helper (`lib/quoteshellarg`) ship with ArcPanel. Installs and upgrades no longer download them from the HestiaCP GitHub organisation.
