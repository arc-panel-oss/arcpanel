# ArcPanel Security policy

Welcome and thanks for taking interest in the security of ArcPanel!

We are mostly interested in reports by actual ArcPanel users, but all high quality contributions are welcome.

## Reporting a vulnerability

If you believe you have discovered a vulnerability in ArcPanel, please report it privately through a [GitHub Security Advisory](https://github.com/arc-panel-oss/arcpanel/security/advisories/new) on the `arc-panel-oss/arcpanel` repository.

Please include a detailed description of the vulnerability, a list of services involved (e.g. exim, dovecot) and the versions which you've tested (the ArcPanel version and, if known, the upstream base version shown on the About page), full steps to reproduce the vulnerability, and your findings and expected results.

Please do not open any public issue on GitHub or post on any other channel or social media before the advisory has been published and a fix has been released.

ArcPanel is based on HestiaCP. If the vulnerability also affects HestiaCP, we will coordinate with the upstream project before publishing. You may also report it to the upstream project directly through its own security policy.

With that, good luck hacking us ;)

## Supported versions

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |

## Qualifying Vulnerabilities

### Vulnerabilities we really care about

- Remote command execution
- Code/SQL Injection
- Authentication bypass
- Privilege Escalation
- Cross-site scripting (XSS)
- Performing limited admin actions without authorization
- CSRF
- Compromise of the release process: package signing, the APT repository at `apt.arcpanel.org` or the installer at `arcpanel.org/install.sh`

### Vulnerabilities we accept

- Open redirects
- Password brute-forcing that circumvents rate limiting

## Non-Qualifying Vulnerabilities

- Theoretical attacks without proof of exploitability
- Attacks that are the result of a third party library should be reported to the library maintainers
- Social engineering
- Reflected file download
- Physical attacks
- Weak SSL/TLS/SSH algorithms or protocols
- Attacks involving physical access to a user’s device, or involving a device or network that’s already seriously compromised (eg man-in-the-middle).
- The user attacks themselves
- anything in `/test/` folder
