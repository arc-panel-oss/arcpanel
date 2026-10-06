# Current Features

This document lists every feature the panel currently provides. It is based on the source code in this repository (the HestiaCP 1.10.5 codebase). Sources:

- CLI commands in `bin/` (524 `v-*` scripts)
- Web UI pages in `web/` and `web/templates/pages/`
- Installer options in `install/arcpanel-install-*.sh`
- Templates in `install/common/templates/` and `install/deb/templates/`

Each section names the CLI commands that implement the feature, so you can check the details in the code.

> **Baseline.** This document describes the HestiaCP 1.10.5 baseline. ArcPanel 2.0 replaced its web interface: the panel app (`web-app/`) serves every page at the root URL, `web/` is removed (what the commands still use moved to `lib/`), FileGator is removed in favour of the panel's file manager, and the CSS themes are replaced by the panel themes `nocturne`, `light` and `auto`. See [CHANGELOG.md](../CHANGELOG.md) (2.0.0) and the [roadmap](roadmap.md) for what ArcPanel adds.

---

## 1. Platform and Installation

### Supported operating systems

- Debian 13 and 12. The README still lists Debian 11, but the 1.10.5 changelog removes support for it.
- Ubuntu 26.04 LTS, 24.04 LTS and 22.04 LTS.
- 64-bit only. KVM or LXC virtualization is recommended; OpenVZ 7 or older may cause DNS and firewall problems.

### Installer (`install/arcpanel-install.sh`)

You can install in interactive or unattended mode. Each component can be turned on or off:

| Option | Component | Default |
| --- | --- | --- |
| `--apache` | Apache2 web server (behind NGINX) | yes |
| `--phpfpm` | PHP-FPM | yes |
| `--multiphp` | Multiple PHP versions | no |
| `--vsftpd` / `--proftpd` | FTP server (vsftpd or ProFTPD) | vsftpd |
| `--named` | BIND DNS server | yes |
| `--mysql` / `--mysql8` | MariaDB or MySQL 8 | MariaDB |
| `--postgresql` | PostgreSQL | no |
| `--exim` | Exim mail server (MTA) | yes |
| `--dovecot` | Dovecot IMAP/POP3 server | yes |
| `--sieve` | Sieve mail filtering | no |
| `--clamav` | ClamAV anti-virus | yes |
| `--spamassassin` | SpamAssassin anti-spam | yes |
| `--iptables` | iptables firewall | yes |
| `--fail2ban` | Fail2Ban brute-force protection | yes |
| `--quota` | File system disk quota | no |
| `--resourcelimit` | Resource limits per user (cgroups) | no |
| `--webterminal` | Browser-based web terminal | no |
| `--api` | REST API | yes |
| `--port` | Panel port | 8083 |
| `--lang` | Default language | en |
| `--hostname`, `--email`, `--username`, `--password` | Initial server and admin account settings | — |
| `--with-debs`, `--force` | Install from local `.deb` packages; force install | — |

### Updates and maintenance

- Automatic updates for the panel, which you can turn on or off (`v-add-cron-arcpanel-autoupdate`, `v-delete-cron-arcpanel-autoupdate`)
- Manual update from apt or from a Git branch (`v-update-sys-arcpanel`, `v-update-sys-arcpanel-all`, `v-update-sys-arcpanel-git`)
- Release branch selection (`v-change-sys-release`)
- An **Updates** page that lists the panel packages and shows whether each one has an update (`v-list-sys-arcpanel-updates`)
- Optional email when an update is installed, with the install log attached
- Version-to-version upgrade scripts in `install/upgrade/`
- Full system rebuild and config repair (`v-rebuild-all`, `v-repair-sys-config`, `v-update-sys-defaults`)

---

## 2. Users, Roles and Accounts

### User management

- Create, edit, delete, suspend and unsuspend users (`v-add-user`, `v-delete-user`, `v-suspend-user`, `v-unsuspend-user`)
- Rename users (`v-change-user-name`)
- Change a user's contact name, email, password, language, theme, shell and package
- **Roles**: User, Administrator and DNS Sync User (`v-change-user-role`)
- Turn off Control Panel login for a user. The account keeps its services.
- An administrator can **log in as** another user
- Default list sort order for each user, by date or by name (`v-change-user-sort-order`)
- Default name servers for each user (`v-change-user-ns`)
- PHP CLI version for each user (`v-change-user-php-cli`)
- Search across all of a user's objects (`v-search-object`, `v-search-user-object`, `v-search-domain-owner`)
- Move a domain to a different owner (`v-change-domain-owner`)
- Rebuild user configuration (`v-rebuild-user`, `v-rebuild-users`)

### Authentication and security

- Two-factor authentication (TOTP) with a QR code and an account recovery code (`v-add-user-2fa`, `v-delete-user-2fa`, `v-check-user-2fa`)
- A 2FA reset flow (`web/reset2fa/`)
- An IP allow list for each user's login attempts
- Password reset by email. An administrator can allow or block this.
- Login history: an auth log with IP address and browser (`v-list-user-auth-log`, `v-delete-user-auth-log`)
- An action log for each user (`v-list-user-log`, `v-log-action`, `v-delete-user-log`)
- A timeout for inactive sessions
- CSRF protection, with a "Strict" option
- A login screen style setting: default or old style

### SSH and SFTP access

- SSH access with a shell chosen per user, including a jailed bash (`v-change-user-shell`, bubblewrap `jailbash`)
- SFTP chroot jail (`v-add-user-sftp-jail`, `v-add-sys-sftp-jail`)
- SSH chroot jail (`v-add-sys-ssh-jail`)
- Manage SSH keys for each user (`v-add-user-ssh-key`, `v-delete-user-ssh-key`, `v-list-user-ssh-key`, `v-add-user-sftp-key`)
- Install developer tools for each user: Composer and WP-CLI (`v-add-user-composer`, `v-add-user-wp-cli`)

### Hosting packages (plans)

- Create, edit, copy, rename and delete packages (`v-add-user-package`, `v-copy-user-package`, `v-rename-user-package`, `v-delete-user-package`)
- Package limits:
  - Disk quota and bandwidth
  - Number of backups, and whether incremental backups are allowed
  - Number of web domains and the number of aliases per domain
  - Default web, backend and proxy templates
  - DNS template, number of DNS zones and records, and name servers
  - Number of mail domains and mail accounts, and the mail rate limit per account per hour
  - Number of databases and cron jobs
  - SSH access (shell)
  - System resources (cgroups): CPU quota (%), CPU quota period, memory limit and swap limit (`v-update-user-cgroup`)
- File system disk quota enforcement (`v-add-sys-quota`, `v-update-user-quota`)

### Notifications

- In-panel notifications for each user, which users can mark as read or delete (`v-add-user-notification`, `v-acknowledge-user-notification`, `v-delete-user-notification`)
- Optional daily report emails (`v-add-cron-reports`)

### Usage statistics

- Disk, bandwidth, IP and object counters for each user (`v-update-user-disk`, `v-update-user-counters`, `v-update-user-stats`)
- A **Statistics** page with an overall view and a per-user view. It shows bandwidth, disk use (web, databases, mail and the user directory) and object counts (`v-list-users-stats`, `v-list-user-stats`)

---

## 3. Web Domains

### Domain management

- Add, edit, delete, suspend and unsuspend web domains (`v-add-web-domain`, `v-add-domain`, `v-delete-web-domain`)
- One command can create the web, DNS and mail parts of a domain together (`v-add-domain`)
- Domain aliases, with an optional DNS record created for each alias (`v-add-web-domain-alias`, `v-add-dns-on-web-alias`)
- Rename a domain or change its IP address (`v-change-web-domain-name`, `v-change-web-domain-ip`)
- Custom document root, which can point to another domain's directory (`v-change-web-domain-docroot`)
- Directory listing on or off (`v-change-web-domain-dirlist`)
- Domain redirection to the www or non-www form, or to any URL, with a chosen HTTP status code (`v-add-web-domain-redirect`)
- HTTP basic authentication for each domain (`v-add-web-domain-httpauth`)
- Rule that blocks subdomains of a domain owned by another user ("Enforce subdomain ownership")
- Let other users create subdomains (`v-add-web-domain-allow-users`)
- Access and error log viewer (`v-list-web-domain-accesslog`, `v-list-web-domain-errorlog`)
- Download a site's files as a zip archive (`v-dump-site`)

### Web server stack

- NGINX alone, NGINX with PHP-FPM, or NGINX as a reverse proxy in front of Apache2
- Apache2 handlers: PHP-FPM, mod_fcgid, CGI and www-data
- Multiple PHP versions at the same time (5.6 to 8.5). You can choose the PHP version for each domain (`v-add-web-php`, `v-delete-web-php`, `v-change-web-domain-backend-tpl`)
- Templates you can change for each domain: web (`v-change-web-domain-tpl`), proxy (`v-change-web-domain-proxy-tpl`) and backend PHP-FPM pool (`v-change-web-domain-backend-tpl`)
- Proxy extensions (the file types that NGINX serves directly)
- Built-in NGINX templates for apps and frameworks, including WordPress (multisite, xmlrpc-disabled and HTTP/3 versions), Laravel, Symfony, Drupal, Joomla, Magento, Moodle, Nextcloud, ownCloud, PrestaShop, OpenCart, Gitea, Forgejo, Odoo, Mautic, phpBB, Flarum, Grav, CraftCMS, CodeIgniter, Contao, YOURLS, and about 30 more
- Custom templates: you can add your own `.tpl` and `.stpl` files (`v-update-web-templates`)
- A skeleton (default files) for new domains, and custom error and suspended pages

### Caching

- NGINX FastCGI cache for NGINX + PHP-FPM, with a cache duration you can set (`v-add-fastcgi-cache`)
- NGINX proxy cache for NGINX + Apache2 (`caching` proxy template)
- Clear the NGINX cache from the UI or the API (`v-purge-nginx-cache`)

### SSL/TLS

- Let's Encrypt certificates with automatic renewal. You can choose RSA or ECDSA keys (`v-add-letsencrypt-domain`, `v-update-letsencrypt-ssl`)
- Wildcard certificates through DNS validation
- Upload your own certificate, key and CA chain (`v-add-web-domain-ssl`, `v-change-web-domain-sslcert`)
- Generate a self-signed certificate (`v-generate-ssl-cert`)
- Force HTTPS redirection (`v-add-web-domain-ssl-force`)
- HSTS (`v-add-web-domain-ssl-hsts`)
- SSL home directory option (`v-change-web-domain-sslhome`)
- Show certificate details: issued to, SANs, validity dates, signature, key size and issuer

### FTP

- Several FTP accounts per domain, each with its own path (`v-add-web-domain-ftp`, `v-change-web-domain-ftp-path`)
- Send FTP credentials by email
- vsftpd or ProFTPD as the FTP server

### Web statistics

- AWStats for each domain (`v-add-web-domain-stats`)
- Password protection for the statistics page (`v-add-web-domain-stats-user`)

### Quick Install Apps (one-click installers)

These apps can be installed from the UI (**Quick Install App**) or the CLI (`v-quick-install-app`):

Castopod, ClassicPress, Concrete CMS, DokuWiki, Dolibarr, Drupal, Flarum, Grav, Joomla, Laravel, MediaWiki, NamelessMC, Nextcloud, OpenCart, osTicket, PrestaShop, QloApps, Shopware, Symfony, thirty bees, Vvveb, WordPress.

---

## 4. DNS

- Add, edit, delete, suspend and unsuspend DNS zones and records (`v-add-dns-domain`, `v-add-dns-record`, `v-change-dns-record`)
- Record types include A, AAAA, CNAME, MX, NS, TXT, SRV, CAA, PTR and others
- Suspend or unsuspend a single record (`v-suspend-dns-record`)
- Zone templates: default, default-nomail, child-ns, Gmail, Office 365 and Zoho (`v-change-dns-domain-tpl`)
- Zone settings: IP address, TTL, SOA and expiration date
- **DNSSEC**: turn it on for each zone and view the public DNSKEY and DS records (`v-change-dns-domain-dnssec`, `v-list-dnssec-public-key`)
- Create your own name servers (child NS)
- **DNS cluster**: a master/slave cluster through the API with a DNS Sync User role (`v-add-remote-dns-host`, `v-sync-dns-cluster`, `v-add-remote-dns-domain`, `v-add-remote-dns-record`)
- Suspend or unsuspend a remote DNS host
- Show the DNS records a mail domain needs (MX, SPF, DKIM, DMARC) (`list_mail_dns`)

---

## 5. Mail

### Mail domains

- Add, edit, delete, suspend and unsuspend mail domains (`v-add-mail-domain`)
- Anti-virus with ClamAV, for each domain (`v-add-mail-domain-antivirus`)
- Spam filter with SpamAssassin, with an option to reject spam (`v-add-mail-domain-antispam`, `v-add-mail-domain-reject`)
- DKIM signing and DKIM DNS records (`v-add-mail-domain-dkim`, `v-list-mail-domain-dkim-dns`)
- Catch-all address (`v-add-mail-domain-catchall`)
- Rate limit per domain, counted in emails per hour per account (`v-change-mail-domain-rate-limit`)
- SMTP relay for each domain (`v-add-mail-domain-smtp-relay`)
- A TLS certificate for each domain for Exim, Dovecot and webmail. It can be from Let's Encrypt or uploaded (`v-add-mail-domain-ssl`, `v-update-mail-domain-ssl`)
- Choose the webmail client for each domain or turn webmail off (`v-add-mail-domain-webmail`)

### Mail accounts

- Add, edit, delete, suspend and unsuspend mail accounts (`v-add-mail-account`)
- Password rules: at least 8 characters, with uppercase, lowercase and a number. Credentials can be sent by email.
- Mailbox quota (`v-change-mail-account-quota`)
- Aliases (`v-add-mail-account-alias`)
- Forwarding, with a forward-only mode that does not keep a copy (`v-add-mail-account-forward`, `v-add-mail-account-fwd-only`)
- Discard all incoming mail
- Auto-reply (vacation message) (`v-add-mail-account-autoreply`)
- Rate limit for each account (`v-change-mail-account-rate-limit`)
- Sieve filtering (optional)

### Mail server (server-wide)

- Exim 4 (SMTP) and Dovecot (IMAP/POP3)
- A global SMTP relay for providers that block port 25 (`v-add-sys-smtp-relay`)
- An SMTP server for the panel's own emails (`v-add-sys-smtp`)
- DNSBL configuration (`v-add-sys-mail-dnsbl`)
- Clear the mail queue (`v-delete-sys-mail-queue`)
- Webmail: Roundcube, and SnappyMail as an option. The webmail alias can be changed (`v-add-sys-roundcube`, `v-add-sys-snappymail`, `v-change-sys-webmail`)

---

## 6. Databases

- MariaDB, MySQL 8 and PostgreSQL
- Add, edit, delete, suspend and unsuspend databases (`v-add-database`)
- Change a database's user, password or owner (`v-change-database-user`, `v-change-database-password`, `v-change-database-owner`)
- Choose the character set when you create a database
- Send database credentials by email
- **Multiple database hosts**, including remote hosts, with a maximum number of databases for each host (`v-add-database-host`, `v-suspend-database-host`)
- Import and export (dump) databases (`v-import-database`, `v-dump-database`)
- Disk use for each database (`v-update-database-disk`)
- **phpMyAdmin** with an alias you can change (`v-change-sys-db-alias`)
- **phpMyAdmin single sign-on (SSO)** (`v-add-sys-pma-sso`)
- **Limit phpMyAdmin to logins from the panel only**: this turns off phpMyAdmin's own login form (`v-add-sys-pma-restrict`)
- **phpPgAdmin** for PostgreSQL

---

## 7. Cron Jobs

- Add, edit, delete, suspend and unsuspend cron jobs (`v-add-cron-job`, `v-change-cron-job`)
- A schedule generator in the UI (every N minutes, hours, days or months, weekdays, and so on)
- Shows the server's system time
- Panel system cron jobs, which the administrator can view under **Server → Panel Cronjobs**

---

## 8. Backups and Restore

### Standard backups

- Full backups of each user: web, DNS, mail, databases, cron jobs and user files (`v-backup-user`, `v-backup-users`)
- Schedule a backup or download it from the UI (`v-schedule-user-backup`, `v-schedule-user-backup-download`)
- Compression with gzip or zstd, with a compression level you can set
- A local backup directory you can change
- Remote backup targets:
  - **FTP**
  - **SFTP**
  - **Backblaze B2**
  - **Rclone**, which supports more than 50 cloud storage providers
- Backup exclusions for each user (web domains, mail, databases, cron jobs and user directories) (`v-update-user-backup-exclusions`)
- Restore everything or only part of a backup: web, DNS, mail, database or cron (`v-restore-user`, `v-restore-web-domain`, `v-restore-database`, `v-restore-mail-domain`, `v-restore-dns-domain`, `v-restore-cron-job`)
- Back up only the configuration (`v-backup-user-config`)
- The number of backups kept is set in the user's package

### Incremental backups (Restic)

- Incremental backups to a Restic repository (`v-add-backup-host-restic`, `v-backup-user-restic`)
- Retention rules: keep the last N daily, weekly, monthly and yearly snapshots
- Create snapshots, browse them and view their files (`v-list-user-backups-restic`, `v-list-user-files-restic`)
- Restore a whole account or only part of it: a web domain, a database, a mail domain, a DNS zone, a cron job or one file (`v-restore-user-full-restic`, `v-restore-file-restic`, …)
- Turn incremental backups on or off for each package

### Migration from other panels

- Import a **cPanel** backup (`v-import-cpanel`)
- Import a **DirectAdmin** backup (`v-import-directadmin`)

---

## 9. Firewall and Security

- iptables firewall with rules you can add, edit, move, delete, suspend and unsuspend (`v-add-firewall-rule`, `v-move-firewall-rule`)
- Rule options: ACCEPT or DROP, TCP/UDP/ICMP, ports (one port, ranges or lists) and IPs in CIDR form
- **IPsets**: IP lists from a URL or a file, updated automatically (`v-add-firewall-ipset`, `v-update-firewall-ipset`)
- **Fail2Ban** brute-force protection with a ban list you can manage (`v-add-firewall-ban`, `v-delete-firewall-ban`)
- Firewall chains for each service, used by Fail2Ban (for example SSH, mail and web), with a port and protocol (`v-add-firewall-chain`)
- Turn the system firewall on or off (`v-add-sys-firewall`, `v-stop-firewall`)
- **System protection settings**:
  - A system administrator account that other administrators can only read
  - Hide the system administrator account from other administrators
  - Stop other administrators from opening Server Settings
- Demo / read-only mode (`v-change-sys-demo-mode`)
- Read-only login for suspended users (preview feature)

---

## 10. Server Administration

### Server settings (`/edit/server/`)

- Basic options: hostname, time zone, default theme and default language. Each can be applied to all users.
- Debug mode
- Preview features, which can be turned on or off (`/list/server/preview/`)
- Web server, proxy server and PHP interpreter settings. Choose which PHP versions are installed and the system PHP version. Set the backend pool mode.
- DNS server and DNS cluster settings
- Mail server settings: anti-virus, spam filter, webmail alias and global SMTP relay
- Database settings: MySQL and PostgreSQL support, phpMyAdmin and phpPgAdmin aliases, SSO and access limits
- Backup settings: local and remote targets and incremental (Restic) backups
- The panel's own SSL certificate: Let's Encrypt, uploaded or self-signed (`v-change-sys-arcpanel-ssl`, `v-add-letsencrypt-host`)
- Change the panel port (`v-change-sys-port`)
- **Policies**, which control what users may do:
  - Edit their account details
  - Change templates for web domains and DNS zones
  - View and delete action and login logs
  - Log in with read-only access while suspended
  - Create backups while suspended
  - Keep error documents and skeleton templates in sync when a user is rebuilt
- **Plugins**: Quick App Installer, File Manager, Web Terminal, system resource limits (cgroups), file system disk quota and firewall

### Service configuration editors

Edit config files in the browser with a service restart option. Supported services: NGINX, Apache2 (httpd), PHP (php.ini / PHP-FPM), MySQL/MariaDB, PostgreSQL, BIND9, Dovecot, Exim, ClamAV, SpamAssassin, Fail2Ban, iptables, SSH, cron, vsftpd, ProFTPD and the web terminal (`v-change-sys-service-config`, `v-list-sys-*-config`).

### Services and monitoring

- A **Services** page: start, stop and restart any service, and view CPU and memory use for each service (`v-list-sys-services`, `v-restart-service`, `v-start-service`, `v-stop-service`)
- Restart the whole server (`v-restart-system`)
- Server info: CPU, RAM, disk, network, web, DNS, mail and database status (`v-list-sys-info`, `v-list-sys-*-status`)
- **RRD graphs**: load average, memory, network, disk, web server, FTP, SSH, mail, MySQL and PostgreSQL. Periods: daily, weekly, monthly, yearly, 2 years and 3 years (`v-list-sys-rrd`, `v-update-sys-rrd-*`, `v-export-rrd`)

### IP address management

- Add, edit and delete IP addresses with netmask and network interface (`v-add-sys-ip`, `v-delete-sys-ip`)
- Shared or dedicated IP status (`v-change-sys-ip-status`)
- Give an IP to a user or to a domain (`v-change-sys-ip-owner`, `v-change-sys-ip-name`)
- NAT IP association for servers behind NAT (`v-change-sys-ip-nat`)
- Multiple IPs for web, mail and DNS

### White label

- Custom application name and page title
- Custom sender name, sender address and subject for panel emails
- Hide the link to the documentation
- Upload or reset a custom logo (`v-update-white-label-logo`)

### Customisation

- Themes: default, dark, flat and vestia (`v-change-user-theme`, `v-refresh-sys-theme`)
- **More than 45 interface languages**, including English, Vietnamese, Japanese, Chinese (simplified and traditional), Korean, French, German, Spanish, Russian and Arabic (`web/locale/`)

---

## 11. File Manager and Web Terminal

- A **file manager** in the browser (FileGator) that logs users in through the panel (`v-add-sys-filemanager`)
- File system commands used by the file manager: create, copy, move and delete files and directories, change permissions, extract archives, create archives and search (`v-add-fs-*`, `v-copy-fs-*`, `v-move-fs-*`, `v-change-fs-file-permission`, `v-extract-fs-archive`, `v-search-fs-object`)
- A **web terminal**: a shell in the browser over WebSocket, with a port you can set (`v-add-sys-web-terminal`, `v-change-sys-web-terminal-port`). It is only available to users who have a login shell.

---

## 12. API and Automation

- **REST API** (`web/api/`) for all `v-*` commands
- **Access keys** for each user, with a comment and a set of permissions (`v-add-access-key`, `v-list-access-keys`, `v-delete-access-key`)
- Built-in permission sets for access keys:
  - `billing`: create, suspend and delete users and change their packages, for billing systems such as WHMCS
  - `mail-accounts`: manage mail accounts
  - `purge-nginx-cache`: clear the NGINX cache
  - `update-dns-records`: update DNS records, which is useful for dynamic DNS
  - `sync-dns-cluster`: DNS cluster sync
  - `phpmyadmin-sso`: create temporary users for phpMyAdmin SSO
- API access levels: off, admin only or all users. Legacy API keys can also be turned on (`v-change-sys-api`, `v-generate-api-key`)
- An IP allow list for API access (`v-add-sys-api-ip`)
- A **full CLI** of 524 `v-*` commands (add, change, delete, list, suspend, rebuild, backup and restore). Most list commands can print their output as JSON, CSV, shell or plain text.
- Bulk actions in the UI: apply one action (suspend, unsuspend, delete, rebuild and others) to many users, domains, databases, cron jobs, IPs, packages, firewall rules, services or backups (`web/bulk/`)

---

## 13. Operational Background Jobs

- Let's Encrypt renewal job (`v-add-cron-letsencrypt-job`)
- Queue processing for scheduled backups, restores and Let's Encrypt requests (`v-update-sys-queue`, `v-schedule-*`)
- Disk, traffic and statistics counters (`v-update-*-disk`, `v-update-web-domains-traff`, `v-update-web-domains-stat`)
- Restart jobs that wait in a queue (`v-add-cron-restart-job`)
- Automatic panel updates (optional)
