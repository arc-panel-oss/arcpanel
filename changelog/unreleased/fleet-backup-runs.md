---
type: added
order: 20
---

- Records of backup runs: every run of a tar or restic backup (nightly, requested in the panel or the API, or started on the server), failed ones included with the exit code and reason, the last 30 per user. `v-list-user-backup-runs` and `v-list-sys-backup-runs` list them, and REST API v2 answers `GET /users/{user}/backup-runs` and, for administrators and resellers, `GET /backup-runs` with `updated_since`.
