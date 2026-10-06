# 14 — Later Backlog Designs (E15–E33, post-2.0)

**Epics:** E15–E33 · **Status:** Sketch · **Depends on:** the 2.0 platform ([02](./02-laravel-foundation.md), [03](./03-tenancy-and-authorization.md), [04](./04-port-registry.md), [12](./12-security-model.md))

These are design sketches, not full designs: enough to show that each epic fits the platform rules (Laravel UI from E2 components, owner-scoped through E11 policies, additive `v-*` only) and to fix the shared pieces several epics need. Each one gets a full document when it enters _Next_.

---

## 0. Shared foundations

| Foundation                                                                                                                                                                              | Introduced by | Reused by                                                                                  |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------ |
| **Job Center** — durable job records, live output, retry/cancel                                                                                                                         | E15           | E17 fixes, E20, E21, E22, E25, E31, E33, Docker/Node long operations from E4/E14           |
| **Audit event schema** — `{ts, actor, effective_user, role, ip, action, object, result, meta}` defined next to the E11 `Capabilities` table so every policy-guarded action can emit one | E23           | E29 (webhook events are audit events with a public subset), E30, E32, E33 history          |
| **Channel sender** — email, Telegram, Slack/Discord/Mattermost webhook, generic signed webhook, in-panel notification                                                                   | E16           | E29 (same signer and retry/delivery log), E17 digest, E33 failure alerts, E27 RBL alerts   |
| **Secrets storage** — `data/secrets/<user\|system>/<kind>/<name>`, root-only, write-only UI ([12](./12-security-model.md) §6.2)                                                         | 2.0           | E16 channel tokens, E20 deploy keys, E28 provider tokens, E29 webhook secrets, E32 AI keys |
| **Snapshot helper** — `v-add-sys-snapshot SCOPE` (**new**, E33) for `/etc`, `conf/`, `data/` config-only snapshots; restic/`v-backup-user` for user data                                | E33           | E21, E22 pre-change snapshots                                                              |
| **Streaming transport** — the web terminal WebSocket server with a `stream` message type ([12](./12-security-model.md) §10)                                                             | E14/E4        | E15 job logs, E18 live tail                                                                |

### 0.1 The first database

Until E15 the panel has no database: the CLI's flat files are the state. Job records do not fit that model — they are high-volume, panel-owned, need status updates from a worker process and queries by user/tree/state. E15 introduces **SQLite** at `/usr/local/hestia/data/arcpanel/arcpanel.sqlite` (`hestiaweb:hestiaweb 0640`, WAL mode), used through Laravel's `sqlite` connection and migrations. Why SQLite and not MariaDB: no new service, no credentials, works when the DB server is disabled or broken, trivially backed up by the config snapshot, and the write rate (jobs, alert state, audit index) is far below SQLite's limits on one host. Rule: SQLite holds **panel-owned operational data only** (jobs, alert history, audit index, webhook deliveries, update history); hosting state stays in the CLI's files.

---

## Wave 1 (months 13–15)

### E15 — Job Center

**Goal:** every operation longer than a few seconds is a visible job with live output, result and retry.

- **Worker:** `arcpanel-worker.service` (systemd) runs `php artisan queue:work --queue=jobs` as **`hestiaweb`** — not root. Jobs call `v-*` through `ArcCommand` exactly as web requests do, so the privilege model is unchanged. Concurrency 2 by default (`JOB_WORKERS` in `hestia.conf`). Laravel queue driver `database` on the SQLite connection (§0.1).
- **Job record:** `jobs_log` table: `id, type, user, owner, actor, state (queued|running|done|failed|cancelled), started_at, finished_at, exit_code, command (masked), output_path, retryable, parent_id`. Output is written to `/usr/local/hestia/data/arcpanel/jobs/<id>.log` (`0640 hestiaweb`), streamed to the browser through the ws `stream` type (`kind: job`), with secrets masked by `ArcCommand` before writing.
- **Replacing pipes:** the panel no longer appends to `data/queue/*.pipe` for user-initiated backups, restores and Let's Encrypt; it runs `v-backup-user` etc. directly inside a job with `Options::timeout` of hours. Cron-driven pipes (`v-update-sys-queue`) stay for system jobs. `v-schedule-*` remain for CLI/API users; `v-list-sys-jobs` (**new**) merges both views (pipe content + SQLite) for the UI.
- **Commands (new):** `v-list-sys-jobs [USER] [FORMAT]`, `v-delete-sys-job-logs DAYS` (retention 30 days, cron).
- **Laravel:** `Jobs\RunArcCommand`, `JobService::dispatch(type, user, command, args, retryable)`, `JobPolicy` (user: own; reseller: tree; admin: all), job drawer component in the notification tray, job detail page with live log, retry when `retryable` (idempotent commands only, declared in `config/arc-commands.php`), cancel = `SIGTERM` to the child process group.
- **Dependencies:** [02](./02-laravel-foundation.md) §4, ws transport, SQLite §0.1.
- **Risk:** worker crash leaves jobs `running` → a heartbeat column and `v-check-sys-jobs` (cron) marks stale jobs failed and raises an E16 alert.

### E16 — Alerts and notification channels

**Goal:** full disks, stopped services and expiring certificates reach the admin's Telegram/Slack/email within five minutes, once, with a "resolved" message.

- **Engine:** `v-check-sys-alerts` (**new**, cron every 5 min, root) evaluates rules in `data/alerts/rules.conf` (Hestia object lines: `RULE='disk' SCOPE='system|user' THRESHOLD='90' SEVERITY='critical' COOLDOWN='60' CHANNELS='tg-admin,email' SUSPENDED='no'`). Built-in checks: disk/inode %, load, memory, stopped services (`v-list-sys-services`), SSL expiry < N days (`v-list-web-domain-ssl`, `v-list-mail-domain-ssl`), failed LE renewal, failed backup/job (E15), user quota ≥ 90 %, port orphans (E12 report), RBL listing (E27), stale jobs.
- **State:** `data/alerts/state/<rule>-<object>.json` (firing since, last sent, resolved) prevents duplicates; SQLite `alerts_history` for the UI.
- **Channels:** `data/alerts/channels.conf` with type and target; secrets (bot token, webhook URL) in `data/secrets/system/alerts/<channel>`; `v-send-sys-alert CHANNEL SUBJECT BODY` (**new**) implements email (panel SMTP via `mail-wrapper.php`), Telegram API, Slack/Discord/Mattermost incoming webhook, generic HMAC-signed webhook (shared signer with E29), in-panel `v-add-user-notification`.
- **Commands (new):** `v-add-sys-alert-rule`, `v-delete-sys-alert-rule`, `v-list-sys-alert-rules`, `v-add-sys-alert-channel`, `v-delete-sys-alert-channel`, `v-test-sys-alert-channel`, `v-list-sys-alert-history`.
- **Laravel:** Alerts page (rules, channels with "send test", history); scope by role — users receive alerts about their own objects (quota, SSL, failed jobs) via in-panel/email, resellers about their tree, admins everything.
- **Risk:** alert storms on a dying server → cooldown per rule, global cap per 10 minutes, severity-based digest.

### E19 — Per-domain PHP settings and extensions

**Goal:** a user raises `upload_max_filesize` for one site within package limits, and it survives `v-rebuild-web-domain`.

- **ADR (pool append vs `.user.ini`):** recommend **pool-level `php_admin_value`**. Current pools are generated from `install/deb/templates/web/php-fpm/*.tpl` into `/etc/php/<ver>/fpm/pool.d/<domain>.conf` by `prepare_web_backend` / `add_web_config` with `%user%`, `%domain%`, `%backend_version%` variables and already contain `php_admin_value[...]` lines. Upstream pool templates have no include hook, so the additive mechanism is: `v-change-web-domain-php-setting` writes the whitelisted keys to `data/users/<user>/php/<domain>.conf`, and `v-rebuild-web-domain-php-settings USER DOMAIN` (**new**) appends a marked block `; arcpanel-php-settings-begin … end` of `php_admin_value[...]` lines to the generated pool file, then `v-restart-service php<ver>-fpm` through the restart queue. The block is re-applied after any rebuild by a hook (`/etc/hestiacp/hooks/post_rebuild_web_domain.sh` if upstream provides the hook point; otherwise the nightly `v-rebuild-web-domains-php-settings` reconciler plus a call from the Laravel `WebDomainService` after every rebuild it triggers). `.user.ini` is rejected: users can edit it, limits are unenforceable, and `php_admin_value` wins anyway.
- **Whitelist and limits:** `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `max_input_vars`, `display_errors`, `date.timezone`; package keys `PHP_MAX_MEMORY`, `PHP_MAX_UPLOAD`, `PHP_MAX_EXECUTION_TIME` (default `unlimited` = template value).
- **Commands (new):** `v-change-web-domain-php-setting USER DOMAIN KEY VALUE`, `v-delete-web-domain-php-setting`, `v-list-web-domain-php-settings`, `v-rebuild-web-domain-php-settings`, `v-add-sys-php-extension VERSION EXT` / `v-delete-sys-php-extension` / `v-list-sys-php-extensions` (apt `php<ver>-<ext>` from a whitelist in `install/common/php-extensions.list`).
- **Laravel:** "PHP settings" tab on the domain edit page showing package limits; Admin → Server → PHP extension manager (job via E15).
- **Risk:** `post_max_size < upload_max_filesize` footguns → validator enforces consistency; invalid value makes FPM refuse to reload → `php-fpm<ver> -t` before restart, rollback the block on failure.

### E23 — System-wide audit log

**Goal:** one tamper-evident record of who did what across all tenants, including the real actor during login-as.

- **Event schema** (`web-app/app/Audit/Event.php`, JSON): `ts`, `id` (ULID), `actor` (login user or API key id), `effective_user`, `role`, `ip`, `user_agent_hash`, `action` (the `Capabilities` key, e.g. `web.domains.create`), `object` (`type:id`), `result` (`ok|denied|error:E_LIMIT`), `job_id?`, `meta` (masked arguments), `prev_hash`, `hash`.
- **Storage:** append-only JSONL `data/audit/YYYY-MM-DD.jsonl` written by `v-add-sys-audit-event` (**new**, root; the panel passes the event as a JSON argument via temp file) so `hestiaweb` cannot rewrite history; `hash = sha256(prev_hash + line)`, daily chain anchored in `data/audit/YYYY-MM-DD.anchor` and reported by E17. SQLite index (`audit_index`: ts, actor, effective_user, owner, action, object → file offset) for queries.
- **Ingestion:** `v-log-action` output (`history.log`) and the auth log are tailed nightly into the same stream with `actor=cli|api`, so CLI/API activity appears beside UI activity; the E11 impersonation start/stop become explicit `auth.impersonate.start|stop` events.
- **Emission points:** one `AuditMiddleware` after the policy decision records `denied`; services record `ok|error` after the `ArcCommand` result. No per-controller code.
- **Commands (new):** `v-add-sys-audit-event`, `v-list-sys-audit DAYS [USER] [FORMAT]`, `v-verify-sys-audit DAYS` (chain check), `v-export-sys-audit` (CSV/JSON), optional syslog forwarding setting `AUDIT_SYSLOG='yes'`.
- **Laravel:** Audit page — admin all, reseller tree read-only (filter `owner` in tree), user keeps the existing log page; retention setting `AUDIT_RETENTION_DAYS`.
- **Risk:** event volume on busy API servers → index only, files rotate daily, compression after 7 days.

### E17 — Server and Site Doctor

**Goal:** detect the five most common support issues and fix them with one click when a `v-*` fix exists.

- **Check contract:** each check is `func/doctor/<area>/<name>.sh` exporting `doctor_check` that prints one JSON object: `{id, scope: system|user|domain, status: ok|warn|fail|skip, title, explanation, docs_url, fix: {command, args} | null, weight}`. `v-run-sys-doctor [USER] [DOMAIN] [FORMAT]` (**new**) sources every check matching the scope, runs them with a per-check timeout, aggregates a score (`100 − Σ weight(fail)`) and caches the result in `data/doctor/<scope>.json` (TTL 10 min).
- **Site checks:** A/AAAA resolve to the domain IP (NAT-aware via `v-list-sys-ips` `NAT`), SSL valid and covering aliases, HTTP→HTTPS redirect, PHP version EOL, MX/SPF/DKIM/DMARC match `v-list-mail-domain-dkim-dns`, PTR for mail IP, docroot permissions, WordPress debug on in production. **Server checks:** root SSH password login, Fail2Ban jails, firewall enabled, panel port default, pending updates (E33 inventory), swap, inodes, orphan ports ([04](./04-port-registry.md) `v-check-sys-ports`), time sync, audit chain intact (E23), stale jobs (E15), secrets of deleted users ([12](./12-security-model.md) §6.2).
- **Fixes:** only existing `v-*` calls (`v-add-letsencrypt-domain`, `v-add-mail-domain-dkim`, `v-add-web-domain-ssl-force`, `v-add-sys-firewall`), run as E15 jobs after a confirmation dialog; policies decide who may run which fix (user: own domain fixes; admin: server fixes).
- **Laravel:** Doctor page per domain and per server with score, grouped findings, Fix buttons; weekly digest via E16.
- **Risk:** false positives (split-horizon DNS, Cloudflare proxy) → checks carry `skip` reasons and per-check "ignore for this domain" stored in `data/doctor/ignore.conf`.

---

## Wave 2 (months 16–18)

### E33 — Update Center

**Goal:** per-component update policies with maintenance windows, pre-flight checks, snapshots, post-update health checks and rollback, so admins can turn on automatic updates safely.

- **Inventory:** `v-list-sys-updates [FORMAT]` (**new**) merges `v-list-sys-hestia-updates`, `apt list --upgradable` (security flagged from the `-security` suite), PHP/MariaDB/PostgreSQL minors, Docker apps (`v-list-docker-apps` vs catalog pins), Node globals (`v-list-user-npm-packages --outdated`), WordPress sites (E21, read-only) into one JSON with current/new version, changelog URL and `security: yes|no`.
- **Policy:** `data/updates/policy.conf` lines `COMPONENT='os' POLICY='auto-security' WINDOW='Sun,Mon 02:00-05:00' DELAY='3' HELD='pkg1,pkg2'`; `v-change-sys-update-policy COMPONENT POLICY [WINDOW] [DELAY]` (**new**). The panel's own auto-update cron and `v-change-sys-release` appear as the `panel` component.
- **Runner:** `v-run-sys-updates [COMPONENT] [--security-only]` (**new**), scheduled by cron inside the window, or started from the UI as an E15 job. Sequence: pre-flight (disk ≥ 2 GB free, no apt/dpkg lock, no backup/job running, E17 server score ≥ threshold) → `v-add-sys-snapshot config` (tar of `/etc`, `$HESTIA/conf`, `$HESTIA/data` excluding sessions/jobs/audit; rotated 5) → update → post-check (`v-list-sys-services` all running, panel `/app/_health` 200, sample of domains returns the same status as before) → on failure: E16 alert, `UPDATES_PAUSED='yes'` until an admin acknowledges.
- **Rollback:** `v-rollback-sys-hestia VERSION` (**new**, previous debs from `/var/cache/apt/archives` or the ArcPanel apt repo), Docker apps to the previous image pin kept by `v-update-docker-app`, config snapshot restore (`v-restore-sys-snapshot ID`). OS packages are not rolled back automatically.
- **Reboots:** detect `/var/run/reboot-required`; banner; `v-schedule-sys-reboot` (**new**) uses `at`/systemd timer inside the next window after an E16 notification.
- **History:** SQLite `update_history` + audit events; `v-list-sys-update-history`.
- **Laravel:** redesigned Updates page (components, security badges, policy editor, window picker, Update now / Schedule, history). Admin only; users/resellers see their own Docker/Node/WordPress rows.
- **Risk:** a bad panel update breaks the panel that would roll it back → the rollback command is a plain shell script runnable over SSH and documented; the post-check runs from cron, not from the panel.

### E20 — Git Deploy for every web domain

**Goal:** push-to-deploy with atomic releases and one-click rollback for PHP, static and Node sites.

- **Layout:** `~/web/<domain>/releases/<timestamp>/`, `~/web/<domain>/shared/` (linked paths such as `storage/`, `.env`, `wp-content/uploads`), `~/web/<domain>/current → releases/<ts>`; docroot set once to `current` or `current/public` via `v-change-web-domain-docroot`. Keep last N (`GIT_KEEP_RELEASES`, default 5).
- **Credentials:** per-domain deploy key pair generated by `v-add-web-domain-git` (**new**), private key in `data/secrets/<user>/git/<domain>` (root-only, used by root to clone **as the user** via `GIT_SSH_COMMAND` with a temp copy chowned to the user and removed after); public key shown once. HTTPS token alternative in the same store.
- **Pipeline:** `v-run-web-domain-deploy USER DOMAIN [REF]` (**new**): fetch → checkout into a new release dir → link shared paths → run preset (`laravel`, `symfony`, `wordpress-bedrock`, `static`, `node` via E13/E14 build) or `.arcpanel-deploy.sh` as the user in the jail → health URL check → atomic symlink switch → `v-purge-nginx-cache` → prune. A failed step never switches `current`. Runs as an E15 job.
- **Webhook:** `POST /hooks/deploy/<domain>/<id>` with HMAC-SHA256 secret (GitHub, GitLab, Gitea signature formats), branch filter, rate-limited; the endpoint only enqueues a job.
- **Commands (new):** `v-add-web-domain-git USER DOMAIN URL BRANCH [PRESET]`, `v-delete-web-domain-git`, `v-run-web-domain-deploy`, `v-list-web-domain-deploys`, `v-rollback-web-domain-deploy USER DOMAIN RELEASE`.
- **Laravel:** Deploy tab on the domain (repo, branch, preset, webhook URL + secret shown once, releases list with rollback), reuse of the E14 deploy helper code.
- **Risk:** build steps consuming the host → run inside the user's cgroup slice with `nice`/`ionice`; timeout per preset.

### E24 — Web Application Firewall and bot protection

**Goal:** block common web attacks per domain before they reach PHP, with safe presets.

- **Engine ADR:** recommend **Coraza** (`coraza-nginx` / Coraza SPOA alternative) over ModSecurity v3: actively maintained, OWASP CRS v4 compatible, Apache-2.0, and no libmodsecurity packaging burden on five OS targets. Fallback if the NGINX connector packaging proves immature at decision time: ModSecurity v3 from the OS repos where available. Decision recorded in an ADR with a packaging spike.
- **Wiring:** per-domain `~/conf/web/<domain>/nginx.conf_waf` (and `nginx.ssl.conf_waf`) written by `v-add-web-domain-waf USER DOMAIN [detect|block]` (**new**); the existing templates already include `nginx.conf_*`, so no template change. Modes `detect` (default) and `block`; exclusion presets for WordPress, Nextcloud, Laravel as CRS exclusion files; per-domain custom exclusions in `data/users/<user>/waf/<domain>.conf`.
- **Rate limiting / geo:** `v-add-web-domain-ratelimit USER DOMAIN ZONE RATE PATHS` writes `limit_req` snippets to the same include; `v-add-web-domain-geoblock USER DOMAIN COUNTRIES` uses the MaxMind GeoLite2 database (licence key stored in `data/secrets/system/geoip`, refreshed weekly) or DB-IP as the free alternative — chosen in the ADR.
- **CrowdSec (optional):** `v-add-sys-crowdsec` installs the agent + firewall bouncer; decisions surface in Firewall → Bans through `v-list-firewall-ban` (bouncer writes to its own ipset, listed read-only).
- **Observability:** blocked requests parsed from the audit log into SQLite `waf_events` for the Security tab (last 100 per domain), E18 shows them in context.
- **Laravel:** Security tab on the domain (mode, recent blocks with "add exclusion", rate limits, geoblock); admin global defaults.
- **Risk:** false positives breaking sites → `detect` default, presets, owner-visible blocks, one-click exclusion.

### E27 — Mail deliverability center

**Goal:** show why mail is rejected or lost, from inside the panel.

- **Checks:** per-domain SPF/DKIM/DMARC/PTR/MTA-STS/TLS-RPT (BIMI optional) as E17 checks under `func/doctor/mail/`; results on a Mail → Deliverability page with "Fix" (`v-add-mail-domain-dkim`, DNS record creation via `v-add-dns-record` when the zone is local).
- **RBL:** `v-check-sys-mail-rbl` (**new**, daily) queries outbound mail IPs against a configurable RBL list → E16 alert on listing.
- **Tracking:** `v-list-mail-domain-log USER DOMAIN [SENDER] [RCPT] [MSGID] [FROM] [TO]` (**new**) parses `/var/log/exim4/mainlog` as root, filtered to the user's domains, returning delivered/deferred/bounced with the SMTP response; queue viewer `v-list-sys-mail-queue` / `v-delete-sys-mail-queue-message ID` / `v-retry-sys-mail-queue-message ID` (**new**).
- **Autoconfig:** Laravel routes `/.well-known/autoconfig/mail/config-v1.1.xml`, `/autodiscover/autodiscover.xml` and an iOS `.mobileconfig` served from the mail domain through a small NGINX include written by `v-add-mail-domain-autoconfig` (**new**).
- **Risk:** log parsing cost on busy mail servers → time-bounded search with an index of message ids per day in SQLite.

### E28 — Cloudflare and external DNS providers

**Goal:** zones managed in the panel are mirrored to Cloudflare (later Route 53, Hetzner) with the panel as source of truth.

- **Connectors:** `v-add-dns-provider USER PROVIDER NAME TOKEN_FILE` (**new**) stores the scoped token in `data/secrets/<user>/dns/<name>`; `v-sync-dns-domain-provider USER DOMAIN` (**new**) computes the diff between `data/users/<user>/dns/<domain>.conf` and the provider zone and applies one-way changes; triggered by the Laravel `DnsService` after each panel change and by a 15-minute reconciliation cron. Existing `v-*-dns-*` untouched.
- **Cloudflare specifics:** per-record `proxied` flag stored as an additive key in a sidecar `data/users/<user>/dns/<domain>.provider.conf` (upstream record lines unchanged); real visitor IP via `install/deb/nginx/cloudflare.inc` refreshed weekly by `v-update-sys-cloudflare-ips` (**new**).
- **ACME DNS-01:** `v-add-letsencrypt-domain-dns-provider USER DOMAIN [ALIASES]` (**new**) performs the challenge through the provider API for wildcard certificates of externally hosted zones, reusing `func/letsencrypt`-style flow additively.
- **Laravel:** Providers page per user, provider badge on zones, sync status and last error, "proxied" toggle on records.
- **Risk:** destructive sync of a zone the user also edits at Cloudflare → first sync is a dry-run preview; records not known to the panel are left alone unless "manage whole zone" is enabled.

---

## Wave 3 (months 19–21)

### E21 — WordPress Toolkit

**Goal:** update and secure all WordPress sites from one screen, with a snapshot before every bulk action.

- **Discovery:** `v-list-user-wp-installs USER [FORMAT]` (**new**) scans docroots for `wp-config.php`, reads version/URL via the per-user WP-CLI (`v-add-user-wp-cli`), caches in `data/users/<user>/wp.conf`; nightly refresh.
- **Actions:** `v-run-user-wp USER DOMAIN SUBCOMMAND…` (**new**) wraps WP-CLI as the user with an allow-list (`core update`, `plugin update`, `theme update`, `maintenance-mode`, `user update`, `config set WP_DEBUG`, `search-replace`, `cache flush`, `core verify-checksums`); every action is an E15 job; bulk = one parent job with child jobs.
- **Safety:** pre-update snapshot through restic (`v-backup-user-restic`) when enabled, else `v-backup-user`; auto-update policy per site in `wp.conf`.
- **Security:** hardening checklist mapped to the `wordpress-secure` template (E6), file permission check, plugin versions against a vulnerability feed (WPScan API with key, or the free Wordfence Intelligence feed — chosen in an ADR for licence terms), cached daily in SQLite `wp_vulns`.
- **Laravel:** WordPress page (all sites, versions, pending updates, vulnerabilities, bulk select) and site detail; reseller sees its tree.
- **Risk:** WP-CLI memory/time on large sites → run under the user's cgroup with per-site timeout; failed child jobs do not stop siblings.

### E30 — Collaborators

**Goal:** a site owner invites a developer with limited scope instead of sharing the main password.

- **Model:** a collaborator is a separate login (own password, mandatory 2FA) stored in `data/users/<user>/collaborators.conf` (`LOGIN='dev1' HOST_USER='alice' SCOPES='web:rw:example.com,files:rw:example.com,db:ro:*' EXPIRES='2027-01-01' SUSPENDED='no'`), credentials in `data/users/<user>/collaborators/<login>.hash`.
- **Policy layer:** a new principal type `Principal::collaborator` — `OwnerScope` resolves to the host user, then `ScopeCheck` applies module × object × rw after the owner check ([03](./03-tenancy-and-authorization.md) §7). Capabilities not in the scope → 404.
- **File access:** per-domain SFTP/FTP account via `v-add-web-domain-ftp` instead of shell; File Manager v2 limited to the scoped domain's directory.
- **Commands (new):** `v-add-user-collaborator`, `v-delete-user-collaborator`, `v-change-user-collaborator-scopes`, `v-list-user-collaborators`, `v-check-user-collaborator-password`.
- **Laravel:** Account → Collaborators page (invite by email, scopes editor, expiry, revoke); login form accepts `host/login`; every action audited (E23) with `actor=collab:<login>`.
- **Dependency:** must not start before E23 (roadmap).
- **Risk:** scope creep in policies → collaborator scopes are only ever an **intersection** with what the host user may do.

---

## Wave 4 (months 22–24)

### E22 — Staging and clone

**Goal:** copy to staging, test, push back — with a restorable snapshot before every push.

- `v-clone-web-domain USER SRC DST [--with-db]` (**new**): `v-add-web-domain` + rsync of files as the user, dump/restore of detected databases into new ones (`v-add-database`, `v-import-database`), config rewrite (WordPress `wp-config.php`, Laravel `.env`), URL search-replace via WP-CLI; staging gets `v-add-web-domain-httpauth` and `noindex` headers via `nginx.conf_staging`. Link stored in `data/users/<user>/staging.conf`.
- `v-push-web-domain-staging USER STAGING [files|db|both] [EXCLUDES]` (**new**): mandatory pre-push snapshot (restic or `v-backup-user`), then reverse rsync/db import; the database push is a separate explicit checkbox.
- E15 jobs with a diff summary (changed files, DB size) before confirmation; Staging badge and push dialog in the domain page.
- **Risk:** large sites take long → progress via job output; `--with-db` optional; exclusions default to `wp-content/uploads`.

### E29 — Outgoing webhooks and OpenAPI

**Goal:** integrations without polling and a documented API.

- **OpenAPI 3** spec for `lib/api/index.php` (the API script, `web/api` before 2.0) generated from `config/arc-commands.php` plus per-command metadata (`info:`/`options:` headers already present in every `v-*`, parsed by a build script — `src/hst_generate_clidocs.sh` already does similar), permission sets documented; published on the docs site.
- **Events:** the E23 audit stream filtered to a public subset (`user.created`, `user.suspended`, `domain.added`, `ssl.renewed`, `backup.failed`, `app.installed`, `job.failed`, …); subscriptions in SQLite `webhooks` (url, events, scope = owner tree, secret id); secrets in `data/secrets/<user>/webhooks/<id>`.
- **Delivery:** the E16 channel sender's generic webhook: HMAC-SHA256 header, retries with exponential backoff (5 attempts), SQLite `webhook_deliveries` log with response code and body excerpt.
- **Commands (new):** `v-add-user-webhook`, `v-delete-user-webhook`, `v-list-user-webhooks`, `v-list-user-webhook-deliveries`.
- **Risk:** SSRF through webhook URLs → deny private/link-local targets by default (admin override), no redirects followed.

### E18 — Log Explorer and real-time stats

**Goal:** find the error behind a 500 without SSH.

- **Streaming:** `v-list-web-domain-log-stream USER DOMAIN access|error [FILTER]` (**new**) tails `/var/log/nginx/domains/<domain>.log|.error.log` and Apache logs with filters (time range, status, IP, path, text) as root, output over the ws `stream` type; same for PHP-FPM slow log, Node (`pm2 logs`), Docker (`compose logs`) and the user's mail log (E27 filter).
- **Search:** bounded `grep`/`awk` over rotated logs with a 10k-line cap; download of the filtered result as a job.
- **GoAccess:** additive stats template `goaccess` selectable in `v-add-web-domain-stats`; real-time HTML report generated into the protected `/vstats/` path behind panel auth.
- **Laravel:** Logs tab on the domain (live tail, filters, saved filters in SQLite), Node/Docker app pages reuse the component.
- **Risk:** log volume → server-side filtering before streaming; rate limit lines per second.

### E25 — Malware scanning for web files

**Goal:** detect compromised sites before the server IP is blacklisted.

- `v-scan-user-web USER [DOMAIN]` (**new**) runs `clamdscan --fdpass` (ClamAV already installed when `--clamav yes`) plus optional PHP webshell signatures (`install/common/clamav/arcpanel-webshells.ldb`, maintained in-repo) with `nice -n 19 ionice -c3` inside the user's cgroup; results in `data/users/<user>/scan/<date>.json`.
- Quarantine: files moved as root to `data/quarantine/<user>/<id>` (`0700 root`) with original path and mode; `v-restore-user-quarantine ID`, `v-delete-user-quarantine ID` (**new**).
- Scheduling by package key `MALWARE_SCAN='daily|weekly|no'`; E15 job, E16 alert to the owner; optional scan on upload finish in File Manager v2 ([12](./12-security-model.md) §7.2).
- **Risk:** false positives quarantining a live site → default action `report`, `quarantine` opt-in per package; restore is one click.

---

## Wave 5 (months 25+)

### E31 — Migration wizard

**Goal:** move a HestiaCP/VestaCP/ArcPanel account (and later Plesk, CyberPanel, aaPanel) with no manual fixes.

- **Server-to-server:** `v-import-remote-user SOURCE_HOST SOURCE_USER [SSH_KEY_FILE|API_KEY_FILE]` (**new**): on the source run `v-backup-user` over SSH (or API `v-schedule-user-backup` + download), rsync the archive to `/backup`, `v-restore-user` locally, then an E17 site check per domain and a DNS cut-over checklist. Credentials via `data/secrets/system/migrations/<id>`, deleted after the job.
- **Importers:** `v-import-plesk`, `v-import-cyberpanel`, `v-import-aapanel` following `bin/v-import-cpanel`'s structure (archive → users/domains/mail/db mapping → `v-add-*` calls).
- **Single-site WordPress:** archive + SQL dump or SFTP source, URL rewrite via WP-CLI.
- **Pre-flight / dry run:** PHP versions available (`v-list-sys-php`), package limits, disk space, TTL advice; report of skipped items.
- **Laravel:** wizard (source → credentials → preview → run as E15 job → cut-over checklist); admin and reseller (into own tree).
- **Risk:** version skew of backup formats → the importer pins the supported source versions and refuses others with a clear message.

### E26 — Login hardening: passkeys, enforced 2FA, SSO

**Goal:** passkeys, enforced 2FA per role/package and OIDC login, now that the legacy session bridge is gone.

- **WebAuthn:** credentials stored in `data/users/<user>/passkeys.conf` through `v-add-user-passkey` / `v-delete-user-passkey` / `v-list-user-passkeys` (**new**); the WebAuthn ceremony runs in Laravel (`web-auth/webauthn-lib`), verification result passed to the CLI as a signed assertion for storage only. Usable as second factor or passwordless (admin policy).
- **Enforced 2FA:** `POLICY_REQUIRE_2FA='admin,reseller'` or package key `REQUIRE_2FA='yes'` with `TWOFA_GRACE_DAYS`; after grace, login redirects to 2FA setup until complete.
- **OIDC:** `v-add-sys-oidc-provider NAME ISSUER CLIENT_ID SECRET_FILE CLAIM` (**new**, secret in `data/secrets/system/oidc/<name>`); Laravel `socialite`-free minimal OIDC client; maps a claim (email/preferred_username) to an existing user — never auto-creates; admin-only by default (`OIDC_ROLES`).
- **Sessions:** session index `data/arcpanel/sessions.sqlite`-free approach: `v-list-user-sessions USER` / `v-delete-user-sessions USER [ID]` (**new**, root reads `data/sessions/`) for "sign out other sessions" ([12](./12-security-model.md) §4).
- **Risk:** lock-out when a passkey is lost → recovery code (exists for TOTP) covers passkeys too; admin `v-delete-user-passkey`.

### E32 — AI assistant (experimental)

**Goal:** explain errors, failed jobs and Doctor findings in plain language, propose fixes, never act on its own.

- **Opt-in:** `AI_ASSISTANT='no'` default; admin enables per role (`AI_ROLES`), provider and model in `hestia.conf`, API key in `data/secrets/system/ai/<provider>`; calls go through a Laravel `AiGateway` with per-user daily quota.
- **Inputs:** an error-log excerpt (E18), a failed E15 job's masked output, an E17 finding. **Redaction** before sending: secrets (already masked), emails, IPs, hostnames mapped to placeholders; never mail bodies or database content; the user sees a preview of the exact payload and confirms.
- **Outputs:** explanation + optional proposed `v-*` command rendered as a **button the user must click**, validated against `config/arc-commands.php` and the user's policies before it is offered; executed as a normal E15 job. Config snippets (NGINX/PHP) are offered as text for manual review.
- **Audit:** every request/response pair recorded as an E23 event (`ai.request`, with the redacted payload hash and token counts).
- **Risk:** prompt injection via log content → the model output is never executed or rendered as HTML; only the fixed command allow-list and policies decide what is runnable.
