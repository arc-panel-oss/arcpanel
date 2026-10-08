# 12 — Security Model

**Epics:** all · **Status:** Draft v1 · **Depends on:** [02](./02-laravel-foundation.md), [03](./03-tenancy-and-authorization.md), [04](./04-port-registry.md)

---

## 1. Goal

State the trust boundaries of ArcPanel 2.0, the threats each boundary faces, and the controls every epic must implement. The model keeps HestiaCP's single privilege boundary (`sudo` into `v-*` scripts) and adds no new privileged path. New attack surface — resellers, per-user runtimes, Docker, uploads, third-party credentials — is confined by the rules below.

---

## 2. Trust boundaries

```mermaid
flowchart LR
  subgraph untrusted[Untrusted]
    B[Browser]
    API[API clients<br/>billing, scripts]
    INET[Internet traffic<br/>to hosted sites and apps]
  end

  subgraph hw["Boundary 1 — arcpanelweb (unprivileged)"]
    N[arcpanel-nginx :8083]
    L[Laravel panel app]
    LEG[Legacy web/ pages<br/>until E8]
    APIv1[lib/api/index.php]
  end

  subgraph root["Boundary 2 — root"]
    S[sudo<br/>arcpanelweb → /usr/local/arcpanel/bin/*]
    V[v-* scripts<br/>argument validation]
    D[(data/ conf/ 0640 root)]
    WT[web terminal server.js<br/>root, spawns as user]
  end

  subgraph users["Boundary 3 — hosting users"]
    PM[pm2 / Node apps<br/>systemd --user, cgroup slice]
    DK[Docker Compose apps<br/>rootless or group — ADR-0003]
    FS[/home/user/]
  end

  subgraph svc[Managed services]
    NG[NGINX :80/:443]
    REST[Apache, PHP-FPM, Exim, Dovecot, BIND, DB, iptables]
  end

  B -->|HTTPS, ARCPANELSID + arcpanel_session| N --> L
  N --> LEG
  API -->|HTTPS, access key| N --> APIv1
  L -->|argv exec| S --> V --> D
  LEG --> S
  APIv1 --> S
  V --> REST
  V --> NG
  V -->|user_exec, systemctl --user| PM
  V -->|docker compose as user| DK
  B -. ws, cookie .-> WT -->|node-pty as user| FS
  INET --> NG -. 127.0.0.1:port .-> PM
  NG -. 127.0.0.1:port .-> DK
```

| Boundary                          | Crossed by                                                     | Control                                                                                                                    |
| --------------------------------- | -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Internet → `arcpanelweb`          | HTTPS requests to the panel                                    | TLS (panel certificate), session and CSRF rules (§4, §5), rate limiting on login (§4.5), Fail2Ban `arcpanel` jail (exists) |
| `arcpanelweb` → root              | `sudo /usr/local/arcpanel/bin/<cmd> args`                      | sudoers rule unchanged; `ArcCommand` allow-list and argv execution (§3); `v-*` validation is the real boundary             |
| root → hosting user               | `user_exec`, `systemctl --user`, `docker compose` as user, pm2 | commands always drop privileges for user workloads; path confinement to `$HOMEDIR/$user` (§7)                              |
| hosting user → other users / host | process, filesystem, network                                   | `arcpanel-users` group ACLs (exist), cgroup slice, private ports, no Docker socket, no host network (§8, §9)               |
| Internet → user runtime           | HTTP via domain proxy templates                                | only through NGINX; direct ports only when `PUBLIC='yes'` with a tagged firewall rule ([04](./04-port-registry.md) §7)     |

---

## 3. Privilege boundary: `sudo` and `v-*`

Unchanged from upstream: `install/common/sudo/arcpanelweb` grants `arcpanelweb ALL=NOPASSWD:/usr/local/arcpanel/bin/*`. Consequences and rules:

1. **Every `v-*` is an attack surface.** New commands validate every argument with `is_format_valid` / `is_object_valid` before acting, never interpolate arguments into `eval`, `bash -c` or SQL without the existing quoting helpers, and resolve every user-supplied path with `readlink -f` + prefix check against `$HOMEDIR/$user` (pattern in `bin/v-add-fs-file:41`, `bin/v-add-user-composer`).
2. **No new sudoers lines, no setuid binaries, no world-writable data.** Docker (E4) and Node (E13/E14) are driven by `v-*` scripts, not by giving `arcpanelweb` or users direct access to `docker.sock`.
3. **`ArcCommand`** ([02](./02-laravel-foundation.md) §4) is the only caller in the panel app: allow-list of command names generated from `bin/`, argv execution via Symfony Process (no shell), argument type checks, `\0`/`\n` rejection. A Pest architecture test forbids `exec`, `shell_exec`, `system`, `passthru`, `proc_open` and backticks outside `App\Arc`.
4. **The CLI is the second line.** A bug in a Laravel policy may expose a UI action, but the action still has to pass the command's own checks; owner-aware commands (`v-add-reseller-user`, `v-change-reseller-user-package`, port pool rules) exist for this reason.
5. **Demo and read-only mode** are enforced in `v-*` (`check_arcpanel_demo_mode`) and only mirrored in the UI.

---

## 4. Sessions

| Property                  | Design                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Identity                  | One PHP session file (`ARCPANELSID`, `files` handler, `/usr/local/arcpanel/data/sessions`, dir `0700 arcpanelweb`) shared by Laravel and the web terminal (and the legacy app in 1.x) ([02](./02-laravel-foundation.md) §5)                                                                                                                                                                                                |
| Second cookie             | `arcpanel_session` (Laravel `file` driver) carries only flash, CSRF and transient UI state — never identity                                                                                                                                                                                                                                                                                                                |
| Cookie flags              | `Secure`, `HttpOnly`, `SameSite=Lax` for both; `SameSite=Strict` would break deep links from mail. Set from one place (`src/deb/php/php.ini` for `ARCPANELSID`, `config/session.php` for Laravel) and asserted by an e2e test                                                                                                                                                                                              |
| Fixation                  | `LoginController` calls `session_regenerate_id(true)` on the legacy session **and** `Session::regenerate()` on the Laravel session after a successful password/2FA check, before writing identity keys                                                                                                                                                                                                                     |
| IP binding                | `user_combined_ip` check retained (`DISABLE_IP_CHECK` honoured)                                                                                                                                                                                                                                                                                                                                                            |
| Inactivity                | `INACTIVE_SESSION_TIMEOUT` enforced by both apps identically; expiry logs `v-log-user-logout`                                                                                                                                                                                                                                                                                                                              |
| Logout                    | Destroys the legacy file (`destroy_sessions()` equivalent) and invalidates the Laravel session; "sign out other sessions" (E26) deletes every file under `data/sessions/` whose payload has `user=<name>` — a `v-delete-user-sessions USER` (**new**, E26) owned by root because the directory is only readable by `arcpanelweb` within the panel; until then an admin can `rm` the directory to force re-login everywhere |
| Suspended / demoted users | Re-checked on every request from `v-list-user`, not from the session, as today                                                                                                                                                                                                                                                                                                                                             |
| Session content           | Only the keys listed in [02](./02-laravel-foundation.md) §5.5 — no passwords (legacy stored the password during 2FA; Laravel stores a `pending_2fa_user` marker with a 5-minute expiry instead)                                                                                                                                                                                                                            |

### 4.5 Login rate limiting

Laravel `ThrottleRequests` on `/login` (10 attempts / 5 minutes per IP + username pair) in addition to the existing Fail2Ban `arcpanel` jail reading `v-log-user-login` failures. 2FA attempts: 3 failures invalidate the pending marker (legacy parity).

---

## 5. CSRF

- All Laravel forms and Vue island mutations send the Laravel `_token` (meta tag / `X-CSRF-TOKEN`), verified by `VerifyCsrfToken`. No GET endpoint changes state.
- In 1.x, legacy pages kept `web/inc/prevent_csrf.php` (origin/referer check with `POLICY_CSRF_STRICTNESS`) and the `?token=` parameter on action links, and Laravel appended the session `token` when linking into a legacy action URL. None remain since 2.0 (E8).
- `LegacyCsrfStrictness` middleware applies the same origin/referer policy to Laravel POSTs so admins who set strictness `2` get one behaviour across both apps.
- The web terminal WebSocket verifies `Origin` against the hostname and server IPs (`server.js`), which doubles as its CSRF control; log streaming (E14/E4) reuses that server and therefore that check.

---

## 6. Secrets handling

Referenced by [02](./02-laravel-foundation.md) §4.2 and §4.6.

### 6.1 In the panel app

- `Secret` value object wraps any password, token, key or connection string entering a controller. `__toString` is disabled; `reveal()` is the only accessor; `var_export`/`dump`/`json_encode` render `"[secret]"`.
- `ArcCommand` writes a `Secret` to a temp file (`web-app/storage/tmp/`, `0600`, owner `arcpanelweb`, deleted in `finally`) and passes the path when `config/arc-commands.php` marks the command as file-accepting (`v-check-user-password`, `v-add-user`, `v-add-mail-account`, `v-add-database`, …); otherwise inline, masked in every log line, exception message, job record ([14](./14-later-backlog.md) E15) and audit event (E23). The masking is by argument **position** declared in the config, not by pattern matching.
- Laravel's own `.env` equivalent `/etc/arcpanel/arcpanel.env` (`root:arcpanelweb 0640`) holds only `APP_KEY` and flags. `APP_KEY` encrypts the `arcpanel_session` cookie and nothing persistent, so rotating it only logs everyone out of flash state.
- Never in URLs, never in flash messages, never in Blade (`{{ }}` escaping is not the point — the value must not reach the template at all).

### 6.2 Third-party credentials (Cloudflare tokens, deploy keys, webhook secrets, SMTP relay passwords, AI keys)

- Stored by `v-*` commands under `/usr/local/arcpanel/data/secrets/<user>/<kind>/<name>` (**new** directory; `root:root 0700` dir, `0600` files). The panel never reads them; commands that need them read them as root. Server-wide channel secrets (E16) live in `data/secrets/system/`.
- **Write-only in the UI:** after saving, the UI shows only a fingerprint (last 4 characters or a SHA-256 prefix) and "Replace" / "Delete". `v-list-*` commands for these objects never print the value.
- Deleted with the user: `v-delete-user` hook removes `data/secrets/<user>/`; nightly `v-check-sys-ports`-style reconciliation (E17 Doctor) reports secrets of non-existent users.
- Included in backups? **No** by default (`v-backup-user` is unchanged; the directory is outside the user's tree). E4 app `.env` files inside `/home/<user>/apps/` are backed up with the app; documented.

### 6.3 Existing upstream behaviour kept

`user.conf` stores `MD5` (password hash) and `RKEY`; access keys live in `data/access-keys/` (`0600 root`); legacy API keys in `data/keys/`. These files are root-only and reach the panel only through `v-list-*` JSON that already omits hashes where upstream does so; `UserRepository` additionally strips `MD5`, `RKEY`, `TWOFA`, `QRCODE` before any user record is passed to a view or JSON response.

---

## 7. Filesystem: path confinement and uploads

### 7.1 Path confinement (File Manager v2, Node, Docker, Git deploy)

Two independent checks, both mandatory:

1. **Laravel:** `UserPath::resolve(user, input)` normalises `..`, rejects `\0`, requires the result to start with `/home/<user>/` (home from `v-list-user` `HOME`), and refuses symlink traversal by comparing against the CLI's resolved path returned by `v-get-fs-file-type`/`v-list-fs-directory`. Returns **404** on violation.
2. **CLI:** every `v-*-fs-*` command checks its paths with `func/fs.sh` and runs the action with `user_exec` (so even a passing path cannot touch files the user does not own):
   - `fs_check_path` resolves the path with `readlink -f` (`..` and every symbolic link, including one in the last segment) and requires the result to be the home from `/etc/passwd` or below `<home>/`; the trailing slash keeps `/home/bob2` out for `bob`. Only absolute paths pass; `/tmp` and anything else outside the home answer `E_FORBIDEN`. A path whose last segment does not exist yet (a new file, a copy or move destination) is checked through its parent; a path below a missing folder answers `E_NOTEXIST` when the part that exists is in the home.
   - `fs_check_new_path` is for folders that `mkdir -p` creates with their parents (`v-add-fs-directory`, the destination of `v-extract-fs-archive`): the deepest part that exists, a symbolic link included, must resolve inside the home, and the missing part may not hold `.` or `..`.
   - `fs_check_entry` also refuses the home itself, for the commands that remove or move what they are given (`v-delete-fs-file`, `v-delete-fs-directory`, the sources of `v-move-fs-file` and `v-move-fs-directory`).
   - The path is checked before the command looks at it as root (`-e`, `-f`, `-d`), so the answer does not tell whether a path outside the home exists.

   Until E34 batch 5 (D53 of the [spec](../features/specs/2026-10-08-003-e34-api-v2-batch5-design.md)) the thirteen inherited commands (`v-add-fs-archive`, `v-add-fs-directory`, `v-add-fs-file`, `v-change-fs-file-permission`, `v-check-fs-permission`, `v-copy-fs-*`, `v-move-fs-*`, `v-delete-fs-*`, `v-extract-fs-archive`, `v-open-fs-file`) used an unanchored `egrep "^/tmp|^$homedir"`, which accepted `/tmp` and `/home/bob2` for `bob`; they use `fs_check_path` now. Callers that worked outside the home stage under it or in the panel's staging tree instead: Quick Install writes files through `v-add-fs-upload`, and `v-restore-user` unpacks mail and user folders with `user_exec tar` itself. BATS (`test/arcpanel-files.bats`) proves `/tmp`, `/home/<user>x`, `..`, and links to `/etc` and to another home refused by every command. The check and the action are two steps, so a user can swap a path between them; the action still runs as the user and cannot reach more than the user's own permissions allow.

Admins and resellers acting on another user go through the same resolution for the **effective** user; there is no "browse as root" feature.

### 7.2 Uploads (File Manager v2)

The mechanism is defined in [06](./06-file-manager-v2.md) §4.2 and §6.2; the security properties it must keep:

- Chunks are staged in `web-app/storage/uploads/<token>/` (`arcpanelweb:arcpanelweb 0700`), which is **not** under any web root and is on the panel's filesystem, never in a user's home. `arcpanelweb` never writes inside `/home/<user>/` directly — it cannot (the `g:arcpanel-users:---` ACL set by `v-add-user` keeps homes closed to it).
- Assembly happens in Laravel; the hand-off into the home is one root-side command, `v-add-fs-upload USER STAGED_FILE DST_PATH` (**new**, E3), which verifies that the staged file is inside the staging directory and owned by `arcpanelweb`, that the destination resolves inside the user's home and that the parent is writable **by the user** (`user_exec test -w`), then `install -o USER -g USER -m MODE` + atomic rename, and deletes the staged file. The same command reports `E_DISK` when the user's quota or the disk is full. `v-save-fs-file` (editor) is the same hand-off with an `mtime` precondition.
- No execution from staging: nothing serves `web-app/storage/`, and the panel's PHP pool never includes files from it. Staged files that are never finished are removed after 24 h.
- Optional ClamAV scan at `finish` (`clamdscan --fdpass`, only when `ANTIVIRUS_SYSTEM` is set), result shown as a warning; mandatory quarantine arrives with E25.
- Size limits: `client_max_body_size` for `/files/{user}/api/upload/…` set to the chunk size (8 MiB) so one request cannot exhaust disk; staging free space is checked at `init`; the total is pre-checked against `U_DISK`/`DISK_QUOTA` and finally enforced by `v-add-fs-upload`.
- Downloads are the hand-off in reverse: `v-add-fs-download USER PATH TICKET` (**new**) copies the file as root into `web-app/storage/downloads/<ticket>` (`arcpanelweb 0600`), and NGINX serves it through an `internal` location via `X-Accel-Redirect` — the panel never gains read access to user homes.

---

## 8. Per-user runtime isolation (Node, pm2)

| Control           | Mechanism                                                                                                                                                                                |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Process ownership | pm2 daemon under `systemd --user` of the hosting user (`loginctl enable-linger`); never root, never `arcpanelweb`                                                                        |
| Resources         | inherits `user-<uid>.slice` limits written by `v-update-user-cgroup` from the package (`CPU_QUOTA`, `MEMORY_LIMIT`, `SWAP_LIMIT`); pm2 `max_memory_restart` derived from the same values |
| Network           | apps bind `127.0.0.1:<port>` from the registry; `PUBLIC='yes'` is an explicit, firewall-tagged exception ([04](./04-port-registry.md) §7)                                                |
| Filesystem        | app dir inside `~/web/<domain>/nodeapp`; nvm in `~/.nvm`; jailed shells keep working because the home is bind-mounted by bubblewrap (`install/common/bubblewrap`)                        |
| Suspension        | `v-suspend-user` hook stops the user's pm2 and closes public ports                                                                                                                       |
| Supply chain      | `npm audit --json` surfaced read-only in the UI; nvm tarballs verified against upstream SHASUMS256 before install; offline cache under `data/cache/node/` is `root`-written              |

---

## 9. Docker hardening (E4)

Decided in ADR-0003 (rootless vs. group mode); the controls below hold in both modes.

| Rule                                                                                                                                                                 | Enforcement                                                                                                                                                                                                                                                   |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| No `privileged`, no added capabilities, no `pid: host`, no `network_mode: host`, no `/var/run/docker.sock` mounts, no bind mounts outside `/home/<user>/apps/<app>/` | `v-add-docker-app` renders Compose from the catalog manifest **only**; user-editable fields are variables, not Compose text. A validator (`func/docker.sh: compose_lint`) rejects forbidden keys even in catalog files (defence against a bad catalog update) |
| Pinned images                                                                                                                                                        | manifests pin `image: name@sha256:…` or an exact tag; `v-update-docker-app` pulls the manifest's new pin, never `latest`                                                                                                                                      |
| Resource limits                                                                                                                                                      | `deploy.resources.limits` from the user's package; container cgroup placed under the user's slice (rootless) or `--cgroup-parent user-<uid>.slice` (group mode)                                                                                               |
| Read-only root FS                                                                                                                                                    | manifests set `read_only: true` with explicit `tmpfs` where the upstream image allows; documented per app                                                                                                                                                     |
| Ports                                                                                                                                                                | always `127.0.0.1:<registry-port>:<container-port>`; public exposure only through the registry                                                                                                                                                                |
| Logs                                                                                                                                                                 | `json-file` driver with `max-size`/`max-file` so a chatty container cannot fill `/var/lib/docker`                                                                                                                                                             |
| Registry access                                                                                                                                                      | pulls go out as the user (rootless) or the daemon (group mode); optional mirror/proxy setting for air-gapped hosts                                                                                                                                            |
| Admin kill switch                                                                                                                                                    | `v-change-sys-docker-status off` stops all app containers and disables the store; per-package `DOCKER='no'`                                                                                                                                                   |

---

## 10. Web terminal and log streaming

- `src/deb/web-terminal/server.js` runs as root (needed for `node-pty` as any user), binds `127.0.0.1:8085`, is reached only through the `/_shell/` proxy; it checks `Origin` against hostname/IPs and resolves the `ARCPANELSID` cookie through `web-terminal-session-auth.php`, then spawns a shell as `look ?? user`. Only users whose `SHELL` is not `nologin` get a terminal (existing rule, enforced in the helper by reading `v-list-user`).
- Log streaming (E14 `pm2 logs`, E4 `docker compose logs`, E15 job output) adds a message type to the same server: the client sends `{type:"stream", kind:"node|docker|job", id}`; the server runs the matching `v-list-*-logs USER ID follow` **as the resolved user** via `node-pty`, never a free-form command. Shell-less users may stream logs but not open a shell.
- A session that is read-only (suspended-with-view, protected admin) is refused by the helper for shells and allowed for logs.

---

## 11. Browser hardening (Laravel app)

- Headers from `arcpanel-nginx` already: `X-Content-Type-Options`, `X-Frame-Options SAMEORIGIN`; Laravel adds `Referrer-Policy: same-origin`, `Permissions-Policy` (camera/microphone/geolocation off), `Cross-Origin-Opener-Policy: same-origin`.
- **CSP:** `default-src 'self'; script-src 'self' 'nonce-<per-request>'; style-src 'self' 'nonce-…'; img-src 'self' data: blob:; connect-src 'self' wss://<host>:8083; frame-ancestors 'self'; object-src 'none'; base-uri 'self'`. Alpine.js evaluates expressions with `new Function` and needs `'unsafe-eval'` — the design uses the **Alpine CSP build** (`@alpinejs/csp`), which forbids inline expressions and requires component logic in `Alpine.data()` modules; this also matches the testable-module convention in [11](./11-testing-and-ci.md) §6.3. Vue islands are compiled (no runtime template compilation), so they need no exception. Chart.js, CodeMirror and xterm are bundled locally. The legacy app is exempt (deleted in E8).
- No inline event handlers or inline `<script>` without nonce; `@vite`-style helper replaced by a `@assets` Blade directive that emits nonced tags for the esbuild outputs.
- Uploaded files are never served from the panel origin (File Manager preview uses `v-open-fs-file` content returned as `Content-Disposition: attachment` or rendered in a sandboxed `<iframe sandbox>` with a `blob:` URL).

---

## 12. Authorization controls summary

- Policies + `OwnerScope` ([03](./03-tenancy-and-authorization.md) §7): 404 for objects outside the principal's tree, deny-by-default `Capabilities` table, no checks in templates.
- Impersonation: only via `ImpersonationPolicy`; the legacy `?loginas=` path was admin-only and is gone since 2.0; every impersonation start/stop is logged with both usernames via `v-log-user-login` today and as an explicit audit event from E23.
- API keys: API v1 scopes a non-admin key by its permission sets and one user argument (unchanged). REST API v2 ([E34 spec](../features/specs/2026-10-07-002-e34-api-v2-design.md)) acts as the key's owner through the panel's policies and `OwnerScope`, checks every command of a plan against the key's sets before the first one runs, and has its own sets (`v2-read`, `v2-write`) that API v1 skips; the `SCOPE` design of [03](./03-tenancy-and-authorization.md) §8 was replaced by it. `API_ALLOWED_IP` and `API_SYSTEM` apply to both; v2 adds 60 requests per minute per key and 10 failed authentications per minute per address, logged to `auth.log` for fail2ban.
- Admin protections unchanged: `POLICY_SYSTEM_PROTECTED_ADMIN`, hidden admin, restricted server settings.

---

## 13. Threat model

| #   | Threat (STRIDE)                                   | Vector                                                      | Mitigation                                                                                                                | Doc                                                                      |
| --- | ------------------------------------------------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| T1  | Spoofing — session theft                          | stolen `ARCPANELSID` cookie                                 | TLS only, `HttpOnly`/`Secure`, IP binding, inactivity timeout, logout invalidation                                        | §4                                                                       |
| T2  | Spoofing — login brute force                      | password guessing, 2FA guessing                             | throttle + Fail2Ban, 3-strike 2FA, generic errors                                                                         | §4.5                                                                     |
| T3  | Tampering — command injection                     | crafted form values reaching `v-*`                          | argv exec, escaping, `\0`/`\n` rejection, `is_format_valid` in every command                                              | §3, [02](./02-laravel-foundation.md) §4.2                                |
| T4  | Tampering — path traversal                        | File Manager, Node entry file, Docker volume paths          | double path confinement, `user_exec`, 404 on violation                                                                    | §7                                                                       |
| T5  | Elevation — reseller reads/changes another tenant | list pages, direct URLs, API keys                           | `OwnerScope` server-side filtering, 404, API v2 through the same policies, role e2e suite                                 | [03](./03-tenancy-and-authorization.md), [11](./11-testing-and-ci.md) §4 |
| T6  | Elevation — reseller impersonates admin           | `/impersonate/admin`, legacy `?loginas=` (1.x)              | `ImpersonationPolicy` requires tree + `ROLE='user'`; legacy path admin-only (1.x)                                         | §12                                                                      |
| T7  | Elevation — user escapes runtime                  | Node/Docker process to host                                 | user-owned processes, cgroups, no socket, no privileged compose, pinned images                                            | §8, §9                                                                   |
| T8  | Information disclosure — secrets in logs          | command logging, job output, audit                          | positional masking, `Secret` type, write-only credentials                                                                 | §6                                                                       |
| T9  | Information disclosure — hashes to the browser    | `v-list-user` JSON forwarded to views                       | `UserRepository` strips `MD5`, `RKEY`, `TWOFA`, `QRCODE`                                                                  | §6.3                                                                     |
| T10 | Repudiation — "who did this?" during login-as     | admin or reseller acting as a customer                      | both usernames logged now; E23 audit event with actor + effective user                                                    | §12, [14](./14-later-backlog.md) E23                                     |
| T11 | Denial of service — upload or log fills disk      | chunked uploads, container logs                             | chunk size limit, quota check on finish, Docker log rotation, `BACKUP_DISK_LIMIT`-style guards                            | §7.2, §9                                                                 |
| T12 | Denial of service — port exhaustion / collisions  | many apps per user                                          | pool size per package, `flock`, nightly reconciliation                                                                    | [04](./04-port-registry.md)                                              |
| T13 | Tampering — malicious catalog update              | compromised catalog Git URL                                 | catalog signed (minisign) and pinned; `compose_lint` rejects dangerous keys; admin approves catalog refresh               | §9, [08](./08-app-store.md)                                              |
| T14 | Spoofing — CSRF on panel actions                  | cross-site POST                                             | Laravel token, SameSite, legacy strictness policy, ws Origin check                                                        | §5                                                                       |
| T15 | Tampering — XSS in panel                          | user-controlled strings (domain names, comments, log lines) | Blade escaping, Vue text binding, nonce CSP, Alpine CSP build, logs rendered as text                                      | §11                                                                      |
| T16 | Elevation — `sudo` rule abuse                     | any file dropped into `bin/`                                | `bin/` is `root` `0755`, written only by the deb and `v-update-sys-arcpanel*`; `ArcCommand` allow-list generated at build | §3                                                                       |

---

## 14. Dependency and supply-chain policy

- Dependency updates are made by the maintainer (no Renovate since 2.0); security advisories trigger out-of-band updates.
- `composer audit` / `npm audit` block PRs above `high` without an expiring exception ([11](./11-testing-and-ci.md) §6.5).
- Laravel: stay on the current major with security support; the bundled PHP 8.5 pins the floor.
- Docker catalog images: pinned digests, refreshed by a reviewed catalog PR, never auto-updated on servers without the E33 policy.
- nvm/Node: tarball checksums verified; `NODE_ALLOWED` package key limits majors to supported lines.

---

## 15. Logging and audit (today → E23)

- Today: `v-log-action USER LEVEL CATEGORY DETAILS` writes per-user `history.log` (`v-list-user-log`), `v-log-user-login` writes the auth log, every `v-*` writes `log_event` to `$ARCPANEL/log/system.log`/`error.log`. `ArcCommand` adds `storage/logs/arc-command.log` (masked).
- Gap: no record of the real actor during impersonation, no cross-tenant view, no integrity. E23 adds the append-only audit stream with actor/effective user/role/IP/action/object/result and a daily hash chain ([14](./14-later-backlog.md) E23). Until then, the Laravel request log line includes `login_user`, `effective_user`, route name and result code so the information exists in logs.
- Log files under `web-app/storage/logs` are `0640 arcpanelweb`, rotated daily, 14 days; nothing from `/home/<user>` is copied into panel logs.

---

## 16. Security review checklist (per epic PR)

1. New `v-*` commands: every argument validated; paths confined; `user_exec` for user-space actions; `check_arcpanel_demo_mode`; `v-log-action` entry; BATS negative tests.
2. New routes: policy method with a `Capabilities` entry; row in the role e2e matrix; `NotReadOnly` on mutations; FormRequest validation.
3. Secrets: enter as `Secret`; file-based passing where possible; masking position declared; stored under `data/secrets/` if persistent; write-only UI.
4. Frontend: no inline scripts/expressions outside Alpine CSP modules; user strings rendered as text; new third-party library bundled locally and reviewed.
5. Processes: anything long-running runs as the hosting user or root `v-*` — never as `arcpanelweb` in the background.
6. Network: ports only via the registry; firewall rules only via `v-add-user-port`.
7. Upgrade: new files/dirs created with explicit owner/mode in the upgrade script; brand check green.
8. Docs: threat table row added if a new vector exists.

---

## 17. Incident response notes

| Situation                                              | Action                                                                                                                                                                   |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Panel session compromise suspected                     | `rm -f /usr/local/arcpanel/data/sessions/sess_*` (all users re-login); rotate `APP_KEY` in `arcpanel.env` and run `php artisan optimize` (only flash/CSRF state is lost) |
| Access key leaked                                      | `v-delete-access-key USER KEY`; review `v-list-user-log` and the API lines in the auth log; from E23 search the audit stream by key id                                   |
| Third-party credential leaked (Cloudflare, SMTP relay) | delete via the owning command (`v-delete-dns-provider`, `v-delete-sys-smtp-relay`) and revoke at the provider; the panel never cached it                                 |
| Malicious Docker app or runaway Node app               | `v-change-sys-docker-status off` / `v-stop-node-app`, then `v-suspend-user` (hook closes public ports and stops pm2)                                                     |
| Suspected modified `bin/`                              | `dpkg --verify arcpanel`; reinstall the package; compare with the files of the shipped packageball                                                                       |
| Vulnerability report                                   | `SECURITY.md` process (exists); fixes shipped as a patch release through the release pipeline with upstream notification when the issue is in shared code                |

---

## 18. Open questions

1. Alpine CSP build is stricter than the Alpine used by the legacy app; the design system (05) must adopt the `Alpine.data()` convention from the first component. Confirm no third-party Alpine plugin in use requires `unsafe-eval` (`@alpinejs/collapse` is fine).
2. Rootless Docker places containers in the user's cgroup for free but complicates `127.0.0.1` port publishing (slirp4netns/pasta) — the port-binding requirement in §9 must be validated in the ADR-0003 spike.
3. Whether `data/sessions` should move from `files` to a root-owned helper for E26 "sign out other sessions"; alternative is a `arcpanelweb`-owned small index file keyed by user, maintained by `LegacySession::establish()`.
