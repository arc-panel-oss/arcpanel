---
type: changed
attention: true
order: 10
---

- The 2.3.0 upgrade moves the firewall from iptables and ipset to nftables (`FIREWALL_SYSTEM='nftables'`, unit `arcpanel-firewall.service` instead of `arcpanel-iptables.service`). When the new rules cannot be loaded, the server keeps its iptables rules and the upgrade warns. The `arcpanel` package depends on `nftables`; `ipset` is no longer installed. The firewall service is listed as `nftables`; `iptables` still works to start, stop and restart it. A `data/firewall/custom.sh` that calls `iptables` still runs, but its rules cannot open ports the new table drops: the upgrade warns, and `custom.nft` takes such rules.
