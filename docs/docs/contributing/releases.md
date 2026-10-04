# Releases and package signing

This page is for maintainers. It explains how an ArcPanel release reaches the APT repository and how the signing key is managed.

## Versions

ArcPanel tracks two versions (see [ADR-0006](https://github.com/arc-panel-oss/arcpanel/blob/master/docs/adr/0006-rebranding-depth.md)):

| Key in `hestia.conf` | Meaning | Source |
| --- | --- | --- |
| `ARCPANEL_VERSION` | ArcPanel version shown to users | `Version:` in `src/deb/arcpanel/control` |
| `VERSION` | HestiaCP base version, used by the upstream upgrade scripts | `install/upstream.conf` |

ArcPanel upgrade steps go in `install/upgrade/arcpanel/<version>.sh`. Upstream steps stay in `install/upgrade/versions/`.

## Cutting a release

1. Set the same version in `src/deb/arcpanel/control` (`Version:`) and in `ARCPANEL_INSTALL_VER` in both `install/hst-install-debian.sh` and `install/hst-install-ubuntu.sh`.
2. Use `~beta1`, `~rc1` or `~alpha1` for a pre-release, for example `1.0.1~beta1`.
3. Add the release notes to `CHANGELOG.md`.
4. Push a tag that matches the package version, with `~` written as `-`:

   | Package version | Tag | Repository |
   | --- | --- | --- |
   | `1.0.0` | `v1.0.0` | `https://apt.arcpanel.top/` (stable) |
   | `1.0.1~beta1` | `v1.0.1-beta1` | `https://apt.arcpanel.top/beta/` |

5. The `Release` workflow checks the versions, builds all packages for every supported release on `amd64` and `arm64`, signs the repository and publishes it.
6. For a stable release, move the `release` branch to the tag. The installer compares its own version with `src/deb/arcpanel/control` on that branch.

The public installer URL `https://arcpanel.top/install.sh` must serve `install/hst-install.sh` from the latest stable tag.

## Signing key

### Create the key (once, offline)

1. On an offline machine, create a primary key that only certifies, and a signing subkey:

   ```bash
   gpg --quick-generate-key "ArcPanel Release Signing <info@arcpanel.top>" ed25519 cert 5y
   gpg --quick-add-key <PRIMARY_FINGERPRINT> ed25519 sign 2y
   ```

2. Back up the primary key and a revocation certificate to two offline media.
3. Export only the signing subkey for CI:

   ```bash
   gpg --armor --export-secret-subkeys <SUBKEY_FINGERPRINT>! > arcpanel-signing-subkey.asc
   ```

4. Store it in the repository secrets. Never commit it or paste it into an issue or a log.

### Repository secrets

| Secret | Content |
| --- | --- |
| `APT_SIGNING_KEY` | The exported signing subkey (ASCII armored) |
| `APT_SIGNING_KEY_ID` | Fingerprint of the signing subkey |
| `APT_DEPLOY_SSH_KEY` | Private SSH key that can write to the APT host |
| `APT_DEPLOY_KNOWN_HOSTS` | `known_hosts` line(s) for the APT host |
| `APT_DEPLOY_TARGET` | `user@host:/path/to/webroot`, without a trailing slash |

Keep the secrets in the `release` environment and require a reviewer for it.

### Rotate the signing subkey

1. On the offline machine, add a new signing subkey and export it as above.
2. Replace `APT_SIGNING_KEY` and `APT_SIGNING_KEY_ID`.
3. Publish a release. The workflow exports the public key to `https://apt.arcpanel.top/arcpanel.gpg`.
4. Tell administrators to refresh the key on existing servers:

   ```bash
   curl -s https://apt.arcpanel.top/arcpanel.gpg | gpg --dearmor | sudo tee /usr/share/keyrings/arcpanel-keyring.gpg > /dev/null
   ```

### If the key leaks

1. Remove the secrets from the repository immediately.
2. Revoke the leaked subkey with the primary key and publish the updated public key.
3. Create a new signing subkey, then publish a new release signed with it.
4. Publish a security advisory with the key refresh command above.

An `arcpanel-keyring` package that updates the key automatically is planned for later.
