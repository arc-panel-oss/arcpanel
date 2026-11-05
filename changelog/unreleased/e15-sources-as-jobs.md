---
type: changed
---

- System tasks (PHP versions and extensions, plugins, system packages, panel updates and rollbacks) run as jobs of type `sys.*`: `v-start-sys-task` prints the job ID, `v-run-sys-task` takes `KIND [ARG...]` and runs only inside its job, and the task record `data/sys-task/` is no longer written. `v-list-sys-task` reads the newest system job in its old format; it is deprecated and goes in 2.8.
