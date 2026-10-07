# REST API documentation

The OpenAPI 3.1 document of the ArcPanel REST API (`POST /api/`, served by `lib/api/index.php`). Design: [E29 OpenAPI spec](../features/specs/2026-10-07-001-e29-openapi-design.md).

| File           | What it is                                                                        |
| -------------- | --------------------------------------------------------------------------------- |
| `openapi.yaml` | The document, **generated** by `scripts/build-openapi.js`; do not edit it by hand |
| `index.html`   | Swagger UI for the document (loads `swagger-ui-dist` from jsDelivr)               |

## Reading it

Browsers do not let a page read `openapi.yaml` from `file://`, so serve the folder over HTTP and open the address it prints:

```bash
python3 -m http.server -d docs/api 8000
```

Then open `http://localhost:8000/`. Use the filter box to find a command by tag, for example `Web` or `DNS`. Any OpenAPI 3.1 tool can also read `openapi.yaml`, for example Redoc or an editor plugin.

## How the document models the API

The API has one endpoint: `cmd` names a `v-*` command and `arg1`…`arg13` are its arguments. The document describes each command as an operation on its own path, `/api/#<command>`, so that each command has its own form. The part after `#` is a URL fragment, which browsers and HTTP clients do not send, so every request still goes to `/api/`. Client generators need the same rule.

Credentials go in the request body (`access_key` and `secret_key`), so they appear as fields of the `Credentials` schema and not as an OpenAPI security scheme. The overview of the document (`info.description`) explains authentication, permission sets, the `ArcPanel-Exit-Code` header and the HTTP status of each exit code.

## Trying a call

**Every call runs a real command on the server, as root.** "Try it out" is off by default. When you turn it on, the request goes to the server in the `servers` field (`https://{host}:{port}`), together with the credentials you type in. Use a test server, never a production host, and an access key whose permission sets cover only the commands you try.

The page leaves out empty fields when it sends a request. An empty `access_key` would select access-key authentication, and an empty argument is not the same as a missing one for most commands. The curl command that Swagger UI shows keeps the `#<command>` part of the path, but curl does not send it.

## Regenerating it

The document comes from the `info:`, `options:` and `example:` header lines of `bin/v-*`, from the permission sets in `install/common/api/` and from the response fixtures in `web-app/tests/Fixtures/commands/`. Regenerate it after you add a command or change a header, a permission set or a fixture:

```bash
npm run docs:api
```

The lint workflow runs the check below and fails when the committed document is stale, when a command has no `info:` line, when a permission set names an unknown command, or when a new object word has no tag in the script:

```bash
npm run docs:api:check
```
