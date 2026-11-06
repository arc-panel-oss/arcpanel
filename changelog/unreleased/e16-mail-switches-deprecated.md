---
type: deprecated
---

- The settings `BACKUP_REPORT` (except its daily report) and `UPGRADE_SEND_EMAIL` only switch the email channel of the alert rules `backup.failed` and `update.result` in 3.0 and will be removed in 3.1: set the channels of the rules on the Alerts page instead. The `notify` mode of `v-check-sys-ports` is no longer used by the nightly check.
