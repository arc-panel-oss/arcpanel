---
type: fixed
---

- Automatic and manual panel updates now fail with a clear error when the ArcPanel package source `/etc/apt/sources.list.d/arcpanel.list` or its key `/usr/share/keyrings/arcpanel-keyring.gpg` is missing, or when the repository cannot be read. Before, they reported that nothing needed an update, so a server without the source silently stayed on its version. The automatic update writes the reason and the line to restore in `autoupdate.log`, exits with an error and notifies the administrator once.
