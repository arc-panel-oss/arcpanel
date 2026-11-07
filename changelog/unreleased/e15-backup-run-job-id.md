---
type: fixed
---

- The activity of the Backups hub and the REST API v2 list of backup job runs (`GET /api/v2/backup-job-runs`) name the backup job of a run again; since the runs became jobs they showed the ID of the run's job instead, and the link to the run answered 404. The run line keeps that job in `TASK`.
