---
type: changed
attention: true
---

- Alerts report events (E16): failed jobs (`job.failed`), failed certificates and renewals (`letsencrypt.failed`), failed or partial backups (`backup.failed`) and update results (`update.result`). Commands write them with `alert_event` into `data/alerts/events.log`; the engine sends them on its next run, folds events of the same object within the cooldown into one message with a count, and keeps events older than a day in the history without sending them. An event rule's severity is the lowest severity it sends.
- The backup and update mails and notifications now come from the alert rules: `backup.failed` and `update.result` replace the mails of the backup jobs and of the Update Center. The upgrade turns the server rules on with the panel channel and an email channel to the administrator's contact; `BACKUP_REPORT=off` keeps `backup.failed` on the panel channel only, and `UPGRADE_SEND_EMAIL=false` does the same for `update.result`. The daily backup report of `BACKUP_REPORT=daily` is still mailed.
- Changing `BACKUP_REPORT` or `UPGRADE_SEND_EMAIL` (Updates page, server settings, REST API) also adds or removes the email channel of the matching alert rule.
