---
type: added
attention: true
---

- Alerts (E16): a new engine, `v-check-sys-alerts`, runs every minute from cron and checks the rules of a fixed catalog: disk and inode usage per mount point, load average per core, memory and swap usage, stopped services, findings of the port registry, expiring certificates of web and mail domains and of the panel, and disk quota. A rule has thresholds, a time the condition must hold, a cooldown between reminders and channels; an alert is sent once, again when it gets worse or after the cooldown, and a "resolved" message follows after two good checks.
- New commands `v-list-alert-rules`, `v-change-alert-rule`, `v-list-alert-channels`, `v-list-alerts`, `v-list-alert-history` and `v-add-sys-alerts`. The administrator sets the server rules; every user and reseller has its own scope with the rules for its own objects. Each scope has a panel channel that writes panel notifications (for the server, to every administrator).
- On install and upgrade the server gets default rules (disk, inodes, load, memory, services, certificates, port findings) on the panel channel, and every user gets certificate expiry and failed backups. The files are in `data/alerts/` and `data/users/USER/alerts/`.
- The nightly port check (`v-check-sys-ports`) no longer runs with `notify`: the rule `ports.orphan` sends its findings.
