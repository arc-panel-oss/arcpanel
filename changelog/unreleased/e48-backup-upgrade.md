---
type: changed
attention: true
---

- The upgrade moves the backup settings to destinations and jobs (`v-update-sys-backup-config`): the backup folder becomes the destination `local`, each FTP, SFTP, B2 and rclone host a destination of its type's name (their archives stay unencrypted), and the restic repository the destination `restic` (`s3` for an S3 backup host). The nightly backup becomes the job "Nightly archives" at 05:10 and, with incremental backups, "Nightly snapshots" at 05:40, with the retention of before. The old files are kept as `conf/*.backup.conf.migrated` and `conf/restic.conf.migrated`; the plain restic passwords of the users are encrypted. The cron lines of `v-backup-users` and `v-backup-docker-apps all` give way to `v-update-backup-jobs`, run every minute.
- `v-add-backup-host`, `v-delete-backup-host`, `v-list-backup-host` and their restic and S3 relatives keep their arguments and output and now change those destinations and jobs; `v-backup-users` and `v-backup-users-restic` run the enabled jobs. `BACKUP_SYSTEM` and `BACKUP_INCREMENTAL` follow the destinations and jobs and are no longer read. Archives that earlier versions uploaded to B2 stay in their user folders; new ones go to the root of the bucket.
