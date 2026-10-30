---
type: changed
---

- A firewall stopped on the Services page (or with `v-stop-firewall`) stays stopped when Fail2Ban bans an address or starts a jail. The ban is recorded and applies with the next start of the firewall.
