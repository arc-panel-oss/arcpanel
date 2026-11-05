---
type: added
---

- Server Doctor (E17): `v-run-sys-doctor` runs 15 read-only checks of the server (SSH root login with a password, Fail2ban, the firewall, the panel port, pending ArcPanel and security updates, swap, inodes, the port registry, time synchronisation, `php.ini`, Docker apps, app processes, the panel certificate, the reverse DNS) as a `doctor.run` job and stores the results with a score in `data/doctor/server.json`; `v-list-sys-doctor` prints them. The DNS checks ask the resolver in the new setting `DOCTOR_RESOLVER` (default `1.1.1.1`, `system` for the server's resolver).
