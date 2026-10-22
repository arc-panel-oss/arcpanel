---
type: changed
---

- **Backup notices.** A user whose backup fails or partly fails gets one panel notification with the job and the error, for a requested backup as for a scheduled one; administrators get one notification and one mail per job run that ends partial or failed, instead of a mail for every user. `BACKUP_REPORT='off'` (Server → Backups → Settings) now stops the failure mails of each backup too; the panel notifications stay. Every mail the backups send is noted, by its subject, in `log/backup.log`.
- `BACKUP_REPORT='daily'` mails one summary a day after 07:00: the job runs of the last 24 hours and the state of each destination.
