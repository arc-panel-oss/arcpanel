# ArcPanel

**Lightweight and powerful control panel for the modern web.**

ArcPanel is an open-source control panel for Linux web servers. From one dashboard and one command line it manages web domains, DNS zones, mail, databases, cron jobs, backups, firewall and SSL on a single host, and it adds what a modern hosting stack needs: resellers, a port registry, per-user Node.js and Docker apps, template metadata and a first-party file manager.

ArcPanel is based on [HestiaCP](https://github.com/hestiacp/hestiacp) 1.10.5 and keeps its internals (`/usr/local/hestia`, the `v-*` commands, the REST API, the flat-file data model), so scripts and integrations written for HestiaCP keep working.

| Item            | Value                                                                                        |
| --------------- | -------------------------------------------------------------------------------------------- |
| Current release | ArcPanel 1.0.0 (in development), upstream base HestiaCP 1.10.5                               |
| Target          | ArcPanel 2.0: the whole web UI on Laravel, legacy pages retired ([roadmap](docs/roadmap.md)) |
| Platforms       | Debian 12 / 13, Ubuntu 22.04 / 24.04 / 26.04 LTS, amd64 and arm64 (no 32-bit)                |
| License         | [GPL-3.0-or-later](LICENSE)                                                                  |

---

## Features

### Hosting stack

From the HestiaCP baseline; see [current features](docs/current-features.md).

- NGINX and Apache2 with PHP-FPM, multiple PHP versions (5.6 to 8.5)
- BIND DNS with clustering, Let's Encrypt with wildcard certificates
- Exim, Dovecot, ClamAV, SpamAssassin, Sieve, Roundcube and SnappyMail webmail
- MariaDB / MySQL and PostgreSQL, phpMyAdmin and phpPgAdmin
- vsftpd / ProFTPD, SFTP jail, web terminal
- Backups (local, remote, incremental with restic), migrations from cPanel and DirectAdmin
- iptables, Fail2Ban and ipset, brute-force protection
- REST API and 500+ `v-*` commands

### What ArcPanel adds

| Area                                             | Status (2026-10-06)                                                                                                                                   | Where to read                                                                                                                                                                            |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Rebranding and packaging                         | Implemented; release infrastructure open                                                                                                              | [ADR-0006](docs/adr/0006-rebranding-depth.md)                                                                                                                                            |
| Laravel panel app at the root URL                | Implemented: every page of the panel (E7); since 2.0 (E8) at the root URL, the HestiaCP web interface removed and old URLs redirected for one release | [02 Laravel foundation](docs/design/02-laravel-foundation.md), [10 Page migration](docs/design/10-page-migration-and-retirement.md)                                                      |
| Reseller role                                    | Implemented: Users, Packages, quotas, login-as                                                                                                        | [03 Tenancy](docs/design/03-tenancy-and-authorization.md), [E11 spec](docs/features/specs/2026-10-05-003-e11-reseller-design.md)                                                         |
| Port registry                                    | Implemented: allocation, sub-allocation, public ports, firewall link                                                                                  | [04 Port registry](docs/design/04-port-registry.md), [E12 spec](docs/features/specs/2026-10-05-004-e12-port-registry-design.md)                                                          |
| Design system and new shell                      | Implemented: component library, role-aware shell, command palette, notification tray                                                                  | [05](docs/design/05-design-system-and-frontend.md), [E1-S2 spec](docs/features/specs/2026-10-05-002-e1-s2-dashboard-web-domains-design.md)                                               |
| File Manager v2                                  | Implemented: every file operation run as the user, uploads, code editor, restic snapshot browsing; FileGator removed (2.0)                            | [06](docs/design/06-file-manager-v2.md), [E3 spec](docs/features/specs/2026-10-05-008-e3-file-manager-design.md)                                                                         |
| Node.js runtimes and apps                        | Implemented: per-user runtimes and global packages, apps run by a per-user pm2 daemon                                                                 | [07](docs/design/07-node-platform.md), [E13 spec](docs/features/specs/2026-10-05-006-e13-node-manager-design.md), [E14 spec](docs/features/specs/2026-10-05-007-e14-node-apps-design.md) |
| App Store (Docker, apt)                          | Docker apps implemented: curated catalog, containers run as the hosting user, updates with rollback. apt/snap system apps designed                    | [08](docs/design/08-app-store.md), [E4 spec](docs/features/specs/2026-10-05-009-e4-docker-apps-design.md)                                                                                |
| Web templates with metadata                      | Batch 1 implemented: metadata, per-domain variables, ten new templates, template picker                                                               | [09](docs/design/09-web-templates-metadata.md), [E6 spec](docs/features/specs/2026-10-05-005-e6-templates-batch1-design.md)                                                              |
| Job Center, alerts, audit, WAF, Update Center, … | Sketched (post-2.0)                                                                                                                                   | [14 Later backlog](docs/design/14-later-backlog.md)                                                                                                                                      |

---

## Architecture at a glance

```text
Browser ── HTTPS :8083 ──► hestia-nginx ──► hestia-php (FPM, user hestiaweb)
                                              ├── web-app/   Laravel 13 panel app (/)        ─┐
                                              └── lib/api/   REST API script (/api/)         ─┤
                                                                                               │ sudo
                                                                 bin/v-* commands (root) ◄─────┘
                                                                 func/*.sh libraries
                                                                 flat files under /usr/local/hestia/data
                                                                 managed services: nginx, apache2, php-fpm,
                                                                 bind, exim, dovecot, mariadb, postgresql, iptables
```

- **The CLI is the API.** The panel app and the REST API call `v-*` commands through `sudo`; the commands validate arguments, write the flat files and configure the services. The panel never writes hosting state itself.
- **Strangler migration.** In 1.x the Laravel app and the legacy app shared one port and one session, and pages moved to Laravel one module at a time; 2.0 removed the legacy app.
- **Owner model.** Admin, reseller and user roles with a one-level ownership tree; every page and command is scoped by owner.
- **Additive changes to upstream.** New commands, libraries, keys and files only, so HestiaCP releases keep merging.

Full description: [01 Architecture overview](docs/design/01-architecture-overview.md). Data model: [13 Data model reference](docs/design/13-data-model-reference.md) and [15 Database schema and ERD](docs/design/15-database-schema-and-erd.md).

---

## Installing ArcPanel on a server

ArcPanel installs on a **fresh** operating system only. Upgrading an existing HestiaCP server in place is not supported. Basic Linux server administration knowledge is assumed.

1. Log in as `root` (or a sudo user) on the server.
2. Download the installer:

   ```bash
   wget https://arcpanel.top/install.sh
   ```

   If the download fails with an SSL error, install `ca-certificates` first (`apt-get update && apt-get install ca-certificates`).

3. Run it and follow the prompts:

   ```bash
   sudo bash install.sh
   ```

   The installer adds the ArcPanel APT repository and installs the `arcpanel`, `arcpanel-nginx` and `arcpanel-php` packages (plus `arcpanel-web-terminal` when selected). The panel answers on `https://<server>:8083` when it finishes; the login details are printed and, if an e-mail address was given, mailed.

To choose components or run unattended, pass flags (`sudo bash install.sh -h` lists them): for example `--apache no --phpfpm yes --multiphp yes --named yes --mariadb yes --postgresql no --exim yes --dovecot yes --clamav no --spamassassin no --iptables yes --fail2ban yes --webterminal yes --port 8083 --hostname panel.example.com --email admin@example.com --interactive no`. Locally built packages install with `--with-debs <dir>`.

**Upgrading:** automatic updates are on by default (Server settings → Updates). Manually: `apt-get update && apt-get upgrade`. Upgrade steps for ArcPanel features live in `install/upgrade/arcpanel/<version>.sh` and run from the package.

**Compatibility names.** ArcPanel names exist next to the original ones and both work: `/usr/local/arcpanel` → `/usr/local/hestia`, `$ARCPANEL` → `$HESTIA`, `v-update-sys-arcpanel` → `v-update-sys-hestia`, `systemctl restart arcpanel` → `systemctl restart hestia`.

---

## Repository layout

```text
bin/            v-* commands (upstream + ArcPanel additions): the system's API
func/           shell libraries; ArcPanel adds tenancy.sh, ports.sh, hooks.sh, node.sh, nodeapp.sh, fs.sh, docker.sh, …
install/        installers, default packages, templates, API permission sets, upgrade steps
src/deb/        Debian packaging of the panel stack (hestia, hestia-nginx, hestia-php, web terminal)
lib/            libraries of the commands: Quick Install, 2FA, mail wrapper, API script, locales, logos (moved from web/ in 2.0)
web-app/        Laravel 13 panel app: ArcCommand, auth bridge, policies, services, Blade + Alpine, Vue islands, Pest and Vitest tests
test/           BATS suites, smoke script, Playwright e2e, dev-sync.sh (deploy a checkout to a test server)
docs/           roadmap, current features, design documents, specs, ADRs, schemas
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

`src/hst_autocompile.sh --hestia --noinstall --keepbuild '~localsrc'` builds the `arcpanel` package from the working tree (the CI workflow `pr-docker-bats.yml` does the same inside a systemd container and installs it with `--with-debs`). `--all` also builds `arcpanel-nginx` and `arcpanel-php`.

---

## Documentation

| Document                                             | Content                                                                             |
| ---------------------------------------------------- | ----------------------------------------------------------------------------------- |
| [docs/roadmap.md](docs/roadmap.md)                   | 12-month roadmap: epics E1–E14 and the post-2.0 backlog E15–E33, priorities, status |
| [docs/current-features.md](docs/current-features.md) | What the HestiaCP 1.10.5 baseline does today                                        |
| [docs/design/](docs/design/README.md)                | System design, one document per epic, plus the data model reference and the ERD     |
| [docs/features/specs/](docs/features/specs/)         | Approved specs of implemented slices with their implementation notes                |
| [docs/adr/](docs/adr/README.md)                      | Architecture decision records                                                       |
| [AGENTS.md](AGENTS.md), [CLAUDE.md](CLAUDE.md)       | Rules for AI coding agents working in this repository                               |
| [SECURITY.md](SECURITY.md)                           | How to report a vulnerability                                                       |

---

## Contributing

- Development happens on the `develop` branch; `master` holds releases (`vX.Y.Z`, betas as `vX.Y.Z-beta.N`).
- Keep upstream merges cheap: do not rename HestiaCP internals, add new `v-*` commands and libraries instead of editing existing ones, and keep the original copyright headers ([ADR-0006](docs/adr/0006-rebranding-depth.md)).
- Every change comes with tests at the right layer (BATS for commands, Pest for the panel app, Vitest for Alpine modules, Playwright for flows) and with the documentation updates the design calls for.
- Commit messages follow Conventional Commits with the epic as scope, for example `feat(e12): add the Ports pages`.
- Bugs and feature requests: [GitHub issues](https://github.com/arc-panel-oss/arcpanel/issues). Vulnerabilities: see [SECURITY.md](SECURITY.md), never a public issue.

## Attribution

ArcPanel is based on [HestiaCP](https://github.com/hestiacp/hestiacp), which is in turn based on [VestaCP](https://vestacp.com/). We thank both projects and their contributors; the original copyright notices are kept in the source files. ArcPanel is an independent project, not affiliated with or endorsed by the HestiaCP project. "Hestia Control Panel", "HestiaCP" and the Hestia logo belong to their respective owners and are used here only to describe where ArcPanel comes from.

## License

ArcPanel is licensed under the [GNU General Public License v3.0 or later](LICENSE).
