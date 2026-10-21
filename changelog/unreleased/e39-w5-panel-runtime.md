---
type: security
---

- The panel's PHP pool runs with `open_basedir`. It may read the panel app, `lib/`, `data/`, `/etc/arcpanel/`, `/tmp/` and the backup folder of `arcpanel.conf`, plus the folders listed one per line in `/etc/arcpanel/panel-php-basedir.conf`, a file that upgrades keep. It also runs without `system`, `passthru`, `shell_exec`, `popen` and `dl`. Changing `BACKUP` with `v-change-sys-config-value` updates the list.
- `v-add-sys-arcpanel` no longer starts the panel app as root. The command allow-list and the translations are built as `arcpanelweb`, and root moves the files into place.
- The firewall no longer accepts packets from outside that claim one of the server's own addresses. The server's traffic to itself still arrives on the loopback interface.
- Only ArcPanel puts the internal `silo-endpoint` template on a domain of the administrator. The template commands, packages and restores refuse it for other users. The object storage endpoint answers `403` to the admin API path of Silo.
