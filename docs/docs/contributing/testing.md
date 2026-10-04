# Beta and release candidate testing

::: tip
Beta releases and release candidates are announced on the [GitHub releases page](https://github.com/arc-panel-oss/arcpanel/releases).
:::

ArcPanel publishes its packages (`arcpanel`, `arcpanel-nginx`, `arcpanel-php` and `arcpanel-web-terminal`) in its own APT repository at `apt.arcpanel.top`. The repository has two suites: `stable` for regular releases and `beta` for betas and release candidates. Tags named `v*-beta.*` are published to the `beta` suite.

## Using beta packages

::: danger
Betas and release candidates might still contain bugs and can possibly break your server. We cannot guarantee it will be fixed directly! Please be careful when testing on servers in production or containing important data!
:::

The installer writes the ArcPanel package source to `/etc/apt/sources.list.d/arcpanel.list` and the signing key to `/usr/share/keyrings/arcpanel-keyring.gpg`. Step-by-step instructions for switching a server to the `beta` suite, and back to `stable`, will be published here together with the first beta release.

To test unreleased code without the APT repository, build the packages yourself and install them with `--with-debs`, as described in [Building packages](./building).

## Reporting bugs

If you encounter a bug, please [open an issue](https://github.com/arc-panel-oss/arcpanel/issues/new/choose) or [submit a Pull Request](https://github.com/arc-panel-oss/arcpanel/pulls).
