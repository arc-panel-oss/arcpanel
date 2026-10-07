# E34 REST API v2, batch 3: tasks and backups — design

**Status:** Approved 2026-10-08 · **Epic:** E34 (REST API v2) · **Previous specs:** [batch 1](./2026-10-07-002-e34-api-v2-design.md), [batch 2](./2026-10-07-003-e34-api-v2-batch2-design.md) (its §2, D18–D24, binds this spec) · **Roadmap:** [E34](../../roadmap.md#e34--rest-api-v2), [E15](../../roadmap.md#e15--job-center)

## 1. Summary

Batch 3 gives API v2 a way to follow long operations and the endpoints of backups:

- **Tasks:** `GET /api/v2/tasks/{id}` (D19 of batch 2), a read-only facade over the task sources the panel has: the system task, the Docker engine task, the Node.js task of a user, the Docker task of an app, the Quick Install task of a user, and the queue of `v-schedule-*` commands.
- **Queue tasks:** the queue has no ids and keeps no result today. A new wrapper command, `v-run-queue-task`, gives every queued job a record with its status, exit code and log (D34).
- **Backups:** archives (create, list, show, restore, delete, download), restic snapshots (create, list, show, browse, restore, delete) and exclusions.
- **Database dumps** through the download mechanism of D24.
- **`Link rel="monitor"`** on every `202`, including the Let's Encrypt requests of batches 1 and 2.

The record of D34 is a small piece of E15 (Job Center). When E15 lands, it becomes the source of the task endpoint and the endpoint does not change.

## 2. Decisions

| #   | Question            | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| --- | ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D31 | Task ids            | The id names its source and owner, separated by `:` (user names may contain dots): `sys:ID`, `docker-engine:ID`, `node:USER:ID`, `docker:USER:APP:ID`, `install:USER:ID`, `queue:USER:ID`, where `ID` is the id the source printed. The id is opaque to clients; the SDK treats it as a string.                                                                                                                                                                                                                                        |
| D32 | A task that is gone | The system, Docker, Node.js and Quick Install sources keep only their last task: a new start overwrites it. A well-formed id whose source holds another task now, or a queue task older than its retention, answers **`410 E_GONE`** (new API-layer code): the task ended, its result is no longer kept. A malformed id, or one whose owner or app does not exist or is outside the principal's tree, answers `404`.                                                                                                                   |
| D33 | Who sees a task     | Whoever may see what it works on: `sys` and `docker-engine` tasks are for administrators; `node`, `docker`, `install` and `queue` tasks follow the owner scope of their user (`404` outside the tree). A read-only key may read tasks. Quick Install tasks are readable through the API although the page hides them from read-only sessions: reading changes nothing.                                                                                                                                                                 |
| D34 | Queue tasks         | `v-run-queue-task USER ID -- COMMAND [ARG…]` wraps a queued command. The `v-schedule-*` commands create the record (`STATUS='queued'`) under `$ARCPANEL/data/users/USER/tasks/ID.conf`, print the id, and queue the wrapped line. The wrapper marks the record `running`, runs the command with its output in `ID.log`, and stores `succeeded` or `failed` with the exit code. Records and logs are kept 7 days. The commands that do the work (`v-backup-user`, `v-restore-user`, …) do not change.                                   |
| D35 | Downloads (D24)     | A download answers the file itself: an empty body with `X-Accel-Redirect` to an internal nginx location, so nginx serves the bytes, with `Range` support. Backup archives use `/backup/`; the panel builds that path from its configured backup folder instead of the fixed `/backup/` prefix it has today. An archive that is only on a remote backup host answers `202` with a queue task that fetches it; the client downloads again when the task succeeded. A read-only key may download, as a read-only session may on the page. |
| D36 | Database dumps      | `POST /users/{user}/databases/{database}/dump` runs `v-dump-database … gzip` and answers the `.sql.gz` file per D35. It belongs to the database area (`v2-db`). The command deletes the dump an hour later, as it does for the page.                                                                                                                                                                                                                                                                                                   |
| D37 | `Link` header       | Every `202` of API v2 carries `Link: </api/v2/tasks/{id}>; rel="monitor"`. The Let's Encrypt `202`s of batches 1 and 2 keep their `Location` (the domain) and gain the header, which is additive.                                                                                                                                                                                                                                                                                                                                      |
| D38 | Set                 | `v2-backup`: the backup, snapshot, exclusion and task-reading commands. Reading tasks is also part of `v2-read` and of every area set whose endpoints answer `202`, so that a key can follow its own operations.                                                                                                                                                                                                                                                                                                                       |

## 3. Milestones

| #   | Milestone              | Content   | Done when                                                                                                                                     |
| --- | ---------------------- | --------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | Queue task records     | §4        | `v-run-queue-task`, `v-list-user-tasks`, the changed `v-schedule-*` commands and the clean-up, with BATS cases                                |
| M2  | Task endpoint          | §5        | `getTask`, `listUserTasks`, `listSystemTasks` over the six sources; `E_GONE`; `Link` on every `202` (batches 1 and 2 included); Pest matrix   |
| M3  | Archives and downloads | §6.1      | Archive endpoints, restore with selection, download of local and remote archives (D35), the configurable `X-Accel-Redirect` path; Pest matrix |
| M4  | Snapshots, exclusions  | §6.2–§6.3 | Snapshot endpoints (incremental packages only), file browsing and file restore, exclusions; Pest matrix                                       |
| M5  | Database dumps         | §6.4      | `dumpDatabase` with `v2-db`; Pest matrix                                                                                                      |
| M6  | Docs and smoke         | §8, §9    | Smoke green on `arcpanel-test`; `docs/api/v2/openapi.json` current; documents of §9 updated; implementation notes appended                    |

Commits: `feat(e34): …` per milestone, `docs(e34): …` for M6.

## 4. Queue task records (M1)

### 4.1 `v-run-queue-task`

```text
# info: run a queued command and record its result
# options: USER ID -- COMMAND [ARG…]
```

- Checks that the record `$ARCPANEL/data/users/USER/tasks/ID.conf` exists with `STATUS='queued'`; otherwise it exits without running anything (a line queued twice runs once).
- Sets `STATUS='running'`, `START`, `PID`; runs `COMMAND ARG…` with standard output and error appended to `ID.log`; then sets `STATUS` to `succeeded` (exit 0) or `failed`, `EXIT` and `END`.
- Keeps the redirections the queued line had before (`>> $ARCPANEL/log/backup.log` and the others) by appending the same output there too, so the existing logs do not lose lines.
- Writes the record under the user's `flock`, as `func/node.sh` does for the Node.js task.
- `COMMAND` must be one of the commands the schedule commands queue (§4.2); anything else exits `E_FORBIDEN`.

### 4.2 Schedule commands

`v-schedule-user-backup`, `v-schedule-user-restore`, `v-schedule-user-backup-download`, `v-schedule-user-backup-restic`, `v-schedule-user-restore-restic`, `v-schedule-user-restore-file-restic` and `v-schedule-letsencrypt-domain`:

- create the record (`ID` from the date in seconds and four random digits, as the other task sources; `KIND` as `backup`, `restore`, `backup-download`, `snapshot`, `snapshot-restore`, `file-restore`, `letsencrypt`; `ARGS`; `STATUS='queued'`; `QUEUED` time);
- queue `v-run-queue-task USER ID -- <the command line they queued before>`;
- print the id. The output is new; the panel's pages ignore it, the API reads it. A `json` format prints `{"ID": "…"}`.

The duplicate checks (`is_backup_scheduled`) keep matching, because the wrapped line still holds the user and the command name. `v-schedule-letsencrypt-domain` replaces an earlier line for the same domain and kind, as now; the record of the replaced line is set to `failed` with `ERROR='replaced by a newer request'`.

### 4.3 Reading the records

```text
# info: list the tasks of a user
# options: USER [ID] [LINES] [FORMAT]
```

`v-list-user-tasks` prints the queue records of the user, or one record with the last `LINES` lines of its log (default 100, at most 1000). Fields: `ID`, `KIND`, `ARGS`, `STATUS`, `EXIT`, `ERROR`, `QUEUED`, `START`, `END`, `LOG`.

**Lines removed by a worker.** Some worker commands remove every queued line of their user (`sed -i "/ $user /d"`), so a restore queued behind a backup of the same user can disappear without running. The list command marks a record `failed` with `ERROR='removed from the queue'` when it is still `queued` and its line is no longer in any queue file, as the other sources mark a task whose process is gone.

### 4.4 Clean-up

`v-run-queue-task` deletes the user's records and logs older than 7 days each time it runs; `v-update-sys-queue daily` does the same for every user, so that users without new tasks are cleaned too.

## 5. Task endpoint (M2)

| Method and path           | `operationId`     | Role  | Notes                                                                                                                                                            |
| ------------------------- | ----------------- | ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /tasks/{id}`         | `getTask`         | all   | D31–D33; `?lines=` (1–1000, default 100) for the log tail                                                                                                        |
| `GET /users/{user}/tasks` | `listUserTasks`   | all   | The current record of the user's Node.js and Quick Install tasks, the current task of each Docker app, and the queue tasks of the last 7 days; newest first; D20 |
| `GET /system/tasks`       | `listSystemTasks` | admin | The current system task and Docker engine task                                                                                                                   |

**Schema `Task`** (fixed by D19 of batch 2): `id`, `kind`, `status` (`queued`, `running`, `succeeded`, `failed`), `exit_code` (integer or `null` while not finished), `error` (text or `null`), `started_at`, `finished_at`, `log` (the tail, lines joined by `\n`), `resource` (the path under `/api/v2` of what the task works on, or `null`). Two additive fields: `queued_at` (queue tasks only, else `null`) and `args` (the arguments as the source records them, secrets excluded).

**Mapping of the sources:**

| Source        | Read with                                        | `kind`                                        | Status mapping                            | `resource`                                     |
| ------------- | ------------------------------------------------ | --------------------------------------------- | ----------------------------------------- | ---------------------------------------------- |
| System task   | `v-list-sys-task LINES json`                     | `php`, `plugin`, `arcpanel-update`, `package` | `running`; `done` → `succeeded`; `failed` | `null`                                         |
| Docker engine | `v-list-sys-docker-task 0 json`                  | `docker-engine`                               | as above                                  | `/system/docker`                               |
| Node.js       | `v-list-user-node-task USER LINES json`          | its `ACTION`                                  | as above                                  | the app for `app-*`, else `/users/{user}/node` |
| Docker app    | `v-list-docker-app-task USER APP 0 json`         | its `ACTION`                                  | as above                                  | `/users/{user}/docker-apps/{app}`              |
| Quick Install | `v-list-user-quick-install-task USER LINES json` | `quick-install`                               | as above                                  | `/users/{user}/web-domains/{domain}`           |
| Queue         | `v-list-user-tasks USER ID LINES json`           | its `KIND`                                    | as stored                                 | the archive, snapshot or domain it concerns    |

The Docker sources return their log by byte offset, at most 256 KB per call; the endpoint reads on from the returned offset until the end of the log and keeps the last `lines` lines. An empty source (never a task) answers `410` for an id of that source.

**`Link` header (D37).** A `TaskLink` helper builds the header from a task id. Every controller method that answers `202` adds it: `requestWebDomainLetsEncrypt` (batch 1), `requestMailDomainLetsEncrypt` (batch 2), and the `202`s of this batch.

## 6. Endpoints of batch 3

All paths below start with `/api/v2/users/{user}`. Every role may call them for the users of its tree, as on the Backups page; no backup action is admin-only. Writes need a key that is not read-only and accept `?dry_run=1`.

### 6.1 Archives (`v2-backup`)

| Method and path                  | `operationId`    | Notes                                                                                                                                                         |
| -------------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /backups`                   | `listBackups`    | Newest first; `meta.limit` (the user's `BACKUPS`) and `meta.incremental`; D20                                                                                 |
| `POST /backups`                  | `createBackup`   | `202` with the queue task; `409 E_EXISTS` when a backup or restore of the user is already queued, with `existing` the task                                    |
| `GET /backups/{backup}`          | `getBackup`      |                                                                                                                                                               |
| `DELETE /backups/{backup}`       | `deleteBackup`   | `204`, at once (`v-delete-user-backup`)                                                                                                                       |
| `POST /backups/{backup}/restore` | `restoreBackup`  | Optional `web`, `dns`, `mail`, `db`, `udir` (lists of names in the archive) and `cron` (boolean); nothing sent restores everything. `202` with the queue task |
| `GET /backups/{backup}/download` | `downloadBackup` | D35: the file when it is local, else `202` with the fetch task                                                                                                |

- **Names:** a backup name must start with `USER.` and contain no `..` (`BackupService::ownsName`); otherwise `404`.
- **Restore selection:** names not in the archive are a `422` on their field; the panel intersects the lists with the archive's content, as the page does.
- **Schema `Backup`:** `name`, `type` (enum from the extension), `size_mb`, `created_at`, `runtime_seconds`, `content` (`web`, `dns`, `mail`, `db`, `udir` as lists, `cron` as boolean).
- **Download:** `Content-Type` and `Content-Disposition` as the page sends them; the response is documented as `application/octet-stream` with the `202` alternative.

### 6.2 Snapshots (`v2-backup`)

Only for users whose package has `BACKUPS_INCREMENTAL='yes'`; otherwise every snapshot path answers `404`.

| Method and path                      | `operationId`       | Notes                                                                                                                                                         |
| ------------------------------------ | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /snapshots`                     | `listSnapshots`     | Newest first; D20                                                                                                                                             |
| `POST /snapshots`                    | `createSnapshot`    | `202` with the queue task                                                                                                                                     |
| `GET /snapshots/{snapshot}`          | `getSnapshot`       | With its content per type                                                                                                                                     |
| `DELETE /snapshots/{snapshot}`       | `deleteSnapshot`    | `204`, at once                                                                                                                                                |
| `GET /snapshots/{snapshot}/files`    | `listSnapshotFiles` | `?path=` relative to the home; the entries of the file manager (batch 5 §7) with `writable: false`; D20                                                       |
| `POST /snapshots/{snapshot}/restore` | `restoreSnapshot`   | Either nothing (everything), `{type, object}` (`type` one of `web`, `dns`, `mail`, `db`, `cron`), or `{path}` (one file or folder). `202` with the queue task |

- **Ids:** `[0-9a-f]{8,64}`, the full or short restic id.
- **`path` restore:** characters restic cannot take, or the home itself, are a `422` (the rules of `v-schedule-user-restore-file-restic`). The restore overwrites the current file, as on the page; the operation's description says so.
- **Schema `Snapshot`:** `id`, `short_id`, `created_at`, `hostname`, `paths`, and `content` on a single snapshot.

### 6.3 Exclusions (`v2-backup`)

| Method and path          | `operationId`         | Notes                                                                           |
| ------------------------ | --------------------- | ------------------------------------------------------------------------------- |
| `GET /backup-exclusions` | `getBackupExclusions` | `{web, dns, mail, db, cron, user}`, each a list of `name` or `name:sub` strings |
| `PUT /backup-exclusions` | `setBackupExclusions` | The full object; a type left out keeps its list                                 |

The `.nvm` and Docker app toggles of the page are plain entries of `user` here.

### 6.4 Database dump (`v2-db`)

| Method and path                   | `operationId`  | Notes                                                                  |
| --------------------------------- | -------------- | ---------------------------------------------------------------------- |
| `POST /databases/{database}/dump` | `dumpDatabase` | The `.sql.gz` file per D35; read-only keys are refused, as on the page |

The path the command prints must match `^/backup/[\w.-]+$`, as on the page; anything else is `500 E_SERVER`.

## 7. Errors

- New API-layer code `E_GONE` (`410`), in the `ErrorCode` enum of the document.
- `409 E_EXISTS` with `existing` for a queued backup or restore (D21 of batch 2 extended to tasks).
- A source that cannot be read (command failure) answers `500 E_SERVER`; the task endpoint never invents a status.

## 8. Testing

- **BATS** (`test/arcpanel-api-v2-tasks.bats`): `v-run-queue-task` runs a queued record once, records success and failure with the exit code, refuses a command outside the list and a record that is not queued; each schedule command prints an id and queues the wrapped line; `v-list-user-tasks` lists, shows one with its log, marks a removed line `failed`; the clean-up removes records older than 7 days; the replaced Let's Encrypt request is `failed` with its reason; the duplicate checks still refuse a second backup.
- **Pest feature** (`ApiV2TasksTest`, `ApiV2BackupsTest`): the six sources mapped to `Task`; `410` for a replaced task and for an expired queue task; `404` for a malformed id and outside the tree; admin-only system tasks; the `Link` header on every `202` (batches 1–3); the archive, snapshot and exclusion endpoints with the matrix of admin, reseller A, reseller B and user, cross-tenant `404`, read-only `403` on writes and `200` on downloads, dry run, a `v2-read` key refused before any command, the main path with a key holding only `v2-backup`; local download headers with the configured folder, remote download `202`; dump headers.
- **Pest unit:** the id parser of D31, the source mappings, `Backup` and `Snapshot` Resources.
- **Smoke** (`test/arcpanel-api-v2-smoke.sh`): a backup of a throw-away user created through the API, its task followed to `succeeded`, the archive listed and downloaded with curl (size checked), a restore of one web domain followed to `succeeded`, the archive deleted; a database dump downloaded; a Let's Encrypt request answering with `Link`.

## 9. Documentation changes

- `docs/roadmap.md`: the E34 status line; the E15 section notes that the queue records of D34 are the first piece of its job store.
- `docs/design/13-data-model-reference.md`: `data/users/USER/tasks/`, the new commands, the output of the schedule commands.
- `docs/design/01-architecture-overview.md`: the queue section names `v-run-queue-task`.
- `docs/api/README.md`: tasks, `E_GONE`, `Link`, downloads.
- `docs/api/openapi.yaml` (API v1): regenerated for the new commands and the changed schedule commands.

## 10. Out of scope

- A job store for everything (E15), cancelling or retrying tasks, webhooks for task events (E29).
- Backup hosts and the server's backup settings (batch 6, server settings).
- Browsing archives that are not restic snapshots.

## 11. Risks

| Risk                                                                   | Mitigation                                                                                                                          |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| A worker removes the lines of other queued tasks of its user           | The list command marks such records `failed` with the reason (§4.3); BATS covers it                                                 |
| The wrapper changes the queue's behaviour                              | It runs the same command line and appends the same logs; the BATS suites of backups and Let's Encrypt run unchanged                 |
| A single-slot source loses the result of a task a client still follows | `410 E_GONE` says so explicitly; E15 removes the limit later                                                                        |
| Large downloads tie up PHP                                             | nginx serves the bytes (`X-Accel-Redirect`); PHP only checks and answers headers                                                    |
| Task logs show secrets                                                 | The sources mask what they print today; `args` drops the arguments the schedule commands mark as secret; Pest checks a restore task |

## 12. Implementation notes

The work log of each milestone is appended here.

### M2 (2026-10-08)

- Added `getTask`, `listUserTasks` and `listSystemTasks` with `TaskService`, `TaskId`, `TaskLink` and `E_GONE`.
- `Link rel="monitor"` on web and mail Let's Encrypt `202` responses; set `v2-backup` and task reads in `v2-read`, `v2-web`, `v2-mail` and `v2-write`.
- Pest: `tests/Feature/ApiV2TasksTest.php`.

### M1 (2026-10-08)

- Added `func/queuetask.sh`, `bin/v-run-queue-task` and `bin/v-list-user-tasks`.
- The seven `v-schedule-*` commands create a record under `data/users/USER/tasks/`, print the task id (`json` → `{"ID":"…"}`), and queue `v-run-queue-task USER ID -- …` instead of the bare worker line.
- `v-update-sys-queue daily` calls `qt_cleanup_all()` so records older than seven days are removed even when no new queue task runs.
- BATS: `test/arcpanel-api-v2-tasks.bats`; Let's Encrypt scheduling in `test/arcpanel-api-v2.bats` uses `--partial` on the inner command inside the wrapped line.
