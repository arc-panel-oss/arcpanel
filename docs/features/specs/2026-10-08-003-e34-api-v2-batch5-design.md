# E34 REST API v2, batch 5: files — design

**Status:** Approved 2026-10-08 · **Epic:** E34 (REST API v2) · **Previous specs:** [batch 2](./2026-10-07-003-e34-api-v2-batch2-design.md) (§2, D18–D24), [batch 3](./2026-10-08-001-e34-api-v2-batch3-design.md) (downloads, D35) · **Base spec:** [E3 File Manager v2](./2026-10-05-008-e3-file-manager-design.md)

## 1. Summary

Batch 5 brings the file manager of E3 into API v2: listing, reading and saving text files, creating, renaming, copying, moving, deleting, permissions, compressing, extracting, searching, folder sizes, chunked uploads and downloads. Every operation runs a `v-*` command as the hosting user, as the panel's file manager does, within the user's home.

It also closes a gap found while preparing it: thirteen inherited file commands check their paths with an unanchored pattern that accepts `/tmp` and the homes of users whose name starts with the same letters (`/home/bob2` for `bob`). Only the panel's own path check stops that today (D53).

Browsing and restoring files of restic snapshots belong to batch 3 (§6.2 there).

## 2. Decisions

| #   | Question                    | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| --- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D48 | Paths                       | Paths are relative to the user's home (`public_html/index.php`), sent as `?path=` on reads and in the body on writes, and checked by `FilePath` as on the page: no leading `/`, no control characters, at most 4096 bytes, segments of at most 255 bytes, `..` resolved and refused above the home. A path outside the home or under a symbolic link that leaves it answers `404`. `FILE_MANAGER` off answers `404` for every file path.                                                                                                                                                                                                                                                                                                                                                                                                          |
| D49 | Synchronous operations      | As on the page: copy, move, delete, permission changes, compress, extract and zip downloads answer when they are done, within the 600 s that nginx and `ArcCommand` allow. An operation over several paths stops at the first failure; the error body names the failed path and lists in `error.done` the paths that were finished. A timeout answers `504` from nginx; the description of each heavy operation says so.                                                                                                                                                                                                                                                                                                                                                                                                                          |
| D50 | Upload sessions             | The chunked protocol of the page: a session per file, 8 MiB chunks, 24 h validity, resumable. The session belongs to the **access key** that opened it (`Principal::accessKey`), not to the user: another key of the same user gets `404`. Chunk `PUT`s go through their own limiter, `api-v2-upload` (600 per minute per key), so that the 60 requests per minute of D16 do not cap uploads at 480 MiB per minute.                                                                                                                                                                                                                                                                                                                                                                                                                               |
| D51 | Downloads (D24, D35)        | `POST /files/download {paths}` answers the bytes in the same request: the panel runs `v-add-fs-download` into its staging folder and answers `X-Accel-Redirect` to `/_arc_files/TICKET`, the internal location of the page. One file is sent as it is, several paths or a folder as a zip. The staged copy is removed by the existing clean-up (`files:gc`) after an hour. A read-only key may download.                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| D52 | Extract                     | Through the API, an archive is extracted into a **folder that does not exist yet**, which the plan creates, so nothing is overwritten silently (the command overwrites with `unzip -o`). Formats: zip, tar.gz/tgz, tar.bz2/tbz, tar.zst, 7z. Single-file `.gz`, `.bz2` and `.zst` are refused (`422`): the command moves the source file for them. The 7z branch of `v-extract-fs-archive`, which runs `p7zip` on a path it just moved, is fixed.                                                                                                                                                                                                                                                                                                                                                                                                 |
| D53 | Path checks of the commands | The thirteen inherited file commands that check their paths with an unanchored `egrep` on `^/tmp` or the home (`v-add-fs-archive`, `v-add-fs-directory`, `v-add-fs-file`, `v-change-fs-file-permission`, `v-check-fs-permission`, `v-copy-fs-file`, `v-copy-fs-directory`, `v-move-fs-file`, `v-move-fs-directory`, `v-delete-fs-file`, `v-delete-fs-directory`, `v-extract-fs-archive`, `v-open-fs-file`) use `fs_check_path` (`func/fs.sh`) instead, which resolves the path and requires it inside the home. M1 first checks every caller (the panel, other commands, Quick Install) for paths under `/tmp`; a caller that needs one is changed to stage under the home or the panel's staging folder before the check is tightened. A BATS case proves that `/tmp`, `/home/bob2` for the user `bob`, and a link leaving the home are refused. |
| D54 | Dry run                     | A dry run validates, authorizes and checks the key's sets, then lists the commands that would change something. The read commands that find conflicts (`v-list-fs-directory-json`, `v-get-fs-file-info`) run in a dry run too, because they change nothing and the plan depends on them; a conflict is reported in the dry run as it would be in the real call.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| D55 | Wire format and errors      | snake_case fields (`link_type`, `link_inside`, `on_conflict`). Conflicts answer `409 E_EXISTS` with `error.conflicts`, the names that exist; a changed file on save answers `409 E_INUSE` (hash mismatch). Too large answers `413 E_LIMIT`, a full quota `422 E_LIMIT`, too little staging space `507 E_DISK`. Lists follow D20 with `limit` at most 1000 (the page allows 5000).                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| D56 | Set                         | `v2-files`. Reads (list, info, read, search, size, download) are also in `v2-read`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |

## 3. Milestones

| #   | Milestone          | Content    | Done when                                                                                                                                              |
| --- | ------------------ | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| M1  | Command fixes      | D52, D53   | The thirteen commands use `fs_check_path` after their callers were checked; 7z extract fixed; BATS cases for the refused paths and each extract format |
| M2  | Reads              | §4.1       | List, info, read, search, size; Pest matrix                                                                                                            |
| M3  | Changes            | §4.2       | Save, create, rename, copy, move, delete, chmod, compress, extract with D49, D52, D54; Pest matrix                                                     |
| M4  | Uploads, downloads | §4.3, §4.4 | Upload sessions bound to the key, the upload limiter, downloads in one request; Pest cases                                                             |
| M5  | Docs and smoke     | §6, §7     | Smoke green on `arcpanel-test`; `docs/api/v2/openapi.json` current; documents of §7 updated; implementation notes appended                             |

## 4. Endpoints

All paths start with `/api/v2/users/{user}/files`. Every role may call them for the users of its tree, as on the page (`FilePolicy`): changing files of a suspended account is for administrators only. Writes need a key that is not read-only and accept `?dry_run=1`.

### 4.1 Reads

| Method and path | `operationId`   | Notes                                                                                                                                                              |
| --------------- | --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET /`         | `listFiles`     | `path` (default the home), `sort` (`name`, `size`, `mtime`), `order` (`asc`, `desc`), `hidden` (boolean, default true); D20                                        |
| `GET /info`     | `getFileInfo`   | `path`                                                                                                                                                             |
| `GET /content`  | `readFile`      | `path`; at most 2 MiB (`413` above). `content`, `hash` (SHA-256), `size`, `modified_at`, `mime`, `utf8`; content that is not UTF-8 is scrubbed and `utf8` is false |
| `GET /search`   | `searchFiles`   | `path`, `q` (1–100 characters, no `/`; a term without wildcards matches as `*q*`); at most 500 results, `meta.truncated`; 10 s                                     |
| `GET /size`     | `getFolderSize` | `path`; bytes; `422 E_LIMIT` after 30 s                                                                                                                            |

**Schema `FileEntry`:** `name`, `path`, `type` (`file`, `directory`, `link`, `other`), `size`, `mode` (octal string), `owner`, `group`, `modified_at`, `link_target`, `link_type`, `link_inside`, `mime`, `writable`, `valid_name`.

### 4.2 Changes

| Method and path     | `operationId`     | Notes                                                                                                                               |
| ------------------- | ----------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `PUT /content`      | `saveFile`        | `path`, `content` (at most 2 MiB), `hash` (of the version read, or `null` for a new file). The file keeps its mode and line endings |
| `POST /directories` | `createDirectory` | `path` (parent), `name`; `201`                                                                                                      |
| `POST /touch`       | `createFile`      | `path` (parent), `name`; `201`                                                                                                      |
| `POST /rename`      | `renameFile`      | `path`, `name`                                                                                                                      |
| `POST /copy`        | `copyFiles`       | `paths` (1–1000), `destination`, `on_conflict` (`fail` default, `skip`, `overwrite`, `rename`)                                      |
| `POST /move`        | `moveFiles`       | as copy                                                                                                                             |
| `POST /delete`      | `deleteFiles`     | `paths` (1–1000); `204`                                                                                                             |
| `POST /chmod`       | `changeFileMode`  | `paths`, `mode` (octal), optional `files_mode` and `recursive` for folders                                                          |
| `POST /compress`    | `compressFiles`   | `paths` (sharing one folder), `name` (ending in `.zip`); `201` with the archive's entry                                             |
| `POST /extract`     | `extractArchive`  | `path`, `destination` (must not exist, D52); `201` with the folder's entry                                                          |

Delete is a `POST` with a body because a list of paths does not fit a `DELETE` path, as on the page.

### 4.3 Uploads

| Method and path                | `operationId`  | Notes                                                                                                                                                                            |
| ------------------------------ | -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `POST /uploads`                | `startUpload`  | `path` (folder), `name`, `size`, optional `relative_path` (folders to create), `modified_at`. Answers `token`, `chunk_size`, `received` (indexes already stored when it resumes) |
| `PUT /uploads/{token}/{index}` | `uploadChunk`  | Raw body (`application/octet-stream`), exactly `chunk_size` bytes except the last chunk; `204`                                                                                   |
| `POST /uploads/{token}/finish` | `finishUpload` | `on_conflict` (`fail`, `skip`, `overwrite`, `rename`); `201` with the file's entry                                                                                               |
| `DELETE /uploads/{token}`      | `cancelUpload` | `204`                                                                                                                                                                            |

- The maximum size is `FILE_MANAGER_UPLOAD_MAX` (`413`); a full quota is `422` when `DISK_QUOTA='yes'`; too little staging space is `507`.
- Dry run applies to `startUpload` and `finishUpload` (validation, conflicts, sets); chunks are not planned.
- `uploadChunk` is documented with a binary request body; the SDK sends `Blob` or `Uint8Array`.

### 4.4 Downloads

| Method and path  | `operationId`   | Notes                                                                                                   |
| ---------------- | --------------- | ------------------------------------------------------------------------------------------------------- |
| `POST /download` | `downloadFiles` | `paths` (1–1000, sharing one folder; not the home itself), optional `inline` (boolean, files only). D51 |

The headers are the page's: `Content-Disposition` with an RFC 5987 file name, `X-Content-Type-Options: nosniff`, and a `sandbox` content security policy for anything shown inline except PDF; SVG is never inline.

## 5. Code shape

- `App\Http\Controllers\Api\V2\FileController` and `FileTransferController` on `FileService`, `UploadSessions` and `DownloadTickets`. `FileService` gains `plan…` methods that return the `Step`s of an operation, so `PlanGate` can check and preview them; the session controllers keep calling the methods that run.
- `UploadSessions` and `DownloadTickets` take an owner key (the login user for the page, `api:KEY_ID` for the API) instead of reading the login user themselves.
- The `api-v2-upload` limiter is registered next to `api-v2` in `AppServiceProvider`; the chunk route uses it instead of `throttle:api-v2`.

## 6. Testing

- **BATS** (`test/arcpanel-files.bats`): every command of D53 refuses `/tmp/x`, `/home/bob2/x` for `bob`, and a symbolic link to `/etc`; extract of each format of D52 into a new folder, 7z included; the single-file formats still behave as before for the page.
- **Pest feature** (`ApiV2FilesTest`): the matrix of admin, reseller A, reseller B and user with cross-tenant `404`; `FILE_MANAGER` off; read-only `403` on changes and `200` on downloads; suspended account refused for non-administrators; dry run with conflicts; a `v2-read` key refused before any change command; the main path with a key holding only `v2-files`; `409` with `conflicts`; `409 E_INUSE` on a stale hash; the partial-failure body with `done`; extract into an existing folder refused; an upload session refused to another key of the same user; the upload limiter; download headers.
- **Pest unit:** `FilePath` (unchanged cases), the owner key of sessions and tickets, the Resources.
- The page's tests (`FilesApiTest`, `FileManagerTest`, `FilePagesTest`) and the Vitest suites pass unchanged.
- **Smoke:** a throw-away user's file uploaded in three chunks, read, saved with its hash, copied, compressed, extracted into a new folder, downloaded as a zip (size checked) and deleted; `../../etc/passwd` refused.

## 7. Documentation changes

- `docs/roadmap.md`: the E34 status line.
- `docs/api/README.md`: the file endpoints, uploads, the upload limiter, downloads.
- `docs/design/13-data-model-reference.md`: the `v2-files` set.
- `docs/design/12-security-model.md`: the path checks of D53.
- The E3 spec gets a line in its implementation notes pointing to this spec and D53.

## 8. Out of scope

- Asynchronous file operations (a `202` variant would be a new operation later).
- Editing files above 2 MiB, binary content in JSON, archives other than zip when compressing.
- Snapshot browsing and file restore (batch 3).

## 9. Risks

| Risk                                      | Mitigation                                                                                                                            |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| A long operation times out half-way       | D49: the body lists what was done when the panel sees the failure; the descriptions warn that a `504` may leave work done             |
| The command fixes break the page          | The page never sends paths outside the home; the page's Pest and Vitest suites and the BATS suites of the file commands run unchanged |
| Uploads exhaust the staging disk          | The page's checks apply (1.05 × size free, `507`), sessions expire after 24 h, `files:gc` runs every 15 minutes                       |
| A key reads files of another key's upload | D50 binds sessions to the key                                                                                                         |

## 10. Implementation notes

The work log of each milestone is appended here.
