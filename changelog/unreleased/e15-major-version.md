---
type: breaking
attention: true
order: 1
---

- **This release is ArcPanel 3.0.0, a major version** (planned as 2.7.0 until 2026-11-06), because it breaks public interfaces under ArcPanel's semantic versioning rules: `v-run-queue-task` is removed (use the job commands `v-list-job`, `v-list-jobs`, `v-cancel-job` and `v-retry-job`); REST API v2 task ids come back as `job:ID`, and a task of a removed source answers 404 instead of 410; the upgrade turns the task records and the backup and Let's Encrypt queue pipes into jobs, which installing an older package cannot undo. REST API v2 stays v2: old task ids are still accepted and the new fields and endpoints are additive.
