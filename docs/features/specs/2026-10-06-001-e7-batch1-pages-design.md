# E7 batch 1 — Databases, Cron, DNS, Mail and Backups pages — design

**Status:** Implemented (2026-10-06, see §15) · **Epic:** E7 · **Base design:** [10 Page migration](../../design/10-page-migration-and-retirement.md) §3–§4 (modules 8–12), [06 File Manager](../../design/06-file-manager-v2.md) (snapshot browser) · **Previous specs:** [E1-S2](./2026-10-05-002-e1-s2-dashboard-web-domains-design.md) (Web Domains pattern, `ARCPANEL_APP_PAGES`), [E11](./2026-10-05-003-e11-reseller-design.md) (owner scoping), [E3](./2026-10-05-008-e3-file-manager-design.md) (file manager island), [E13](./2026-10-05-006-e13-node-manager-design.md) and [E4](./2026-10-05-009-e4-docker-apps-design.md) (exclusion toggles) · **Roadmap:** [§3 E7](../../roadmap.md)

## 1. Summary

The first E7 batch moves the five Q3 modules of design document 10 from the legacy PHP pages to the Laravel panel app: Databases, Cron, DNS, Mail and Backups (tar archives, restic snapshots and exclusions). Every page is owner-scoped for admin, reseller and user from the start, follows the Web Domains pattern of E1-S2 and keeps the legacy behaviour, field names, command order and message strings, except for the legacy defects listed in §11. The restic snapshot browser deferred by E3 lands as a read-only mode of the file manager island, and the backup exclusion toggles deferred by E13 (`~/.nvm`) and E4 (Docker apps) land on the new Exclusions tab. One additive command, `v-schedule-user-restore-file-restic`, makes single-file restores from a snapshot work.

## 2. Decisions taken in the brainstorm

| #   | Question                          | Decision                                                                                                                                                                                                                                                                                                                             |
| --- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| D1  | Spec and milestones               | **One spec, six milestones** in order of growing risk: M0 shared foundation, M1 Databases, M2 Cron, M3 DNS, M4 Mail, M5 Backups.                                                                                                                                                                                                     |
| D2  | Parity                            | **Legacy parity plus what the design documents already name** (DKIM DNS hints, DNSSEC public view, cron builder, restic views, phpMyAdmin SSO). No new `v-*` command unless something is missing; the only one is `v-schedule-user-restore-file-restic` (§9.4). Legacy defects are fixed, not copied (§11).                          |
| D3  | Cut-over                          | **The single `ARCPANEL_APP_PAGES` switch.** Each module adds its 302 redirects and menu links when it lands; turning the switch off brings every legacy page back.                                                                                                                                                                   |
| D4  | Restic snapshot browser           | **Read-only mode of the file manager island** at `/app/files/{user}?snapshot=<id>&path=…` with a "Restore" action (06 §7). The Backups page lists snapshots and restores whole objects.                                                                                                                                              |
| D5  | Backup exclusions                 | **Migrate the page and add the toggles**: "Node.js runtimes" (`.nvm`) and one toggle per Docker app (`apps/<name>`), merged into the `USER` key and written with the existing `v-update-user-backup-exclusions`.                                                                                                                     |
| D6  | Long operations                   | **Same as legacy:** backup, restore and remote download go to the Hestia backup queue; the page flashes "scheduled" and the result arrives as a notification or e-mail. Job tracking waits for E15.                                                                                                                                  |
| D7  | Reseller scope                    | **Owner-scoped like Web Domains:** admin sees everything, a reseller its tree (owner column), a user its own objects; objects outside the tree answer 404. Resellers list, schedule, restore and download their customers' backups.                                                                                                  |
| D8  | Test data on `arcpanel-test`      | `test/e2e/prepare.sh` creates mail, DNS, database, cron and backup data for `arce2euser` and a local restic repository for the snapshot tests. Antispam, antivirus and PostgreSQL branches are covered by Pest fixtures only (the test server has none of them).                                                                     |
| D9  | Out of scope                      | Backup and restic host settings (Server page, batch 2), DNS cluster and remote DNS hosts, database hosts management, Quick Install Apps.                                                                                                                                                                                             |
| D10 | Permissions                       | **One rule set for all five modules:** capabilities `<module>.view/create/update/delete` with the `ALL_ROLES` scope; suspend and unsuspend need `update` (legacy differs between bulk and single endpoints); rebuild is admin-only; mail rate limits are admin-only; DNS templates follow `POLICY_USER_EDIT_DNS_TEMPLATES` or admin. |
| D11 | Admin warning on create           | The legacy "It is strongly advised to {create a standard user account}…" gate becomes an `x-ap-alert` shown when the chosen owner is an administrator; it does not hide the form.                                                                                                                                                    |
| D12 | Credential e-mails (DB, mail box) | **`CredentialsMailer`** sends through the local sendmail binary with the same `database_credentials` / `email_credentials` templates and fallbacks as legacy. It is the only place where the panel reads a file outside a `v-list-*` command (read-only, the template directory). The password appears only in the message body.     |

## 3. Milestones

| #   | Milestone  | Content                                                                                                                                       | Done when                                                                                                       |
| --- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| M0  | Foundation | §5: `BulkRunner` (Web Domains moved onto it), owner list helper, `CredentialsMailer`, capabilities, hand-off helpers in `arcpanel-routes.php` | Web Domains tests pass unchanged on the new runner; new unit tests green                                        |
| M1  | Databases  | §6                                                                                                                                            | Pest for four principals; BATS redirects; Playwright add, edit, bulk suspend, delete, phpMyAdmin SSO link       |
| M2  | Cron       | §7                                                                                                                                            | as M1, plus reports on/off                                                                                      |
| M3  | DNS        | §8                                                                                                                                            | as M1 for zones and records, plus the DNSSEC view                                                               |
| M4  | Mail       | §9                                                                                                                                            | as M1 for domains and accounts, plus DNS hints and the client configuration panel                               |
| M5  | Backups    | §10: tar backups, exclusions with toggles, restic snapshots, `v-schedule-user-restore-file-restic`, snapshot mode of the file manager island  | Playwright: create a backup, restore one item, browse a snapshot and restore one file; BATS for the new command |

Each milestone ends with a green run and a `feat(e7): …` commit.

## 4. Scope

### In scope

1. Laravel modules Databases, Cron, DNS, Mail and Backups with list, add, edit, single and bulk actions, owner-scoped.
2. `BulkRunner`, owner list helper, `CredentialsMailer`.
3. phpMyAdmin single sign-on and database dump download.
4. DNSSEC public key view; mail DNS hints; mail client configuration panel.
5. Backups: tar list, detail, restore, delete, download, remote download; restic list, detail, restore, delete, snapshot; exclusions with `.nvm` and Docker app toggles.
6. Snapshot mode of the file manager island; `v-schedule-user-restore-file-restic`.
7. Hand-off: `arc_route`, `arc_redirect_if_migrated`, legacy menu links, `LegacyRoutes`, `Navigation` sections, palette links and actions, Dashboard usage links.
8. Tests (Pest, Vitest, BATS, Playwright) and the documentation changes of §13.

### Out of scope

- Backup host and restic host settings, `BACKUP_*` server keys (Server page, E7 batch 2).
- DNS cluster, remote DNS hosts, database hosts management.
- Quick Install Apps, Firewall, IPs, Server, Access keys, Logs, Statistics (E7 later batches).
- Job tracking for queued backups (E15).
- Deleting legacy pages (E8).
- Changes to existing upstream commands.

### Done when

1. With `ARCPANEL_APP_PAGES=yes`, `/list/db/`, `/add/db/`, `/edit/db/?database=…`, `/list/cron/`, `/add/cron/`, `/edit/cron/?job=…`, `/list/dns/[?domain=…]`, `/add/dns/[?domain=…]`, `/edit/dns/?domain=…[&record_id=…]`, `/list/mail/[?domain=…]`, `/add/mail/[?domain=…]`, `/edit/mail/?domain=…[&account=…]`, `/list/backup/`, `/list/backup/incremental/` and `/edit/backup/exclusions/` answer 302 to the new pages; with `no` they answer 200.
2. Admin, reseller and user see the objects of their scope, with an owner column when more than one owner is visible; another tree answers 404.
3. Every legacy action of the five modules is available on the new pages with the same command calls (§6–§10), apart from the deviations of §11.
4. A user restores a single file from a restic snapshot through the file manager in snapshot mode.
5. The `.nvm` and Docker app toggles change the `USER` exclusions and keep the other keys.
6. The Navigation sidebar, the command palette and the Dashboard usage bars link to the new pages.

## 5. Foundation (M0)

### 5.1 Capabilities

`Capabilities::MATRIX` gains, with the `ALL_ROLES` scope (admin all, reseller tree, user self):

| Module    | Capabilities                                                                                     |
| --------- | ------------------------------------------------------------------------------------------------ |
| Databases | `db.view`, `db.create`, `db.update`, `db.delete`                                                 |
| Cron      | `cron.view`, `cron.create`, `cron.update`, `cron.delete`                                         |
| DNS       | `dns.view`, `dns.create`, `dns.update`, `dns.delete`, `dns.templates.edit`                       |
| Mail      | `mail.view`, `mail.create`, `mail.update`, `mail.delete`, `mail.rate.edit` (admin)               |
| Backups   | `backups.view`, `backups.create`, `backups.restore`, `backups.delete`, `backups.exclusions.edit` |

The existing navigation-only `*.view` entries move from `OWN` to `ALL_ROLES`. A Pest test per module checks that every route named `<module>.` has its capabilities.

### 5.2 Policies

`DatabasePolicy`, `CronPolicy`, `DnsPolicy`, `MailPolicy`, `BackupPolicy` follow `WebDomainPolicy`: `viewAny`, `view`, `create`, `update`, `delete`, `suspend` (= `update`), `rebuild` (= `update` and admin), an `abilities()` map for the views, plus `DnsPolicy::updateTemplates` and `MailPolicy::updateRateLimit`. No view, Alpine module or service compares user names.

### 5.3 `BulkRunner`

`App\Services\Bulk\BulkRunner::run(array $items, Closure $command, array $restart)` takes items that were already authorised, runs each with `RESTART=no` and `Options::allowFailure()`, collects `done[]` and `failed[label => message]`, runs the restart commands once at the end when something ran, and returns a `BulkResult` with `flash()` giving the existing `%d done, %d failed` messages. `WebDomainService::bulk` uses it without a change in behaviour. Controllers check every item (`assertVisible` → 404, policy → 403) before calling the runner.

### 5.4 Owner list helper

`App\Services\OwnerList` returns the owners whose objects a list page shows (`OwnerScope::visibleUsers()` or every user for admin), validates `?owner=`, and tells the view whether to show the owner column. The create pages reuse the Web Domains owner picker as a Blade partial `<x-ap-owner-picker>`.

### 5.5 `CredentialsMailer`

`App\Services\Mail\CredentialsMailer::send(string $to, string $template, string $language, array $vars, string $fallbackSubject, string $fallbackBody)` reads `$HESTIA/data/templates/email/<language>/<template>.html` (falling back to `en`), takes the subject from `<subject>…</subject>`, replaces `{{var}}` placeholders and sends through Symfony Mailer's sendmail transport. From address and name come from `FROM_EMAIL` / `FROM_NAME` / `APP_NAME` in `v-list-sys-config`, else `noreply@<hostname>`. Failures are flashed as a warning and never block the action.

### 5.6 Hand-off helpers

`web/inc/arcpanel-routes.php` gains the mappings of §4 "Done when" 1 in `arc_route()` and `arc_redirect_if_migrated()`; each legacy list, add and edit page of the five modules calls `arc_redirect_if_migrated()` after `main.php`; `web/templates/includes/panel.php` wraps their menu links with `arc_route()`. `LegacyRoutes` loses the five entries once each route exists; `Navigation` items get their `section`.

## 6. Databases (M1)

### 6.1 Routes (`/app/db`, names `db.*`)

| Method and path                              | Name              | Commands                                                                                 |
| -------------------------------------------- | ----------------- | ---------------------------------------------------------------------------------------- |
| `GET /`                                      | `db.index`        | `v-list-databases USER json` per visible owner                                           |
| `GET /create`, `POST /`                      | `db.create/store` | `v-list-database-hosts json`; `v-add-database USER DB DBUSER <secret> TYPE HOST CHARSET` |
| `POST /bulk`                                 | `db.bulk`         | `v-{suspend,unsuspend,delete,rebuild}-database USER DB` (rebuild: admin)                 |
| `GET /{user}/{database}/edit`, `POST …/edit` | `db.edit/update`  | `v-list-database`; `v-change-database-user`, `v-change-database-password`                |
| `POST …/suspend`, `…/unsuspend`, `DELETE …/` | `db.*`            | single commands                                                                          |
| `POST …/download`                            | `db.download`     | `v-dump-database USER DB <file> gzip`, served with `X-Accel-Redirect`                    |
| `POST …/phpmyadmin`                          | `db.phpmyadmin`   | builds the SSO link and redirects (§6.3)                                                 |

### 6.2 Pages

- **List:** columns Name, Disk, Type, Username, Hostname, Charset, Owner (when shown); row actions Edit (not suspended), phpMyAdmin (§6.3), Download, Suspend/Unsuspend, Delete; bulk Suspend, Unsuspend, Delete, Rebuild (admin); toolbar "Add Database" and the phpMyAdmin / phpPgAdmin buttons (links use `DB_PMA_ALIAS` / `DB_PGA_ALIAS`, disabled with the legacy help link when the panel is reached by IP).
- **Add:** Database (prefix hint `user_`), Username (hint "Maximum 32 characters length, including prefix"), Password (generator and meter, legacy rule `/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(.){8,}$/`), "Send login credentials to e-mail", and under Advanced Options Type (from `DB_SYSTEM`), Host (from `v-list-database-hosts`) and Charset (legacy list, default `utf8mb4`). Success flash "Database {%s} has been created successfully. / {Open %s}".
- **Edit:** database, type, host and charset read-only; Username without prefix; Password optional. Steps run only for changed values; "Changes have been saved."

### 6.3 phpMyAdmin single sign-on

Shown for MySQL databases when `PHPMYADMIN_KEY` is set and the panel is not reached by IP. The controller builds the legacy token (`password_hash(DB . user . client IP . time . PHPMYADMIN_KEY)`), redirects to `<pma>/hestia-sso.php?…` and never logs the key or the token. phpPgAdmin is a plain link.

## 7. Cron (M2)

### 7.1 Routes (`/app/cron`, names `cron.*`)

| Method and path                                  | Name                | Commands                                                                    |
| ------------------------------------------------ | ------------------- | --------------------------------------------------------------------------- |
| `GET /`                                          | `cron.index`        | `v-list-cron-jobs USER json`; `v-list-user USER json` (`CRON_REPORTS`)      |
| `GET /create`, `POST /`                          | `cron.create/store` | `v-get-sys-timezone`; `v-add-cron-job USER MIN HOUR DAY MONTH WDAY CMD`     |
| `POST /bulk`                                     | `cron.bulk`         | `v-{suspend,unsuspend,delete}-cron-job USER JOB no`, then `v-restart-cron`  |
| `GET /{user}/{job}/edit`, `POST …/edit`          | `cron.edit/update`  | `v-list-cron-job`; `v-change-cron-job USER JOB MIN HOUR DAY MONTH WDAY CMD` |
| `POST …/suspend`, `…/unsuspend`, `DELETE …/`     | `cron.*`            | single commands                                                             |
| `POST /{user}/reports`, `DELETE /{user}/reports` | `cron.reports.*`    | `v-add-cron-reports USER`, `v-delete-cron-reports USER`                     |

### 7.2 Pages

List columns Command, Minute, Hour, Day, Month, Day of Week, Owner; the toolbar shows "Enable Notifications" or "Disable Notifications" for the selected owner (or the only one). The add and edit forms use `x-ap-cron-builder` (the "Every 3 days" label is fixed) and show the system time. All five schedule fields and the command are required on add and on edit.

## 8. DNS (M3)

### 8.1 Routes (`/app/dns`, names `dns.*`)

| Method and path                                                       | Name                 | Commands                                                                                                                                                                                               |
| --------------------------------------------------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET /`                                                               | `dns.index`          | `v-list-dns-domains USER json`                                                                                                                                                                         |
| `GET /create`, `POST /`                                               | `dns.create/store`   | `v-list-user-ips`, `v-list-user-ns`, `v-list-dns-templates`; `v-add-dns-domain USER DOMAIN IP NS1…NS8 no DNSSEC`, then `-tpl`, `-exp`, `-ttl` when they differ from the defaults, then `v-restart-dns` |
| `POST /bulk`                                                          | `dns.bulk`           | `v-{suspend,unsuspend,delete,rebuild}-dns-domain USER DOMAIN no`, then `v-restart-dns`                                                                                                                 |
| `GET /{user}/{domain}/edit`, `POST …/edit`                            | `dns.edit/update`    | `v-list-dns-domain`; `v-change-dns-domain-{ip,tpl,soa,exp,ttl,dnssec}` in that order, then `v-restart-dns` once                                                                                        |
| `POST …/suspend`, `…/unsuspend`, `DELETE …/`                          | `dns.*`              | single commands                                                                                                                                                                                        |
| `GET …/dnssec`                                                        | `dns.dnssec`         | `v-list-dnssec-public-key USER DOMAIN json`                                                                                                                                                            |
| `GET …/records`                                                       | `dns.records.index`  | `v-list-dns-records USER DOMAIN json`                                                                                                                                                                  |
| `POST …/records`                                                      | `dns.records.store`  | `v-add-dns-record USER DOMAIN RECORD TYPE VALUE PRIORITY '' yes TTL`                                                                                                                                   |
| `POST …/records/{id}`                                                 | `dns.records.update` | `v-change-dns-record USER DOMAIN ID RECORD TYPE VALUE PRIORITY yes TTL`; `v-change-dns-record-id` when the ID changes                                                                                  |
| `POST …/records/bulk`                                                 | `dns.records.bulk`   | `v-{suspend,unsuspend,delete}-dns-record USER DOMAIN ID no`, then `v-restart-dns`                                                                                                                      |
| `POST …/records/{id}/suspend`, `…/unsuspend`, `DELETE …/records/{id}` | `dns.records.*`      | single commands                                                                                                                                                                                        |

### 8.2 Pages

- **Zones list:** Name (with `⇢ SRC`), Records, Template, TTL, SOA, DNSSEC, Expiration Date, Owner; row actions Records, Add Record, Edit, DNSSEC key (when enabled), Suspend/Unsuspend, Delete; bulk Suspend, Unsuspend, Delete, Rebuild (admin).
- **Add zone:** Domain (lower-cased, leading `www.` removed), IP (user IPs, NAT shown), Template (when allowed), DNSSEC (when `DNS_CLUSTER_SYSTEM=hestia-zone` and `SUPPORT_DNSSEC=yes`), Expiration Date (default today + 1 year), TTL (default 14400), Name servers 1–8 prefilled from `v-list-user-ns` with add and remove buttons (Alpine module `apNameServers`).
- **Edit zone:** the same fields with Domain read-only plus SOA.
- **Records:** columns Record, Type, Priority, TTL ("Default" when empty), IP or Value; add and edit in a drawer with Record (default `@`), Type (the 15 types of `is_dns_type_format_valid`), IP or Value (IP suggestions), Priority (MX and SRV only), TTL, and on edit the record ID. The CLI quotes, IDN-encodes and validates; its error is shown in the drawer.
- **DNSSEC:** read-only DNSKEY record, DS record, public key, key type (KSK 257 / ZSK 256), key tag, flag, algorithm with the legacy label table; copy buttons.

## 9. Mail (M4)

### 9.1 Routes (`/app/mail`, names `mail.*`)

| Method and path                                                               | Name                          | Commands                                                                                                 |
| ----------------------------------------------------------------------------- | ----------------------------- | -------------------------------------------------------------------------------------------------------- |
| `GET /`                                                                       | `mail.index`                  | `v-list-mail-domains USER json`                                                                          |
| `GET /create`, `POST /`                                                       | `mail.create/store`           | `v-list-sys-webmail`; `v-add-mail-domain USER DOMAIN ANTISPAM ANTIVIRUS DKIM`, reject, webmail, relay    |
| `POST /bulk`                                                                  | `mail.bulk`                   | `v-{suspend,unsuspend,delete,rebuild}-mail-domain USER DOMAIN`                                           |
| `GET /{user}/{domain}/edit`, `POST …/edit/{general,ssl,relay}`                | `mail.edit/update.*`          | §9.3                                                                                                     |
| `POST …/suspend`, `…/unsuspend`, `DELETE …/`                                  | `mail.*`                      | single commands                                                                                          |
| `GET …/dns`                                                                   | `mail.dns`                    | `v-list-user-ips`, `v-list-mail-domain-dkim-dns`                                                         |
| `GET …/accounts`                                                              | `mail.accounts.index`         | `v-list-mail-accounts USER DOMAIN json`                                                                  |
| `GET …/accounts/create`, `POST …/accounts`                                    | `mail.accounts.create/store`  | `v-add-mail-account USER DOMAIN ACCOUNT <secret> QUOTA`, aliases, forwards, fwd-only, rate limit (admin) |
| `GET …/accounts/{account}/edit`, `POST …/edit/{general,forwarding,autoreply}` | `mail.accounts.edit/update.*` | §9.4                                                                                                     |
| `POST …/accounts/bulk`, single actions                                        | `mail.accounts.*`             | `v-{suspend,unsuspend,delete}-mail-account USER DOMAIN ACCOUNT`                                          |

### 9.2 Domain pages

- **List:** Name, Accounts, Disk, Anti-Virus, Spam Filter, DKIM, SSL, Owner; row actions Accounts, Add Account, Open Webmail (`https://<WEBMAIL_ALIAS>.<domain>/` when webmail is on), DNS records, Edit, Suspend/Unsuspend, Delete; bulk Suspend, Unsuspend, Delete, Rebuild (admin).
- **Add:** Domain, Webmail client (default `roundcube`, "Disabled"), Spam filter and Reject spam (with `ANTISPAM_SYSTEM`), Anti-virus (with `ANTIVIRUS_SYSTEM`), DKIM, SMTP relay (host, port default 587, user, password). Order: add domain → reject → webmail (add or delete, only with `IMAP_SYSTEM` and `WEBMAIL_SYSTEM`) → relay.
- **DNS hints:** the legacy table (A for `mail.` and the webmail alias, MX, SPF, DMARC, DKIM TXT) with copy buttons and a link to the DNS zone when the user has one with that name.

### 9.3 Domain edit tabs

| Tab        | Fields                                                                                                         | Steps, in legacy order, only for changed values                                                                                                                           |
| ---------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| General    | Webmail, Catch-all, Rate limit (admin), Spam filter, Reject spam, Anti-virus, DKIM                             | antispam, antivirus, DKIM add/delete; catch-all delete; rate limit (`system` when empty); reject add/delete; catch-all change or add; webmail add or delete               |
| SSL        | SSL, Let's Encrypt, key algorithm (`ecdsa-256`, `ecdsa-384`, `rsa`), certificate, key, CA; certificate details | custom certificate change; Let's Encrypt re-issue on algorithm change; Let's Encrypt delete; SSL delete; Let's Encrypt add; custom SSL add (certificate and key required) |
| SMTP relay | Enabled, host, port, user, password (write-only)                                                               | re-add when host, user or port changed or a password was entered; delete when switched off                                                                                |

Switching from Let's Encrypt to a custom certificate still takes two saves (CLI behaviour); the SSL tab says so.

### 9.4 Account pages

- **List:** Name, Disk, Quota, Aliases, Forwarding, Auto Reply; row actions Open Webmail (`?_user=account@domain`), Edit, Suspend/Unsuspend, Delete; bulk Suspend, Unsuspend, Delete.
- **Add:** Account, Password (required unless "Do not store forwarded mail"), Quota (MB, unlimited toggle), Aliases, Forward to, "Discard all mail", "Do not store forwarded mail", Rate limit (admin), "Email login credentials to". Order: add → aliases → discard or forwards → fwd-only → rate limit → credentials e-mail. Success "Mail account {%s@%s} has been created successfully."
- **Edit tabs:** General (password, quota, rate limit, credentials e-mail), Aliases and forwarding (set differences for aliases and forwards; "Discard all mail" removes the other forwards and adds `:blackhole:` once), Autoreply (on/off and message).
- **Client configuration panel** on add and edit: username, password mirror, webmail URL, hostname `mail.<domain>`, IMAP 993/143, POP3 995/110, SMTP 465/587/25, "Normal password", with copy buttons.

## 10. Backups (M5)

### 10.1 Routes (`/app/backups`, names `backups.*`)

`GET /app/backups` redirects to `/app/backups/{effective user}`; admin and resellers switch owner with the picker. `{user}` uses the `effective-user` middleware.

| Method and path                                         | Name                        | Commands                                                                                         |
| ------------------------------------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------ |
| `GET /{user}`                                           | `backups.index`             | `v-list-user-backups USER json`; `v-list-user USER json` (`BACKUPS`, `BACKUPS_INCREMENTAL`)      |
| `POST /{user}`                                          | `backups.store`             | `v-schedule-user-backup USER`                                                                    |
| `GET /{user}/archives/{backup}`                         | `backups.show`              | `v-list-user-backup USER BACKUP json`                                                            |
| `POST /{user}/archives/{backup}/restore`                | `backups.restore`           | `v-schedule-user-restore USER BACKUP [WEB DNS MAIL DB CRON UDIR]` (all, or selected items)       |
| `DELETE /{user}/archives/{backup}`, `POST /{user}/bulk` | `backups.destroy/bulk`      | `v-delete-user-backup USER BACKUP`                                                               |
| `GET /{user}/archives/{backup}/download`                | `backups.download`          | `X-Accel-Redirect: /backup/<file>`, or `v-schedule-user-backup-download` when the file is remote |
| `GET /{user}/snapshots`                                 | `backups.snapshots.index`   | `v-list-user-backups-restic USER json`                                                           |
| `POST /{user}/snapshots`                                | `backups.snapshots.store`   | `v-schedule-user-backup-restic USER`                                                             |
| `GET /{user}/snapshots/{snapshot}`                      | `backups.snapshots.show`    | `v-list-user-backup-restic USER SNAPSHOT json`                                                   |
| `POST /{user}/snapshots/{snapshot}/restore`             | `backups.snapshots.restore` | `v-schedule-user-restore-restic USER SNAPSHOT [OBJECT VALUE]`                                    |
| `DELETE /{user}/snapshots/{snapshot}`                   | `backups.snapshots.destroy` | `v-delete-user-backup-restic USER SNAPSHOT`                                                      |
| `GET /{user}/exclusions`, `POST …`                      | `backups.exclusions.*`      | `v-list-user-backup-exclusions USER json`; `v-update-user-backup-exclusions USER <secret file>`  |

### 10.2 Pages

- **Archives tab:** File name, Date, Size, Type, Runtime; "Create backup" (queued; exit `E_EXISTS` shows "An existing backup is already running. Please wait for that backup to finish."); row actions Download, Restore, Delete; bulk Delete.
- **Archive detail:** sections Web, DNS, Mail, Database, Cron, User directories; restore all, one item or the selected items of one type.
- **Download:** the name must match `^<owner>\.[A-Za-z0-9_.-]+$` and appear in the owner's `v-list-user-backups`; the content type follows the extension (`.tar`, `.tar.gz`, `.tar.zst`); the check uses the effective owner, not the login.
- **Snapshots tab** (only with `BACKUPS_INCREMENTAL=yes`): ID, Date, Host; "Create snapshot"; detail with restore per object (web, dns, mail, db, cron) or full; "Browse files" opens the file manager in snapshot mode; Delete.
- **Exclusions tab:** textareas for web domains, mail domains, databases and user directories (one entry per line, legacy syntax and hints); DNS and Cron entries are kept as they are; toggle "Node.js runtimes (~/.nvm)" (shown when the owner's package has `NODE=yes` or `.nvm` is already excluded); one toggle per Docker app from `v-list-docker-apps` (`apps/<name>`); the note "Snapshots ignore exclusions." The save writes all six keys.

### 10.3 Snapshot mode of the file manager

- `FilePageController::show` accepts `snapshot` (`^[0-9a-f]{8,64}$`) and passes `snapshot` and `snapshotTime` props; abilities in snapshot mode are read-only.
- New JSON routes under `files.api.`: `GET snapshots/{snapshot}/list?path=` (`v-list-user-files-restic USER SNAPSHOT PATH`, NDJSON parsed by `SnapshotListing`) and `POST snapshots/{snapshot}/restore` (`v-schedule-user-restore-file-restic USER SNAPSHOT PATH`).
- The island hides every write action, shows a banner "Snapshot <id> · <time> · read-only" with a link back to Backups, and offers "Restore" for the selected file or folder with the confirmation "Restore %s from the snapshot? The current file is overwritten."

### 10.4 `v-schedule-user-restore-file-restic USER SNAPSHOT PATH`

New command (additive). Checks the user and the format of the snapshot ID, that incremental backups are enabled for the user, that the path is absolute, inside `/home/USER`, without `..` segments or control characters, that the snapshot exists (`v-list-user-backup-restic`) and that no backup or restore is queued (`is_backup_scheduled`). It appends `v-restore-file-restic 'USER' 'SNAPSHOT' 'PATH' yes` to `data/queue/backup.pipe` in the quoting that `v-restore-file-restic` removes when it finishes, and logs the event. Demo mode is honoured.

## 11. Deviations from legacy

| Legacy behaviour                                                                     | New behaviour                                                        |
| ------------------------------------------------------------------------------------ | -------------------------------------------------------------------- |
| Mail edit passes the raw domain unquoted to the reject and webmail commands          | Arguments go through `ArcCommand` as an array                        |
| "Discard all mail" adds `:blackhole:` once per old forward                           | Old forwards are removed, `:blackhole:` is added once                |
| Webmail command runs on every save; "Disabled" calls the add command with `disabled` | Runs only on change; "Disabled" calls `v-delete-mail-domain-webmail` |
| Cron report success is stored as an error message                                    | Success toast                                                        |
| Users may suspend through single endpoints but not in bulk (DNS, cron)               | Suspend follows `update` everywhere (D10)                            |
| DNS template policy is not checked on add; `v-restart-dns` runs twice on zone edit   | Checked on add; one restart                                          |
| Saving exclusions clears DNS and CRON entries                                        | They are written back unchanged                                      |
| Snapshot detail reads the wrong JSON key                                             | The first entry of `v-list-user-backup-restic` is used               |
| Restore of a file from a snapshot posts `type=file`, which the queue command refuses | `v-schedule-user-restore-file-restic` (§10.4)                        |
| Backup download checks the login user and sends `application/gzip` for every archive | Effective owner and listed name are checked; type from the extension |
| Account list never shows "Open Webmail"                                              | Shown when the domain has webmail                                    |
| Bulk actions ignore failures and flash nothing                                       | `BulkRunner` summary                                                 |
| Single actions are GET links with a token                                            | POST/DELETE forms with CSRF                                          |

## 12. Testing

| Suite                            | Cases                                                                                                                                                                                                                          |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Pest unit                        | `BulkRunner`, `OwnerList`, `CredentialsMailer` (template, fallback, placeholders), record DTOs, exclusions file builder, `SnapshotListing`, DNS zone and mail tab planners (one case per branch)                               |
| Pest feature                     | per module: list for admin, reseller A, reseller B and user; owner filter; 404 outside the tree; 403 on admin-only actions; create, edit, single and bulk actions with exact command arguments; read-only; capability coverage |
| Vitest                           | `apNameServers`, DNS record drawer, password generator reuse, file manager snapshot mode                                                                                                                                       |
| BATS (`arcpanel-panel-app.bats`) | 302 for every legacy URL of §4 with the switch on, 200 with it off                                                                                                                                                             |
| BATS (`arcpanel-backups.bats`)   | `v-schedule-user-restore-file-restic`: valid path queued with the right quoting, `..`, path outside the home, unknown snapshot, incremental disabled, already scheduled, demo mode                                             |
| Playwright                       | one spec per module with axe checks; backups: create, restore one item, snapshot browse and file restore                                                                                                                       |

`test/e2e/prepare.sh` gains `mail`, `dns`, `db`, `cron` and `backups` steps for `arce2euser` and a local restic repository for the snapshot test.

## 13. Documentation changes in the same change set

- **10 Page migration:** modules 8–12 marked migrated; §4 notes the shared `BulkRunner`.
- **06 File manager:** the snapshot mode is implemented.
- **13 Data model reference:** `v-schedule-user-restore-file-restic`; the `USER` exclusion entries `.nvm` and `apps/<name>`.
- **Roadmap:** E7 status line.
- **This spec:** implementation notes.

No table or entity is added, so 15 is unchanged.

## 14. Risks

| Risk                                                       | Mitigation                                                                                        |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Mail edit has many branches                                | One planner test per legacy branch; fixtures recorded on `arcpanel-test`; switch off to fall back |
| `v-restore-file-restic` restores as root with `--target /` | Strict path checks in the new command; BATS for traversal and paths outside the home              |
| Sending e-mail from the panel                              | Same sendmail path as legacy; failures never block the action                                     |
| Five modules at once may drift from each other             | Shared runner, owner list, policy shape and Blade partials; one milestone per module              |

## 15. Implementation notes (2026-10-06)

These notes win over the sections above where they differ.

1. **Commits.** M0 `d38a850`, M1 Databases `b4d1e04`, M2 Cron `1764141`, M3 DNS `68fe648`, M4 Mail `0df84ba`, then M5 Backups and these notes.
2. **Shared code (M0, §5).** `App\Services\Support\BulkRunner`/`BulkResult`, `PlanRunner` (restart keys `web`, `proxy`, `dns`, `cron`, `mail`), `ObjectLookup` (one object of a `v-list-*` command, 404 when missing), `OwnerList`/`OwnerSelection`, the `ChecksBulkItems` trait, and the Blade components `x-ap-owner-filter` and `x-ap-owner-picker`. `WebDomainService::bulk()` and `apply()` run on them without a change in behaviour. The toast region also shows `ok_link` (a link after the success message) and `warn` flashes.
3. **Credential e-mails (D12).** The panel already had `CredentialsMailer` (an interface for the FTP mail) and `PanelCredentialsMailer`. A new interface `HostingCredentialsMailer` (`sendDatabaseCredentials`, `sendMailCredentials`) is implemented by the same class, with the legacy templates and fallback texts. The mail account e-mail sends `https://<WEBMAIL_ALIAS>.<domain>/` as the webmail address, not the legacy concatenation.
4. **Databases (M1).** Download is a POST form (`v-dump-database … file gzip`, served by NGINX from `/backup/`). phpMyAdmin sign-on is a POST that redirects to `hestia-sso.php` with the legacy token; the client IP comes from `App\Auth\ClientIp`.
5. **Cron (M2).** The legacy generator had five tabs, not the presets of the old `x-ap-cron-builder`: the component now ports the tabs (Minutes, Hourly, Daily, Weekly, Monthly) and labels `*/3` "Every 3 days". Applying a preset writes all five fields before it fires the input events. The schedule fields start empty on the add page, as in legacy. Single actions call the command without `no` (it restarts cron itself); bulk actions pass `no` and run `v-restart-cron` once.
6. **DNS (M3).** Records are added and edited in a drawer on the records page (`?add=1`, `?edit=ID`); the record ID is a visible field. The DNSSEC switch is only sent when it is shown. A template posted by a user without the right is ignored. A failed step after the zone was added (template, expiry, TTL) lands on the records page with the step named. `x-ap-modal` gained an `open` option and now focuses on the next frame, which also fixes Escape in every modal.
7. **Mail (M4).** Reject spam and Let's Encrypt get the arguments their scripts take (legacy appended `yes` and `' '`). The DNS hints use the user's first IP (legacy showed the last one). Spam filter, reject and anti-virus are left alone when the server lacks the system. Autoreply messages travel with literal `\n` because `ArcCommand` refuses line breaks in arguments; the command turns them back into new lines. Re-adding an SMTP relay without a password stores an empty one, as in legacy; the form says so.
8. **Backups (M5).** Routes: `backups.index` (`/app/backups`, redirects to the effective user or the picked owner), `backups.archives.*`, `backups.snapshots.*`, `backups.exclusions.*` under `/app/backups/{user}`, and `files.api.snapshots.list|restore`. `v-list-users` does not print `BACKUPS_INCREMENTAL`, so the pages read it with `v-list-user`. Archive names must start with `<owner>.`; local archives are served from `config('arc.backup_dir')` (`/backup`) by NGINX, remote ones are fetched first with `v-schedule-user-backup-download`. Archive and snapshot detail pages list cron jobs as one row ("All cron jobs").
9. **Snapshot mode (§10.3).** `FilePageController::show` answers `?snapshot=<id>` with `files.snapshot` and the island props `snapshot {id, time, back, restore}`, `abilities.manage = false`. `createSnapshotApi()` lists through the snapshot route, restores through the restore route and refuses every other call. In the island, files are only selected, and search, download, the details panel and the disk meter are hidden. Snapshot browsing needs `FILE_MANAGER=true`, like the file manager.
10. **`v-schedule-user-restore-file-restic` (§10.4).** `v-restore-file-restic` removes its queue line with a `sed` address built from the path, which breaks on slashes, and passes the path to restic unquoted. The new command therefore accepts only `[[:alnum:]._@+/-]` in the path and queues a line that removes itself through a marker comment. A file whose name has other characters is restored with its folder.
11. **Tests.** On `arcpanel-test`: Pest 622 passed; Vitest 195 passed; BATS `test/arcpanel-panel-app.bats` 25/25 and `test/arcpanel-backups.bats` 5/5; Playwright specs `databases`, `cron`, `dns`, `mail` and `backups` pass, and the backups spec restores a file from a snapshot end to end. `test/e2e/prepare.sh` gained the e2e data of every module, the user `arce2ebackup` on the package `arce2e-backups` (incremental backups), a local restic repository when the server has none, and the `backups*` steps.
