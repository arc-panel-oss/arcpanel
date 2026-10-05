#!/bin/bash
# Test data of the Playwright suite (test/e2e). Run as root on a test server,
# never on production:
#   prepare.sh prepare PASSWORD   creates arce2eadmin (admin), arce2euser, the
#                                 reseller arce2ereseller with its customer
#                                 arce2ecustomer, their web domains, and prints
#                                 a self-signed certificate as JSON; the
#                                 users start without ports (E12)
#   prepare.sh cleanup            deletes them again
#   prepare.sh ports RANGE        allocates RANGE to arce2euser (E6)
#   prepare.sh listen PORT        serves "arce2e-app-PORT" on 127.0.0.1:PORT
#   prepare.sh http DOMAIN        prints the HTTP status, then the start of
#                                 the body, of arce2euser's DOMAIN
set -euo pipefail

HESTIA="${HESTIA:-/usr/local/hestia}"
B="$HESTIA/bin"
USERS="arce2eadmin arce2euser arce2ereseller arce2ecustomer"
PLAN=arce2e-plan
STATE=/root/.arcpanel-e2e-debug-mode

forget_config_cache() {
	runuser -u hestiaweb -- "$HESTIA/php/bin/php" "$HESTIA/web-app/artisan" cache:clear > /dev/null
}

delete_users() {
	# Customers first: a reseller is deleted after its tree
	for user in $("$B/v-list-users" list | grep '^arce2e'); do
		if [ "$user" != arce2ereseller ]; then
			"$B/v-delete-user" "$user" > /dev/null 2>&1 || true
		fi
	done
	"$B/v-delete-user" arce2ereseller > /dev/null 2>&1 || true
	"$B/v-delete-user-package" "$PLAN" > /dev/null 2>&1 || true
	"$B/v-delete-package-tenancy" "$PLAN" > /dev/null 2>&1 || true
}

stop_listeners() {
	pkill -f 'python3 -m http.server 399' > /dev/null 2>&1 || true
	rm -rf /tmp/arce2e-listen-*
}

# Local address of arce2euser's DOMAIN: web.conf holds the NAT address when
# the IP has one
domain_ip() {
	local ip
	ip=$(grep "^DOMAIN='$1' " "$HESTIA/data/users/arce2euser/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
	if [ -f "$HESTIA/data/ips/$ip" ]; then
		echo "$ip"
	else
		basename "$(grep -l "^NAT='$ip'" "$HESTIA"/data/ips/* | head -n1)"
	fi
}

case "${1:-}" in
	ports)
		"$B/v-add-user-port" arce2euser "${2:?range}" > /dev/null
		exit 0
		;;
	listen)
		port="${2:?port}"
		mkdir -p "/tmp/arce2e-listen-$port"
		echo "arce2e-app-$port" > "/tmp/arce2e-listen-$port/index.html"
		cd "/tmp/arce2e-listen-$port"
		setsid nohup python3 -m http.server "$port" --bind 127.0.0.1 > /dev/null 2>&1 < /dev/null &
		for _ in $(seq 1 25); do
			if ss -Hltn "sport = :$port" | grep -q .; then
				exit 0
			fi
			sleep 0.2
		done
		exit 1
		;;
	http)
		domain="${2:?domain}"
		body="$(mktemp)"
		curl -s --noproxy '*' --max-time 10 -o "$body" -w '%{http_code}\n' \
			--resolve "$domain:80:$(domain_ip "$domain")" "http://$domain/" || echo 000
		head -c 200 "$body"
		rm -f "$body"
		exit 0
		;;
esac

if [ "${1:-}" = cleanup ]; then
	stop_listeners
	delete_users
	if [ -f "$STATE" ]; then
		"$B/v-change-sys-config-value" DEBUG_MODE "$(cat "$STATE")"
		rm -f "$STATE"
	fi
	forget_config_cache
	exit 0
fi

password="${2:?password}"
# Customers created by an earlier run of the onboarding spec
for user in $("$B/v-list-users" list | grep '^arce2en'); do
	"$B/v-delete-user" "$user" > /dev/null 2>&1 || true
done
if ! "$B/v-list-user-package" "$PLAN" > /dev/null 2>&1; then
	"$B/v-copy-user-package" default "$PLAN" > /dev/null
fi
"$B/v-change-package-tenancy" "$PLAN" USERS 10
"$B/v-change-package-tenancy" "$PLAN" RESELLER_PACKAGES default
# Port registry (E12): the reseller tree may hold 30 ports; every run starts
# without ports, the ports spec allocates them through the UI.
"$B/v-change-package-tenancy" "$PLAN" PORTS 30
for user in $USERS; do
	if ! "$B/v-list-user" "$user" > /dev/null 2>&1; then
		package=default
		if [ "$user" = arce2ereseller ]; then package="$PLAN"; fi
		"$B/v-add-user" "$user" "$password" "$user@example.invalid" "$package" > /dev/null
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
"$B/v-change-user-role" arce2ereseller reseller > /dev/null
"$B/v-change-user-owner" arce2ecustomer arce2ereseller > /dev/null
"$B/v-change-user-name" arce2ereseller 'E2E Reseller' > /dev/null
for user in arce2ecustomer arce2euser arce2ereseller; do
	"$B/v-delete-user-ports" "$user" > /dev/null
done
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
