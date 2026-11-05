---
type: changed
attention: true
---

- The panel has its first database, `data/arcpanel/arcpanel.sqlite` (SQLite, WAL): the table `jobs_log` indexes the job files for the job lists. `php artisan arcpanel:jobs-sync` updates it every minute from the scheduler and builds it again in full every night (`--full`) or when the table is missing; it holds nothing that the job files lack and is not backed up.
- REST API v2 tasks are jobs (E15): every task id is `job:ID`, `GET /tasks/{id}` still accepts the ids of the old task sources (`sys:ID`, `queue:USER:ID`, `docker:USER:APP:ID`, …) and answers the job with the same 14 digits. A task of a removed source answers 404 instead of 410. Tasks have the new status `cancelled` and the fields `object`, `actor` and `retry_of`; `args` is the job's command line without the command.
- New REST API v2 endpoints `GET /tasks` (every task the key can see, with the filters `status`, `type`, `user` and `since`), `POST /tasks/{id}/cancel` and `POST /tasks/{id}/retry`, with the audit events `job.cancel` and `job.retry`. Cancel and retry need the capability `tasks.manage`, which every role has for the jobs in its scope.
