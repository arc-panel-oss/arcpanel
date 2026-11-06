---
type: added
---

- Doctor pages (E17): **Server → Doctor** for administrators (the server score and its 15 checks, every domain with its score, worst first, **Run now** and **Check everything**), **Doctor** for every account (its domains, and a reseller's customers', with **Run now**), a page per domain and a **Doctor** tab on the web domain page. Each check shows its message, what was found, a documentation link and, when an existing command fixes it, **Fix**, which names the command before it runs as a job.
- REST API v2 endpoints for the Doctor: `GET /api/v2/system/doctor`, `GET /api/v2/users/{user}/doctor` and `…/doctor/{domain}`, runs (`POST …/doctor/run`, 202 with the `doctor.run` task) and fixes (`POST …/checks/{check}/fix`, 202 with the `doctor.fix` task, 409 when the check passes or has no fix). The account endpoints are in the `v2-security` set; `v2-read` and `v2-observe` read the results, `v2-write` and `v2-server` run and fix.
