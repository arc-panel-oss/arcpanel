# ArcPanel · Nocturne templates

Static HTML prototypes for the new ArcPanel shell (epic E2) in **Nocturne**, a dark-first theme built on the ArcPanel brand kit (`docs/brand`, direction A "Arch"). Open `index.html` in a browser. There is no build step. Sidebar links go to the real pages.

## Pages

| Folder / file     | Pages                                                                                                                                                                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `login.html`      | Sign-in                                                                                                                                                                                                                        |
| `logo.html`       | Logo sheet: lockup on dark and light, icon sizes, palette                                                                                                                                                                      |
| `components.html` | Style guide for every `x-ap-*` component in docs/design/05 §5                                                                                                                                                                  |
| `admin/`          | dashboard, users, web, dns, mail, db, cron, backups, apps, store (App Store + system packages), files, settings, services, ips, firewall, ports, updates (+ updates-states), logs, templates                                   |
| `reseller/`       | dashboard, customers, customer (detail), customer-new (wizard), packages, ports, stats, backups, logs, account, and "My hosting": web, dns, mail, db, cron, files, apps, store                                                 |
| `user/`           | dashboard, web, web-edit (domain settings + template picker), dns (zone records), mail, db, cron, backups (restore), apps (+ Node runtimes, My ports), store, app (Node app detail: logs, environment, deploy), files, account |

The File Manager (docs/design/06) has 6 screens for each role: `files` (browse), `files-grid`, `files-upload`, `files-edit`, `files-search` and `files-snapshots`. `files-dialogs.html` holds the shared dialogs: permissions, move, delete, extract, compress, save conflict, too large to edit and new folder.

- **User:** works in their own home folder.
- **Admin:** browses any user's files through an account picker. A "Browsing files of …" banner shows whose files they are.
- **Reseller:** `files` is their own home folder, with a picker for choosing a customer. `files-customer` and the other screens show customer `minh-kitchen` (a Laravel site).

Admin module pages list objects across all owners (with an Owner column). User and reseller "My hosting" pages show the account's own objects. This follows `OwnerScope` in docs/design/03.

## Assets

| File                      | Use                                                                                              |
| ------------------------- | ------------------------------------------------------------------------------------------------ |
| `assets/css/nocturne.css` | Tokens (`--ap-*`, dark plus `[data-theme="light"]`) and component styles                         |
| `assets/js/nocturne.js`   | Prototype behaviour: theme toggle, phone drawer, Ctrl K palette, tabs, password reveal, bulk bar |
| `assets/logo/`            | `logo.svg` (dark backgrounds), `logo-light.svg`, `icon.svg` and PNG exports (16–512, 180 touch)  |

## Notes

- Sidebars follow the role table in docs/design/03 §9.1. Dashboards follow docs/design/05 §4.4. Ports follow docs/design/04 §8, apps follow 07 §6.2 and 08 §5.8, and the template picker follows 09 §10.
- The logo keeps the brand's arch "A" and wordmark geometry. Nocturne adds the night tile `#0f1320`, the arch in `#9db0ff` and one starlight dot in `#f2b451`.
- Class names (`ap-btn`, `ap-table`, `ap-usage` …) map one to one onto the planned Blade components (`<x-ap-button>`, `<x-ap-table>`, `<x-ap-usage-bar>` …).
- The Updates page follows the E33 phase 1 spec §8 (docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md): release manifest, update policy, pre-flight dialog with attention items, progress, history and rollback. `updates-states.html` shows the paused, manifest, running and read-only banners and the reason lines.
- Fonts: Inter and JetBrains Mono from Google Fonts. The panel should bundle them locally (brand.md §5).
- All data on the pages is sample data.
