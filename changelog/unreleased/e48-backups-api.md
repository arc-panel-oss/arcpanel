---
type: added
---

- **REST API v2: backup destinations, jobs and their runs** for administrators, in the `v2-server` set (the reads also in `v2-read` and `v2-observe`): `listBackupDestinations`, `createBackupDestination` (secrets write-only), `getBackupDestination`, `updateBackupDestination`, `deleteBackupDestination` (409 while a job uses it), `checkBackupDestination`; `listBackupJobs`, `createBackupJob`, `getBackupJob`, `updateBackupJob`, `deleteBackupJob`, `runBackupJob`; `listBackupJobRuns`, `getBackupJobRun`.
- `createBackup` takes an optional archive `job` that covers the user; a `Backup` names its `job`, its `destinations` and whether it is `encrypted`; a `BackupRun` names its `job` and the state of each destination; packages have `backup_frequency` (`hourly`, `daily` or `weekly`).
