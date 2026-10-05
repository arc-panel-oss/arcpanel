# E3 — File Manager v2 — design

**Status:** Approved (2026-10-05) · **Epic:** E3 · **Base design:** [06 File Manager v2](../../design/06-file-manager-v2.md) · **Previous specs:** [E1-S2](./2026-10-05-002-e1-s2-dashboard-web-domains-design.md), [E11](./2026-10-05-003-e11-reseller-design.md), [E13](./2026-10-05-006-e13-node-manager-design.md), [E14](./2026-10-05-007-e14-node-apps-design.md) · **ADRs:** [0001 view layer](../../adr/0001-view-layer.md), [0002 URL scheme](../../adr/0002-url-scheme.md) · **Roadmap:** [§3 E3](../../roadmap.md)

## 1. Summary

E3 replaces FileGator with a first-party file manager in the panel app: a Vue island that browses a user's home directory, runs the usual operations (new, rename, copy, move, delete, permissions, compress, extract, search), uploads in resumable chunks, downloads single files and zips, edits text files in CodeMirror 6 and previews images, PDFs and media. Every read and write of a user's file runs **as that user** through `v-*` commands, so the user's own permissions and disk quota apply exactly as over SSH. Admins and resellers open the files of the accounts in their tree. FileGator stays reachable at `/fm/` until E8 removes it.

Design document 06 stays the reference. This spec records the decisions of the 2026-10-05 brainstorm, the scope of E3 and where it differs from 06.

## 2. Decisions taken in the brainstorm

| #   | Question                                | Decision                                                                                                                                                                                                                                                                                                                                                                                             |
| --- | --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Scope                                   | **Roadmap milestones 1–3** (browse and operations, upload and download, editor and preview) **plus deep links** from Web Domains, Node apps and the user detail page. The restic snapshot browser moves to E7 (Backups page); FileGator removal stays in E8 (06 §7). Docker app links come with E4.                                                                                                  |
| D2  | URL and access to other accounts        | **`/app/files/{user}?path=<relative>`**, in line with `/app/node/{user}/…` of E13 and E14. `path` is relative to the user's home. Admins and resellers open the files of any account they can see, with a banner "Browsing the files of **user**"; an account outside the tree answers 404. `/app/files` redirects to the effective account. Differs from 06 §5.3 (`?path=&user=`).                  |
| D3  | Cut-over                                | **`ARCPANEL_APP_PAGES` switch**, as in E1-S2 D5. With the switch on, the legacy menu link and every "open in file manager" link point to `/app/files`; `/fm/` keeps working and is not redirected until E8.                                                                                                                                                                                          |
| D4  | Upload limit                            | Server key **`FILE_MANAGER_UPLOAD_MAX`** in `hestia.conf` (bytes with an optional `K`, `M` or `G` suffix; default `4G`), chunks of 8 MiB, at most 3 chunks in flight per file. `init` checks the account's quota (`U_DISK` against `DISK_QUOTA`) and the free space of the staging file system.                                                                                                      |
| D5  | Clean-up of staging and tickets         | **Laravel scheduler**: a cron line in the `hestiaweb` crontab runs `artisan schedule:run` every minute; `files:gc` removes upload sessions older than 24 h and download tickets older than 1 h. The scheduler is the base E15 will build on.                                                                                                                                                         |
| D6  | Custom web server configuration         | **Not in E3.** `~/conf/web/<domain>/` belongs to root and a user-written NGINX include is root-equivalent without review. The editor shows a "reload" hint for `.htaccess` only. Differs from 06 §6.4.                                                                                                                                                                                               |
| D7  | Trash                                   | **None in v1**; deleting asks for confirmation and names what will be deleted (closes 06 §10 question 1 for now).                                                                                                                                                                                                                                                                                    |
| D8  | Success metric                          | A Playwright test times the upload-and-edit flow on the new page; the FileGator baseline is measured once by hand and both numbers go into the implementation notes.                                                                                                                                                                                                                                 |
| D9  | Data path for uploads, saves, downloads | **Panel staging with a root hand-off (06 §4.2), but every write and read in the home runs through `user_exec`.** Root only opens the staged file (or the ticket file); the process that writes the destination (or reads the source) runs as the user. Differs from 06 §4.2, where `install -o USER` as root would bypass the user's disk quota (root ignores quotas) and could not return `E_DISK`. |
| D10 | Suspended accounts                      | The files of a suspended account are **read-only for resellers**; admins can still write (cleaning up malware is a common reason to suspend).                                                                                                                                                                                                                                                        |
| D11 | Availability                            | The pages and the API answer only when `FILE_MANAGER='true'` (otherwise 404), the same switch FileGator uses today. `v-add-sys-filemanager` is not changed in E3 (06 §7 changes it in E8).                                                                                                                                                                                                           |
| D12 | Conflicts on hand-off                   | Upload, copy, move and rename never overwrite silently: the panel checks the destination first and answers 409 with the choice skip, overwrite or keep both ("name (1).ext"); `v-add-fs-upload` takes the choice as an argument, so a race between check and write cannot overwrite.                                                                                                                 |

Also recorded: editor saves are guarded by a SHA-256 of the content read (not the mtime of 06 §4.2), because `stat` reports whole seconds and two saves within a second would pass; "Compress" creates a `.zip` through a new `v-add-fs-zip` instead of the upstream `v-add-fs-archive`, which only makes `.tar.gz` and breaks on names with spaces (unquoted `$src`).

## 3. Milestones

| #   | Milestone                     | Content                                                                                                                                         | Done when                                                                                                   |
| --- | ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| M1  | CLI                           | §5: `func/fs.sh`, the new commands, `v-add-sys-panel-files`, the internal NGINX location, the upgrade step                                      | `test/arcpanel-files.bats` passes on `arcpanel-test`, including the quota case                              |
| M2  | Panel back end                | §6: `FileService`, upload sessions, download tickets, `FilePolicy`, capabilities, JSON routes, `files:gc` and the schedule                      | Pest unit and feature tests pass for admin, reseller A, reseller B, user of A and a suspended customer of A |
| M3  | Browse and operations         | §7.1–7.3: island `file-manager`, page, tree, list and grid, selection, context menu, shortcuts, operations dialogs, search                      | Vitest passes; every operation works on `arcpanel-test` from the browser                                    |
| M4  | Upload, download, editor      | §7.4–7.6: uploader, downloads, editor, preview                                                                                                  | Vitest passes; a 200 MB upload with a simulated disconnect resumes and lands with the right owner and mode  |
| M5  | Deep links, cut-over, release | §7.7–7.8: links from Web Domains, Node apps and user detail; legacy menu link; navigation; Playwright with the timing test; documentation (§12) | Done when of §4 on `arcpanel-test`                                                                          |

## 4. Scope

### In scope

1. `func/fs.sh` and the commands of §5.3; `v-add-sys-panel-files`; the `/_arc_files/` internal location in `src/deb/nginx/nginx.conf`; `install/upgrade/arcpanel/1.2.0.sh`.
2. `App\Services\Files` (service, path value object, upload sessions, download tickets, records), `FilePolicy`, capabilities `files.view` and `files.manage`, the routes of §6.4, the `files:gc` command and its schedule.
3. The `file-manager` island with the browser, uploader, editor (CodeMirror 6, lazy chunk) and preview.
4. Deep links, navigation entry, legacy menu link through `arc_route()`.
5. BATS, Pest, Vitest and Playwright tests; the documentation changes of §12.

### Out of scope

- Restic snapshot browser and "restore here" (E7 Backups page).
- Removal of FileGator, `/fm/` redirect, changes to `v-add-sys-filemanager` (E8).
- Editing `~/conf/web/<domain>/nginx.conf_*` or any root-owned file (D6).
- Trash and undo (D7); sharing links; WebDAV; version history; "format on save".
- Search inside file contents (search is by name, 06 §4.1).
- Several files open in editor tabs: one file at a time.
- Docker app deep links (E4).
- Any change to upstream commands.

### Done when

1. A user on the `default` package opens Files from the menu, creates a folder, uploads a folder of 20 files and a 200 MB file by drag and drop, and the 200 MB upload survives a network drop and resumes without starting over.
2. The user edits `public_html/index.php` and `.htaccess`, saves with `Ctrl+S`, and the site serves the new content; a second tab that saved in between makes the first save answer with the "changed on disk" dialog.
3. Uploaded and saved files belong to the user with the expected mode (`0644` for uploads, the previous mode for saves); an upload that exceeds the package's disk quota fails with "quota exceeded" and leaves no partial file in the home or in staging (the pre-check of §6.2; the `E_DISK` of the hand-off is proven in BATS).
4. The user downloads one file, and a zip of a folder with names containing spaces and non-ASCII characters; images, a PDF and an MP4 preview in the page; a 5 MB log file offers download instead of the editor.
5. Rename, copy, move, delete, permissions (recursive with separate file and folder modes), compress to zip and extract work on a selection, with a conflict prompt instead of an overwrite.
6. An admin opens a customer's document root from the Web Domain edit page and sees the banner; a reseller opens its customer's files from the customer page and gets 404 for a user of another tree; a read-only session and a reseller on a suspended customer see no write actions and the write routes answer 403.
7. With `ARCPANEL_APP_PAGES=yes` the legacy menu's "File manager" opens `/app/files`; `/fm/` still opens FileGator; with `FILE_MANAGER=false` the menu entry is gone and `/app/files` answers 404.
8. Staging is empty again after `files:gc` runs with an abandoned upload older than 24 h and a ticket older than 1 h.

## 5. CLI (M1)

### 5.1 Files and paths

| Location                                      | Content                                                                                                   | Owner and mode             |
| --------------------------------------------- | --------------------------------------------------------------------------------------------------------- | -------------------------- |
| `$HESTIA/web-app/storage/app/files/`          | staging root, created by `v-add-sys-panel-files`                                                          | `hestiaweb:hestiaweb 0700` |
| `…/files/uploads/<token>/`                    | one upload session: `meta.json`, `chunks/<index>`, the assembled `file`                                   | `hestiaweb`, `0700`/`0600` |
| `…/files/downloads/<ticket>`, `<ticket>.json` | one download: the bytes written by `v-add-fs-download` and the panel's sidecar                            | `hestiaweb`, `0600`        |
| `/var/spool/cron/crontabs/hestiaweb`          | `* * * * * /usr/local/hestia/php/bin/php /usr/local/hestia/web-app/artisan schedule:run > /dev/null 2>&1` | `hestiaweb`, `0600`        |
| `hestia.conf`                                 | `FILE_MANAGER_UPLOAD_MAX` (optional, default `4G`), read by the panel                                     | read only                  |

`<token>` and `<ticket>` are 32 lowercase hexadecimal characters. Commands receive **absolute** paths (the upstream convention); the panel builds them from `HOME` of `v-list-user`.

### 5.2 Library `func/fs.sh`

- `fs_home USER` — home directory from `/etc/passwd`.
- `fs_inside_home USER PATH` — `readlink -f` of the path (or of its parent, for a path that does not exist yet) must be the home itself or start with `<home>/`. The trailing slash matters: the upstream checks use `^$homedir`, which lets `/home/bob` match `/home/bobby` (harmless there only because the operation runs as the user).
- `fs_staged_valid FILE` — the real path is a regular file under `$HESTIA/web-app/storage/app/files/uploads/`, not a symlink, owned by `hestiaweb`.
- `fs_ticket_path TICKET` — validates the ticket format and returns `…/files/downloads/<ticket>`.
- `fs_write_as_user USER SRC DST` — root opens `SRC`, `user_exec` writes `DST` (`sh -c 'umask 077; cat > "$1"'`); exit status `E_DISK` when the write fails with `ENOSPC` or `EDQUOT` (detected by comparing sizes and the error output), the partial file removed.
- `fs_json_escape` — through `jq -Rs`; names are passed NUL-separated so newlines in names survive.

### 5.3 New commands (all additive)

| Command                                                                       | Behaviour                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ----------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v-list-fs-directory-json USER DIR [OFFSET] [LIMIT] [SORT] [ORDER] [HIDDEN]`  | `user_exec find DIR -mindepth 1 -maxdepth 1 -printf …\0`; JSON `{"TOTAL":n,"OFFSET":o,"ENTRIES":[{"NAME","TYPE","SIZE","MODE","OWNER","GROUP","MTIME","LINK","LINK_TYPE","LINK_INSIDE","VALID_NAME"}]}`. Folders first, then `SORT` (`name`, `size`, `mtime`; default `name`) and `ORDER` (`asc`, `desc`). `LIMIT` default 2000, maximum 5000. `HIDDEN=no` drops dot entries. `VALID_NAME` is `no` for names that are not valid UTF-8                                      |
| `v-get-fs-file-info USER PATH [FORMAT]`                                       | one object: `TYPE`, `SIZE`, `MODE`, `OWNER`, `GROUP`, `MTIME`, `MIME` (`file -b --mime-type` as the user), `LINK`, `LINK_INSIDE`, `WRITABLE` (`test -w` as the user). `E_NOTEXIST` when absent                                                                                                                                                                                                                                                                             |
| `v-read-fs-file USER PATH [MAX_BYTES]`                                        | regular files only; `E_LIMIT` above `MAX_BYTES` (default 2097152). First line is a JSON header `{"SIZE","MTIME","MIME","HASH"}` (SHA-256 computed as the user), then the raw content                                                                                                                                                                                                                                                                                       |
| `v-save-fs-file USER STAGED_FILE DST_PATH [EXPECTED_HASH]`                    | editor save. `DST_PATH` resolved with `readlink -f` (a symlink inside the home saves its target). Existing file: must be writable by the user (`E_FORBIDEN`), and its SHA-256 must equal `EXPECTED_HASH` (`E_INUSE` otherwise); new file: `EXPECTED_HASH` empty and the parent writable. Writes a temporary file next to the destination as the user, copies the old mode, renames over it. Prints the new header line                                                     |
| `v-add-fs-upload USER STAGED_FILE DST_PATH [MODE] [ON_CONFLICT]`              | upload hand-off. `MODE` default `0644`; `ON_CONFLICT` `fail` (default, `E_EXISTS`), `overwrite` or `rename` (first free "name (n).ext"). Parent must exist inside the home and be writable by the user. Writes `DST.arcpart-<random>` with `fs_write_as_user`, `chmod`, renames as the user (`mv -n` for `fail`). Removes the staged file in every case. Prints the final path                                                                                             |
| `v-add-fs-download USER TICKET PATH [PATH…]`                                  | one regular file: `user_exec cat PATH` into the ticket file (created first by root as `hestiaweb 0600`). Several paths or a folder: all must share a parent; `user_exec zip -qry - -- NAMES…` run in that parent, streamed into the ticket (`-y` stores symlinks as links, never follows them out of the home). Refuses when free space on the staging file system is below the source size (`du -sb` as the user for a folder; `E_DISK`). Prints `{"NAME","SIZE","MIME"}` |
| `v-add-fs-zip USER ARCHIVE PATH [PATH…]`                                      | "Compress": all paths share a parent, `ARCHIVE` is a new `.zip` in that parent (`E_EXISTS` if present); `user_exec zip -qry` with quoted names                                                                                                                                                                                                                                                                                                                             |
| `v-change-fs-directory-permission USER DIR DIR_MODE [FILES_MODE] [RECURSIVE]` | `RECURSIVE=no` (default) changes the folder only, which the upstream `v-change-fs-file-permission` cannot do (it always runs `chmod -R`). `RECURSIVE=yes`: `find -type d` gets `DIR_MODE`, `find -type f` gets `FILES_MODE` (default `DIR_MODE`); symlinks are skipped; runs as the user                                                                                                                                                                                   |
| `v-list-fs-disk-usage USER DIR`                                               | `du -sb` as the user, 30 s timeout; "Calculate size" on a folder                                                                                                                                                                                                                                                                                                                                                                                                           |
| `v-add-sys-panel-files`                                                       | idempotent setup: staging directories with owner and mode, the `schedule:run` cron line in the `hestiaweb` crontab (same `sed` append as `v-add-sys-node`, because `fs.protected_regular` forbids `>>`)                                                                                                                                                                                                                                                                    |

Every command has the `info:`/`options:` header, sources `func/main.sh` and `func/fs.sh`, validates the user with `is_format_valid`/`is_object_valid`, checks every path with `fs_inside_home`, honours demo mode for writes, and exits with the upstream codes. Reads (`list`, `info`, `read`, `disk-usage`) do not log; writes log with `log_event` without file content.

### 5.4 Upstream commands used as they are

`v-add-fs-directory` (creates parents), `v-add-fs-file`, `v-copy-fs-file`, `v-copy-fs-directory`, `v-move-fs-file`, `v-move-fs-directory` (also rename), `v-delete-fs-file`, `v-delete-fs-directory`, `v-change-fs-file-permission` (files only), `v-extract-fs-archive` (zip, tar.gz, tar.bz2, 7z, rar), `v-search-fs-object`. They all run the operation as the user. `v-move-fs-file` and the copy commands overwrite an existing destination, which is why the panel checks for conflicts first (D12).

### 5.5 NGINX, install and upgrade

- `src/deb/nginx/nginx.conf` gains, next to the `/app/` block added by E1: `location ^~ /_arc_files/ { internal; alias /usr/local/hestia/web-app/storage/app/files/downloads/; }`. Range requests work, so video and PDF previews seek.
- `install/upgrade/arcpanel/1.2.0.sh` runs `v-add-sys-panel-files`; the installers run it after the panel app is installed; `test/dev-sync.sh` runs it after syncing.
- New commands are reachable through the existing sudoers rule and the generated allow-list.

## 6. Laravel side (M2)

### 6.1 Services and records

- `App\Services\Files\FilePath`: value object built from a relative path; rejects `\0`, newlines, absolute input and anything that escapes the home after normalising `.` and `..`; `absolute(home)` and `relative()`.
- `App\Services\Files\FileService`: `list()`, `info()`, `read()`, `save()` (writes the content to a one-shot session directory under `uploads/` and calls `v-save-fs-file`), `mkdir()`, `touch()`, `copy()`, `move()`, `rename()`, `delete()`, `chmod()`, `compress()`, `extract()`, `search()`, `size()`; one method per command through `ArcCommand`; multi-path actions are `Step` lists. Long commands (`compress`, `extract`, `copy` of folders, `download` of a zip) use a 600 s timeout, matching `fastcgi_read_timeout`.
- `App\Services\Files\UploadSessions`: `init()`, `putChunk()`, `finish()`, `cancel()`, `gc()`.
- `App\Services\Files\DownloadTickets`: `create()` (calls `v-add-fs-download`, writes `<ticket>.json` with the login user, target user, name, MIME, size, creation time), `resolve()`, `gc()`.
- Records: `FileEntry`, `DirectoryListing`, `FileInfo`, `FileContent` (content, hash, MIME, `editable`, `utf8`).
- `HOME`, `U_DISK`, `DISK_QUOTA` and `SUSPENDED` come from `v-list-user`, cached per request as `02` allows.

### 6.2 Upload sessions

1. `POST upload` `{path, name, relativePath?, size, lastModified}`: checks `size ≤ FILE_MANAGER_UPLOAD_MAX`, quota (`DISK_QUOTA` not `unlimited` and `U_DISK + size > DISK_QUOTA` → 422 "quota exceeded"), free space of the staging file system ≥ 1.05 × size (507). Looks for an unexpired session with the same login user, target user, destination, name, size and `lastModified`; returns it with `received[]` (resume), or creates one. Answers `{token, chunkSize, received}`.
2. `PUT upload/{token}/{index}`: the body is streamed from `php://input` to `chunks/<index>.part`, size checked (exactly `chunkSize`, the last chunk the remainder), then renamed to `chunks/<index>`. A session belongs to the login user that created it (403 otherwise).
3. `POST upload/{token}/finish` `{onConflict}`: all chunks present, concatenated into `file`, total size verified, folders of `relativePath` created with `v-add-fs-directory`, `v-add-fs-upload … 0644 <onConflict>`; without `onConflict` an existing destination answers 409 before the hand-off. The session directory is removed on success and on a final failure; `E_DISK` → 422 "quota exceeded".
4. `DELETE upload/{token}` removes the session.

### 6.3 Download tickets and preview

`POST download` `{paths[]}` → `{url}` where `url` is `/app/files/{user}/download/{ticket}`. `GET download/{ticket}[?inline=1]` checks that the ticket belongs to the login user and is younger than 1 h, then answers with `X-Accel-Redirect: /_arc_files/<ticket>`, `Content-Type` from the sidecar and `Content-Disposition` (`attachment`, or `inline` for previews of images, PDF, audio and video). A ticket serves many requests within its hour (range requests), and `files:gc` deletes it. PHP never streams file bytes.

### 6.4 Authorization and routes

| Unit           | Change                                                                                                                                                                                         |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Capabilities` | `files.view` becomes admin All, reseller Tree, user Self (was reseller Self); new `files.manage` with the same scopes                                                                          |
| `FilePolicy`   | `view`: capability and `OwnerScope::canSee()`. `manage`: `view`, not read-only, `files.manage`, and the target not suspended unless the acting role is admin (D10). `abilities()` for the page |
| Middleware     | `files.enabled` (404 unless `FILE_MANAGER='true'`, D11), `effective-user`, `not-readonly` on writes, CSRF on every mutation                                                                    |
| Scoping        | a target outside the tree → 404; refusals inside → 403; a path outside the home → 404                                                                                                          |
| Navigation     | "File manager" keeps its place; `Navigation::url('files.index')` resolves to the new route once the page routes land in M3 (M2 registers the JSON routes only)                                 |

Routes (names `files.<action>`): `GET /app/files` (`files.index`, redirects to the effective account), `GET /app/files/{user}` (`files.show`), `GET /app/files/{user}/download/{ticket}` (`files.download`). JSON under `/app/files/{user}/api`: `GET list`, `GET info`, `GET read`, `GET size`, `GET search`; `PUT save` (409 on hash mismatch); `POST mkdir`, `touch`, `copy`, `move`, `rename`, `delete`, `chmod`, `compress`, `extract`, `download`; `POST upload`, `PUT upload/{token}/{index}`, `POST upload/{token}/finish`, `DELETE upload/{token}`. Every route has a `Capabilities` entry (enforced by the existing Pest test).

### 6.5 Scheduler

`routes/console.php` schedules `files:gc` every 15 minutes (`withoutOverlapping`). The command removes upload sessions whose `meta.json` is older than 24 h and tickets older than 1 h, and logs counts only.

## 7. Files page (M3–M5)

### 7.1 Page and island

`files.show` renders the shell, the banner when the target is not the effective account, the first page of the listing on the server (readable without scripts), and mounts the island `file-manager` with props: target user, initial path, abilities, limits (upload maximum, chunk size, edit maximum), quota (`U_DISK`, `DISK_QUOTA`). The island reuses `ApDialog`, `ApBadge`, `ApIcon`, `strings.js` from E13. The path is kept in the URL (`?path=`), so back and forward work and links can be shared within the tree.

### 7.2 Browser

- Left: folder tree, loaded lazily, collapsible (hidden below the tablet breakpoint). Top: breadcrumb, toolbar (New folder, New file, Upload, Download, Compress, Extract, Permissions, Delete, Search, view toggle, hidden-files toggle). Bottom: selection count and size, quota bar.
- List view (name, size, modified, permissions, owner) with sortable columns, and grid view with icons by type. Virtual scrolling; directories above 2 000 entries are paged from the server as the user scrolls.
- Selection with click, `Shift`, `Ctrl`/`Cmd`, `Ctrl+A`; context menu on entries and on the empty area; keyboard: `Enter` open, `Backspace` up, `F2` rename, `Delete` delete, `Ctrl+C`/`Ctrl+X`/`Ctrl+V` copy, cut, paste (within one account), `/` search.
- Hidden files shown by default; the toggle is stored in `localStorage` (wrapped in try/catch).
- Symlinks show their target; links that leave the home show "outside home, not accessible" and open nothing. Entries with `VALID_NAME='no'` are listed but offer no action.

### 7.3 Operations

Rename, copy and move (folder picker dialog or paste), delete (confirmation lists up to 10 names and the total count), new folder, new file, permissions (octal field and rwx grid, "apply to contents" with separate folder and file modes), compress to zip, extract (into the current folder or a new folder named after the archive), calculate folder size, search by name in the current folder and below (10 s timeout, "narrow your search" when it expires). Conflicts open the skip / overwrite / keep both dialog with "apply to all" (D12).

### 7.4 Uploader

Upload button (files or a folder) and drag and drop anywhere on the list; a queue panel with per-file and overall progress, cancel per file and for all; 3 chunks in flight per file, files one after the other; retries of a failed chunk with back-off (3 attempts), then the file stops and offers "resume"; after a reload, dropping the same file resumes (§6.2). Files above the limit are rejected before any request with the limit in the message.

### 7.5 Downloads and preview

Download of one file or of a selection (zip named after the folder or `files.zip`); preview dialog for images, PDF (browser viewer), audio and video (ticket with `inline=1`), and text (read-only view of the editor content, same 2 MiB cap); next and previous within the folder for images.

### 7.6 Editor

- Opens in the page (replacing the list, with a back button) for files whose MIME is `text/*`, JSON, XML, JavaScript, PHP, shell or empty, up to 2 MiB; larger or binary files offer download.
- CodeMirror 6, loaded as a separate chunk on first use: PHP, JavaScript and TypeScript, CSS, HTML, JSON, YAML, Markdown, SQL, shell, NGINX and Apache configuration, `.env` and ini; line numbers, search and replace, soft-wrap toggle, indentation detection, theme following the panel theme.
- Invalid UTF-8 opens read-only with a warning; a BOM is preserved.
- `Ctrl+S` and a Save button; a dirty marker; a prompt before leaving with unsaved changes; 409 opens "The file changed on disk" with Reload and Overwrite (Overwrite reads the new hash and saves again).
- `.htaccess` shows a hint that Apache reads it on the next request; no restart button.

### 7.7 Deep links

| From                               | Link                                                                     |
| ---------------------------------- | ------------------------------------------------------------------------ |
| Web Domain edit page               | "Open document root" → `/app/files/{user}?path=web/<domain>/public_html` |
| Node app page (E14)                | "Open app folder" → `/app/files/{user}?path=web/<domain>/<CWD>`          |
| User detail page (customer detail) | "Files" button → `/app/files/{user}`                                     |

Links render only when the principal has `files.view` for the account and `FILE_MANAGER='true'`.

### 7.8 Legacy cut-over

`web/inc/arcpanel-routes.php` maps `/fm/` to `/app/files` in `arc_route()`, and the menu link in `web/templates/includes/panel.php` uses `arc_route("/fm/")`. `/fm/` itself is not redirected (D3).

## 8. Error handling

| Code / status      | Cause                                                                 | Panel message                                                                              |
| ------------------ | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `E_DISK` / 422     | user quota reached during a write; staging space short for a download | "Quota exceeded" with used and allowed space; "Not enough space on the server" for staging |
| 507                | staging file system lacks space at `init`                             | "The server cannot take this upload now"                                                   |
| `E_LIMIT` / 413    | file above the edit cap; upload above `FILE_MANAGER_UPLOAD_MAX`       | names the limit and offers download                                                        |
| `E_INUSE` / 409    | file changed since it was read                                        | "The file changed on disk" dialog                                                          |
| `E_EXISTS` / 409   | destination exists                                                    | conflict dialog                                                                            |
| `E_NOTEXIST` / 404 | path vanished                                                         | "This file or folder no longer exists"; the list refreshes                                 |
| `E_FORBIDEN` / 403 | path outside the home; destination not writable by the user           | the reason in one line                                                                     |
| timeout            | search, size, compress, extract over their limit                      | "This took too long"; for search, "narrow your search"                                     |
| expired session    | upload session or ticket gone                                         | the uploader starts the file again; the download is requested again                        |

## 9. Install, upgrade and development

- `v-add-sys-panel-files` runs from the installers, `install/upgrade/arcpanel/1.2.0.sh` and `test/dev-sync.sh`; it is safe to run on every upgrade.
- The panel app's storage directory survives package upgrades (it already holds logs and caches); the staging tree lives inside it.
- `npm run build` emits CodeMirror as its own chunk; the main island bundle stays below the size of the `node-apps` island plus 30 KB gzip.

## 10. Testing

| Level                               | Covers                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BATS `test/arcpanel-files.bats`     | listing JSON (paging, sort, hidden, symlink fields, a name with a newline and one with invalid UTF-8); path escapes (`..`, symlink out, `/home/<user>x` prefix) refused for every new command; upload owner, mode, `ON_CONFLICT` modes, staged file outside staging refused and removed, **`E_DISK`** when the destination file system is full (a 1 MB `tmpfs` mounted inside the test user's home) and when the user's quota is reached (Linux quota on the test server; if the WSL kernel lacks quota support, this one case runs on a VM and the implementation notes say so); save with matching and stale hash, mode preserved, symlink target saved; read cap; download of one file and of a folder zip with spaces, symlink inside not followed; zip; directory permissions recursive and not; disk usage; `v-add-sys-panel-files` twice gives the same crontab |
| Pest unit                           | `FilePath` normalisation (fuzz with `..`, `\0`, unicode, trailing slashes); `FilePolicy` (admin, reseller on own customer, on suspended customer, on other tree, user, read-only); upload session state machine (resume, wrong chunk size, foreign session, quota, staging space); ticket ownership and expiry; `FileService` argument order with `ArcCommandFake`; `files:gc`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Pest feature                        | every route × admin, reseller A, reseller B, user of A, suspended customer of A → 200/403/404; `FILE_MANAGER='false'` → 404; capabilities present for every route                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| Vitest                              | selection model, keyboard map, conflict resolution, uploader (chunking, 3 in flight, retry, resume, cancel), editor save flow with 409, language detection by name                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| Playwright `test/e2e/files.spec.js` | Done when 1–7 on `arcpanel-test` with the `arce2e*` users; network drop simulated with `route.abort()` during the 200 MB upload; axe on the page and the editor in both themes; timing of the upload-and-edit flow (D8)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |

## 11. Risks

| Risk                                                      | Mitigation                                                                                                                               |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Quota not enabled on a server                             | the hand-off still writes as the user, so ownership and permissions are right; the pre-check is skipped when `DISK_QUOTA` is `unlimited` |
| Staging and homes on the same small disk                  | free-space check at `init` and before a download; sessions expire after 24 h, tickets after 1 h                                          |
| A download costs one copy of the file                     | accepted for v1 (06 §10 question 2); the ticket API allows a streaming helper later                                                      |
| Race between the conflict check and an upstream copy/move | uploads are race-free (`mv -n`); for copy and move the window is the time between two commands of the same session; documented           |
| Scheduler cron line missing on a server                   | `v-add-sys-panel-files` runs on every upgrade; `files:gc` also runs opportunistically when `init` finds more than 100 sessions           |
| Large CodeMirror bundle                                   | lazy chunk loaded on the first edit only                                                                                                 |
| Upstream `v-*-fs-*` path checks use `^$homedir`           | the panel never passes such paths (`FilePath`), and the operations run as the user                                                       |

## 12. Documentation changes in the same change set

- **06 File Manager v2:** status line; §4.2, §5.3, §6.4 marked as superseded by this spec where D2, D6, D9 and the hash guard differ.
- **13 Data model reference:** the staging tree, the `hestiaweb` cron line, `FILE_MANAGER_UPLOAD_MAX`, the new commands.
- **01 Architecture overview:** no new upstream exception.
- **10 Page migration:** row 6 (Files) marked as migrated behind `ARCPANEL_APP_PAGES`.
- **Roadmap:** E3 points to this spec and gets its status line.

## 13. Implementation notes (2026-10-05)

These notes win over the sections above where they differ.

1. **Upgrade step (§4, §9).** `v-add-sys-panel-files` runs from `install/upgrade/arcpanel/1.1.0.sh`, not a new `1.2.0.sh`: 1.1.0 is not released yet (the package is 1.0.0) and already carries the E11–E14 steps.
2. **Paths below a missing folder (§5.2).** `fs_check_path` answers `E_NOTEXIST` instead of `E_FORBIDEN` when a path cannot be resolved because a folder is missing but the part that exists is in the home directory. The conflict check of a folder upload asks for a file in a folder that does not exist yet, and must hear "not there".
3. **Staged files (§5.3).** A staged file outside the staging tree, owned by someone else than `hestiaweb` or reached through a symlink is refused and left alone: the commands never remove a path they did not validate. A valid staged file is always removed.
4. **Save (§5.3).** A file the user owns is replaced atomically (temporary file next to it, old mode, `mv`); a file owned by someone else that the user may write is rewritten in place, so it keeps its owner, group and hard links. `v-read-fs-file` hashes a root-owned copy of what the user read and prints that copy, so the header always matches the content.
5. **Downloads (§5.3, §6.3).** One file is copied as the user into the ticket; a folder or a selection is zipped by the user straight into the ticket (`zip -qry -`), so nothing is written into the home directory. When `file` names a type `application/octet-stream`, the panel takes the type from the extension, media types first (`.mp4` is `video/mp4`, not `application/mp4`). `X-Accel-Redirect` keeps the panel's `Content-Type`. PDF previews are sent without the CSP `sandbox` directive, because browsers refuse to show a PDF in a sandbox; every other ticket keeps it, and SVG is never inline.
6. **Quota pre-check (D4, §6.2).** `init` compares the package's `DISK_QUOTA` with `U_DISK` only when `hestia.conf` has `DISK_QUOTA='yes'`. Without system quotas the package value is not enforced anywhere else (FTP, SSH), and refusing uploads only in the panel would be inconsistent. The hand-off still ends with `E_DISK` on a real quota or a full disk. Done when 3 is therefore proven in three places: the pre-check in Pest, `E_DISK` from a real user quota (a loop ext4 image with `usrquota`, which works on the WSL kernel) and from a full `tmpfs` in BATS, and owner and mode in Playwright; the test server runs without system quotas.
7. **Long folders (§7.2).** Rows render in steps of 200 as the list scrolls (an `IntersectionObserver` sentinel), and folders above 2 000 entries page from the server, instead of a fixed-height virtual list: the table turns into cards on phones, whose rows have no fixed height.
8. **Text preview (§7.5).** Text files open in the editor (read-only without write access) instead of a separate 64 KiB preview; files above 2 MiB download.
9. **Editor (§7.6).** CRLF files keep CRLF when saved. The colours of the code come from the panel tokens (the `tk-*` colours of the Nocturne prototype) on `--ap-surface`, where the contrast check covers every pair; CodeMirror's default colours failed axe. CodeMirror and its languages are one lazy chunk of 262 KB gzip; the island itself is 22 KB gzip (the `node-apps` island is 13 KB).
10. **Navigation (§7.1).** Only the newest listing is applied, and a refresh after an operation reads the folder the user went to last; the Playwright run showed a paste refresh taking the user back to the folder they had left. After a folder change the keyboard focus returns to the list, so shortcuts keep working.
11. **Deep links (§7.7).** The Web Domain edit page shows "Open document root" (was "Open files" to FileGator) to everyone who may view the files, read-only sessions included. The Node app page shows "Open app folder" (the app's working directory). The user detail page has a "Files" button. `LegacyRoutes` no longer lists `files.index`.
12. **Tests.** There was no Pest test that checks a capability for every route; `FilesApiTest` checks the file manager routes, and `FilePagesTest` checks that every string of the island is in `FilePageController::STRINGS`. `web-app/vitest.config.js` gains `@vitejs/plugin-vue` for the editor component test.
13. **Success metric (D8).** Playwright on `arcpanel-test`: the edit flow (open `edit.php`, type, `Ctrl+S`, the site serves the new content) takes 7 s; the upload flow (new folder, a folder of 20 files, 200 MB with two dropped chunks and a resume, a drag and drop, a name conflict) takes 36 s. The FileGator baseline is to be measured by hand by the maintainer; it was not measured in this change.
