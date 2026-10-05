# E6 batch 1 — Template metadata and ten new templates — design

**Status:** Approved (2026-10-05) · **Epic:** E6 batch 1 · **Previous specs:** [E1-S2](./2026-10-05-002-e1-s2-dashboard-web-domains-design.md), [E12](./2026-10-05-004-e12-port-registry-design.md) · **Roadmap:** [§3 E6](../../roadmap.md)

## 1. Summary

Today a web template is a file name in a dropdown. E6 batch 1 gives every template a metadata sidecar (title, description, category, runtime, variables), lists templates with that metadata through a new `v-list-web-templates-meta`, and wires the `x-ap-card-picker` of E1-S2 into the Laravel Web Domains forms. It ships ten new templates for non-PHP stacks on both supported web stacks, and a per-domain variable mechanism so proxy templates can point at a backend port taken from the E12 port registry. E14 (Node apps) and E4 (Docker apps) bind their ports through the same mechanism.

## 2. Decisions taken in the brainstorm

| #   | Question                              | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| --- | ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Which web stacks                      | **Both.** `nginx-fpm` (NGINX is the web server, `WEB_SYSTEM=nginx`, `WEB_BACKEND=php-fpm`): the new templates are **web templates** in `templates/web/nginx/php-fpm/`. `nginx-apache` (the installer default: NGINX proxy in front of Apache, `PROXY_SYSTEM=nginx`, `WEB_SYSTEM=apache2`): the new templates are **proxy templates** in `templates/web/nginx/`. Both copies of a template share one metadata file. Apache-only and Apache2 templates are out of scope (batch 3).                                                                         |
| D2  | Metadata for existing templates       | **Every shipped template of the two stacks gets metadata** (§4.4), so the card picker is useful from day one. A template without metadata (an admin's custom template) still shows, with its file name as title and category `other`.                                                                                                                                                                                                                                                                                                                    |
| D3  | Backend port of proxy templates       | **The port must belong to the domain owner's pool in the E12 registry.** NGINX runs as a system user, so a free port field would let a user publish another user's app or a local service (Redis, MySQL, the panel) on their domain. The rule applies to admins too: a domain always belongs to one user, and the check uses that user's pool.                                                                                                                                                                                                           |
| D4  | Template list                         | **Ten templates:** `static`, `spa`, `nodejs-proxy`, `nodejs-proxy-ws`, `docker-proxy`, `python-gunicorn`, `nextjs`, `nuxt`, `ghost`, `strapi`. Merges roadmap §3 (E6) with the batch 1 dependencies of §5 and §6 (`docker-proxy`, `nodejs-proxy-ws` for E4 and E14).                                                                                                                                                                                                                                                                                     |
| D5  | How the port reaches the NGINX config | **Template trigger script plus a per-domain variables file** (§5). `add_web_config` (`func/domain.sh`) substitutes a fixed token list and then always runs `<template>.sh` when it is executable, on every path that writes a domain config (add, rebuild, template change, Let's Encrypt, restore). The trigger replaces the `%arc_*%` tokens. Rejected: NGINX `set $var` from an included file (no upstream keepalive, a missing file passes `nginx -t` and fails every request); changing `add_web_config` (not additive, central upstream function). |
| D6  | Orphaned ports                        | **No new upstream hook.** The Laravel UI releases `web:<domain>` ports when it switches a domain to a template without a port or deletes the domain. Changes made through the CLI or the legacy UI leave orphans that the nightly `v-check-sys-ports` reports and `repair` releases (§5.4).                                                                                                                                                                                                                                                              |
| D7  | Legacy UI                             | **Unchanged.** A port template picked in the legacy dropdown without variables renders a 503 "App not configured" page; NGINX keeps working (§5.3).                                                                                                                                                                                                                                                                                                                                                                                                      |
| D8  | Per-template documentation pages      | Out of scope; the docs generator stays in Q4 with batch 3. Metadata carries an optional `docs` URL.                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

## 3. Milestones

| #   | Milestone  | Content                                                                                      | Done when                                                                                     |
| --- | ---------- | -------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| M1  | Metadata   | §4: format, metadata for every shipped template, `v-list-web-templates-meta`, the CI check   | the meta cases of `test/arcpanel-templates.bats` pass on `arcpanel-test`; the CI check passes |
| M2  | Variables  | §5: `func/arc-template.sh`, the three `*-tpl-vars` commands, the `v-check-sys-ports` finding | the variable, port and 503 cases of `test/arcpanel-templates.bats` pass                       |
| M3  | Templates  | §6: ten templates × two stacks × `.tpl`/`.stpl`, triggers, metadata                          | every new template passes `nginx -t` and the `curl` cases on both stacks                      |
| M4  | Laravel UI | §7: `TemplateCatalog`, card pickers, port field, save order, Pest and Playwright             | done criteria of §10                                                                          |

Each milestone is one tested commit on `develop`.

## 4. Metadata

### 4.1 Location

Metadata files live in `install/deb/templates/web/meta/` and are installed to `$WEBTPL/meta/`. `v-update-web-templates` already copies every folder of `templates/web/` except `nginx/` with `cp -rf`, so the installer and the upgrade scripts need no change.

Lookup for template `NAME` of kind `KIND` (`web`, `proxy`, `backend`):

1. `meta/KIND/NAME.json` — used when the same name means different things per kind (`default`, `hosting`, `caching`, `no-php`).
2. `meta/NAME.json` — shared by both stacks. All ten new templates use this form.
3. Backend templates of the form `PHP-8_3` match `meta/backend/PHP-x_y.json`, a pattern file whose `title` contains `{version}` (rendered as `PHP 8.3`).

### 4.2 Format

```json
{
	"title": "Node.js app (reverse proxy)",
	"description": "Proxies the domain to a Node.js process listening on a port of your pool.",
	"category": "nodejs",
	"runtime": "node",
	"internal": false,
	"variables": [{ "key": "BACKEND_PORT", "type": "port", "required": true, "label": "App port" }],
	"docs": "https://docs.arcpanel.top/docs/server-administration/web-templates.html"
}
```

| Field         | Rules                                                                                                                           |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `title`       | required, ≤ 60 characters, English                                                                                              |
| `description` | required, one sentence, ≤ 160 characters, English                                                                               |
| `category`    | required, one of `php-cms`, `php-framework`, `ecommerce`, `nodejs`, `python`, `static`, `proxy`, `other`                        |
| `runtime`     | optional, one of `php`, `node`, `python`, `docker`, `none`; informational (E5 links it to an installable runtime later)         |
| `internal`    | optional, default `false`; `true` hides the template from the picker (`suspended`)                                              |
| `variables`   | optional list. `key`: `[A-Z][A-Z0-9_]*`. `type`: `port` only in batch 1. `required`: boolean. `label`: English, ≤ 40 characters |
| `docs`        | optional `https://` URL                                                                                                         |

The Laravel app shows `title`, `description` and `label` through `__()`, so they are translation source strings like every other English string of the app.

### 4.3 `v-list-web-templates-meta [KIND] [FORMAT]`

New command, read-only. `KIND` is `web` (default), `proxy` or `backend`; `FORMAT` is `json`, `shell`, `plain` or `csv` like `v-list-web-templates`.

- The template names come from the **same directories and filters** as the existing list commands: `$WEBTPL/$WEB_SYSTEM/$WEB_BACKEND/` for `web`, `$WEBTPL/$PROXY_SYSTEM/` without `proxy_ip` for `proxy`, `$WEBTPL/$WEB_BACKEND/` for `backend`. Only templates present on disk are listed; metadata without a template is ignored.
- Each entry: `NAME`, `TITLE`, `DESCRIPTION`, `CATEGORY`, `RUNTIME`, `INTERNAL`, `VARIABLES` (JSON array in `json`, `KEY:type` list otherwise), `DOCS`, `HAS_META` (`yes`/`no`).
- An empty `PROXY_SYSTEM` or `WEB_BACKEND` gives an empty list for that kind, as the existing commands do.
- JSON is parsed with `jq`, which the installer already pulls in. A malformed metadata file is treated as missing and logged once to `$HESTIA/log/system.log`.

### 4.4 Metadata for existing templates

Written in M1 for every template shipped for the two stacks:

- `nginx/php-fpm/` — 53 web templates (`suspended` is `internal`);
- `nginx/` — proxy templates `default`, `caching`, `hosting`, `suspended` (`internal`);
- `apache2/php-fpm/` — web template `default`;
- `php-fpm/` — backend templates `default`, `no-php`, `socket`, plus the `PHP-x_y` pattern.

Apache-only templates (`apache2/default`, `hosting`, `phpcgi`, `phpfcgid`) are left without metadata and fall back to their file name.

### 4.5 CI check

`scripts/check-template-meta.js` runs in `lint.yml` next to `check-tokens.js`. It fails when a metadata file is not valid against §4.2, when a template of §4.4 or §6 has no metadata, or when a metadata file names no template in any of the directories of §4.3.

## 5. Per-domain variables

### 5.1 Storage

`$HESTIA/data/users/<user>/web-tplvars/<domain>.conf`, one `KEY='value'` per line, mode `640`, owner root. The file belongs to the domain, not to a template: switching between two port templates keeps `BACKEND_PORT`.

### 5.2 Commands

| Command                                               | Behaviour                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v-change-web-domain-tpl-vars USER DOMAIN KEY=VALUE…` | Finds the **active template**: the domain's `PROXY` template on `nginx-apache`, its `TPL` on `nginx-fpm`. Every `KEY` must be declared by that template's metadata (`E_INVALID` otherwise). For a `port` variable it applies §5.5. Writes the new file to a temporary path, runs `v-rebuild-web-domain USER DOMAIN`, and on failure (`nginx -t` refuses the result) restores the previous file, rebuilds again and returns `E_RESTART`. Logs through `v-log-action` like other web commands. |
| `v-list-web-domain-tpl-vars USER DOMAIN [FORMAT]`     | Prints the stored variables, plus `ACTIVE_TEMPLATE` and `MISSING` (required variables of the active template without a value).                                                                                                                                                                                                                                                                                                                                                               |
| `v-delete-web-domain-tpl-vars USER DOMAIN`            | Releases the ports whose `APP` is `web:<domain>` (`v-release-user-port`), removes the file and rebuilds the domain when it exists. Idempotent: a missing domain or file is not an error, so it also cleans up after a deleted domain.                                                                                                                                                                                                                                                        |

All three check `USER` and `DOMAIN` the usual way (`is_object_valid`), refuse suspended objects for changes (`is_object_unsuspended`), and hold `data/ports/.lock` while they touch the registry.

### 5.3 Rendering: `func/arc-template.sh`

`arc_apply_tpl_vars USER DOMAIN` is called by the trigger of every template that declares variables. For each of `nginx.conf` and `nginx.ssl.conf` in `$HOMEDIR/<user>/conf/web/<domain>/` that exists:

1. Read the variables file and replace every `%arc_<key in lower case>%` token with its value. A `port` value that is not an integer between 1024 and 65535 counts as missing.
2. When a token remains (a required variable is missing), replace the lines between `# arc:app-begin` and `# arc:app-end` with:

   ```nginx
   location / {
   	default_type text/html;
   	return       503
   	  '<!doctype html><title>App not configured</title><h1>This site&#39;s app is not configured yet.</h1>';
   }
   ```

   The page is inline so it works for domains created before E6, whose `document_errors/` was copied from the skeleton at creation time.

3. Leave the file untouched when it has no `%arc_` token, so running twice is harmless (the trigger runs once for `.tpl` and once for `.stpl`).

The trigger of a template with variables is three lines:

```bash
#!/bin/bash
source /usr/local/hestia/func/arc-template.sh
arc_apply_tpl_vars "$1" "$2"
```

Existing triggers (`hosting.sh`, `caching.sh`) do not change. The `.sh` files are installed with mode `755`; a missing exec bit leaves the tokens in place and `nginx -t` fails, which the BATS suite catches.

### 5.4 Orphans in `v-check-sys-ports`

New finding `orphan-web`: an entry whose `APP` is `web:<domain>` when the domain no longer exists, or when its active template declares no `port` variable, or when the domain's variables file does not hold that port. `v-check-sys-ports repair` releases these entries. `v-check-sys-ports` and the Admin Ports page of E12 list it with the other findings.

### 5.5 Port rules

The port is checked against the pool of the **domain owner**, whoever runs the command.

| Registry state of the port in the owner's pool                       | Result                                                                                        |
| -------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| unassigned (single entry or inside a block)                          | `v-allocate-user-port USER web:<domain> tcp PORT` (splits a block)                            |
| `APP` is `web:<domain>`, `node:<domain>`, `docker:<any>` or `manual` | accepted as is                                                                                |
| `APP` is `web:<other>` or `node:<other>`                             | `E_INUSE`, naming the app                                                                     |
| not in the owner's pool                                              | `E_FORBIDEN`                                                                                  |
| value `auto`                                                         | `v-allocate-user-port USER web:<domain>` (lowest free port; `E_LIMIT` when the pool is empty) |

When `BACKEND_PORT` changes from a port with `APP=web:<domain>` to another port, the old port is released. With `PORT_REGISTRY='no'` (E12) only the range check of §5.3 applies: an admin who turns the registry off accepts that D3 no longer holds; the docs of `PORT_REGISTRY` say so.

## 6. The ten templates

Every template exists as `.tpl` and `.stpl` in `templates/web/nginx/php-fpm/` (`nginx-fpm`) and in `templates/web/nginx/` (`nginx-apache`): 40 template files, 14 triggers (the seven port templates × two stacks), 10 shared metadata files.

All of them keep the upstream structure of `no-php.tpl`: `listen`, `server_name %domain_idn% %alias_idn%`, the domain access, bytes and error logs, `include …/nginx.forcessl.conf*` (`.tpl` only), the `/\.(?!well-known\/)` deny, `/error/`, `/vstats/`, the phpMyAdmin/phpPgAdmin includes and `include …/nginx.conf_*` (`nginx.ssl.conf_*` in `.stpl`). The application part sits between `# arc:app-begin` and `# arc:app-end`. The `.stpl` files take their SSL block from `default.stpl` of the same folder. The `nginx-apache` copies listen on `%proxy_port%`/`%proxy_ssl_port%` and never pass requests to Apache; the domain's Apache web template stays `default` and is idle.

Proxy locations use `proxy_pass http://127.0.0.1:%arc_backend_port%;`, `proxy_http_version 1.1`, `Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`.

| Template          | Variables      | Application part                                                                                                                                                | Category / runtime  |
| ----------------- | -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- |
| `static`          | —              | serves `%docroot%`, `index index.html index.htm`, long `expires` and `Cache-Control: public` for assets, `gzip_static on`, security headers, `.php` returns 404 | `static` / `none`   |
| `spa`             | —              | as `static`, plus `try_files $uri $uri/ /index.html`; `index.html` sent with `Cache-Control: no-cache`                                                          | `static` / `none`   |
| `nodejs-proxy`    | `BACKEND_PORT` | proxies `/`                                                                                                                                                     | `nodejs` / `node`   |
| `nodejs-proxy-ws` | `BACKEND_PORT` | as `nodejs-proxy`, plus `Upgrade`/`Connection` headers and `proxy_read_timeout 3600s`                                                                           | `nodejs` / `node`   |
| `docker-proxy`    | `BACKEND_PORT` | as `nodejs-proxy-ws`, plus `client_max_body_size 512m`, `proxy_buffering off`, `proxy_request_buffering off`                                                    | `proxy` / `docker`  |
| `python-gunicorn` | `BACKEND_PORT` | `/static/` and `/media/` served from `%docroot%` with long `expires`, the rest proxied                                                                          | `python` / `python` |
| `nextjs`          | `BACKEND_PORT` | `/_next/static/` proxied with `Cache-Control: public, max-age=31536000, immutable`, websocket headers on `/_next/webpack-hmr`, the rest proxied                 | `nodejs` / `node`   |
| `nuxt`            | `BACKEND_PORT` | `/_nuxt/` proxied with the immutable cache header, the rest proxied                                                                                             | `nodejs` / `node`   |
| `ghost`           | `BACKEND_PORT` | `client_max_body_size 50m`, `/ghost/` sent with `Cache-Control: no-store`, the rest proxied                                                                     | `nodejs` / `node`   |
| `strapi`          | `BACKEND_PORT` | `client_max_body_size 256m`, websocket headers, the rest proxied                                                                                                | `nodejs` / `node`   |

Next.js and Nuxt assets are proxied (not read from disk) because the build output folder depends on the project layout; NGINX caches them through the response headers.

## 7. Laravel UI

### 7.1 `App\Services\WebDomains\TemplateCatalog`

- `templates(string $kind): Collection<TemplateMeta>` — calls `v-list-web-templates-meta <kind> json`, caches the result for five minutes per kind, drops `internal` entries except the domain's current template.
- `TemplateMeta` (readonly): `name`, `title`, `description`, `category`, `runtime`, `variables`, `docs`, `hasMeta`; `portVariable(): ?string`.
- `activeKind(): string` — `proxy` on `nginx-apache`, `web` on `nginx-fpm`; tells the forms which picker carries the port field.
- `WebDomainService::webTemplates()`, `backendTemplates()` and `proxyTemplates()` keep their signatures for other callers (`PackageService`); the forms switch to the catalog.

### 7.2 Forms

- **Add** and the **Advanced** tab of **Edit** use `x-ap-card-picker` for the web template and the proxy template: search on title, description and name, category filter chips, a `runtime` badge, the file name in small print. The backend template keeps `x-ap-select`.
- When the selected template of the active kind declares a `port` variable, an "App port" field appears: a select with "Assign automatically from my pool" (value `auto`, default) and the owner's unassigned and `manual` ports from `v-list-user-ports`, plus the current port. When the owner's pool is empty and limited, the field shows the E12 hint to ask the owner (admin or reseller) for ports.
- `StoreWebDomainRequest` and the Advanced tab request validate the port value as `auto` or an integer in the owner's list.
- **Save order:** template commands as in E1-S2 (`v-change-web-domain-tpl` / `v-change-web-domain-proxy-tpl`), then `v-change-web-domain-tpl-vars USER DOMAIN BACKEND_PORT=<value>` when the active template has a port variable, or `v-delete-web-domain-tpl-vars USER DOMAIN` when it has none and variables existed. Domain delete calls `v-delete-web-domain-tpl-vars` after `v-delete-web-domain`. Each step is a `Step` of the existing `ApplyResult`, so a failed step is reported in place, as E1-S2 does.
- The domain detail and list pages show the template title (falling back to the name) and, for port templates, the port with a link to the Ports page.

### 7.3 Policies

No new capability. Choosing templates keeps `WebDomainPolicy::updateTemplates` (E1-S2). The port list comes from the owner's registry entries, which `PortPolicy` already lets admins, the owning reseller and the user read.

## 8. Errors and security

| Case                                                | Result                                                                                                  |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| port not in the owner's pool                        | `E_FORBIDEN`; error on the port field                                                                   |
| port used by another domain's web or node app       | `E_INUSE`; error on the port field, naming the app                                                      |
| owner pool empty (limited `PORTS`)                  | `E_LIMIT`; the E12 "ask your provider" hint                                                             |
| `KEY` not declared by the active template           | `E_INVALID`                                                                                             |
| `nginx -t` fails after the rebuild                  | previous variables restored, domain rebuilt, `E_RESTART`                                                |
| required variable missing (legacy UI, CLI, restore) | domain serves the 503 "App not configured" page; NGINX stays valid                                      |
| suspended user or domain                            | changes refused (`E_SUSPENDED`); the upstream `suspended` template applies as today; variables are kept |
| domain renamed with `v-change-web-domain-name`      | the variables file keeps the old name: 503 page and an `orphan-web` finding until the port is set again |
| user restored from a backup                         | variables are not in the backup: 503 page until the port is set again; restore hook left to E14         |

Values are written only after validation (`KEY` pattern, integer port), so no shell or NGINX syntax reaches the config through a variable.

## 9. Testing

| Suite                               | Cases                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BATS `test/arcpanel-templates.bats` | `v-list-web-templates-meta` lists exactly the templates on disk for each kind, with fallback entries for a template without metadata and a malformed file; every new template passes `nginx -t` on the server's stack; a port template without variables answers 503; `auto` allocates a `web:<domain>` port and `curl --resolve` reaches a `python3 -m http.server` on it; another user's port → `E_FORBIDEN`; `web:<other>` → `E_INUSE`; empty limited pool → `E_LIMIT`; port change releases the old port; rebuild and template change keep the variables; `v-delete-web-domain-tpl-vars` releases and is idempotent; `orphan-web` found after a CLI template change and released by `repair`; cleanup |
| Stack coverage                      | `arcpanel-test` runs the installer default (`nginx-apache`). The `nginx-fpm` copies are checked by a BATS case that renders each `.tpl`/`.stpl` of `nginx/php-fpm/` with the substitutions of `add_web_config` into a temporary `nginx -t -c` config, and by the CI matrix once E10 adds an `nginx-fpm` job                                                                                                                                                                                                                                                                                                                                                                                               |
| CI `scripts/check-template-meta.js` | §4.5                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| Pest                                | `TemplateCatalog` parsing, caching and `internal` filtering (fake `ArcCommand`); port field validation; save order for template change with and without port, port change, switch to a template without port, delete; error mapping of `E_FORBIDEN`, `E_INUSE`, `E_LIMIT` to the port field                                                                                                                                                                                                                                                                                                                                                                                                               |
| Playwright                          | add a web domain choosing `nodejs-proxy` in the card picker with an automatic port; the domain answers 503; start a listener on the port; the domain answers 200                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

## 10. Done criteria

1. On `arcpanel-test`, a user adds a web domain on Laravel, picks `nodejs-proxy` from the card picker, gets a port from their pool, and the domain reaches an app on that port over HTTP and HTTPS (Let's Encrypt staging or the self-signed certificate).
2. Every template in the picker shows a title, a description and a category; search and category filters work.
3. A user cannot bind a port outside their pool through the UI or the CLI.
4. All suites of §9 pass; `nginx -t` passes for all 40 new template files.
5. No line of an existing upstream file in `bin/`, `func/` or `install/` changes; the new files are listed in the upstream-diff allowlist as additions.

## 11. Out of scope

Apache2 templates; batch 2 and 3 templates; template documentation pages; variable types other than `port`; restoring variables from backups; template metadata editing in the UI; card picker in the legacy UI.

## 12. Risks

| Risk                                                       | Mitigation                                                                                              |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Upstream renames the trigger mechanism in `add_web_config` | BATS 503 and `curl` cases fail at once; the trigger has existed since early VestaCP                     |
| Admin copies a port template without its `.sh`             | tokens stay, `nginx -t` fails and the rebuild reports it; docs note that custom copies need the trigger |
| Two copies of each template drift apart                    | both copies are reviewed together; the BATS render case covers both folders                             |
| Metadata goes stale when upstream adds a template          | the CI check fails on a shipped template without metadata                                               |
