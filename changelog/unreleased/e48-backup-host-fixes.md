---
type: fixed
---

- Incremental (restic) backups now run every night after the archive backups, also on servers that keep no archive backups; before, they ran only when started by hand.
- An SFTP backup host set up with a private key in the panel keeps working: the key is stored root-only next to the host's settings instead of pointing at a temporary file that was deleted.
- An rclone backup host without a folder uploads archives to the root of the remote.
- Deleting an archive on an SFTP backup host no longer prints debug output, and downloading a remote archive no longer calls a Google Drive function that does not exist.
