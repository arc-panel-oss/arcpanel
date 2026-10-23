---
type: added
---

- System-wide audit log: every change made through the panel, REST API v2 and REST API v1 is recorded once, with the real actor, the user acted on during login-as, the role, the IP address, the access key, the action, the object and the result. Sign-ins, sign-outs, login-as, two-factor changes, access keys, backup downloads, database dumps, file downloads and revealed environment values are recorded too. Events hold the names of the changed fields, never their values.
- The events are kept in daily files in `data/audit/` that only root can read, chained with SHA-256 so that a changed, removed or inserted line is found; they are gzipped after 7 days and removed after `AUDIT_RETENTION_DAYS` (180 by default). With `AUDIT_SYSLOG='yes'` each event also goes to the local syslog with the tag `arcpanel-audit`. Both are on the Security tab of the server settings.
- New page Server › Audit log for administrators (filters, export as CSV or JSON, "Verify chain"); resellers read the events of their tree under Accounts › Audit log.
- New commands `v-add-sys-audit-event`, `v-list-sys-audit`, `v-check-sys-audit` and `v-update-sys-audit` (run daily from cron).
- REST API v2: `GET /audit` (`listAuditEvents`) and `GET /audit/verify` (`verifyAuditChain`) in the new permission set `v2-audit`, which `v2-read` includes.
