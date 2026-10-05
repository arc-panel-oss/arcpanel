#!/bin/bash
# Test data of the Playwright suite (test/e2e). Run as root on a test server,
# never on production:
#   prepare.sh prepare PASSWORD   creates arce2eadmin (admin) and arce2euser,
#                                 their web domains, and prints a self-signed
#                                 certificate as JSON
#   prepare.sh cleanup            deletes them again
set -euo pipefail

HESTIA="${HESTIA:-/usr/local/hestia}"
B="$HESTIA/bin"
USERS="arce2eadmin arce2euser"
STATE=/root/.arcpanel-e2e-debug-mode

forget_config_cache() {
	runuser -u hestiaweb -- "$HESTIA/php/bin/php" "$HESTIA/web-app/artisan" cache:clear > /dev/null
}

if [ "${1:-}" = cleanup ]; then
	for user in $USERS; do
		"$B/v-delete-user" "$user" > /dev/null 2>&1 || true
	done
	if [ -f "$STATE" ]; then
		"$B/v-change-sys-config-value" DEBUG_MODE "$(cat "$STATE")"
		rm -f "$STATE"
	fi
	forget_config_cache
	exit 0
fi

password="${2:?password}"
for user in $USERS; do
	if ! "$B/v-list-user" "$user" > /dev/null 2>&1; then
		"$B/v-add-user" "$user" "$password" "$user@example.invalid" default > /dev/null
	fi
	file="$(mktemp)"
	printf '%s\n' "$password" > "$file"
	"$B/v-change-user-password" "$user" "$file" > /dev/null
	rm -f "$file"
	for domain in $("$B/v-list-web-domains" "$user" plain | awk '{print $1}'); do
		"$B/v-delete-web-domain" "$user" "$domain" no > /dev/null
	done
done
"$B/v-change-user-role" arce2eadmin admin > /dev/null
"$B/v-add-web-domain" arce2euser e2e-site.example > /dev/null
"$B/v-add-web-domain" arce2euser e2e-bulk.example > /dev/null

# The style guide is part of the accessibility run.
if [ ! -f "$STATE" ]; then
	grep -o "^DEBUG_MODE='[a-z]*'" "$HESTIA/conf/hestia.conf" | cut -d"'" -f2 > "$STATE" || echo false > "$STATE"
fi
"$B/v-change-sys-config-value" DEBUG_MODE true
"$B/v-change-sys-config-value" ARCPANEL_APP_LOGIN yes
"$B/v-change-sys-config-value" ARCPANEL_APP_PAGES yes
forget_config_cache

dir="$(mktemp -d)"
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=e2e-site.example" \
	-keyout "$dir/key.pem" -out "$dir/crt.pem" > /dev/null 2>&1
"$HESTIA/php/bin/php" -r 'echo json_encode(["certificate" => file_get_contents($argv[1]), "key" => file_get_contents($argv[2])]);' \
	"$dir/crt.pem" "$dir/key.pem"
rm -rf "$dir"
