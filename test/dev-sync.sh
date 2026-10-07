#!/bin/bash
# Development only (not packaged): copy this checkout into an installed
# ArcPanel test server, then prepare the panel app and restart the panel.
#
# Run inside the test server as root, e.g. from Windows:
#   wsl.exe -d arcpanel-test -u root -- bash /mnt/d/project/arcpanel/arcpanel/test/dev-sync.sh
#
# Options:
#   --no-composer   skip `composer install` (vendor/ unchanged)
#   --no-restart    do not restart the arcpanel service

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
	echo "dev-sync: run as root" >&2
	exit 1
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
composer_step=yes
restart_step=yes
for arg in "$@"; do
	case "$arg" in
		--no-composer) composer_step=no ;;
		--no-restart) restart_step=no ;;
		*)
			echo "dev-sync: unknown option $arg" >&2
			exit 1
			;;
	esac
done

echo "dev-sync: $SRC -> $ARCPANEL"

CHANGED=$(mktemp)
trap 'rm -f "$CHANGED"' EXIT

# Copy without the owner, group and modes of the source: a Windows checkout
# (/mnt/<drive>) reports uid 1000, which is arcpanelweb on a test server, and
# arcpanelweb must never own files that root runs. Changed paths are recorded so
# CRLF line endings of a Windows checkout can be removed afterwards.
sync_tree() {
	local dest="${*: -1}"
	rsync -rlt --no-owner --no-group --chmod=D755,F644 --out-format="${dest}%n" "$@" >> "$CHANGED"
}

copy_file() {
	install -o root -g root -m "$1" "$2" "$3"
	echo "$3" >> "$CHANGED"
}

# Panel app: mirror the source, keep runtime state and installed dependencies.
mkdir -p "$ARCPANEL/web-app"
sync_tree --delete \
	--exclude '/vendor/' \
	--exclude '/storage/' \
	--exclude '/bootstrap/cache/*.php' \
	--exclude '/lang/*.json' \
	--exclude '/config/arc-commands.php' \
	--exclude '/.env' \
	--exclude '/.phpunit.cache/' \
	"$SRC/web-app/" "$ARCPANEL/web-app/"
sync_tree --ignore-existing "$SRC/web-app/storage/" "$ARCPANEL/web-app/storage/"

# Libraries of the commands (E8): mirror the source, keep the vendor
# directories that Composer installs on the server.
mkdir -p "$ARCPANEL/lib"
sync_tree --delete --exclude '/vendor/' --exclude '/quickinstall/vendor/' "$SRC/lib/" "$ARCPANEL/lib/"
# CLI and installer files: overwrite, never delete.
sync_tree "$SRC/bin/" "$ARCPANEL/bin/"
sync_tree "$SRC/func/" "$ARCPANEL/func/"
sync_tree "$SRC/install/" "$ARCPANEL/install/"
# The Docker app catalog (E4) mirrors the checkout: removed apps go away
mkdir -p "$ARCPANEL/install/common/apps"
sync_tree --delete "$SRC/install/common/apps/" "$ARCPANEL/install/common/apps/"
chmod 755 "$ARCPANEL"/bin/* "$ARCPANEL/web-app/artisan" "$ARCPANEL/lib/mail-wrapper.php"
find "$ARCPANEL/install" -name '*.sh' -exec chmod 755 {} +
chmod 644 "$ARCPANEL"/install/*.sh

# Packaged configuration
copy_file 0644 "$SRC/src/deb/nginx/nginx.conf" "$ARCPANEL/nginx/conf/nginx.conf"
copy_file 0644 "$SRC/src/deb/php/php.ini" "$ARCPANEL/php/lib/php.ini"
if [ -d "$ARCPANEL/web-terminal" ]; then
	copy_file 0644 "$SRC/src/deb/web-terminal/server.js" "$ARCPANEL/web-terminal/server.js"
	copy_file 0755 "$SRC/src/deb/web-terminal/web-terminal-session-auth.php" \
		"$ARCPANEL/web-terminal/web-terminal-session-auth.php"
fi

# Line endings: the repository stores LF only
while IFS= read -r file; do
	if [ -f "$file" ] && grep -qI $'\r' "$file"; then
		sed -i 's/\r$//' "$file"
	fi
done < "$CHANGED"

if [ "$composer_step" = yes ]; then
	export COMPOSER_ALLOW_SUPERUSER=1
	# Dev dependencies are kept so the test suite can run on the server.
	composer install --working-dir="$ARCPANEL/web-app" --no-interaction --no-progress --quiet
fi
for dir in "$ARCPANEL/lib" "$ARCPANEL/lib/quickinstall"; do
	if [ "$composer_step" = yes ] || [ ! -d "$dir/vendor" ]; then
		COMPOSER_ALLOW_SUPERUSER=1 composer install --working-dir="$dir" --no-dev --no-interaction --no-progress --quiet
	fi
done

"$ARCPANEL/bin/v-add-sys-arcpanel"
"$ARCPANEL/bin/v-add-sys-tenancy"
"$ARCPANEL/bin/v-add-sys-port-registry"
"$ARCPANEL/bin/v-add-sys-node"
"$ARCPANEL/bin/v-add-sys-web-templates-meta"
"$ARCPANEL/bin/v-add-sys-panel-files"
if grep -q "^DOCKER_SYSTEM='yes'" "$ARCPANEL/conf/arcpanel.conf"; then
	"$ARCPANEL/bin/v-add-sys-docker"
fi

if [ "$restart_step" = yes ]; then
	"$ARCPANEL/nginx/sbin/arcpanel-nginx" -t -q
	systemctl restart arcpanel
	if systemctl list-unit-files arcpanel-web-terminal.service > /dev/null 2>&1; then
		systemctl try-restart arcpanel-web-terminal.service || true
	fi
fi

echo "dev-sync: done"
