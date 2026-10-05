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
#   prepare.sh files              fixtures of the file manager spec (E3);
#                                 files-stat FILE, files-config KEY VALUE,
#                                 files-gc and files-clean go with it
#   prepare.sh dockerapps         the Docker engine and a web domain with
#                                 HTTPS for arce2edev (E4); dockerapps-clean
#                                 removes its apps and the domain again
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

# Docker apps (E4): the engine, and a web domain with HTTPS (self-signed) of
# arce2edev (developer package: DOCKER='yes', DOCKER_APPS='3'); the spec
# installs Uptime Kuma from the store.
DOCKERAPP_DOMAINS="e2e-kuma.example"

dockerapps_reset() {
	if ! "$B/v-list-user" arce2edev > /dev/null 2>&1; then
		return 0
	fi
	if [ -f "$HESTIA/data/users/arce2edev/dockerapps.conf" ]; then
		for name in $(grep -oE "^NAME='[^']*'" "$HESTIA/data/users/arce2edev/dockerapps.conf" | cut -d"'" -f2); do
			"$B/v-delete-docker-app" arce2edev "$name" > /dev/null 2>&1 || true
		done
	fi
	for domain in $DOCKERAPP_DOMAINS; do
		"$B/v-delete-web-domain" arce2edev "$domain" no > /dev/null 2>&1 || true
	done
	runuser -u arce2edev -- rm -rf /home/arce2edev/apps/.removed
}

dockerapps_prepare() {
	local dir domain
	"$B/v-add-sys-docker" > /dev/null
	dir="$(mktemp -d)"
	for domain in $DOCKERAPP_DOMAINS; do
		"$B/v-add-web-domain" arce2edev "$domain" > /dev/null
		openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$domain" \
			-keyout "$dir/$domain.key" -out "$dir/$domain.crt" > /dev/null 2>&1
		"$B/v-add-web-domain-ssl" arce2edev "$domain" "$dir" > /dev/null
	done
	rm -rf "$dir"
	forget_config_cache
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

# File manager (E3): arce2euser gets a folder of fixtures below the
# document root of e2e-site.example; the spec uploads, edits and moves
# files there through the panel.
FILES_ROOT=/home/arce2euser/web/e2e-site.example/public_html/e2e-files

# Write FILE (relative to FILES_ROOT) as arce2euser
user_file() {
	local path="$FILES_ROOT/$1"
	runuser -u arce2euser -- mkdir -p "$(dirname "$path")"
	runuser -u arce2euser -- tee "$path" > /dev/null
}

files_prepare() {
	runuser -u arce2euser -- rm -rf "$FILES_ROOT"
	runuser -u arce2euser -- mkdir -p "$FILES_ROOT"
	printf '<?php echo "arce2e-before";\n' | user_file edit.php
	printf '# arce2e htaccess\n' | user_file .htaccess
	printf 'hello' | user_file 'my site/a b.txt'
	printf 'xin chào' | user_file 'my site/ảnh/x.txt'
	ln -s /etc/passwd "$FILES_ROOT/my site/outside"
	chown -h arce2euser:arce2euser "$FILES_ROOT/my site/outside"
	echo 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' | base64 -d | user_file photo.png
	printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' | user_file doc.pdf
	head -c 4096 /dev/urandom | user_file clip.mp4
	head -c 5242880 /dev/zero | tr '\0' 'a' | user_file big.log
	printf 'one' | user_file 'archive-src/one.txt'
	runuser -u arce2euser -- sh -c "cd '$FILES_ROOT' && zip -qr site.zip archive-src && rm -rf archive-src"
}

case "${1:-}" in
	files)
		files_prepare
		exit 0
		;;
	files-http)
		# The body of e2e-files/FILE on e2e-site.example over HTTP
		curl -s --noproxy '*' --max-time 10 --resolve "e2e-site.example:80:$(domain_ip e2e-site.example)" \n "http://e2e-site.example/e2e-files/${2:?file}" || true
		exit 0
		;;
	files-suspend)
		"$B/v-suspend-user" "${2:?user}" > /dev/null
		exit 0
		;;
	files-unsuspend)
		"$B/v-unsuspend-user" "${2:?user}" > /dev/null 2>&1 || true
		exit 0
		;;
	files-clean)
		runuser -u arce2euser -- rm -rf "$FILES_ROOT"
		exit 0
		;;
	files-stat)
		# owner:group mode size, then the first bytes, of FILE below FILES_ROOT
		path="$FILES_ROOT/${2:?file}"
		stat -c '%U:%G %a %s' -- "$path"
		if [ -f "$path" ]; then
			head -c 200 -- "$path"
		fi
		exit 0
		;;
	files-config)
		"$B/v-change-sys-config-value" "${2:?key}" "${3:?value}"
		forget_config_cache
		exit 0
		;;
	files-gc)
		# An upload session older than 24 h and a ticket older than an hour,
		# then the scheduler's clean-up; prints what is left of them
		staging="$HESTIA/web-app/storage/app/files"
		token=e2e0000000000000000000000000beef
		install -d -o hestiaweb -g hestiaweb -m 700 "$staging/uploads/$token" "$staging/uploads/$token/chunks"
		printf '{"created":%s}' "$(($(date +%s) - 90000))" > "$staging/uploads/$token/meta.json"
		touch "$staging/downloads/$token" "$staging/downloads/$token.json"
		chown hestiaweb:hestiaweb "$staging/uploads/$token/meta.json" "$staging/downloads/$token" "$staging/downloads/$token.json"
		touch -d '2 hours ago' "$staging/downloads/$token" "$staging/downloads/$token.json"
		runuser -u hestiaweb -- "$HESTIA/php/bin/php" "$HESTIA/web-app/artisan" files:gc > /dev/null
		find "$staging" -name "$token*" | wc -l
		exit 0
		;;
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
	dockerapps)
		dockerapps_reset
		dockerapps_prepare
		exit 0
		;;
	dockerapps-clean)
		dockerapps_reset
		exit 0
		;;
	dockerapp-port)
		# The web port of arce2edev's app NAME, empty when it has none
		grep "^NAME='${2:?name}' " "$HESTIA/data/users/arce2edev/dockerapps.conf" 2> /dev/null \
			| grep -oE "WEB_PORT='[0-9]*'" | cut -d"'" -f2 || true
		exit 0
		;;
	port-app)
		# The registry app of PORT of arce2edev, empty when it is free
		grep "^PORT='${2:?port}' " "$HESTIA/data/ports/arce2edev.conf" 2> /dev/null | grep -oE "APP='[^']*'" | cut -d"'" -f2 || true
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
dockerapps_reset
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
