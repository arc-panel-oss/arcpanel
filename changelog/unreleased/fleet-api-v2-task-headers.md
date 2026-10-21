---
type: changed
order: 40
---

- The REST API v2 document declares `Link` on every `202` that starts a task and `Location` only where the answer names a resource, as the panel answers. Before, it declared `Location` on the backup, snapshot, malware scan, Docker engine and PHP operations, which send only `Link`.
