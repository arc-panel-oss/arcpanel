---
type: changed
---

- **The project moves to arcpanel.org.** New installs use the package repository `apt.arcpanel.org`, the panel looks up its public address at `https://arcpanel.org/ip`, and the packages, messages and templates link to `https://arcpanel.org`. `arcpanel.top` and `apt.arcpanel.top` keep working until 30 April 2027; to move an installed server now, change `apt.arcpanel.top` to `apt.arcpanel.org` in `/etc/apt/sources.list.d/arcpanel.list`.
