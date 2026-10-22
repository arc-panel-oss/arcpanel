---
type: fixed
---

- REST API v2 can set up the S3 backup host: `PATCH /api/v2/system/settings/backups` takes `repo_type` and the `s3_endpoint`, `s3_bucket`, `s3_region`, `s3_key_id` and `s3_secret` fields of the Backups tab, and the settings answer shows the connection without the secret. Keys with the server or write permission set may run it.
