---
type: changed
order: 20
---

- Deleting an IP list that an active rule uses answers `E_INUSE` (`409` in REST API v2) and names the rule.
- The firewall rule list shows `0.0.0.0/0` and `::/0` as "Any address, IPv4 and IPv6", and the rule form says that both mean every address of both versions.
