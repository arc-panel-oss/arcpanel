<h1 align="center">
	<a href="https://arcpanel.top/">
		<picture>
			<source media="(prefers-color-scheme: dark)" srcset="docs/brand/logo-dark.svg">
			<img src="docs/brand/logo.svg" alt="ArcPanel" height="64">
		</picture>
	</a>
</h1>

<h2 align="center">Lightweight and powerful control panel for the modern web</h2>

<p align="center"><strong>Based on HestiaCP 1.10.5.</strong> Current release: ArcPanel 1.0.0 (in development).</p>

<p align="center">
	<a href="https://arcpanel.top/">arcpanel.top</a> |
	<a href="https://docs.arcpanel.top/">Documentation</a> |
	<a href="https://github.com/arc-panel-oss/arcpanel/releases">Releases</a> |
	<a href="CHANGELOG.md">Changelog</a>
	<br/><br/>
	<a href="https://github.com/arc-panel-oss/arcpanel/actions/workflows/lint.yml">
		<img src="https://github.com/arc-panel-oss/arcpanel/actions/workflows/lint.yml/badge.svg" alt="Lint Status"/>
	</a>
</p>

## What is ArcPanel?

ArcPanel is an open-source control panel for Linux web servers. It gives administrators an easy to use web and command line interface to deploy and manage web domains, mail accounts, DNS zones and databases from one central dashboard, without configuring each service by hand.

ArcPanel is based on [HestiaCP](https://github.com/hestiacp/hestiacp) 1.10.5. It keeps the HestiaCP internals (`/usr/local/hestia`, `/etc/hestiacp`, the `v-*` commands and the API), so scripts and integrations written for HestiaCP keep working. ArcPanel adds its own name, packages, APT repository and versioning, plus ArcPanel names for what administrators see on the server.

## Features and services

- Apache2 and NGINX with PHP-FPM
- Multiple PHP versions (5.6 - 8.5, 8.5 as default)
- DNS Server (Bind) with clustering capabilities
- POP/IMAP/SMTP mail services with Anti-Virus, Anti-Spam, and Webmail (ClamAV, SpamAssassin, Sieve, Roundcube)
- MariaDB/MySQL and/or PostgreSQL databases
- Let's Encrypt SSL support with wildcard certificates
- Firewall with brute-force attack detection and IP lists (iptables, fail2ban, and ipset)

## Supported platforms and operating systems

- **Debian:** 12, 13
- **Ubuntu:** 22.04 LTS, 24.04 LTS, 26.04 LTS
- **Architectures:** AMD64 / x86_64 and ARM64 / aarch64

**Notes:**

- ArcPanel does not support 32-bit operating systems.
- ArcPanel in combination with OpenVZ 7 or lower might have issues with DNS and/or firewall. If you use a Virtual Private Server, we strongly advise you to use one based on KVM or LXC.

## Installing ArcPanel

**Note:** You must install ArcPanel on top of a fresh operating system installation to ensure proper functionality. ArcPanel supports fresh installs only; upgrading an existing HestiaCP server in place is not supported.

While we have taken every effort to make the installation process and the control panel interface as friendly as possible (even for new users), it is assumed that you have some prior knowledge of how to set up a Linux server.

### Step 1: Log in

Log in to your server as **root** or as a user with `sudo` privileges, either directly on the console or remotely via SSH:

```bash
ssh root@your.server
```

### Step 2: Download

Download the installation script for the latest release:

```bash
wget https://arcpanel.top/install.sh
```

If the download fails due to an SSL validation error, make sure the `ca-certificates` package is installed:

```bash
apt-get update && apt-get install ca-certificates
```

### Step 3: Run

Run the script and follow the on-screen prompts:

```bash
sudo bash install.sh
```

The installer adds the ArcPanel APT repository (`apt.arcpanel.top`) and installs the `arcpanel`, `arcpanel-nginx` and `arcpanel-php` packages (and `arcpanel-web-terminal` if selected). After the installation you receive on-screen instructions and, if an email address was given, a welcome email with the login details.

### Custom installation

You can pass flags to the installer to choose which software is installed, or to run an unattended installation. To list the available options, run:

```bash
sudo bash install.sh -h
```

You can also use the [install command builder](https://docs.arcpanel.top/install.html) to generate the command. For offline installs or locally built packages, use `--with-debs` (see [Building packages](https://docs.arcpanel.top/docs/contributing/building.html)).

## Upgrading

Automatic updates are enabled by default and can be managed from **Server Settings > Updates**. To check for and install updates manually, use APT:

```bash
apt-get update
apt-get upgrade
```

## Commands and compatibility names

ArcPanel adds ArcPanel names next to the original ones. Both work:

| Original name | ArcPanel name |
| --- | --- |
| `/usr/local/hestia` | `/usr/local/arcpanel` |
| `$HESTIA` | `$ARCPANEL` |
| `v-update-sys-hestia`, `v-list-sys-hestia-updates`, … | `v-update-sys-arcpanel`, `v-list-sys-arcpanel-updates`, … |
| `systemctl restart hestia` | `systemctl restart arcpanel` |

## Issues and support

- Bugs and other reproducible issues: [open an issue](https://github.com/arc-panel-oss/arcpanel/issues) on GitHub.
- Security vulnerabilities: do not open a public issue; follow the [security policy](SECURITY.md).

Please describe the troubleshooting steps you have already taken and include as much information as possible. We cannot provide support for third-party applications (such as WordPress).

## Contributing

If you would like to contribute, please read the [contribution guidelines](CONTRIBUTING.md).

## Attribution

ArcPanel is based on [HestiaCP](https://github.com/hestiacp/hestiacp) (Hestia Control Panel), which is in turn based on [VestaCP](https://vestacp.com/). We thank the HestiaCP and VestaCP projects and all their contributors. Copyright notices of the original authors are kept in the source files.

ArcPanel is an independent project. It is not affiliated with or endorsed by the HestiaCP project. "Hestia Control Panel", "HestiaCP" and the Hestia logo belong to their respective owners and are used here only to describe where ArcPanel comes from.

## License

ArcPanel is licensed under the [GNU General Public License v3.0](LICENSE).
