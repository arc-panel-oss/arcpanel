# E29 OpenAPI document of the REST API — design

**Status:** Implemented (2026-10-07, see §12) · **Epic:** E29 (the OpenAPI part; webhooks stay open) · **Base design:** [14 Later backlog](../../design/14-later-backlog.md) §E29, [01 Architecture](../../design/01-architecture-overview.md) §4.3, [03 Tenancy](../../design/03-tenancy-and-authorization.md) §8 · **Roadmap:** [§E29](../../roadmap.md)

## 1. Summary

The REST API at `/api/` (`lib/api/index.php`) runs any `v-*` command, but its only documentation today is the command list. This spec adds an **OpenAPI 3.1 document** of that API, generated from the command headers, and a static **Swagger UI** page to read and try it:

- `scripts/build-openapi.js` reads the `info:` / `options:` / `example:` header of every `bin/v-*`, the permission sets in `install/common/api/` and the response fixtures of the panel tests, and writes `docs/api/openapi.yaml`.
- `docs/api/index.html` shows the document with Swagger UI.
- CI fails when the committed document does not match the commands (`node scripts/build-openapi.js --check`).

The API itself does not change: same wire format, same authentication, same exit codes.

## 2. Decisions taken in the brainstorm

| #   | Question           | Decision                                                                                                                                                                                                                                                                                                                                                     |
| --- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| D1  | Scope              | **The REST API at `/api/` only.** The panel app has no JSON API of its own (`web-app/routes/web.php` serves pages). Outgoing webhooks (the rest of E29) stay in the backlog.                                                                                                                                                                                 |
| D2  | How it is produced | **Generated** by a Node script from the command headers, not written by hand, so it follows the commands. The generated file is committed and checked in CI. The source is `bin/` itself: `config/arc-commands.php` is generated from the same folder (`BuildAllowList`), so 14 §E29's mention of it adds nothing.                                           |
| D3  | Commands covered   | **All `bin/v-*` commands** (665 on 2026-10-07), grouped by tags. Each operation names the permission sets that include it.                                                                                                                                                                                                                                   |
| D4  | One endpoint       | **One pseudo-path per command**, `/api/#<command>` (for example `/api/#v-add-user`), method `POST`, `operationId` = the command name. Each command gets its own form in Swagger UI; the browser drops the fragment, so "Try it out" posts to `/api/`. The fragment is not standard OpenAPI practice, and the document says so in `info.description`.         |
| D5  | Format and version | **OpenAPI 3.1, YAML**, in English, formatted with Prettier (the repository's `prettier --check .` covers it). No new npm dependency: the script writes YAML itself and formats it through the Prettier API.                                                                                                                                                  |
| D6  | Authentication     | Credentials travel in the request body, which OpenAPI security schemes cannot express. The document describes them as body fields of a shared `Credentials` schema: `access_key` + `secret_key` (recommended), `hash` = `ACCESS_KEY:SECRET_KEY`, and the legacy `user` + `password` and legacy `hash` (marked deprecated).                                   |
| D7  | Responses          | `200` with the command output (`application/json` when the output parses as JSON, else `text/plain`), `204` when an access-key call prints nothing, and the error codes of `exit_code_to_http_code` (`400`, `401`, `422`), each with the `ArcPanel-Exit-Code` header. A table of the exit codes (`E_ARGS=1` … `E_API_DISABLED=21`) is in `info.description`. |
| D8  | Examples           | The request example comes from the header's `example:` line, split into `arg1…argN`. Commands with a `[FORMAT]` option get `json` as the example format. Response examples come from `web-app/tests/Fixtures/commands/<command>/<case>.json` where they exist (synthetic users only, AGENTS.md §10).                                                         |
| D9  | Viewer             | **`docs/api/index.html`** loads a pinned `swagger-ui-dist` 5 from jsDelivr and reads `openapi.yaml` next to it. It needs an HTTP server (browsers block `fetch` from `file://`); `docs/api/README.md` gives the command. Publishing on the docs site stays with the E9 release infrastructure.                                                               |
| D10 | Broken headers     | The commands whose header has no `info:` or no `options:` line are fixed in their header, not special-cased in the script. Commands written in PHP use the `//#` prefix, which the script reads.                                                                                                                                                             |

## 3. Milestones

| #   | Milestone       | Content | Done when                                                                                                                                                                                                             |
| --- | --------------- | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | Generator       | §4–§6   | Header fixes; `scripts/build-openapi.js` with Vitest cases (`web-app/tests/js/openapi.test.js`); `docs/api/openapi.yaml` generated, valid OpenAPI 3.1, Prettier clean; `--check` in the lint workflow and npm scripts |
| M2  | Viewer and docs | §7, §9  | `docs/api/index.html` and `docs/api/README.md`; Swagger UI renders every tag and "Try it out" posts to `/api/` (checked in a browser); documents of §9 updated; implementation notes appended                         |

Commits: `feat(e29): …` for M1, `docs(e29): …` for M2.

## 4. Header parsing

A header is the block of comment lines at the top of a command, after the shebang, with the prefix `#` (shell) or `//#` (PHP), followed by a space. The script reads:

| Line           | Result                                                                                                                                                                                      |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `info: TEXT`   | `summary` (first letter upper-cased)                                                                                                                                                        |
| `options: …`   | The arguments, in order. `NAME` is required, `[NAME]` optional; `NONE` or an empty line means no arguments. A token such as `PORT\|RANGE` or `[files\|db\|both]` keeps its text as the name |
| `example: …`   | The request example (first `example:` line only); tokens are split like a shell would (quotes respected)                                                                                    |
| the other text | `description`, as written, until the first line that is not a comment                                                                                                                       |

The header of `bin/v-add-user` ends at the `#---` separator line, which is not part of the description.

## 5. Document layout

```yaml
openapi: 3.1.0
info:
  {
    title: ArcPanel REST API,
    version: <package.json version>,
    description: <auth,
    exit codes,
    permission sets>,
  }
servers: [{ url: "https://{host}:{port}", variables: { host: …, port: { default: "8083" } } }]
tags: [… one per object …]
paths:
  /api/#v-add-user:
    post:
      operationId: v-add-user
      tags: [Users]
      summary: Add system user
      description: …
      x-arcpanel-permission-sets: [billing]
      x-arcpanel-user-arg: 1
      requestBody: { $ref or inline schema: Credentials + cmd (const) + arg1…argN + returncode }
      responses: { "200", "204", "400", "401", "422" }
components:
  schemas: { Credentials, AccessKeyCredentials, HashCredentials, LegacyCredentials, ReturnCode }
  headers: { ArcPanel-Exit-Code }
  responses: { NoContent, BadRequest, Unauthorized, Unprocessable }
```

- **Arguments:** `arg1…argN`, each a string whose `description` is the option name from the header (`USER`, `DOMAIN`, `[RESTART]`). Required options are `required`. `[FORMAT]` has the enum `json`, `plain`, `csv`, `shell`; `[RESTART]` has `yes`, `no`.
- **`x-arcpanel-user-arg`:** the position of `USER` in the options. For a key of a non-admin user, the API refuses the call unless that argument equals the key's user (`lib/api/index.php`); the operation description says so.
- **Both body types:** `application/x-www-form-urlencoded` and `application/json`, with the same schema.
- **Tags:** the object word after the verb (`v-add-web-domain` → `web`), with a fixed map to readable names (`sys` → System, `database` and `databases` → Databases, `user` and `users` → Users, `fs` → Files, …). Words with fewer than three commands go to `Other`. The map lives in the script; an unknown word fails `--check` so the map is kept up to date.
- **Order:** tags as in the map, operations sorted by command name, so the output is stable.

## 6. The script

`scripts/build-openapi.js`, ES module like `scripts/check-template-meta.js`, exported functions for the tests (`parseHeader`, `buildOperation`, `buildDocument`).

| Invocation                                    | Effect                                                                   |
| --------------------------------------------- | ------------------------------------------------------------------------ |
| `node scripts/build-openapi.js`               | Writes `docs/api/openapi.yaml`                                           |
| `node scripts/build-openapi.js --check`       | Exits 1 and names the problem when the file is missing, stale or invalid |
| `npm run docs:api` / `npm run docs:api:check` | The same, through `package.json`                                         |

`--check` also fails on a command with no `info:` line, an unknown tag word, or a permission set that names a command that does not exist.

## 7. Viewer

`docs/api/index.html`: one page, Swagger UI from `https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.<pinned>/`, `url: "openapi.yaml"`, `deepLinking: true`, `tryItOutEnabled: false` (a call runs a real command). `docs/api/README.md` explains how to view it (`npx http-server docs/api` or `python3 -m http.server -d docs/api`), how to regenerate it, and that credentials typed into "Try it out" go to the server named in the `servers` field.

## 8. Testing

- **Vitest** (`web-app/tests/js/openapi.test.js`): header parsing (shell and PHP prefixes, required and optional options, `NONE`, quoted example tokens, description cut at the separator); operation shape (pseudo-path, `operationId`, required args, enums, user argument position, permission sets); the document validates against the structure of OpenAPI 3.1 used here (paths, unique `operationId`, every `$ref` resolves).
- **CI:** a `OpenAPI document` job in `.github/workflows/lint.yml` runs `node scripts/build-openapi.js --check`.
- **Browser:** Swagger UI loads the document without errors; one operation per tag opens; "Try it out" on `v-list-sys-info` sends `POST /api/` (checked in the browser's network log against a local server, no real panel).

## 9. Documentation changes

- `docs/roadmap.md` §E29: status line (OpenAPI document done, webhooks open).
- `docs/design/14-later-backlog.md` §E29: the OpenAPI bullet points to this spec.
- `docs/design/01-architecture-overview.md` §4.3 and open question 3: link to `docs/api/`.
- `AGENTS.md` §8 (the regenerate command) and §11 (a new command regenerates `docs/api/openapi.yaml`).

## 10. Out of scope

- Changes to the API (a Laravel port, new authentication, JSON error bodies, the `v-check-access-key-scope` of 03 §8).
- Response schemas per command beyond the fixtures: the `json` output of a `v-list-*` command is described as an object keyed by the object name.
- Outgoing webhooks and the event schema.
- Publishing on the docs site.

## 11. Risks

| Risk                                                 | Mitigation                                                                                                    |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Tools reject the `#` in a path                       | Swagger UI and Redoc accept it; D4 is stated in `info.description`; the paths are literal, never templated    |
| A header lies about its arguments                    | The document is as good as the headers; header fixes go into the command, and the CI check keeps them in step |
| Someone runs a destructive command from "Try it out" | `tryItOutEnabled: false`, a warning in the README and in `info.description`                                   |
| Document size (665 operations)                       | Shared components keep each operation short; measured in the implementation notes                             |

## 12. Implementation notes

Implemented on 2026-10-07, one commit per milestone after the spec.

- **Size:** 666 operations (the 665 commands of `bin/` and `v-make-tmp-file`), 18 tags, about 54,000 lines and 1.7 MB of YAML. The script runs in about 2.5 seconds, Prettier included.
- **`v-make-tmp-file`** is not a script: `lib/api/index.php` handles it itself, and three permission sets name it. The script documents it from a header kept in its `builtinCommands` list.
- **Header fixes (D10):** `bin/v-add-sys-dependencies` had no `info:` line; `bin/v-delete-user-auth-log` and `bin/v-import-directadmin` had no `options:` line.
- **User argument:** `x-arcpanel-user-arg` follows `search_command_arg_position` in `func/main.sh`, which reads only the shell `# options:` line. The three PHP commands that take a user (`v-add-web-domain-app`, `v-list-quick-install-app-options`, `v-quick-install-app`) therefore get no user check from the API for the keys of non-admin users. The document states this; fixing the API is out of scope (§10) and left for a follow-up.
- **Empty examples (addition to §5 and §7):** every argument without an example and every credential field has `examples: [""]`, otherwise Swagger UI fills them with `string` and sends them. `docs/api/index.html` adds a `requestInterceptor` that drops empty fields, because an empty `access_key` selects access-key authentication and an empty argument is not a missing one.
- **Fixtures:** 28 commands get JSON response examples and one (`v-list-sys-status`) gets text examples. The fixtures of `v-list-sys-hestia-updates`, a command that no longer exists, are ignored.
- **YAML:** the script writes block YAML itself and formats it with the Prettier API and the `.editorconfig` settings. A one-off check parsed the file back with the `yaml` package and found it deep-equal to the generated document. No full OpenAPI validator ran (it would be a new dependency); `validateDocument` checks references and operation ids, and Swagger UI loaded the document without errors.
- **Browser check (§8):** Swagger UI 5.33.1 rendered the 18 tags without console errors and merged the `allOf` of `Credentials` and the arguments into one form. "Try it out" on `v-list-quick-install-app-options`, sent to an unused local port, went out as `POST https://localhost:9/api/` with only the non-empty fields, both as a form and as JSON. Swagger UI renders the operations of a large tag as you scroll; deep links (`#/System/v-list-sys-info`) work after a page load.
- **Tests:** 20 Vitest cases in `web-app/tests/js/openapi.test.js`. The full Vitest run has 251 cases; `tests/js/modal.test.js` fails now and then on a focus assertion, also when run alone, independently of this change.
- **Not run:** BATS and the smoke test, because no command behaviour changed (only header comments). `npm run lint` reports Prettier issues in about 735 files of the Windows checkout (CRLF line endings) before and after this change; the files of this change pass Prettier, Biome and markdownlint.
