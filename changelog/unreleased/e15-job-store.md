---
type: added
---

- Job Center, part 1: one record format and one runner for long operations. A job is `data/jobs/ID.conf` with its log `ID.log` (capped at 5 MB, the first 1 MB and the last 4 MB kept); it runs in a transient systemd unit `arcpanel-job-ID` in the user's slice (server jobs in `system.slice`), with a time limit per job type, a heartbeat and masked output (secret-store values and URL credentials show as `***`). Finished jobs are kept 30 days, at most 500 per user.
- New commands `v-run-job`, `v-list-job` (a job and its log from a byte offset), `v-list-jobs`, `v-cancel-job` (stops a queued or running job), `v-retry-job` (a new job for a failed or cancelled job of a retryable type) and `v-update-sys-jobs` (starts queued jobs, reaps dead runners, cleans up).
