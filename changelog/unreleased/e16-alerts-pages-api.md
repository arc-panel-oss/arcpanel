---
type: added
---

- Alerts pages (E16): **Alerts** for every account (open alerts, rules, channels and the alert history of the signed-in user) and **Server → Alerts** for administrators (the server's own rules and channels). A channel is added, changed, tested and deleted there; its secret is write-only and never shown again. The backup and update settings link to the alert rules that took over their mail switches.
- REST API v2 endpoints for alerts: `GET /api/v2/alerts`, the rules, channels and history of the server under `/api/v2/system/alert-*` and of a user under `/api/v2/users/{user}/alert*`, with a channel test endpoint. The new permission set `v2-alerts` grants them alone; `v2-read` and `v2-observe` include the lists, `v2-write` and `v2-server` every alert command.
- The panel keeps the alert history in its database (`arcpanel:alerts-sync`, every minute and in full each night) so the history can be filtered and paged.
