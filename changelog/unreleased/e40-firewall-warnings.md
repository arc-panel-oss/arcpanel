---
type: added
order: 12
---

- `v-check-firewall` also returns warnings (`WARNINGS`; `warnings` in `GET /api/v2/system/firewall/status`), which the Firewall page shows as alerts: `iptables-engine` when the 2.3.0 upgrade could not move the server to nftables, `fail2ban-action` when the Fail2Ban action is older than 2.3 (a reload replaces it), `nftables-service` when the distribution's `nftables.service` is enabled or active, and `ipv6-ssh` or `ipv6-panel` when a server with a global IPv6 address accepts the SSH ports or the panel port over IPv4 only. While a server is left on iptables, the ban and chain commands refuse with `E_DISABLED` instead of recording bans that block nothing. `v-update-firewall` masks the distribution's `nftables.service` while nobody uses it, and `v-delete-sys-firewall` unmasks it.
