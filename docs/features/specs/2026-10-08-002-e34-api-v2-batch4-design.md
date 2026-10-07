# E34 REST API v2, batch 4: applications and ports — design

**Status:** Approved 2026-10-08 · **Epic:** E34 (REST API v2) · **Previous specs:** [batch 2](./2026-10-07-003-e34-api-v2-batch2-design.md) (§2, D18–D24), [batch 3](./2026-10-08-001-e34-api-v2-batch3-design.md) (tasks, D31–D38) · **Base specs:** [E13 Node.js runtimes](./2026-10-05-006-e13-node-manager-design.md), [E14 Node.js apps](./2026-10-05-007-e14-node-apps-design.md), [E4 Docker apps](./2026-10-05-009-e4-docker-apps-design.md), [E12 port registry](./2026-10-05-004-e12-port-registry-design.md)

## 1. Summary

Batch 4 brings the application modules into API v2:

- **Node.js runtimes** of a user (E13): nvm on or off, versions, the default version, global packages.
- **Node.js apps** (E14): create, change, start and stop, install and build, environment, scale, logs.
- **Docker apps** (E4): the catalog, install, the app tasks (update, rollback, snapshot), actions, environment, limits, domain, logs; the Docker engine of the server for administrators.
- **Ports** (E12): a user's ports and the administrator's registry. These pages post forms today, so the API adds the first JSON layer on top of the port planner.

Long operations answer `202` with a task of batch 3. The batch depends on batch 3 (tasks, `Link`).

## 2. Decisions

| #   | Question                | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| --- | ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| D39 | Paths                   | Under the owner: `/users/{user}/node` (runtimes), `/users/{user}/node-apps/{domain}`, `/users/{user}/docker-apps/{name}`, `/users/{user}/ports`. Server-wide, for administrators: `/system/docker` (engine) and `/system/ports` (registry). The catalog is `/docker-catalog`, filtered for the principal (D44).                                                                                                                                                                |
| D40 | Long operations         | Installing a runtime or a global package, updating or checking packages, installing and building an app, installing a Docker app and its tasks (update, rollback, snapshot), installing the engine: `202` with the task (`node:USER:ID`, `docker:USER:APP:ID`, `docker-engine:ID`) and `Link rel="monitor"` (D37). A busy task slot (one Node.js task per user, one task per Docker app, one engine task) answers `409 E_INUSE` with `existing`, the path of the running task. |
| D41 | Node.js environment     | `GET …/env` answers the names and a `set` flag, never values. `?reveal=1` adds the values; it needs the right to manage the app **and** a key that holds `v2-apps` or `v2-write` (a `v2-read` key gets `403` even for an administrator). `PUT …/env` takes the full list; an entry without `value` keeps the stored value, an entry left out is removed. Values travel to the command in a `Secret` file, as on the page.                                                      |
| D42 | Docker environment      | The command already masks secret variables and reports `set`; the API passes that on. Generated passwords (`{{random:N}}` defaults) are never returned, as on the page. `PUT …/env` keeps a secret whose value is sent empty, as on the page.                                                                                                                                                                                                                                  |
| D43 | Logs                    | Snapshots, never streams (D23): `?lines=` (1–1000, default 200) and an opaque cursor `meta.next`; sending it back as `?after=` returns what came since. The cursor wraps the byte offset of Node.js logs and the time stamp of Docker logs, so clients handle both the same way. Node.js apps take `?stream=` (`out`, `err`, `install` or `build`), Docker apps `?service=`.                                                                                                   |
| D44 | Admin-only catalog apps | Apps marked `admin_only` in the catalog are hidden from non-administrators: absent from the list, `404` on their detail and on install, as on the page.                                                                                                                                                                                                                                                                                                                        |
| D45 | Ports                   | A JSON layer on the `plan*` steps of `PortService` (dry run comes for free). A range is named in paths as `8000` or `8000-8009`, plus `?proto=` (`tcp` or `udp`) where a port could exist for both. The page's flash results become the error body. The registry checks of the administrator (repair, adopt, close-rule) keep their semantics.                                                                                                                                 |
| D46 | Sets                    | `v2-apps` holds the Node.js, Docker and port commands of users and resellers. The engine and the registry are administrator operations and belong to `v2-server` (batch 6); this batch creates `v2-server` with them.                                                                                                                                                                                                                                                          |
| D47 | Code shape              | API controllers of their own, as in batches 1 and 2. The inline rules of the session controllers (`NodeAppController` start and env rules, `DockerAppController::checkedVars`, the catalog filter, the port range parsing and owner lookups) move into `NodeAppActions`, `DockerAppActions` and `PortActions`, which the session controllers then call. Their JSON stays camelCase for the islands; the API Resources are snake_case.                                          |

## 3. Milestones

| #   | Milestone        | Content | Done when                                                                                                                  |
| --- | ---------------- | ------- | -------------------------------------------------------------------------------------------------------------------------- |
| M1  | Node.js runtimes | §4.1    | Runtime endpoints, `v2-apps` created; Pest matrix                                                                          |
| M2  | Node.js apps     | §4.2    | App endpoints with D41 and D43; Pest matrix; `NodeAppsTest` unchanged                                                      |
| M3  | Docker apps      | §4.3    | Catalog and app endpoints with D42–D44; Pest matrix; `DockerAppsTest` unchanged                                            |
| M4  | Docker engine    | §4.4    | Engine endpoints, `v2-server` created; Pest matrix                                                                         |
| M5  | Ports            | §4.5    | User and registry endpoints; `PortsTest` unchanged                                                                         |
| M6  | Docs and smoke   | §6, §7  | Smoke green on `arcpanel-test`; `docs/api/v2/openapi.json` current; documents of §7 updated; implementation notes appended |

## 4. Endpoints

Unless a row says otherwise: writes need a key that is not read-only and accept `?dry_run=1`; a create answers `201` with `Location`; `DELETE` answers `204`; the role rules and package switches are those of the pages (`NodePolicy`, `DockerPolicy`, `PortPolicy`).

### 4.1 Node.js runtimes (`v2-apps`)

Paths start with `/api/v2/users/{user}/node`. Managing needs the package's `NODE='yes'` (`403` otherwise); removing does not, as on the page.

| Method and path                                 | `operationId`           | Notes                                                                         |
| ----------------------------------------------- | ----------------------- | ----------------------------------------------------------------------------- |
| `GET /`                                         | `getNodeAccount`        | Enabled, default version, allowed versions, limit, room, runtimes             |
| `PUT /`                                         | `setNodeEnabled`        | `{"enabled": bool}`: `v-add-user-nvm` or `v-delete-user-nvm`                  |
| `GET /releases`                                 | `listNodeReleases`      | Majors with latest, LTS, end of life, allowed                                 |
| `GET /versions`                                 | `listNodeVersions`      |                                                                               |
| `POST /versions`                                | `installNodeVersion`    | `{"spec": "lts" \| "22" \| "22.11.0"}`; `202`                                 |
| `PUT /versions/{version}/default`               | `setNodeDefaultVersion` |                                                                               |
| `DELETE /versions/{version}`                    | `deleteNodeVersion`     |                                                                               |
| `GET /versions/{version}/packages`              | `listNodePackages`      |                                                                               |
| `POST /versions/{version}/packages`             | `installNodePackage`    | `{"spec": "pnpm@9"}`; `202`                                                   |
| `POST /versions/{version}/packages/update`      | `updateNodePackages`    | `202`                                                                         |
| `POST /versions/{version}/packages/check`       | `checkNodePackages`     | `202`; the result shows in the next `listNodePackages` (`outdated`, `latest`) |
| `DELETE /versions/{version}/packages/{package}` | `deleteNodePackage`     | Scoped names (`@scope/name`) are URL-encoded                                  |

### 4.2 Node.js apps (`v2-apps`)

Paths start with `/api/v2/users/{user}/node-apps`. Managing needs the package's `NODE='yes'` and room under `NODE_APPS`.

| Method and path                                  | `operationId`                                                    | Notes                                                                                                                                                                                                                         |
| ------------------------------------------------ | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /`                                          | `listNodeApps`                                                   | `meta`: daemon state, totals, limit, room                                                                                                                                                                                     |
| `POST /`                                         | `createNodeApp`                                                  | `domain`; optional `name`, `start` (`npm:SCRIPT` or a path; default from detection), `cwd` (default `public_html`), `version` (`default` or x.y.z), `port` (`auto` or a number), `instances` (1), `template` (`nodejs-proxy`) |
| `GET /detect/{domain}`                           | `detectNodeApp`                                                  | `?cwd=`; what the add form pre-fills (scripts of `package.json`, entry file)                                                                                                                                                  |
| `GET /{domain}`                                  | `getNodeApp`                                                     |                                                                                                                                                                                                                               |
| `PATCH /{domain}`                                | `updateNodeApp`                                                  | `start` with `cwd`, `version`, `instances` with `memory_mb`: each group plans its own command (`v-change-node-app-start`, `-version`, `-scale`)                                                                               |
| `DELETE /{domain}`                               | `deleteNodeApp`                                                  | `?keep_files=1`                                                                                                                                                                                                               |
| `POST /{domain}/start` `stop` `restart` `reload` | `startNodeApp`, `stopNodeApp`, `restartNodeApp`, `reloadNodeApp` | Stop needs only the right to remove, as on the page                                                                                                                                                                           |
| `POST /{domain}/install`                         | `installNodeApp`                                                 | `npm ci` or `npm install`; `202`                                                                                                                                                                                              |
| `POST /{domain}/build`                           | `buildNodeApp`                                                   | `{"script": "build"}`; `202`                                                                                                                                                                                                  |
| `GET /{domain}/env`                              | `getNodeAppEnv`                                                  | D41                                                                                                                                                                                                                           |
| `PUT /{domain}/env`                              | `setNodeAppEnv`                                                  | D41; the page's limits (200 entries, reserved names, 4 KB per value, 64 KB in total)                                                                                                                                          |
| `GET /{domain}/logs`                             | `getNodeAppLogs`                                                 | D43                                                                                                                                                                                                                           |

**Schema `NodeApp`:** `domain`, `name`, `start`, `script`, `cwd`, `node_version`, `runtime`, `port`, `instances`, `max_memory_mb`, `template`, `state`, `status`, `online`, `reason`, `pid`, `cpu`, `memory_mb`, `restarts`, `uptime_seconds`, `url`, `ssl`, `suspended`, `created_at`.

### 4.3 Docker apps (`v2-apps`)

Managing needs the engine installed (`DOCKER_SYSTEM`) and the package's `DOCKER='yes'`.

| Method and path                                                | `operationId`                                         | Notes                                                                                                             |
| -------------------------------------------------------------- | ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `GET /docker-catalog`                                          | `listDockerCatalog`                                   | D44                                                                                                               |
| `GET /docker-catalog/{slug}`                                   | `getDockerCatalogApp`                                 | `?version=`; variables with their types, defaults (generated ones shown as generated), rules                      |
| `GET /users/{user}/docker-apps`                                | `listDockerApps`                                      | `meta`: engine state, totals, limit, room                                                                         |
| `POST /users/{user}/docker-apps`                               | `createDockerApp`                                     | `slug`, `name`; optional `domain`, `version`, `vars` (map). `202` with the install task and `Location` of the app |
| `GET /users/{user}/docker-apps/{name}`                         | `getDockerApp`                                        | With services, ports, post-install notes, rollback and last dump                                                  |
| `DELETE /users/{user}/docker-apps/{name}`                      | `deleteDockerApp`                                     | `?keep_data=1`                                                                                                    |
| `POST /users/{user}/docker-apps/{name}/tasks`                  | `startDockerAppTask`                                  | `{"action": "install" \| "update" \| "rollback" \| "snapshot", "version"?}`; `202`                                |
| `POST /users/{user}/docker-apps/{name}/start` `stop` `restart` | `startDockerApp`, `stopDockerApp`, `restartDockerApp` |                                                                                                                   |
| `GET` / `PUT /users/{user}/docker-apps/{name}/env`             | `getDockerAppEnv`, `setDockerAppEnv`                  | D42                                                                                                               |
| `PUT /users/{user}/docker-apps/{name}/limits`                  | `setDockerAppLimits`                                  | `cpus`, `memory_mb`, within the package's `DOCKER_CPUS` and `DOCKER_MEMORY`                                       |
| `PUT /users/{user}/docker-apps/{name}/domain`                  | `setDockerAppDomain`                                  | `domain`                                                                                                          |
| `GET /users/{user}/docker-apps/{name}/logs`                    | `getDockerAppLogs`                                    | D43                                                                                                               |

**Schema `DockerApp`:** `name`, `slug`, `app_name`, `version`, `latest_version`, `update_available`, `domain`, `url`, `ssl`, `web_port`, `extra_ports`, `state`, `status`, `reason`, `suspended`, `busy`, `previous_version`, `cpus`, `memory_mb`, `cpu`, `memory_used_mb`, `containers`, `running_containers`, `created_at`; detail only: `services`, `ports`, `post_install`, `rollback`, `last_dump`.

### 4.4 Docker engine (`v2-server`, administrators)

| Method and path               | `operationId`         | Notes                                                                                       |
| ----------------------------- | --------------------- | ------------------------------------------------------------------------------------------- |
| `GET /system/docker`          | `getDockerEngine`     | Installed, running, versions, data root, disk, images, containers, apps, users, last report |
| `POST /system/docker/install` | `installDockerEngine` | `202` with `docker-engine:ID`                                                               |

The sys-config cache of `DOCKER_SYSTEM` is forgotten when the install starts and when its task ends, as the page does.

### 4.5 Ports

**A user's ports** (`v2-apps`), paths start with `/api/v2/users/{user}/ports`:

| Method and path          | `operationId`     | Notes                                                                                                                  |
| ------------------------ | ----------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `GET /`                  | `listPorts`       | Entries with owner, app, public, public allowed, rule, listen state; `meta`: limit, allocated, free, range             |
| `POST /reserve`          | `reservePort`     | `proto`; optional `port`, `comment`                                                                                    |
| `POST /give`             | `givePorts`       | `{"to": USER, "range": "8000-8009"}` or `{"to": USER, "count": 10}`; administrators and resellers (`PortPolicy::give`) |
| `POST /request`          | `requestPorts`    | `count`, `reason`; a notification to the owner                                                                         |
| `POST /{range}/release`  | `releasePort`     |                                                                                                                        |
| `PUT /{range}/public`    | `setPortPublic`   | `{"public": bool}`; opening needs `public_ok`                                                                          |
| `PUT /{range}/public-ok` | `setPortPublicOk` | `{"public_ok": bool}`; an administrator either way, a reseller may only forbid                                         |
| `POST /{range}/reclaim`  | `reclaimPorts`    |                                                                                                                        |
| `DELETE /{range}`        | `deletePorts`     | `?force=1`                                                                                                             |

**The registry** (`v2-server`, administrators), paths start with `/api/v2/system/ports`:

| Method and path            | `operationId`         | Notes                                                                     |
| -------------------------- | --------------------- | ------------------------------------------------------------------------- |
| `GET /`                    | `getPortRegistry`     | Range, firewall state, users, entries, reserved ranges, sockets, findings |
| `POST /allocate`           | `allocatePorts`       | `user`, `range` or `count`, `public_ok`, `comment`                        |
| `PUT /range`               | `setPortRange`        | `start` (1024–65534), `end`                                               |
| `POST /reserved`           | `addReservedPorts`    | `range`, `comment`                                                        |
| `DELETE /reserved/{range}` | `deleteReservedPorts` |                                                                           |
| `POST /repair`             | `repairPorts`         | `v-check-sys-ports repair`; answers the findings after the repair         |
| `POST /register`           | `registerPort`        | `user`, `port`, `proto`                                                   |
| `POST /adopt`              | `adoptPortRule`       | `port`, `rule`                                                            |
| `POST /close-rule`         | `closePortRule`       | `rule`                                                                    |

`PORT_REGISTRY` off answers `404` for every port path, as the pages do.

## 5. Errors

- `409 E_INUSE` with `existing` for a busy task slot (D40).
- `403` for actions the package does not allow (`NODE`, `DOCKER`), with the message of the page.
- `422` for limits a command refuses (`NODE_VERSIONS`, `NODE_APPS`, `DOCKER_APPS`, `PORTS`); the command's `E_LIMIT` maps to `422` as in batch 1.

## 6. Testing

- **Pest feature** per module (`ApiV2NodeTest`, `ApiV2NodeAppsTest`, `ApiV2DockerAppsTest`, `ApiV2DockerEngineTest`, `ApiV2PortsTest`): the matrix of admin, reseller A, reseller B and user with cross-tenant `404`; package switches; read-only `403`; dry run; a `v2-read` key refused before any command; the main path with a key holding only `v2-apps` (or `v2-server`); `202` with `Link` and the task id; `409 E_INUSE` for a busy slot; D41 (names only, `reveal` refused to `v2-read`, values kept when omitted); D42 masking; D43 cursors; D44 hidden apps; port flows of the E12 spec through JSON.
- **Pest unit:** the moved action classes, the log cursor, the Resources (no env values without `reveal`, no secrets).
- The session controllers' tests (`NodeTest`, `NodeAppsTest`, `DockerAppsTest`, `PortsTest`, the policy tests) pass unchanged.
- **Smoke:** a Node.js app created with a throw-away domain, its install task followed to `succeeded`, started, its log read, stopped and deleted; a Docker catalog read and, when the test server has the engine, an app installed and deleted; a port reserved and released.

## 7. Documentation changes

- `docs/roadmap.md`: the E34 status line.
- `docs/api/README.md`: the modules, D41, D43.
- `docs/design/13-data-model-reference.md`: the `v2-apps` and `v2-server` sets.
- The E13, E14, E4 and E12 specs get a line in their implementation notes pointing to this spec.

## 8. Out of scope

- Live log streams and the web terminal (D23).
- The Docker catalog's own maintenance and new catalog apps.
- Ports of the firewall itself (batch 6).

## 9. Risks

| Risk                                                                | Mitigation                                                                                             |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| An environment value leaks through a read key or a log              | D41: values only with `reveal` and a writing set; Pest checks list, single and dry-run answers         |
| The moved rules change the islands' behaviour                       | The session controllers call the same action classes; their tests and the Vitest suites pass unchanged |
| A long task outlives the client's interest and blocks the slot      | Same as the pages: one slot per user or app; `409 E_INUSE` names the running task                      |
| Ports have no JSON today, so the new layer may drift from the forms | Both use the `plan*` steps of `PortService`; the form tests and the API tests cover the same flows     |

## 10. Implementation notes

The work log of each milestone is appended here.

### M1 (2026-10-08)

- Added `/api/v2/users/{user}/node` runtime endpoints and `Api\V2\NodeController` reusing `NodeService` and `NodePolicy`.
- Created `install/common/api/v2-apps`; node commands merged into `v2-read` and `v2-write`.
- Pest: `ApiV2NodeTest` (matrix, NODE package rule, dry run, `v2-apps`/`v2-read`, `202`/`Link`, `409 E_INUSE`).
- Regenerated `docs/api/v2/openapi.json` (116 operations).

### M2 (2026-10-08)

- Added `/api/v2/users/{user}/node-apps` endpoints and `Api\V2\NodeAppController` with D41 env masking and D43 log cursors.
- Extracted `NodeAppActions` and `NodeAppLogCursor`; session `NodeAppController` reuses them (D47).
- Extended `v2-apps` with node-app commands; reads and writes merged into `v2-read` / `v2-write`.
- Pest: `ApiV2NodeAppsTest`, `NodeAppActionsTest`; `NodeAppsTest` unchanged.
- Regenerated `docs/api/v2/openapi.json` (131 operations).

### M3 (2026-10-08)

- Added `/api/v2/docker-catalog` and `/api/v2/users/{user}/docker-apps` endpoints with D42–D44; `Api\V2\DockerCatalogController` and `Api\V2\DockerAppController`.
- Extracted `DockerAppActions` and `DockerAppLogCursor`; session `DockerAppController` reuses them (D47).
- Extended `v2-apps` with Docker catalog and app commands; reads and writes merged into `v2-read` / `v2-write`.
- Pest: `ApiV2DockerAppsTest`, `DockerAppActionsTest`, `DockerAppResourceTest`; `DockerAppsTest` unchanged.
- Regenerated `docs/api/v2/openapi.json` (146 operations).
