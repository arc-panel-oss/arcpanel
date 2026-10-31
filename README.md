# ArcPanel

**Lightweight and powerful control panel for the modern web.**

ArcPanel is an open-source control panel for Linux web servers. From one dashboard and one command line it manages web domains, DNS zones, mail, databases, cron jobs, backups, firewall and SSL on a single host, and it adds what a modern hosting stack needs: resellers, a port registry, per-user Node.js and Docker apps, long-running app processes, a PHP manager, template metadata, a first-party file manager and REST API v2.

ArcPanel is based on [HestiaCP](https://github.com/hestiacp/hestiacp) 1.10.5 and, since 2.0, a hard fork of it ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)). It keeps the architecture (the `v-*` commands, the REST API, the flat-file data model) under ArcPanel names: `/usr/local/arcpanel`, `/etc/arcpanel/arcpanel.conf`, `arcpanelweb`, `v-*-arcpanel-*`. Scripts and integrations written for HestiaCP need these names, and API clients read the `ArcPanel-Exit-Code` header.

| Item      | Value                                                                                                                                                                                                                       |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Releases  | Stable line 2.2 from the signed APT repository `apt.arcpanel.org`; see the [CHANGELOG](CHANGELOG.md) and [GitHub Releases](https://github.com/arc-panel-oss/arcpanel/releases)                                              |
| Next      | 2.3 (firewall on nftables with IPv6); the plan of every line is in [releases.md](https://github.com/arc-panel-oss/docs/blob/main/releases.md) and the [roadmap](https://github.com/arc-panel-oss/docs/blob/main/roadmap.md) |
| Platforms | Debian 12 / 13, Ubuntu 22.04 / 24.04 / 26.04 LTS, amd64 only (arm64 is not supported for now; no 32-bit)                                                                                                                    |
| Website   | [arcpanel.org](https://arcpanel.org): install, documentation, support plans                                                                                                                                                 |
| Questions | [GitHub Discussions](https://github.com/arc-panel-oss/arcpanel/discussions)                                                                                                                                                 |
| License   | [GPL-3.0-or-later](LICENSE), no paid edition                                                                                                                                                                                |

---

## Features

### Hosting stack

From the HestiaCP baseline; see [current features](https://github.com/arc-panel-oss/docs/blob/main/current-features.md).

- NGINX and Apache2 with PHP-FPM, multiple PHP versions (5.6 to 8.5)
- BIND DNS with clustering, Let's Encrypt with wildcard certificates
- Exim, Dovecot, ClamAV, SpamAssassin, Sieve, Roundcube and SnappyMail webmail
- MariaDB / MySQL and PostgreSQL, phpMyAdmin and phpPgAdmin
- vsftpd / ProFTPD, SFTP jail, web terminal
- Backups (local, remote, incremental with restic), migrations from cPanel and DirectAdmin
- iptables, Fail2Ban and ipset, brute-force protection
- REST API v1 and 750+ `v-*` commands

### What ArcPanel adds

| Area                              | Status (2.2)                                                                                                                                                                 | Where to read                                                                                                                                                                                                                                                                                                                         |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full rename and packaging         | Done in 2.0: every internal name is ArcPanel's; packages from a signed APT repository, automatic updates that stay on the installed line                                     | [ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)                                                                                                                                                                                                                                     |
| Laravel panel app at the root URL | Every page of the panel (E7); since 2.0 (E8) at the root URL, the HestiaCP web interface removed and old URLs redirected                                                     | [02 Laravel foundation](https://github.com/arc-panel-oss/docs/blob/main/design/02-laravel-foundation.md), [10 Page migration](https://github.com/arc-panel-oss/docs/blob/main/design/10-page-migration-and-retirement.md)                                                                                                             |
| Reseller role                     | Users, packages, shared quotas, login-as                                                                                                                                     | [03 Tenancy](https://github.com/arc-panel-oss/docs/blob/main/design/03-tenancy-and-authorization.md), [E11 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-003-e11-reseller-design.md)                                                                                                                |
| Port registry                     | Allocation, sub-allocation, public ports, firewall link                                                                                                                      | [04 Port registry](https://github.com/arc-panel-oss/docs/blob/main/design/04-port-registry.md), [E12 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-004-e12-port-registry-design.md)                                                                                                                 |
| Design system and new shell       | Component library, role-aware shell, command palette, notification tray                                                                                                      | [05](https://github.com/arc-panel-oss/docs/blob/main/design/05-design-system-and-frontend.md), [E1-S2 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-002-e1-s2-dashboard-web-domains-design.md)                                                                                                      |
| File Manager v2                   | Every file operation run as the user, uploads, code editor, restic snapshot browsing; FileGator removed (2.0)                                                                | [06](https://github.com/arc-panel-oss/docs/blob/main/design/06-file-manager-v2.md), [E3 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-008-e3-file-manager-design.md)                                                                                                                                |
| Node.js runtimes and apps         | Per-user runtimes and global packages, apps run by a per-user pm2 daemon                                                                                                     | [07](https://github.com/arc-panel-oss/docs/blob/main/design/07-node-platform.md), [E13 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-006-e13-node-manager-design.md), [E14 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-007-e14-node-apps-design.md)             |
| App processes                     | Laravel Octane, queue workers, Gunicorn, Uvicorn, Puma, Go binaries and custom commands, each as a service of the hosting user                                               | [E42 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-16-003-e42-app-processes-design.md)                                                                                                                                                                                                                 |
| Docker apps and system packages   | A curated catalog of Docker apps run as the hosting user, with updates and rollback; system packages from the distribution archive                                           | [08](https://github.com/arc-panel-oss/docs/blob/main/design/08-app-store.md), [E4 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-009-e4-docker-apps-design.md), [E5 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-06-006-e5-e6b2-system-packages-templates-design.md) |
| Web templates with metadata       | Sixteen new templates (Node.js frameworks, Python, Go, Ruby, Laravel Octane, hardened WordPress), per-domain variables, template picker                                      | [09](https://github.com/arc-panel-oss/docs/blob/main/design/09-web-templates-metadata.md), [E6 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-05-005-e6-templates-batch1-design.md)                                                                                                                     |
| PHP Manager                       | PHP versions, extensions, php.ini and PHP-FPM profiles                                                                                                                       | [E41 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-12-001-e41-php-manager-design.md)                                                                                                                                                                                                                   |
| Malware scan                      | Scan of the PHP files of web domains, with quarantine                                                                                                                        | [E25 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-12-002-e25-malware-scan-design.md)                                                                                                                                                                                                                  |
| Webmail and database tools        | Roundcube, SnappyMail, phpMyAdmin and phpPgAdmin on their own ports, with webmail sign-on                                                                                    | [E46 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md)                                                                                                                                                                                                        |
| REST API v2                       | Owner-scoped endpoints for what the panel does, scoped access keys, dry runs, a generated OpenAPI document in `docs/api/v2/`                                                 | [REST API guide](https://github.com/arc-panel-oss/docs/blob/main/api/README.md), [E34 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-07-002-e34-api-v2-design.md)                                                                                                                                       |
| Security remediation              | The web UI never runs as root; root never follows user paths; pinned SHA-256 for every download; a `sudo` allow-list for the panel user; owner scoping tested on every route | [E39 W3 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md), [E39 W4 spec](https://github.com/arc-panel-oss/docs/blob/main/features/specs/2026-10-16-002-e39-w4-authorization-and-tenancy-design.md)                                                                   |
| Coming next                       | Firewall on nftables with IPv6 (2.3), object storage and an S3 backup host (2.4), Update Center (2.5), backup destinations and schedules, audit log (2.6)                    | [releases.md](https://github.com/arc-panel-oss/docs/blob/main/releases.md), [roadmap](https://github.com/arc-panel-oss/docs/blob/main/roadmap.md)                                                                                                                                                                                     |

---

## Architecture at a glance

```text
Browser ── HTTPS :8083 ──► arcpanel-nginx ──► arcpanel-php (FPM, user arcpanelweb)
                                              ├── web-app/   Laravel 13 panel app (/)        ─┐
                                              └── lib/api/   REST API script (/api/)         ─┤
                                                                                               │ sudo
                                                                 bin/v-* commands (root) ◄─────┘
                                                                 func/*.sh libraries
                                                                 flat files under /usr/local/arcpanel/data
                                                                 managed services: nginx, apache2, php-fpm,
                                                                 bind, exim, dovecot, mariadb, postgresql, iptables
```

- **The CLI is the API.** The panel app and the REST API call `v-*` commands through `sudo`; the commands validate arguments, write the flat files and configure the services. The panel never writes hosting state itself.
- **Strangler migration.** In 1.x the Laravel app and the legacy app shared one port and one session, and pages moved to Laravel one module at a time; 2.0 removed the legacy app.
- **Owner model.** Admin, reseller and user roles with a one-level ownership tree; every page and command is scoped by owner.
- **Hard fork.** No upstream merges since 2.0; HestiaCP security fixes are read and ported by hand ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)).

Full description: [01 Architecture overview](https://github.com/arc-panel-oss/docs/blob/main/design/01-architecture-overview.md). Data model: [13 Data model reference](https://github.com/arc-panel-oss/docs/blob/main/design/13-data-model-reference.md) and [15 Database schema and ERD](https://github.com/arc-panel-oss/docs/blob/main/design/15-database-schema-and-erd.md).

---

## Installing ArcPanel on a server

ArcPanel installs on a **fresh** operating system only. Upgrading an existing HestiaCP server in place is not supported. Basic Linux server administration knowledge is assumed.

1. Log in as `root` (or a sudo user) on the server.
2. Download the installer:

   ```bash
   wget https://arcpanel.org/install.sh
   ```

   If the download fails with an SSL error, install `ca-certificates` first (`apt-get update && apt-get install ca-certificates`).

3. Run it and follow the prompts:

   ```bash
   sudo bash install.sh
   ```

   The installer adds the ArcPanel APT repository and installs the `arcpanel`, `arcpanel-nginx` and `arcpanel-php` packages (plus `arcpanel-web-terminal` when selected). The panel answers on `https://<server>:8083` when it finishes; the login details are printed and, if an e-mail address was given, mailed.

To choose components or run unattended, pass flags (`sudo bash install.sh -h` lists them): for example `--apache no --phpfpm yes --multiphp yes --named yes --mariadb yes --postgresql no --exim yes --dovecot yes --clamav no --spamassassin no --iptables yes --fail2ban yes --webterminal yes --port 8083 --hostname panel.example.com --email admin@example.com --interactive no`. Locally built packages install with `--with-debs <dir>`.

**Upgrading:** automatic updates are on by default (Server settings → Updates). Manually: `apt-get update && apt-get upgrade`. Upgrade steps of a release live in `install/upgrade/versions/<version>.sh` and run from the package.

**Names.** Since 2.0 every internal name is ArcPanel's; the HestiaCP names and the 1.x aliases are gone (see the [CHANGELOG](CHANGELOG.md)).

---

## Repository layout

```text
bin/            v-* commands (upstream + ArcPanel additions): the system's API
func/           shell libraries; ArcPanel adds tenancy.sh, ports.sh, hooks.sh, node.sh, nodeapp.sh, fs.sh, docker.sh, …
install/        installers, default packages, templates, API permission sets, upgrade steps
src/deb/        Debian packaging of the panel stack (arcpanel, arcpanel-nginx, arcpanel-php, web terminal)
lib/            libraries of the commands: Quick Install, 2FA, mail wrapper, API script, locales, logos (moved from web/ in 2.0)
web-app/        Laravel 13 panel app: ArcCommand, auth bridge, policies, services, Blade + Alpine, Vue islands, Pest and Vitest tests
test/           BATS suites, smoke script, Playwright e2e, dev-sync.sh (deploy a checkout to a test server)
docs/api/       generated REST API documents (v1, v2) and their Swagger UI; every other document is in arc-panel-oss/docs
build.js        esbuild + Lightning CSS build of the panel app assets
```

---

## Developer setup

### Requirements

| Tool           | Version                                                                                       | Used for                                              |
| -------------- | --------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| Node.js + npm  | Node LTS, npm 11 (`packageManager` in `package.json`)                                         | asset build, lint, Vitest, Playwright                 |
| PHP + Composer | PHP 8.5, Composer 2                                                                           | the panel app (`web-app/`), Pest, Pint                |
| A test server  | Debian 12 / Ubuntu 24.04 with ArcPanel installed (VM, LXC, WSL or a Docker systemd container) | BATS, smoke test, e2e                                 |
| Git            | any recent                                                                                    | BATS helpers are git submodules (`test/test_helper/`) |

### First checkout

```bash
git clone --recurse-submodules https://github.com/arc-panel-oss/arcpanel.git
cd arcpanel
npm ci        # tooling + pre-commit hooks (Prettier, Biome, markdownlint, brand check)
npm run build # frontend assets (npm run dev = watch mode)
cd web-app && composer install && cd ..
```

### Lint, format, unit tests (no server needed)

```bash
npm run lint             # Prettier --check, Biome, markdownlint
bash test/shellcheck.sh  # every shell script, severity error
bash test/brand-check.sh # user-visible text must say ArcPanel
npm run test:js          # Vitest: Alpine modules and Vue islands of the panel app
cd web-app && vendor/bin/pint --test && vendor/bin/pest
```

### Running against a test server

Install ArcPanel on a disposable server (see above), then deploy the checkout to it with the sync script, which copies the tree with root ownership and package modes, installs Composer dependencies, builds the panel caches and restarts the panel:

```bash
sudo bash test/dev-sync.sh # on the server; --no-composer / --no-restart to skip steps
```

On the server, as root:

```bash
bats test/arcpanel-*.bats                     # every ArcPanel suite (or one, e.g. test/arcpanel-ports.bats)
bash test/arcpanel-login-smoke.sh             # HTTP smoke test: shared session, web domains, resellers
bash test/e2e/prepare.sh prepare '<password>' # seed users for the browser suite
```

From the workstation:

```bash
E2E_BASE_URL='https://<server>:8083' npm run test:e2e # Playwright + axe + Lighthouse accessibility
```

The scripts create and delete their own users (`arce2e*`, `arcsmoke*`); never run them against a production server. Windows users with a WSL test distribution can run the sync step with `wsl.exe -d <distro> -u root -- bash /mnt/<drive>/.../test/dev-sync.sh`; see [AGENTS.md](AGENTS.md) §8 for the known pitfalls.

### Building packages

`src/arcpanel-build.sh --arcpanel --noinstall --keepbuild '~localsrc'` builds the `arcpanel` package from the working tree (the CI workflow `pr-docker-bats.yml` does the same inside a systemd container and installs it with `--with-debs`). `--all` also builds `arcpanel-nginx` and `arcpanel-php`.

---

## Documentation

The documentation lives in its own repository, [arc-panel-oss/docs](https://github.com/arc-panel-oss/docs). Only the generated REST API documents stay here, in `docs/api/`, so that each release carries the documents that match its code.

| Document                                                                                        | Content                                                                                                                                   |
| ----------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| [docs/roadmap.md](https://github.com/arc-panel-oss/docs/blob/main/roadmap.md)                   | Roadmap: epics, priorities and status; [releases.md](https://github.com/arc-panel-oss/docs/blob/main/releases.md) lists the release lines |
| [docs/current-features.md](https://github.com/arc-panel-oss/docs/blob/main/current-features.md) | What the HestiaCP 1.10.5 baseline does today                                                                                              |
| [docs/design/](https://github.com/arc-panel-oss/docs/blob/main/design/README.md)                | System design, one document per epic, plus the data model reference and the ERD                                                           |
| [docs/features/specs/](https://github.com/arc-panel-oss/docs/tree/main/features/specs)          | Approved specs of implemented slices with their implementation notes                                                                      |
| [docs/adr/](https://github.com/arc-panel-oss/docs/blob/main/adr/README.md)                      | Architecture decision records                                                                                                             |
| [REST API](https://github.com/arc-panel-oss/docs/blob/main/api/README.md)                       | The two REST APIs; the generated OpenAPI documents are in `docs/api/` of this repository                                                  |
| [AGENTS.md](AGENTS.md), [CLAUDE.md](CLAUDE.md)                                                  | Rules for AI coding agents working in this repository                                                                                     |
| [SECURITY.md](SECURITY.md)                                                                      | How to report a vulnerability                                                                                                             |

---

## Contributing

- Features go to `develop`; fixes go to the oldest supported release line `vX.Y.x` and are merged forward. `master` follows the newest stable release ([releasing runbook](https://github.com/arc-panel-oss/docs/blob/main/operations/releasing.md)).
- ArcPanel owns every file: keep the conventions of the inherited code, use ArcPanel names only (`test/brand-check.sh`), and keep the original copyright headers ([ADR-0007](https://github.com/arc-panel-oss/docs/blob/main/adr/0007-full-rename-and-hard-fork.md)).
- Every change comes with tests at the right layer (BATS for commands, Pest for the panel app, Vitest for Alpine modules, Playwright for flows) and with the documentation updates the design calls for.
- Commit messages follow Conventional Commits with the epic as scope, for example `feat(e12): add the Ports pages`.
- Questions and ideas: [GitHub Discussions](https://github.com/arc-panel-oss/arcpanel/discussions). Bugs: [GitHub issues](https://github.com/arc-panel-oss/arcpanel/issues); first-time contributors start with [good first issues](https://github.com/arc-panel-oss/arcpanel/labels/good%20first%20issue). Vulnerabilities: see [SECURITY.md](SECURITY.md), never a public issue.

## Attribution

ArcPanel is based on [HestiaCP](https://github.com/hestiacp/hestiacp), which is in turn based on [VestaCP](https://vestacp.com/). We thank both projects and their contributors; the original copyright notices are kept in the source files. ArcPanel is an independent project, not affiliated with or endorsed by the HestiaCP project. "Hestia Control Panel", "HestiaCP" and the Hestia logo belong to their respective owners and are used here only to describe where ArcPanel comes from.

## License

ArcPanel is licensed under the [GNU General Public License v3.0 or later](LICENSE).
