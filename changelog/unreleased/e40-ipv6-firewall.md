---
type: added
order: 10
---

- **IPv6 in the firewall.** The firewall runs on nftables: one table, `inet arcpanel`, filters IPv4 and IPv6 and is replaced in one step, checked first, so a rule set nftables refuses never replaces the one that runs. A port closed in the panel is now closed over IPv6 too; rules, bans and IP lists accept IPv6 addresses and networks, the IP list page offers IPv6 country lists, and Fail2Ban bans IPv6 addresses. `0.0.0.0/0` and `::/0` mean every address of both versions; ICMPv6 neighbour discovery is always allowed.
- `v-check-firewall` and `GET /api/v2/system/firewall/status` (`getFirewallStatus`) tell whether the loaded rules are still the panel's (`ok`, `drift`, `missing`); the Firewall page warns and offers a reload when they are not.
- `data/firewall/custom.nft`: nftables commands loaded together with the panel's table.
