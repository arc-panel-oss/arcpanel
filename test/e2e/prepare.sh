#!/bin/bash
# Test data of the Playwright suite (test/e2e). Run as root on a test server,
# never on production:
#   prepare.sh prepare PASSWORD   creates arce2eadmin (admin), arce2euser, the
#                                 reseller arce2ereseller with its customer
#                                 arce2ecustomer, their web domains, and prints
#                                 a self-signed certificate as JSON; the
#                                 users start without ports (E12);
#                                 arce2edev on the developer package starts
#                                 without Node.js (E13)
#   prepare.sh cleanup            deletes them again
#   prepare.sh ports RANGE        allocates RANGE to arce2euser (E6)
#   prepare.sh listen PORT        serves "arce2e-app-PORT" on 127.0.0.1:PORT
#   prepare.sh http DOMAIN        prints the HTTP status, then the start of
#                                 the body, of arce2euser's DOMAIN
set -euo pipefail

HESTIA="${HESTIA:-/usr/local/hestia}"
B="$HESTIA/bin"
USERS="arce2eadmin arce2euser arce2ereseller arce2ecustomer arce2edev"
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

# Local address of DOMAIN of USER (default arce2euser): web.conf holds the
# NAT address when the IP has one
domain_ip() {
	local ip
	ip=$(grep "^DOMAIN='$1' " "$HESTIA/data/users/${2:-arce2euser}/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
	if [ -f "$HESTIA/data/ips/$ip" ]; then
		echo "$ip"
	else
		basename "$(grep -l "^NAT='$ip'" "$HESTIA"/data/ips/* | head -n1)"
	fi
}

# Node.js apps (E14): arce2edev gets two web domains with HTTPS (self-signed)
# and the code of an Express app and a Next.js app; the spec creates the
# apps, installs the dependencies and builds through the panel.
NODEAPP_DOMAINS="e2e-api.example e2e-next.example"

nodeapps_reset() {
	if ! "$B/v-list-user" arce2edev > /dev/null 2>&1; then
		return 0
	fi
	for domain in $("$B/v-list-node-apps" arce2edev json | jq -r '.APPS[].DOMAIN'); do
		"$B/v-delete-node-app" arce2edev "$domain" > /dev/null 2>&1 || true
	done
	for domain in $NODEAPP_DOMAINS; do
		"$B/v-delete-web-domain" arce2edev "$domain" no > /dev/null 2>&1 || true
	done
	"$B/v-delete-user-nvm" arce2edev > /dev/null 2>&1 || true
	rm -f "$HESTIA/data/users/arce2edev/node-task.conf" "$HESTIA/data/users/arce2edev/node-task.log"
}

# Write FILE (relative to the domain's public_html) as arce2edev
dev_file() {
	local path="/home/arce2edev/web/$1/public_html/$2"
	runuser -u arce2edev -- mkdir -p "$(dirname "$path")"
	runuser -u arce2edev -- tee "$path" > /dev/null
}

nodeapps_prepare() {
	local dir domain
	"$B/v-add-user-nvm" arce2edev > /dev/null
	"$B/v-add-user-node-version" arce2edev lts > /dev/null
	dir="$(mktemp -d)"
	for domain in $NODEAPP_DOMAINS; do
		"$B/v-add-web-domain" arce2edev "$domain" > /dev/null
		runuser -u arce2edev -- rm -f "/home/arce2edev/web/$domain/public_html/index.html"
		openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$domain" \
			-keyout "$dir/$domain.key" -out "$dir/$domain.crt" > /dev/null 2>&1
		"$B/v-add-web-domain-ssl" arce2edev "$domain" "$dir" > /dev/null
	done
	rm -rf "$dir"

	dev_file e2e-api.example package.json <<- 'EOF'
		{ "name": "e2e-api", "private": true, "scripts": { "start": "node server.js" }, "dependencies": { "express": "^4.21.2" } }
	EOF
	dev_file e2e-api.example server.js <<- 'EOF'
		const express = require('express');
		const app = express();
		app.get('*', (req, res) => {
			console.log(`request ${req.path}`);
			res.send(`arce2e-express ${process.env.PORT} ${process.env.GREETING || '-'}`);
		});
		app.listen(process.env.PORT, process.env.HOST);
	EOF
	dev_file e2e-next.example package.json <<- 'EOF'
		{ "name": "e2e-next", "private": true, "scripts": { "build": "next build", "start": "next start" }, "dependencies": { "next": "^15", "react": "^19", "react-dom": "^19" } }
	EOF
	dev_file e2e-next.example pages/index.js <<- 'EOF'
		export default function Home() {
			return <p>arce2e-next</p>;
		}
	EOF
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
	https)
		domain="${2:?domain}"
		body="$(mktemp)"
		curl -sk --noproxy '*' --max-time 10 -o "$body" -w '%{http_code}\n' \
			--resolve "$domain:443:$(domain_ip "$domain" "${3:-arce2euser}")" "https://$domain/" || echo 000
		head -c 65536 "$body"
		rm -f "$body"
		exit 0
		;;
	nodeapps)
		nodeapps_reset
		nodeapps_prepare
		exit 0
		;;
	nodeapps-clean)
		nodeapps_reset
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
# Node.js manager (E13): the developer package
"$B/v-add-sys-node" > /dev/null
for user in $USERS; do
	if ! "$B/v-list-user" "$user" > /dev/null 2>&1; then
		package=default
		if [ "$user" = arce2ereseller ]; then package="$PLAN"; fi
		if [ "$user" = arce2edev ]; then package=developer; fi
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
nodeapps_reset
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
