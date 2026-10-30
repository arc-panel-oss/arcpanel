---
type: security
---

- The firewall no longer accepts packets from outside that claim one of the server's own addresses. The server's traffic to itself still arrives on the loopback interface. Since the table no longer lists the server's IPv6 addresses, rotating IPv6 privacy addresses no longer make the Firewall page report changed rules after a reboot.
