---
type: security
---

- Bucket quotas hold under fast uploads. Silo checks its hard quota against usage from a background scan and leaves unfinished multipart uploads out, so an account could store several times its quota between scans. The five-minute repair (`v-check-sys-silo repair`) now measures each bucket on disk, unfinished uploads included, and denies writes to a bucket above its quota until it is below again; deleting stays allowed. The bucket shows "Over quota" on the Object storage page, `FULL='yes'` in `v-list-user-bucket(s)` and `full: true` in REST API v2.
- `v-add-sys-silo` warns when the data path is on the root file system: quotas take effect within minutes, so a file system of its own keeps a full bucket from filling the server's disk.
