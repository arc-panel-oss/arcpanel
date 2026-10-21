---
type: security
---

- Secrets that app processes reference are listed as in use, cannot be deleted without force, restart the running processes after a change and refuse control characters.
- One-off app process tasks run in the account's resource slice with the memory, CPU and task limits of the process, and stop with every process they started when the account is suspended or deleted.
- Importing a restored app process takes back a single recorded port only while the account holds fewer ports than its package allows.
