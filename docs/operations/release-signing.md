# Release signing and the APT repository

Runbook for the ArcPanel APT repository at `https://apt.arcpanel.top/`: the one-time setup of the host, the signing key and the GitHub secrets, the release checklist, and what to do when a key is lost or compromised. The design is in [ADR-0006](../adr/0006-rebranding-depth.md) (decision 3) and the [rebranding spec](../features/specs/2026-10-04-001-arcpanel-rebranding-design.md) (WP3).

## How a release reaches the repository

Pushing a tag `v*` runs `.github/workflows/release.yml`:

1. **Check version.** The tag must equal `Version` in `src/deb/arcpanel/control` with `~` written as `-`, and `ARCPANEL_INSTALL_VER` in both installers must equal that version. A version with `~` is a pre-release.
2. **Build.** `src/arcpanel-build.sh --all` runs in Debian 12, Debian 13, Ubuntu 22.04, Ubuntu 24.04 and Ubuntu 26.04 containers, on `amd64` and `arm64`. The packages are grouped by distribution codename.
3. **Sign and publish.** In the protected `release` environment, `.github/scripts/build-apt-repo.sh` builds a fresh `reprepro` repository (component `main`, one distribution per codename), signs it with the release subkey, writes the public key to `arcpanel.gpg`, and `rsync` uploads it over SSH.

| `control` version | Tag            | Channel | URL                              |
| ----------------- | -------------- | ------- | -------------------------------- |
| `2.0.0`           | `v2.0.0`       | stable  | `https://apt.arcpanel.top/`      |
| `2.0.1~beta1`     | `v2.0.1-beta1` | beta    | `https://apt.arcpanel.top/beta/` |

The installer checks that `https://apt.arcpanel.top/` (or `/beta/` for a pre-release) answers, downloads the key from `https://apt.arcpanel.top/arcpanel.gpg` into `/usr/share/keyrings/arcpanel-keyring.gpg`, and writes `/etc/apt/sources.list.d/arcpanel.list`.

Each publish **replaces** the channel: `rsync --delete` removes the packages of the previous release. A stable publish keeps `/beta/`; a beta publish touches only `/beta/`.

## One-time setup

### 1. The APT host

Any small Debian or Ubuntu server with a public IPv4 address works; the repository is static files.

1. Point an `A` record (and `AAAA` if the host has IPv6) for `apt.arcpanel.top` at the host.
2. Create a deploy user that owns only the web root:

   ```bash
   adduser --disabled-password --gecos "" aptdeploy
   install -d -o aptdeploy -g aptdeploy -m 755 /var/www/apt
   ```

3. Serve the web root with nginx over HTTPS. The installer requests the channel root (`/` and `/beta/`) and stops unless it gets `200`, and `reprepro` writes no index page, so turn on `autoindex`:

   ```nginx
   server {
   	listen      80;
   	listen      [::]:80;
   	server_name apt.arcpanel.top;
   	root        /var/www/apt;

   	location / {
   		autoindex on;
   	}

   	location ~ /\. {
   		deny all;
   	}
   }
   ```

4. Get a certificate with `certbot --nginx -d apt.arcpanel.top`, which adds the `443` server and the redirect.

### 2. The signing key

The key has an offline **primary key** (certify only) and a **signing subkey**. Only the subkey goes to GitHub; the primary key never leaves the offline machine. Do this on a machine that is not used for daily work (a live USB or an air-gapped laptop), with a strong passphrase:

```bash
gpg --quick-generate-key "ArcPanel Release Signing Key" rsa4096 cert never
```

```bash
gpg --quick-add-key sign never < PRIMARY_FPR > rsa4096
```

```bash
gpg --list-keys --with-subkey-fingerprints <PRIMARY_FPR>
```

The second fingerprint printed is the subkey's (`<SUBKEY_FPR>`). Keys and subkey use `never` as the expiry on purpose: installed servers download the public key once and have no way to refresh it yet (see [Known gaps](#known-gaps)), so an expiry date would break `apt-get update` on every server when it passes.

Back up, to two offline media kept apart:

- the full secret key: `gpg --armor --export-secret-keys <PRIMARY_FPR> > arcpanel-primary-secret.asc`;
- the revocation certificate gpg wrote to `~/.gnupg/openpgp-revocs.d/<PRIMARY_FPR>.rev`.

CI signs without a passphrase prompt, so the subkey exported for GitHub carries no passphrase. Remove it in a temporary keyring, never in the offline one:

```bash
gpg --armor --export-secret-subkeys '<SUBKEY_FPR>!' > subkey-protected.asc
```

```bash
export GNUPGHOME="$(mktemp -d)"
```

```bash
gpg --import subkey-protected.asc
```

```bash
gpg --edit-key < PRIMARY_FPR > passwd
```

Enter the current passphrase, leave the new one empty, confirm, then type `save`. Export the unprotected subkey and check that the primary key shows as `sec#` (secret part absent):

```bash
gpg --armor --export-secret-subkeys '<SUBKEY_FPR>!' > subkey.asc
```

```bash
gpg --list-secret-keys
```

After the secret is stored in GitHub (next step), delete `subkey.asc`, `subkey-protected.asc` and the temporary `GNUPGHOME` with `shred -u` and `rm -rf`.

### 3. The deploy SSH key

Generate a key used only by the release workflow:

```bash
ssh-keygen -t ed25519 -N "" -C "arcpanel-release" -f apt_deploy
```

On the APT host, add `apt_deploy.pub` to `/home/aptdeploy/.ssh/authorized_keys` with the `restrict` option, which turns off forwarding and terminals but still allows `rsync`:

```text
restrict ssh-ed25519 AAAA... arcpanel-release
```

Record the host key for `known_hosts`, and compare its fingerprint with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` run on the host:

```bash
ssh-keyscan -t ed25519 apt.arcpanel.top > apt_known_hosts
```

```bash
ssh-keygen -lf apt_known_hosts
```

### 4. GitHub environment and secrets

1. In the repository settings, create the environment `release`. Add the maintainers as required reviewers and limit deployments to tags matching `v*`.
2. Store the five secrets **in that environment**, not at repository level, so that only the publish job can read them. Feed values from files or stdin, never as command-line arguments:

   ```bash
   gh secret set APT_SIGNING_KEY -R arc-panel-oss/arcpanel --env release < subkey.asc
   ```

   ```bash
   printf '%s' '<SUBKEY_FPR>' | gh secret set APT_SIGNING_KEY_ID -R arc-panel-oss/arcpanel --env release
   ```

   ```bash
   gh secret set APT_DEPLOY_SSH_KEY -R arc-panel-oss/arcpanel --env release < apt_deploy
   ```

   ```bash
   gh secret set APT_DEPLOY_KNOWN_HOSTS -R arc-panel-oss/arcpanel --env release < apt_known_hosts
   ```

   ```bash
   printf '%s' 'aptdeploy@apt.arcpanel.top:/var/www/apt' | gh secret set APT_DEPLOY_TARGET -R arc-panel-oss/arcpanel --env release
   ```

| Secret                   | Value                                                          |
| ------------------------ | -------------------------------------------------------------- |
| `APT_SIGNING_KEY`        | ASCII-armored signing subkey without passphrase (`subkey.asc`) |
| `APT_SIGNING_KEY_ID`     | Fingerprint of the signing subkey                              |
| `APT_DEPLOY_SSH_KEY`     | Private key `apt_deploy`                                       |
| `APT_DEPLOY_KNOWN_HOSTS` | Host key line from `ssh-keyscan`                               |
| `APT_DEPLOY_TARGET`      | `user@host:/path/to/webroot`, no trailing slash                |

Then delete `apt_deploy` from the workstation; the public half stays on the host.

### 5. Public key at the repository root

The installer always downloads the key from the root (`https://apt.arcpanel.top/arcpanel.gpg`), but a beta publish writes it only to `/beta/arcpanel.gpg`. If the first release is a beta, copy the public key to the root once:

```bash
gpg --armor --export <PRIMARY_FPR> > arcpanel.gpg
```

```bash
scp arcpanel.gpg aptdeploy@apt.arcpanel.top:/var/www/apt/arcpanel.gpg
```

The first stable publish overwrites it with the same key.

## Release checklist

1. `develop` is green in CI, and the release scope in [the roadmap](../roadmap.md) is done or explicitly deferred.
2. Set the version in `src/deb/arcpanel/control` and `ARCPANEL_INSTALL_VER` in `install/arcpanel-install-debian.sh` and `install/arcpanel-install-ubuntu.sh`; add upgrade steps under `install/upgrade/versions/<version>.sh` if any; add the `CHANGELOG.md` section.
3. Merge `develop` into `master`, then tag `master` with `v<version>` (`~` written as `-`).
4. A PM or PL pushes `master` and the tag (`git push origin master` and `git push origin v<version>`).
5. Watch the `Release` run; approve the `release` environment when the build jobs are green.
6. For a stable release, move the `release` branch to the tag. The bootstrap installer `install/arcpanel-install.sh` downloads the per-OS installers from that branch, and they compare their version with `src/deb/arcpanel/control` on it.
7. Verify from a clean machine:

   ```bash
   curl -fsS https://apt.arcpanel.top/dists/noble/InRelease | head -5
   ```

   ```bash
   curl -fsS https://apt.arcpanel.top/arcpanel.gpg | gpg --show-keys
   ```

   Then run the installer on a fresh VM of each supported OS without `--with-debs`, and check that `apt-get update` reports no signature warning.

## Rolling back

There is no previous release in the repository to fall back to, because each publish replaces the channel. Roll back by releasing a higher version with the fix or the revert. Re-running the workflow of an older tag republishes the older packages, but servers that already upgraded keep the newer ones, since `apt` does not downgrade.

## Lost or compromised keys

**Deploy SSH key leaked:** remove its line from `authorized_keys` on the host, generate a new key (setup step 3) and replace `APT_DEPLOY_SSH_KEY`. Check the web root against the last published release.

**Signing subkey leaked:** anyone holding it can sign packages that every ArcPanel server trusts.

1. On the offline machine, revoke the subkey (`gpg --edit-key <PRIMARY_FPR>`, `key N`, `revkey`, `save`) and add a new signing subkey.
2. Export the new subkey as in setup step 2 and replace `APT_SIGNING_KEY` and `APT_SIGNING_KEY_ID`.
3. Release a new version, which republishes the repository and `arcpanel.gpg` with the new subkey and the revocation.
4. Publish a security advisory telling administrators to refresh the key (installed servers do not do it on their own):

   ```bash
   curl -fsS https://apt.arcpanel.top/arcpanel.gpg | gpg --dearmor > /usr/share/keyrings/arcpanel-keyring.gpg
   ```

**Primary key lost:** the existing subkey keeps signing until it is compromised; without the primary key no subkey can be revoked or added. Plan a new key and a coordinated key change on every server.

**Primary key compromised:** revoke it with the revocation certificate, create a new key, and announce the change: every installed server must import the new public key by hand.

## Known gaps

- Installed servers fetch the public key once, at install time. There is no keyring package or upgrade step that refreshes it, so a key rotation or an expiry date needs manual action on every server. An `arcpanel-archive-keyring` package or an upgrade step that refreshes the keyring would close this gap.
- The installer downloads the key with `curl -s` and does not check the HTTP status, so a missing `arcpanel.gpg` produces an empty keyring and a signature error at `apt-get update` instead of a clear message.
- Each publish removes earlier versions, so `v-rollback-sys-arcpanel` (backlog) cannot take previous packages from the repository.
