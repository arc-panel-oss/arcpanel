# ArcPanel — Product Requirements Document

**Status:** Draft v1 (2026-10-07) · **Covers:** ArcPanel 2.x for the three panel roles — admin, reseller and user · **Companions:** [roadmap](./roadmap.md) (what and when), [design documents](./design/README.md) (how), [ADRs](./adr/README.md) (decisions that are expensive to reverse)

This document states what ArcPanel must do for each of its three roles and why. It describes the product as a whole: what 2.0 ships and what the post-2.0 backlog adds. Each user story carries a status tag:

- **[2.0]** — implemented on `develop` for the 2.0 release.
- **[Planned Exx]** — designed or scoped in the roadmap; the epic number points to it.

Where this document and a feature spec differ, the spec and its implementation notes win (see the repository guide).

---

## Problem Statement

People who run Linux web hosting have to choose between expensive commercial panels and free panels that stop at "one administrator and some users". The free baseline ArcPanel started from (HestiaCP 1.10.5) has three problems that each role feels differently:

**The server owner (admin)** has to do every customer onboarding personally, because there is nobody between "full administrator" and "single hosting user". Nothing in the panel knows which network ports a user may bind to, so Node.js and Docker workloads collide or get exposed by accident. Installing a runtime, a Node app or a self-hosted tool means SSH. The web interface is a set of hand-written PHP pages that are hard to test and extend, and the panel does not tell the admin when a disk fills, a service stops or a certificate is about to expire.

**The hosting provider or agency (reseller)** cannot run its own customers on someone else's server. It either gets full administrator rights — and with them every other tenant's data — or it is a plain user that must file a ticket with the server owner for every new customer, package change or extra port. There is no notion of "my customers", "my plans" or "my share of the server".

**The hosting customer (user)** gets a dated interface with a separate-looking file manager, a template dropdown that lists NGINX file names, and no way to host anything but PHP without SSH, nvm, pm2 and a hand-written reverse proxy. When something breaks, the user reads raw logs or waits for support, and support requests go to the server owner even when the customer bought hosting from a reseller.

## Solution

ArcPanel is an open-source Linux hosting control panel, a hard fork of HestiaCP 1.10.5 with every internal name changed to ArcPanel's. It keeps the proven core — one shell command per operation (`v-*`), plain-text data files, the REST API — and replaces everything the roles see:

- **Three roles, owner-scoped everywhere.** An **admin** owns the server. A **reseller** owns a tree of customers and manages them inside its own package limits, with no view of any other tenant. A **user** manages its own hosting. Every page, list, search result and command answers only for objects the signed-in principal may see; objects outside its scope do not exist for it (not found), and refused actions inside its scope are forbidden.
- **A modern panel app.** A Laravel application with one design system serves every page at the root URL: role-aware navigation, a command palette, light and dark themes, mobile layouts and accessibility scores of 90 or more.
- **Install anything without SSH.** Per-user Node.js runtimes and pm2-managed Node apps bound to domains with HTTPS; a curated Docker app catalog where every container runs as the hosting user; web templates with descriptions, variables and runtime badges for PHP, static, SPA, Node, Python, Go, Ruby and Laravel Octane sites; admin-installed system packages for the runtimes those templates need.
- **A port registry.** The admin hands out ports; resellers pass them on to customers; Node and Docker apps only bind ports from the registry; public exposure is an explicit, firewall-backed choice.
- **First-party tools.** A file manager where every file operation runs as the user, a web terminal, backup browsing down to single files in restic snapshots.
- **Operations and trust (post-2.0).** A job center for long operations, alerts to the channels admins already watch, a Doctor that explains misconfigurations, an audit log of who did what across tenants, safe automatic updates, and workflow features (Git deploy, WordPress toolkit, staging, collaborators).

### Role summary

| Role        | Who                               | Scope                                                   | Lands on                                                                  |
| ----------- | --------------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------- |
| Admin       | Server owner(s)                   | Every account and every server setting                  | Server dashboard: health, users, resellers, expiring certificates         |
| Reseller    | Hosting provider, agency          | Itself plus the users it owns (its **tree**), one level | Reseller dashboard: allocation vs. plan, customers, expiring certificates |
| User        | Hosting customer                  | Its own account                                         | User dashboard: own usage, quick actions, "Your provider" card            |
| DNS cluster | System account for DNS sync (API) | No panel access                                         | —                                                                         |

A reseller is also a user: it has its own domains, mail and databases ("My hosting"), counted against its own package like any user.

### Capability matrix (summary)

`all` = any account · `tree` = the reseller and its customers · `self` = the effective user · `pkg` = only when the account's package allows it · ✖ = not available.

| Capability                                                  | Admin           | Reseller                                          | User                         |
| ----------------------------------------------------------- | --------------- | ------------------------------------------------- | ---------------------------- |
| Server settings, services, IPs, firewall, updates, packages | ✔               | ✖                                                 | ✖                            |
| Accounts: list, create, edit, suspend, delete               | all             | tree (customers)                                  | ✖                            |
| Accounts: change role, change owner, shell, PHP CLI         | ✔               | ✖                                                 | ✖                            |
| Hosting packages (plans)                                    | create/edit all | assign allowed plans; create own if policy allows | ✖                            |
| Log in as                                                   | any user        | own customers (if policy allows)                  | ✖                            |
| Web, DNS, mail, databases, cron, files                      | all             | tree                                              | self                         |
| Backups: list, schedule, restore, download                  | all             | tree                                              | self                         |
| Node.js runtimes and apps, Docker apps                      | all             | tree, pkg                                         | self, pkg                    |
| Ports                                                       | allocate to any | sub-allocate own pool to customers                | reserve, release, set public |
| Statistics                                                  | server + any    | tree totals or one customer                       | self                         |
| Logs                                                        | all, delete     | own; customers' read-only                         | self (if policy allows)      |
| Access keys and SSH keys                                    | any account     | self                                              | self                         |
| Web terminal                                                | ✔               | own shell only, never while acting as a customer  | ✔ (if shell allowed)         |

---

## User Stories

<!-- markdownlint-disable MD029 MD030 -- one numbered list across all roles, so every story keeps a stable number -->

### Everyone (all three roles)

1. As any panel user, I want to sign in with my username and password and, when enabled, a TOTP second factor, so that my account is protected. **[2.0]**
2. As any panel user, I want to reset a forgotten password or a lost second factor through a verified flow, so that I can recover access without contacting support. **[2.0]**
3. As any panel user, I want the sidebar to show only the sections my role and my package allow, so that I never click into a page that refuses me. **[2.0]**
4. As any panel user, I want a command palette (Ctrl+K) that searches the users, domains, mail accounts and databases I am allowed to see, so that I reach any object in two keystrokes. **[2.0]**
5. As any panel user, I want a notification tray with my unread notifications, so that I see what the panel or my provider told me. **[2.0]**
6. As any panel user, I want light and dark themes and layouts that work on a phone, so that I can manage hosting from any device. **[2.0]**
7. As any panel user, I want every page to be usable with the keyboard and a screen reader, so that the panel is accessible. **[2.0]**
8. As any panel user, I want the interface in my language, so that I understand every label and message. **[2.0]**
9. As any panel user, I want long operations (installs, backups, restores, builds) to run in the background with visible progress, so that I can keep working and know when they finish. **[2.0]**
10. As any panel user, I want an IP allow list for my logins, so that only my networks can sign in to my account. **[2.0]**
11. As any panel user, I want to see my login history and my action log, so that I can spot activity I did not do. **[2.0]**
12. As any panel user, I want old 1.x bookmarks to redirect to the new pages, so that my links keep working after the upgrade. **[2.0]** (redirects end in 2.1)
13. As any panel user, I want to sign in with a passkey, so that I do not depend on a password. **[Planned E26]**
14. As any panel user, I want to see my active sessions and sign out the others, so that I can end a session on a lost device. **[Planned E26]**
15. As any panel user, I want a job drawer that shows every running, finished and failed long operation I started, with its log and a retry button, so that nothing fails silently. **[Planned E15]**

### Admin

#### Server setup and operations

16. As an admin, I want a dashboard with server health (load, memory, disk, services) and counts of users, resellers and customers, so that I see the state of the server at a glance. **[2.0]**
17. As an admin, I want to see certificates that expire within 14 days across all accounts, so that I renew them before visitors see errors. **[2.0]**
18. As an admin, I want to edit server settings (hostname, time zone, language, policies, mail, backups, web terminal, file manager, API access) on one page, so that I do not edit configuration files by hand. **[2.0]**
19. As an admin, I want to start, stop and restart services and see their status, memory and uptime, so that I can recover a stopped service from the browser. **[2.0]**
20. As an admin, I want to edit service configuration files (NGINX, Apache, PHP-FPM, MariaDB, PostgreSQL, Exim, Dovecot, BIND) with a restart hint, so that I can tune the stack without SSH. **[2.0]**
21. As an admin, I want to manage IP addresses and assign them to accounts, so that customers can get dedicated IPs. **[2.0]**
22. As an admin, I want to manage firewall rules, IP sets and fail2ban bans, so that I control who reaches the server. **[2.0]**
23. As an admin, I want firewall rules created by the port registry to be marked and protected from manual edits, so that port exposure stays consistent with the registry. **[2.0]**
24. As an admin, I want server graphs (load, memory, network, disk, services) over time, so that I can see trends and spikes. **[2.0]**
25. As an admin, I want to see pending panel updates, toggle automatic panel updates and run updates as a background task, so that the panel stays current. **[2.0]**
26. As an admin, I want to manage installed PHP versions and panel plugins from the server pages, so that I can offer the runtimes my customers need. **[2.0]**
27. As an admin, I want to install system packages (Python 3, Go, Ruby, FFmpeg, ImageMagick, build tools) from a curated catalog, so that runtime-based web templates work without SSH. **[2.0]**
28. As an admin, I want to install and remove the Docker engine from the server pages, so that I decide whether the server offers Docker apps. **[2.0]**
29. As an admin, I want a White Label setting for the panel name and logo, so that the panel carries my brand. **[2.0]**
30. As an admin, I want to generate a certificate signing request from the panel, so that I can order commercial certificates. **[2.0]**
31. As an admin, I want a web terminal in the browser, so that I can run a command without an SSH client. **[2.0]**

#### Accounts, resellers and plans

32. As an admin, I want a Users list with role, owner, package, usage and status columns and filters by role and owner, so that I find any account quickly. **[2.0]**
33. As an admin, I want a tree view (admin → resellers → customers, plus users I own directly), so that I see who belongs to whom. **[2.0]**
34. As an admin, I want a create-user wizard that also sets role and owner and can create a first web domain (with DNS and mail) and a database, so that one form onboards a customer completely. **[2.0]**
35. As an admin, I want to send a welcome e-mail with the panel details when I create an account, so that the customer can sign in right away. **[2.0]**
36. As an admin, I want to convert a user into a reseller when its package allows customers, so that an existing customer can start reselling. **[2.0]**
37. As an admin, I want to demote a reseller back to a user only when it has no customers left, so that no customer is left without an owner. **[2.0]**
38. As an admin, I want to move a user from one owner to another (myself or a reseller I own), with the new owner's quota checked, so that I can transfer customers between resellers. **[2.0]**
39. As an admin, I want to suspend a reseller together with all its customers in one action, so that I can stop an abusive or unpaid reseller at once. **[2.0]**
40. As an admin, I want deleting a reseller to be refused while it still has customers, so that I never orphan accounts by accident. **[2.0]**
41. As an admin, I want to edit any account's contact, password, language, name servers, login restrictions, shell and PHP CLI version, so that I can support every customer. **[2.0]**
42. As an admin, I want to reset another account's second factor and disable its panel login without suspending its services, so that I can handle lost devices and abuse. **[2.0]**
43. As an admin, I want to log in as any user and return to my own account, so that I see exactly what the customer sees. **[2.0]**
44. As an admin, I want the protected system administrator account to stay read-only in the panel, so that nobody locks the server out of its own panel. **[2.0]**
45. As an admin, I want to create, edit, copy, rename and delete hosting packages with all limits (disk, bandwidth, domains, aliases, DNS, mail, databases, cron, backups, shell, templates, name servers, CPU and memory limits), so that I define my plans in one place. **[2.0]**
46. As an admin, I want a "Reseller" section in the package form — maximum customers, which plans the reseller may assign, whether this plan is offered to resellers, and the port pool size — so that a package fully defines a reseller's business. **[2.0]**
47. As an admin, I want to see how many accounts use each package and to be refused when I delete a package in use, so that no account loses its plan. **[2.0]**
48. As an admin, I want to decide by policy whether resellers may create their own packages and whether they may log in as their customers, so that I control how much freedom resellers get. **[2.0]**
49. As an admin, I want a package to switch Node.js and Docker apps on or off and limit them, so that only plans I choose can run them. **[2.0]**
50. As an admin, I want resellers' allocations checked when they create customers or change plans, so that resellers can never sell more than their own plan. **[2.0]**
51. As an admin, I want orphaned customers (owner deleted or demoted) to fall back to me and be reported by the daily counters job, so that ownership never silently breaks. **[2.0]**

#### Ports

52. As an admin, I want to set the server-wide port range and see the reserved ports (panel, SSH, mail, database, FTP), so that the registry never hands out a system port. **[2.0]**
53. As an admin, I want to allocate single ports or ranges to any account and see live usage with owners and conflicts, so that every listening high port has an owner. **[2.0]**
54. As an admin, I want a package's port pool to be allocated automatically when an account is created and topped up on a package change, so that I do not allocate ports by hand. **[2.0]**
55. As an admin, I want to allow or forbid public exposure per port, so that only ports I approve are reachable from the internet. **[2.0]**
56. As an admin, I want suspending or deleting an account to close its public ports and release them (to the reseller's pool for customers), so that no stale firewall opening remains. **[2.0]**

#### Hosting on behalf of others

57. As an admin, I want to manage any account's web domains, DNS, mail, databases, cron jobs, files, backups, Node apps and Docker apps, so that I can fix any customer problem. **[2.0]**
58. As an admin, I want to set mail rate limits per domain and account and to rebuild objects, so that I can contain spam and repair configurations. **[2.0]**
59. As an admin, I want to read and delete the logs of every account, so that I can investigate and clean up. **[2.0]**
60. As an admin, I want server-wide statistics and the statistics of any single account, so that I see who uses the server's resources. **[2.0]**
61. As an admin, I want to manage access keys of any account with any permission set and gate the API (off, admin only, everyone), so that integrations work and stay controlled. **[2.0]**

#### Planned for admins

62. As an admin, I want alert rules (disk, inodes, load, stopped services, expiring certificates, failed backups, quotas, orphan ports, blacklisted mail IPs) sent once to e-mail, Telegram, Slack, Discord or a webhook, with a "resolved" follow-up, so that I learn about problems within minutes. **[Planned E16]**
63. As an admin, I want a Doctor page that checks DNS, certificates, mail authentication, PHP end-of-life, SSH and firewall hardening, and offers one-click fixes, so that common misconfigurations are fixed without a ticket. **[Planned E17]**
64. As an admin, I want one update inventory with a policy per component (off, notify, security only, all), maintenance windows, pre-update snapshots, health checks and rollback, so that I can turn on automatic updates without fear. **[Planned E33]**
65. As an admin, I want a tamper-evident audit log of every state change across all tenants, including who impersonated whom, so that I can answer "who did this". **[Planned E23]**
66. As an admin, I want to require two-factor authentication per role or package with a grace period, so that privileged accounts cannot skip it. **[Planned E26]**
67. As an admin, I want single sign-on through OIDC for the panel, so that admins use the company identity provider. **[Planned E26]**
68. As an admin, I want a per-domain web application firewall with safe presets, rate limiting and geo-blocking, so that attacks are blocked before they reach PHP. **[Planned E24]**
69. As an admin, I want scheduled malware scans of web files with quarantine and owner notification, so that compromised sites are caught before the IP is blacklisted. **[Planned E25]**
70. As an admin, I want to manage PHP extensions per installed PHP version and set per-package maximums for per-domain PHP settings, so that customers can tune PHP inside limits I set. **[Planned E19]**
71. As an admin, I want a migration wizard that imports accounts from HestiaCP, VestaCP, another ArcPanel, Plesk, CyberPanel or aaPanel with a dry run, so that moving customers is low-risk. **[Planned E31]**
72. As an admin, I want a published OpenAPI description and signed outgoing webhooks for account and domain events, so that billing systems integrate without polling. **[Planned E29]**
73. As an admin, I want to enable an AI assistant per role with my own provider key, with redaction and an audit record of every request, so that less experienced users get help without leaking data. **[Planned E32]**

### Reseller

#### Dashboard and account

74. As a reseller, I want a dashboard that shows, per limit, how much of my plan I have allocated to customers and how much my tree actually uses, so that I know how much I can still sell. **[2.0]**
75. As a reseller, I want my customer count against my maximum, my suspended customers and my most recently created customers on the dashboard, so that I see the state of my business. **[2.0]**
76. As a reseller, I want to see certificates that expire within 14 days across my tree, so that I can act before my customers notice. **[2.0]**
77. As a reseller, I want my own hosting ("My hosting": web, DNS, mail, databases, cron, files, apps) in the same panel, so that I run my own sites next to my customers'. **[2.0]**
78. As a reseller, I want to edit my own contact, password, language and login restrictions on "My account", so that I manage myself like any user. **[2.0]**
79. As a reseller, I want the panel to label my accounts section "Customers", so that the interface speaks my language. **[2.0]**

#### Customers

80. As a reseller, I want a Customers list with package, usage bars and status for every account I own and no one else's, so that I manage exactly my customers. **[2.0]**
81. As a reseller, I want a create-customer wizard — account, plan from my assignable list with live "fits in my plan" feedback, optional first web domain with DNS and mail, optional database, review — so that I onboard a customer in one pass without the admin. **[2.0]**
82. As a reseller, I want a refusal to name the first limit that does not fit (for example "web domains" or "customers"), so that I know what to change or what to ask my admin for. **[2.0]**
83. As a reseller, I want a failed later wizard step to keep the earlier steps and tell me which step failed, so that I can finish the onboarding from the customer's page. **[2.0]**
84. As a reseller, I want to send the welcome e-mail with panel details to a new customer, so that the customer can sign in right away. **[2.0]**
85. As a reseller, I want a customer detail page with contact, plan, usage against limits and the customer's domains and object counts, so that I can answer support questions. **[2.0]**
86. As a reseller, I want to edit a customer's contact, password, language, name servers and login IP allow list, so that I handle routine support myself. **[2.0]**
87. As a reseller, I want to change a customer's plan to another plan I may assign, with my allocation re-checked, so that customers can upgrade and downgrade. **[2.0]**
88. As a reseller, I want to suspend, unsuspend and delete customers one by one or in bulk, so that I can act on unpaid or abusive accounts. **[2.0]**
89. As a reseller, I want a bulk action that contains an account outside my tree to fail as a whole with nothing changed, so that a crafted request cannot touch another tenant. **[2.0]**
90. As a reseller, I want to log in as one of my customers, see a "viewing as" badge with a return button, and come back to my own account, so that I see and fix exactly what the customer sees. **[2.0]**
91. As a reseller, I want to be returned to my own account with a notice when the customer I am viewing is moved away or deleted, so that I never act on an account I no longer own. **[2.0]**
92. As a reseller, I want my web terminal to stay my own shell and to be hidden while I act as a customer, so that impersonation never gives me a customer's shell. **[2.0]**

#### Plans

93. As a reseller, I want to see the plans my admin lets me assign, so that I know what I can offer. **[2.0]**
94. As a reseller, I want to create and edit my own plans with my own limit shown next to every field and values above it refused, so that I can design offers that fit my allocation. **[2.0]** (only when the admin's policy allows it)
95. As a reseller, I want my own plans to be shown without any internal prefix and to be invisible to other resellers, so that my offers stay mine. **[2.0]**
96. As a reseller, I want to delete my own plans only when no customer uses them, so that no customer loses its plan. **[2.0]**

#### Hosting of the tree

97. As a reseller, I want to manage my customers' web domains, DNS, mail, databases, cron jobs and files with an owner column and filter, so that I can work across my tree without switching accounts. **[2.0]**
98. As a reseller, I want to list, schedule, restore and download my customers' backups, including single files from restic snapshots, so that I can recover customer data. **[2.0]**
99. As a reseller, I want statistics as totals over my tree or for one customer, so that I see where my resources go. **[2.0]**
100.  As a reseller, I want to read my customers' logs (read-only) and my own logs, so that I can investigate problems without changing evidence. **[2.0]**
101.  As a reseller, I want to manage my customers' Node.js runtimes, Node apps and Docker apps when their plans allow them, so that I can deploy applications for them. **[2.0]**

#### Ports

102. As a reseller, I want a Ports page that shows my pool (ports the admin allocated to me), the ports held by each customer and their live usage, so that I see who uses what. **[2.0]**
103. As a reseller, I want to give free ports from my pool to a customer and take unused ports back, so that I distribute my allocation myself. **[2.0]**
104. As a reseller, I want my port pool counted as a limit for my whole tree, so that customer plans with ports are checked like any other limit. **[2.0]**
105. As a reseller, I want to withdraw a customer's permission to make ports public, so that I can stop exposure I do not support. **[2.0]** (granting public exposure stays with the admin)
106. As a reseller, I want to ask my admin for more ports from the Ports page, so that I do not need another channel. **[2.0]**
107. As a reseller, I want my customers' "request more ports" messages to reach me instead of the admin, so that my customers' support stays with me. **[2.0]**

#### Isolation guarantees

108. As a reseller, I want every page, list, search result, notification and JSON endpoint to treat another reseller's customers as non-existent (not found), so that no other tenant can learn mine exist and I cannot see theirs. **[2.0]**
109. As a reseller, I want to be unable to log in as an admin, another reseller or another reseller's customers, so that impersonation cannot escalate. **[2.0]**
110. As a reseller, I want my session to end when the admin demotes me, so that a demoted account keeps no reseller powers. **[2.0]**

#### Planned for resellers

111. As a reseller, I want an API key limited to my tree (create, suspend, unsuspend, delete customers, change plans, reset passwords), so that my billing system automates onboarding. **[Planned E29]**
112. As a reseller, I want my own logo and name on my customers' login page and e-mails, so that customers see my brand, not the server owner's. **[Planned, post-2.0]**
113. As a reseller, I want alerts for my tree (customer quotas near limit, expiring certificates, failed backups), so that I act before customers complain. **[Planned E16]**
114. As a reseller, I want a read-only audit log of my tree, so that I can see who changed what in my customers' accounts. **[Planned E23]**
115. As a reseller, I want the job drawer to show long operations across my tree, so that I can follow installs and restores I started for customers. **[Planned E15]**
116. As a reseller, I want to see the update state of my tree's Docker apps, Node packages and WordPress sites, so that I know which customers need updates. **[Planned E33]**
117. As a reseller, I want restored customers to keep me as their owner, so that a restore does not move a customer back to the admin. **[Planned, follow-up of E11]** (today the admin re-assigns the owner after a restore)

### User

#### Account

118. As a user, I want a dashboard with my usage against my plan and quick actions (add web domain, mail domain, database, DNS zone, cron job, open files), so that my common tasks are one click away. **[2.0]**
119. As a user owned by a reseller, I want a "Your provider" card with my reseller's name and contact e-mail, so that I know whom to ask for help. **[2.0]**
120. As a user, I want to change my contact, password, language and login IP allow list and turn my second factor on or off, so that I manage my own account. **[2.0]**
121. As a user, I want to manage my SSH keys, so that I can use SSH and SFTP without passwords. **[2.0]**
122. As a user, I want to create access keys for myself with user-level permission sets when the admin allows the API, and see each secret only once, so that my scripts can use the API safely. **[2.0]**
123. As a user, I want to read and (when the admin's policy allows) delete my own logs and my login history, so that I can review my account's activity. **[2.0]**
124. As a user, I want monthly statistics of my bandwidth, disk use and object counts, so that I see how much of my plan I use. **[2.0]**

#### Web

125. As a user, I want to add, edit, suspend and delete web domains with aliases, redirects, custom document root, HTTP authentication and statistics, so that I run my sites without help. **[2.0]**
126. As a user, I want free Let's Encrypt certificates (including wildcard where DNS allows), HTTPS redirect and HSTS, or my own certificate, so that my sites are secure. **[2.0]**
127. As a user, I want a card picker of web templates with titles, descriptions, categories and a badge that says whether the needed runtime is installed, so that I choose a template without reading NGINX files. **[2.0]**
128. As a user, I want to set a template's variables (for example the backend port, filled from my port pool), so that proxy and app templates work without editing configs. **[2.0]**
129. As a user, I want templates for static sites, SPAs, Node.js (HTTP and WebSocket), Next.js, Nuxt, Strapi, Ghost, Python (Gunicorn, Django, Flask/FastAPI), Go, Rails, Laravel Octane and hardened WordPress, so that my stack is supported out of the box. **[2.0]**
130. As a user, I want to install common PHP applications on a domain with Quick Install, running in the background, so that I get a CMS without manual setup. **[2.0]**
131. As a user, I want to change the PHP version and caching of a domain, so that each site uses what it needs. **[2.0]**

#### DNS, mail, databases, cron

132. As a user, I want to manage DNS zones and records with templates and see the public DNSSEC keys, so that my domains resolve correctly. **[2.0]**
133. As a user, I want to manage mail domains and accounts with forwarders, autoreplies, quotas, catch-all, DKIM, antispam and antivirus, and see the DNS records I must publish, so that my mail works and is trusted. **[2.0]**
134. As a user, I want to create databases and database users and open phpMyAdmin with single sign-on, so that I manage data without another login. **[2.0]**
135. As a user, I want to create cron jobs with a schedule builder, so that I do not have to write cron syntax. **[2.0]**

#### Files and terminal

136. As a user, I want a file manager with tree and list views, multi-select, rename, copy, move, delete, permissions, search and keyboard shortcuts, so that I manage files without FTP. **[2.0]**
137. As a user, I want drag-and-drop and folder uploads with progress, zip downloads of selections and archive extraction, so that I move content quickly. **[2.0]**
138. As a user, I want a code editor with syntax highlighting and previews of images, PDFs and text, so that I edit configuration and code in the browser. **[2.0]**
139. As a user, I want every file operation to run as my own system user, so that the panel cannot write anything I could not write myself. **[2.0]**
140. As a user, I want a web terminal with my own shell, so that I can run commands without an SSH client. **[2.0]** (when my plan has a shell)

#### Backups

141. As a user, I want to create, download and restore my backups, selectively per web domain, mail domain, database, cron or user directory, so that I can recover from mistakes. **[2.0]**
142. As a user, I want to browse restic snapshots in the file manager and restore single files or folders, so that I recover one file without restoring everything. **[2.0]**
143. As a user, I want to exclude paths, my Node.js runtimes or individual Docker apps from backups, so that backups stay small. **[2.0]**

#### Node.js

144. As a user whose plan includes Node.js, I want to install Node.js versions (LTS or a specific version), choose a default and remove versions, so that each app gets the Node it needs. **[2.0]**
145. As a user whose plan includes Node.js, I want to install and remove global npm packages per Node version and use pnpm or yarn, so that my tools are available in the terminal. **[2.0]**
146. As a user whose plan includes Node.js, I want to create a Node app bound to a web domain with an entry file, a Node version and a port taken from my pool, so that my app is served on my domain with HTTPS. **[2.0]**
147. As a user, I want to start, stop, restart and reload my Node apps, edit their environment, switch their Node version and see CPU, memory, restarts, uptime and logs, so that I operate them without pm2 commands. **[2.0]**
148. As a user, I want my Node apps to come back after a server reboot, so that my sites stay up. **[2.0]**
149. As a user, I want to install dependencies and run the build of a Node app from the panel with visible output, so that I deploy without SSH. **[2.0]**

#### Docker apps

150. As a user whose plan includes Docker apps, I want a store of curated apps with search and categories, so that I can self-host tools like Uptime Kuma, n8n or Gitea. **[2.0]**
151. As a user, I want to install an app on one of my domains by filling a short form of variables, and get HTTPS automatically, so that the app is reachable securely. **[2.0]**
152. As a user, I want every container of my apps to run as my own system user inside my resource limits, so that my apps cannot affect other customers. **[2.0]**
153. As a user, I want to start, stop, restart, update (with a data snapshot and automatic rollback on failure) and remove my apps, see their status and logs and edit their settings, so that I run them without Docker commands. **[2.0]**
154. As a user, I want to open an app's data folder in the file manager, so that I can inspect or change its files. **[2.0]**

#### Ports

155. As a user, I want to see the ports assigned to me, reserve one for something I run over SSH, release it, and make it public when my admin allows it, so that I know what I may bind. **[2.0]**
156. As a user, I want to request more ports, with the request going to my owner (my reseller or the admin), so that I get capacity without an outside channel. **[2.0]**

#### Planned for users

157. As a user, I want to set PHP values (memory limit, upload size, execution time, input vars, error display, time zone) per domain inside my plan's maximums, so that I fix "upload too large" myself. **[Planned E19]**
158. As a user, I want push-to-deploy from Git for any web domain with atomic releases, build presets and one-click rollback, so that I deploy without FTP or SSH. **[Planned E20]**
159. As a user, I want one screen that lists my WordPress sites with pending updates and known vulnerable plugins, and bulk updates with a snapshot first, so that my sites stay patched. **[Planned E21]**
160. As a user, I want to clone a site to staging and push it back selectively after a snapshot, so that I test changes away from production. **[Planned E22]**
161. As a user, I want to search my access and error logs by time, status, IP and path and tail them live, so that I find the cause of an error without SSH. **[Planned E18]**
162. As a user, I want a mail deliverability page (SPF, DKIM, DMARC, PTR checks and message tracking for my domains), so that I find out why a message bounced. **[Planned E27]**
163. As a user, I want to connect my zones to Cloudflare and keep them in sync, so that I can use an external DNS provider with the panel's automation. **[Planned E28]**
164. As a user, I want to invite collaborators with access limited to selected modules and domains, read or write, with an expiry, so that I never share my main password. **[Planned E30]**
165. As a user, I want a Doctor check of my domains with plain-language explanations, so that I fix DNS, certificate and mail problems myself. **[Planned E17]**
166. As a user, I want alerts about my own objects (quota near limit, certificate expiring, failed backup), so that I act before my visitors notice. **[Planned E16]**

---

<!-- markdownlint-enable MD029 MD030 -->

## Implementation Decisions

### Architecture

- **The command line is the system of record.** Every state change goes through a `v-*` command run as root through sudo by the panel's unprivileged web user. The panel app never writes hosting data, configuration or files in a user's home itself; it reads `v-list-* … json` and calls commands. No business rule lives only in the web layer.
- **Hosting state stays in flat files** (one object per line, `KEY='value'`). Keys that inherited commands would overwrite are kept in sidecar files next to the user and package records (owner and reseller counters per user; reseller keys and port pool size per package; template variables per domain). A panel-owned SQLite database is reserved for operational data of the post-2.0 epics (jobs, alerts, audit events), never for hosting state.
- **Privilege boundary is fixed.** The web user may run the `v-*` commands and nothing else: no new sudoers lines, setuid helpers or sockets. Commands validate their own arguments and honour demo mode. Every user-facing file, Node and Docker operation runs as the hosting user.
- **Panel app** (Laravel, PHP 8.5): Blade with Alpine.js for CRUD pages; Vue islands for interactive screens (file manager, Node apps, Docker store). Controllers stay thin: validate → authorize → service → response. Services call commands through one wrapper (`ArcCommand`) that escapes arguments, enforces an allow-list, maps exit codes to errors and passes secrets through temporary files that are masked in logs.
- **Long operations** start a background task through a command; the page polls its status. The post-2.0 Job Center replaces polling with a queue and stored job logs.
- **Hard fork, one name** (ADR-0007): every internal name is ArcPanel's; 2.0 is a fresh install only. HestiaCP security advisories are read and ported by hand for each release.

### Roles and tenancy

- `ROLE` takes `admin`, `reseller`, `user` or `dns-cluster`. An owner is the root admin or a reseller owned by the root admin — **one level only**; resellers cannot own resellers. Admins have no owner. Users created by any admin are owned by the root admin.
- **Quota semantics (ADR-0004, soft nested quota):** a customer's own limits are enforced as for any user. A reseller's plan is enforced at **allocation time**: for each total limit (web domains, DNS domains, mail domains, mail accounts, databases, cron jobs, disk, bandwidth, ports), _reseller's own usage + sum of its customers' plan limits + the new plan's limit ≤ the reseller's limit_, and _customers + 1 ≤ maximum customers_. An `unlimited` reseller limit skips the key; a finite one refuses an `unlimited` plan. Runtime usage above the plan is reported, not enforced by suspension.
- **Reseller-created plans** are stored under a reseller-prefixed name, shown without the prefix, visible only to that reseller, and every limit is at most the reseller's own. Resellers cannot rename or copy plans.
- **Owner-aware commands** carry the reseller rules so the CLI enforces them too: add a customer under a reseller, change a customer's plan as its reseller, list a reseller's customers and assignable plans, list owners, change owner, check a reseller's quota (returns the first exceeded key), recompute reseller counters, and manage reseller plans and package tenancy keys.
- **Authorization model:** one capability matrix as data (`capability → role → scope`, scope = all, tree or self); a capability or role missing from the matrix is denied. Policy methods are three steps: capability lookup, owner-scope check, read-only check. Navigation, policies and the role tests read the same matrix. Templates, front-end code and the command wrapper contain no role or owner checks.
- **Owner scope** is computed once per request from the owners list (never cached across requests, never taken from client input). Objects outside the principal's scope answer **404**; refusals inside it answer **403**.
- **Impersonation:** admins impersonate any user; resellers impersonate their own customers with role `user` only, when the policy allows it. The reseller's impersonation is re-checked on every request and ends silently (with a notice) when the target leaves the tree. The web terminal keeps the impersonator's own shell and is hidden while a reseller impersonates.
- **Account lifecycle rules:** user → reseller only with a plan that allows customers; reseller → user and reseller deletion only with an empty tree; "suspend reseller and customers" is an admin bulk action; deleting or suspending an account releases or closes its ports through lifecycle hooks.
- **Policies in server settings:** resellers may create plans (default no); resellers may log in as customers (default yes); users may view and delete their logs; users may edit web and DNS templates; API access level (off, admin only, everyone).

### Port registry

- One registry of port entries per user (port, protocol, app, public flag, public-allowed flag, comment, firewall rule id, date) inside a server-wide range with reserved system ports.
- **Physical pool model:** every port belongs to exactly one account. A reseller's pool is its unassigned entries; sub-allocation moves entries to the customer. Ports of a deleted customer return to its reseller's pool.
- Node and Docker apps bind only ports allocated from the registry. Making a single port public writes a firewall rule linked to the entry; ranges cannot be public. Only admins grant public permission; resellers may withdraw it from customers.

### Platform features

- **Templates:** metadata sidecars per template (title, description, category, required runtime, variables, docs link); the picker shows whether the runtime is installed. Per-domain template variables are stored beside the domain and survive rebuilds.
- **Node.js:** runtimes unpacked from a signed shared cache into the user's home; one pm2 daemon per user under a systemd user unit, started only for users with apps; a periodic check converges apps and units; package keys switch Node on and limit versions.
- **Docker:** one engine managed by the admin; every service runs as the hosting user without extra capabilities inside the user's resource slice; curated catalog pinned by image digest; updates snapshot data and roll back automatically; database dumps before backups.
- **File manager:** every read and write runs as the user; uploads are staged and handed off by root; restic snapshots are browsed in a read-only mode with a restore action.
- **System packages:** a curated catalog from the distribution's archive, admin only; ArcPanel records what it installed so removal never takes packages it did not add.

### Post-2.0 decisions already fixed by the roadmap

- Long operations move to a Job Center (queue worker, stored logs, retry, per-role visibility) that the alerts, Doctor fixes, Git deploy, WordPress toolkit, staging, migrations and updates build on.
- The audit log is append-only with a daily hash chain; admins see everything, resellers their tree read-only, users their own log. Collaborators must not ship before the audit log.
- Third-party credentials (DNS provider tokens, deploy keys, webhook secrets, AI keys) are stored root-only, write-only in the UI and masked everywhere.
- Tree-scoped API keys for resellers wait for the Laravel API (E29); until then a reseller's key acts only on the reseller itself, so it has no escalation path.

---

## Testing Decisions

**What a good test is here.** A test drives the product through a public seam — a `v-*` command, an HTTP route, a rendered page, a user flow — and asserts what a role can observe: exit codes and resulting data files, HTTP status, rendered data, redirects and flash messages, and that no other tenant's username, domain or e-mail appears in a response. Tests do not assert private methods, the order of internal calls or markup details. Validation, test scope and coverage are never lowered to make a run pass.

### Seams, from highest to lowest

1. **Browser flows (Playwright)** against a real server built from the installer, with synthetic users seeded by the e2e preparation script. Covers the role flows end to end: a reseller onboards a customer through the wizard, logs in as it, works in its account and returns; the admin sees the customer in the tree; cross-tenant probes in UI and API return not found; plus one spec per module. Includes axe checks and Lighthouse accessibility of 90 or more in both themes. Prior art: the existing e2e specs for resellers, ports, web domains, Node apps, Docker apps and files.
2. **HTTP routes with a command fake (Pest feature).** Every route is exercised with four principals — admin, reseller A, reseller B and a user who is a customer of A — against fixture trees, asserting 200, 403 or 404 per the capability matrix. A test enumerates the route list and fails when a route has no capability entry. Recorded command outputs live as fixtures per command and case. Prior art: the existing reseller-scope, users, packages, ports and module feature tests.
3. **Commands (BATS)** on the WSL test server: arguments, exit codes, side effects on data and generated configuration, idempotency, lifecycle hooks, and that sidecars survive package changes, rebuilds and renames. Prior art: the tenancy, ports, node, node apps, docker, files, backups and system-packages suites.
4. **Units (Pest unit, Vitest):** the capability matrix (completeness and the reseller row), owner scope with fixture trees, each policy's matrix, impersonation resolution, quota display and change planners, navigation per role and while impersonating; Alpine modules and Vue islands as behaviour tests. Prior art: the authorization, principal resolver, policy and planner unit tests and the existing Vitest suites.
5. **HTTP smoke script** on the test server: sign-in for each role, a reseller creates a customer, logs in as it and returns.

**Rules for new work.** Every new route gets a capability entry and feature tests for the four principals, including a 404 for cross-tenant access. Every new command gets BATS cases and an allow-list entry. Every new page gets an e2e spec. A change counts as verified only when the suites it touches pass **and** the smoke or e2e flow that exercises it passes on the test server.

---

## Out of Scope

- Multi-level resellers (a reseller owning another reseller).
- Hard nested quota (suspending a reseller's tree automatically when usage exceeds its plan).
- Reseller White Label and custom panel domains per reseller (post-2.0).
- Billing: invoicing, payment, WHMCS or other billing modules inside ArcPanel. The integration path is the API and signed webhooks (E29).
- Multi-server orchestration, clusters, Kubernetes, Windows hosts and a mobile app.
- New mail, DNS or database engines beyond the inherited stack.
- First-class process managers for non-Node runtimes (Python, Go, Ruby apps run through templates and the user's own tooling); Node apps are the reference design for later.
- In-place upgrade from HestiaCP to ArcPanel 2.0 (2.0 is a fresh install; the planned migration wizard covers moving accounts).
- A separate single-page app with a JSON-only API.

## Further Notes

- **Known gaps in 2.0 that affect roles:** a restored customer is owned by the root admin until the admin re-assigns its owner; tree-scoped API keys for resellers are not available yet; publishing the 2.0 release waits for the release infrastructure (domain, APT repository, signing key) and for the fresh-install verification of the full rename.
- **Success measures** (from the roadmap's outcomes): a reseller onboards a customer end to end with no admin involvement; no cross-tenant data is visible through UI or API; every publicly reachable high port has an owner; a user deploys a Node app with HTTPS in under five minutes and it survives a reboot; a user installs a Docker app with HTTPS and removes it cleanly without SSH; Lighthouse accessibility of 90 or more on every page.
- **Glossary:** _principal_ — whoever is acting (signed-in account, impersonated account or API key); _effective user_ — the account whose objects are read or changed; _owner_ — the reseller that manages an account, or the root admin; _tree_ — a reseller and every account it owns; _package_ — a hosting plan.
- **Where details live:** tenancy and authorization design (03), port registry (04), security model (12), data model reference (13), database schema (15), and the per-epic specs under `docs/features/specs/`, whose implementation notes record what was actually built.
