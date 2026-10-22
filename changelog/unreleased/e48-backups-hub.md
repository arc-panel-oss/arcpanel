---
type: added
---

- **Server → Backups.** Administrators manage backups on pages of their own: an overview with the runs of the last day, the next runs, failing destinations and missing recovery kits; destinations, added with a wizard that tests the connection; schedules, with the next runs shown as the schedule is typed; the activity of every run with the result of each account; and the settings of compression, temporary folder, backups at once, load limit, report mail and suspended accounts. The package form gains "Backup frequency at most". See the [E48 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md).
- The Backups tab of Server → Settings now links to Server → Backups instead of holding the backup hosts; the REST API keeps `PATCH /api/v2/system/settings` for backups.
