# REST API documentation

ArcPanel has two REST APIs, both reached with the same access keys:

| API | Path        | Served by                  | What it is                                                                                                 | Design                                                                     |
| --- | ----------- | -------------------------- | ---------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| v1  | `/api/`     | `lib/api/index.php`        | One endpoint that runs a `v-*` command by name with positional arguments                                   | [E29 OpenAPI spec](../features/specs/2026-10-07-001-e29-openapi-design.md) |
| v2  | `/api/v2/…` | the panel app (`web-app/`) | Resource routes with JSON bodies, HTTP verbs and status codes, owner scoping and a dry run of every change | [E34 API v2 spec](../features/specs/2026-10-07-002-e34-api-v2-design.md)   |

v1 is complete and stays supported. v2 covers users, packages and web domains so far; the commands it does not cover yet stay reachable through v1 with the same key.

| File              | What it is                                                                                    |
| ----------------- | --------------------------------------------------------------------------------------------- |
| `openapi.yaml`    | The v1 document, **generated** by `scripts/build-openapi.js`; do not edit it by hand          |
| `v2/openapi.json` | The v2 document, **generated** by `php artisan arcpanel:api-docs`; do not edit it by hand     |
| `index.html`      | Swagger UI for both documents (loads `swagger-ui-dist` from jsDelivr), with a selector on top |

## Reading them

Browsers do not let a page read the documents from `file://`, so serve the folder over HTTP and open the address it prints:

```bash
python3 -m http.server -d docs/api 8000
```

Then open `http://localhost:8000/` for v1 or `http://localhost:8000/?api=v2` for v2; the selector on top switches between them. Use the filter box to find a command by tag, for example `Web` or `DNS`. Any OpenAPI 3.1 tool can also read both documents, for example Redoc or an editor plugin.

## How the v1 document models the API

The API has one endpoint: `cmd` names a `v-*` command and `arg1`…`arg13` are its arguments. The document describes each command as an operation on its own path, `/api/#<command>`, so that each command has its own form. The part after `#` is a URL fragment, which browsers and HTTP clients do not send, so every request still goes to `/api/`. Client generators need the same rule.

Credentials go in the request body (`access_key` and `secret_key`), so they appear as fields of the `Credentials` schema and not as an OpenAPI security scheme. The overview of the document (`info.description`) explains authentication, permission sets, the `ArcPanel-Exit-Code` header and the HTTP status of each exit code.

## How API v2 works

- **Authentication:** the header `Authorization: Bearer ACCESS_KEY:SECRET_KEY`. The key acts as its owner, with that user's role and read-only state; objects outside the owner's tree answer `404`, refusals inside it `403`. `GET /api/v2/me` shows what a key may do.
- **Permission sets:** a non-administrator key needs sets. `v2-read` allows the read endpoints and `v2-write` every v2 endpoint. Each area also has its own set (`v2-accounts`, `v2-web`, `v2-dns`, `v2-mail`, `v2-db`, `v2-cron`): a key may hold several, and a plan that uses commands from two areas needs both sets. API v1 ignores every v2 set, so they open nothing there. A change checks every command of its plan before the first one runs, so a refused call changes nothing.
- **Dry run:** every change accepts `?dry_run=1` and answers the steps it would run, with secrets masked, without running any.
- **Answers:** one object as `{"data": {…}}`, a list as `{"data": […], "meta": {"total": N}}`. `?limit=` (1–1000) and `?offset=` page a list; with `limit`, `meta` also has `limit` and `offset`. An error is `{"error": {"code": "E_EXISTS", "message": "…", "fields": {…}}}`; a duplicate whose id the server assigns also carries `error.existing`, the path of that object. Every answer carries `ArcPanel-Version` and `X-Request-Id`; `429` carries `Retry-After`, and a queued Let's Encrypt request answers `202` with `Location`.
- **Resources of batch 2:** DNS zones and records, mail domains and accounts, databases and cron jobs, under `/users/{user}/`. Database bodies use the short name; the path and the answer use the full name and also `short_name`. A cron `schedule` is five crontab fields.
- **Switches and limits:** `API_SYSTEM` and `API_ALLOWED_IP` apply as to v1 (a change takes up to a minute); 60 requests per minute per key and 10 failed authentications per minute per address.
- **Stability:** every operation has a stable camelCase `operationId`; within v2 only additive changes are made.

## Trying a call

**Every call changes a real server.** v1 runs the command as root; v2 runs the commands of its plan. "Try it out" is off by default. When you turn it on, the request goes to the server in the `servers` field (`https://{host}:{port}` for v1, `https://{host}:{port}/api/v2` for v2), together with the credentials you type in. Use a test server, never a production host, and an access key whose permission sets cover only the commands you try. With v2, try a change with `dry_run` first.

For v1 the page leaves out empty fields when it sends a request. An empty `access_key` would select access-key authentication, and an empty argument is not the same as a missing one for most commands. The curl command that Swagger UI shows keeps the `#<command>` part of the path, but curl does not send it. v2 requests are sent as typed.

## Regenerating them

### v1

The document comes from the `info:`, `options:` and `example:` header lines of `bin/v-*`, from the permission sets in `install/common/api/` and from the response fixtures in `web-app/tests/Fixtures/commands/`. Regenerate it after you add a command or change a header, a permission set or a fixture:

```bash
npm run docs:api
```

The lint workflow runs the check below and fails when the committed document is stale, when a command has no `info:` line, when a permission set names an unknown command, or when a new object word has no tag in the script:

```bash
npm run docs:api:check
```

### v2

The document comes from the routes, FormRequests, Resources and PHPDoc tags (`@operationId`, `@tags`, `@status`) of the v2 controllers, through Scramble (a development dependency of the panel app). Regenerate it after you change a v2 route, request or resource:

```bash
cd web-app && php artisan arcpanel:api-docs
```

The `openapi-v2` job of the lint workflow runs the check below. It fails when the committed document is stale, or when an operation has no `@operationId`, a duplicate one or one that is not camelCase:

```bash
cd web-app && php artisan arcpanel:api-docs --check
```
