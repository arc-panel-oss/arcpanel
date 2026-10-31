---
type: changed
---

- **Backups of an account.** The Backups page opens on an overview: the last backup, the next run of each job that covers the account, where backups are kept (by name only) and Back up now, which picks among the archive jobs of the account. Archives show their destinations and job and can be filtered by destination; a History tab lists every run with its state on each destination; the Snapshots tab is always there and explains when the package has no incremental backups.
- **Restore wizard.** Restore on an archive or a snapshot opens three steps: the backup, everything or some items (hidden files folded away), and a confirmation.
- **Exclusions as lists.** Web domains, mail domains and databases are picked from the account's own records, each with the folders or accounts to leave out, or all of a kind with one switch; cron jobs are one switch. Exclusions set from the command line for other names are kept and shown.
- `v-schedule-user-backup` takes an optional archive job: `v-schedule-user-backup USER [FORMAT] [JOB]`.
