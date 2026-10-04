# Building packages

::: info
For building `arcpanel-nginx` or `arcpanel-php`, at least 2 GB of memory is required!
:::

Here is more detailed information about the build scripts that are run from `src`:

## Installing ArcPanel from a branch

The following is useful for testing a Pull Request or a branch on a fork.

1. Install Node.js [Download](https://nodejs.org/en/download) or use [Node Source APT](https://github.com/nodesource/distributions)

```bash
# Replace with https://github.com/username/arcpanel.git if you want to test a branch that you created yourself
git clone https://github.com/arc-panel-oss/arcpanel.git
cd ./arcpanel/

# Replace master with the branch you want to test
git checkout master

cd ./src/

# Compile packages
./hst_autocompile.sh --all --noinstall --keepbuild '~localsrc'

cd ../install

bash hst-install-{os}.sh --with-debs /tmp/hestiacp-src/deb/
```

`hst-install-{os}.sh` is the script behind the public `install.sh` download; `{os}` is `debian` or `ubuntu`. Any option can be appended to the installer command. [See the complete list](../introduction/getting-started#list-of-installation-options).

## Build packages only

```bash
# Only the arcpanel package
./hst_autocompile.sh --hestia --noinstall --keepbuild '~localsrc'
```

```bash
# arcpanel + arcpanel-nginx and arcpanel-php
./hst_autocompile.sh --all --noinstall --keepbuild '~localsrc'
```

## Build and install packages

::: info
Use if you have ArcPanel already installed, for your changes to take effect.
:::

```bash
# Only the arcpanel package
./hst_autocompile.sh --hestia --install '~localsrc'
```

```bash
# arcpanel + arcpanel-nginx and arcpanel-php
./hst_autocompile.sh --all --install '~localsrc'
```

## Updating ArcPanel from GitHub

The following is useful for pulling the latest staging/beta changes from GitHub and compiling the changes.

::: info
The following method only supports building the `arcpanel` package. If you need to build `arcpanel-nginx` or `arcpanel-php`, use one of the previous commands.
:::

1. Install Node.js [Download](https://nodejs.org/en/download) or use [Node Source APT](https://github.com/nodesource/distributions)

```bash
v-update-sys-hestia-git [USERNAME] [BRANCH]
# or the ArcPanel alias of the same command
v-update-sys-arcpanel-git [USERNAME] [BRANCH]
```

**Note:** Sometimes dependencies will get added or removed when the packages are installed with `dpkg`. It is not possible to preload the dependencies. If this happens, you will see an error like this:

```bash
dpkg: error processing package arcpanel (–install):
dependency problems - leaving unconfigured
```

To solve this issue, run:

```bash
apt install -f
```

## Building for other architectures or OS releases on the same machine

`hst_autocompile.sh` only ever builds for the environment it's actually running in (its own `--cross` flag just makes the architecture-independent `arcpanel` package build for both AMD64 and ARM64 directly, with no emulation needed). To also build `arcpanel-nginx`, `arcpanel-php` or `arcpanel-web-terminal` (which contain compiled native code) for **other** architectures or OS releases on the same machine, use `chroot_build_all.sh` instead — it spins up and runs the unmodified `hst_autocompile.sh` inside each one.

```bash
./chroot_build_all.sh --all '~localsrc'
```

Every combination is built inside a QEMU-emulated chroot (debootstrap + `qemu-user-static`). The first run downloads/bootstraps a minimal root filesystem per combination under `/var/lib/hestiacp-build-chroot/<distro>-<release>-<arch>`; subsequent runs reuse it, so only the first build of a given combination is slow.
