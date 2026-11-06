---
type: changed
attention: true
---

- The upgrade to 3.0.0 imports the finished task records of 2.6 (system tasks, the Docker engine and Docker apps, Node.js, app processes, Quick Install, malware scans, backups, restores, Let's Encrypt and deploys) as jobs with the same IDs, so old task ids keep working, and turns the lines still waiting in the backup and Let's Encrypt pipes into queued jobs. A task that still runs during the upgrade is imported by `v-update-sys-jobs` once it has finished.
- The cron lines `v-update-sys-queue backup` and `v-update-sys-queue letsencrypt` are replaced by `v-update-sys-jobs` every minute, also in new installs and in the cron repair of the system health check.
