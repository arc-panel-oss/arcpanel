---
type: security
---

- The File Manager and REST API v2 limit what one hosting account stages in the panel, shared by all its logins and keys: at most `FILE_STAGING_SESSIONS` open uploads (4) and `FILE_STAGING_LIMIT` bytes of open uploads and download tickets together (8G); uploads and downloads keep `FILE_STAGING_RESERVE` percent of the staging file system free (10). Above a limit the answer is `507` `E_LIMIT` with the reason. An upload closes 2 hours after its last chunk, and the page's chunks are limited to 600 a minute per account. `v-list-sys-config` lists these keys and `FILE_MANAGER_UPLOAD_MAX`; change them with `v-change-sys-config-value`.
