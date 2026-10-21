---
type: security
attention: true
---

- Comments of access keys, firewall rules and ports accept letters, digits, spaces, dots, dashes and underscores only; `v-add-access-key` refuses control characters in every argument. Every reader of an access key refuses a key file that names a setting twice, and the upgrade moves such files to `data/access-keys/.quarantine/`, lists them in the upgrade log and tells the administrator.
