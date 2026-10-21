---
type: security
---

- New `v-change-sys-tls-login yes|no` and a "Mail and FTP" setting on the Security tab make Dovecot and the FTP server refuse logins that are not encrypted: Dovecot 2.3 sets `disable_plaintext_auth`, vsftpd `force_local_logins_ssl` and ProFTPD `TLSRequired auth`. Webmail keeps working, since Dovecot treats connections from the server itself as encrypted; Dovecot 2.4 refuses unencrypted logins already. New servers start with the setting on; upgraded servers keep their current login rules until an administrator turns it on.
- vsftpd now answers passive data connections only from the address of the client that logged in (`pasv_promiscuous=NO`), on new and upgraded servers.
