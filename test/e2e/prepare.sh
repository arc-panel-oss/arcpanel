#!/bin/bash
# Test data of the Playwright suite (test/e2e). Run as root on a test server,
# never on production:
#   prepare.sh prepare PASSWORD   creates arce2eadmin (admin), arce2euser, the
#                                 reseller arce2ereseller with its customer
#                                 arce2ecustomer, their web domains, and prints
#                                 a self-signed certificate as JSON; the
#                                 users start without ports (E12);
#                                 arce2edev on the developer package starts
#                                 without Node.js (E13); arce2euser has the
#                                 database arce2euser_e2edb, one cron job
#                                 "echo e2e-cron" without e-mail reports, the
#                                 DNS zone e2e-zone.example and the mail
#                                 domain e2e-mail.example with the account
#                                 info (E7); arce2ebackup has a package with
#                                 incremental backups (E7 Backups); firewall
#                                 rules "e2e-fw*", IP lists "arce2e*" and the
#                                 ban of 198.51.100.77 are removed, and
#                                 /root/arce2e-ipset.txt is written (E7
#                                 batch 2 Firewall); API_SYSTEM is 2 and the
#                                 test users have no access keys (E7 batch 2
#                                 Keys) and no secrets (E38)
#   prepare.sh cleanup            deletes them again and restores API_SYSTEM
#   prepare.sh ssh-key            prints a fresh public SSH key
#   prepare.sh terminal-on        WEB_TERMINAL=true and a bash shell for
#                                 arce2euser; terminal-off restores both
#   prepare.sh quickinstall       a fresh web domain e2e-wiki.example for
#                                 arce2euser; quickinstall-http prints its
#                                 DokuWiki page, quickinstall-clean deletes it
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
#   prepare.sh backups            a local restic repository (when the server
#                                 has none), a web domain, ~/tmp/e2e-note.txt,
#                                 one tar backup and one snapshot for
#                                 arce2ebackup (E7); backups-clear-queue,
#                                 backups-change-file and backups-run-queue go
#                                 with it
#   prepare.sh reset-user PASSWORD
#                                 arce2ereset whose contact is the mailbox
#                                 reset@e2e-mail.example (E7 batch 3 reset);
#                                 reset-code prints the code of the newest
#                                 reset mail, reset-clean deletes both
#   prepare.sh syspackages        the system package catalog entry
#                                 arctest-e2e (GNU hello, provides ruby)
#                                 without hello installed (E5);
#                                 syspackages-clean removes both again
#   prepare.sh php                PHP Manager spec (E41): saves the php.ini
#                                 files of PHP 8.4, removes the profile
#                                 arce2e and the uuid extension of 8.4;
#                                 php-clean restores the files, deletes the
#                                 profile, the extension and PHP 8.3 when the
#                                 spec installed them, and resets the PHP
#                                 keys of the default package
set -euo pipefail

ARCPANEL="${ARCPANEL:-/usr/local/arcpanel}"
B="$ARCPANEL/bin"
USERS="arce2eadmin arce2euser arce2ereseller arce2ecustomer arce2edev arce2ebackup"
BACKUP_PACKAGE=arce2e-backups
RESTIC_REPO=/backup/restic-e2e
RESTIC_STATE=/root/.arcpanel-e2e-restic
PLAN=arce2e-plan
STATE=/root/.arcpanel-e2e-debug-mode

forget_config_cache() {
	runuser -u arcpanelweb -- "$ARCPANEL/php/bin/php" "$ARCPANEL/web-app/artisan" cache:clear > /dev/null
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
	"$B/v-delete-user-package" "$BACKUP_PACKAGE" > /dev/null 2>&1 || true
}

# Backups spec (E7): restic repository, domain, file, one archive, one snapshot
backups_prepare() {
	local user=arce2ebackup
	if [ ! -f "$ARCPANEL/conf/restic.conf" ]; then
		mkdir -p "$RESTIC_REPO"
		"$B/v-add-backup-host-restic" "$RESTIC_REPO" 5 -1 -1 -1 -1 > /dev/null
		touch "$RESTIC_STATE"
	fi
	if ! "$B/v-list-web-domain" "$user" e2e-backup.example > /dev/null 2>&1; then
		"$B/v-add-web-domain" "$user" e2e-backup.example > /dev/null
	fi
	runuser -u "$user" -- sh -c "mkdir -p /home/$user/tmp && echo original > /home/$user/tmp/e2e-note.txt"
	sed -i "/'$user'/d; / $user /d" "$ARCPANEL/data/queue/backup.pipe" 2> /dev/null || true
	for backup in $("$B/v-list-user-backups" "$user" plain | awk '{print $1}'); do
		"$B/v-delete-user-backup" "$user" "$backup" > /dev/null 2>&1 || true
	done
	"$B/v-backup-user" "$user" > /dev/null 2>&1
	"$B/v-backup-user-restic" "$user" > /dev/null 2>&1
}

stop_listeners() {
	pkill -f 'python3 -m http.server 399' > /dev/null 2>&1 || true
	rm -rf /tmp/arce2e-listen-*
}

# Local address of DOMAIN of USER (default arce2euser): web.conf holds the
# NAT address when the IP has one
domain_ip() {
	local ip
	ip=$(grep "^DOMAIN='$1' " "$ARCPANEL/data/users/${2:-arce2euser}/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
	if [ -f "$ARCPANEL/data/ips/$ip" ]; then
		echo "$ip"
	else
		basename "$(grep -l "^NAT='$ip'" "$ARCPANEL"/data/ips/* | head -n1)"
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
	rm -f "$ARCPANEL/data/users/arce2edev/node-task.conf" "$ARCPANEL/data/users/arce2edev/node-task.log"
}

# Docker apps (E4): the engine, and a web domain with HTTPS (self-signed) of
# arce2edev (developer package: DOCKER='yes', DOCKER_APPS='3'); the spec
# installs Uptime Kuma from the store.
DOCKERAPP_DOMAINS="e2e-kuma.example"

dockerapps_reset() {
	if ! "$B/v-list-user" arce2edev > /dev/null 2>&1; then
		return 0
	fi
	if [ -f "$ARCPANEL/data/users/arce2edev/dockerapps.conf" ]; then
		for name in $(grep -oE "^NAME='[^']*'" "$ARCPANEL/data/users/arce2edev/dockerapps.conf" | cut -d"'" -f2); do
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

# Firewall spec (E7 batch 2): no rule, IP list or ban of an earlier run; the
# IP list file the spec loads
FIREWALL_IPSET_FILE=/root/arce2e-ipset.txt
firewall_reset() {
	local id
	for id in $("$B/v-list-firewall" json 2> /dev/null | jq -r 'to_entries[] | select(.value.COMMENT | startswith("e2e-fw")) | .key' | sort -rn); do
		"$B/v-delete-firewall-rule" "$id" > /dev/null 2>&1 || true
	done
	for id in $("$B/v-list-firewall-ipset" json 2> /dev/null | jq -r 'keys[] | select(startswith("arce2e"))'); do
		"$B/v-delete-firewall-ipset" "$id" > /dev/null 2>&1 || true
	done
	"$B/v-delete-firewall-ban" 198.51.100.77 SSH > /dev/null 2>&1 || true
	# v-add-firewall-ipset ignores lists of fewer than five entries
	printf '198.51.100.%s\n' 81 82 83 84 85 > "$FIREWALL_IPSET_FILE"
}

# Keys spec (E7 batch 2): API_SYSTEM=2 while the suite runs (every account
# may have access keys), restored by cleanup; no access key of a test user
API_STATE=/root/.arcpanel-e2e-api-system
keys_reset() {
	local key user
	for user in $USERS; do
		for key in $("$B/v-list-access-keys" "$user" json 2> /dev/null | jq -r 'keys[]' 2> /dev/null); do
			"$B/v-delete-access-key" "$key" > /dev/null 2>&1 || true
		done
	done
	"$B/v-delete-user-ssh-key" arce2euser e2e-key@arcpanel > /dev/null 2>&1 || true
}

# Terminal and Quick Install specs (E7 batch 2): WEB_TERMINAL and the shell of
# arce2euser while the terminal spec runs, a fresh web domain for DokuWiki
TERMINAL_STATE=/root/.arcpanel-e2e-web-terminal
QUICKINSTALL_DOMAIN=e2e-wiki.example
terminal_off() {
	if [ -f "$TERMINAL_STATE" ]; then
		"$B/v-change-sys-config-value" WEB_TERMINAL "$(cat "$TERMINAL_STATE")"
		rm -f "$TERMINAL_STATE"
	fi
	"$B/v-change-user-shell" arce2euser nologin > /dev/null 2>&1 || true
}

# Reset spec (E7 batch 3): a throw-away user whose contact address is a
# mailbox on the e2e mail domain
RESET_USER=arce2ereset
RESET_MAILBOX=reset
RESET_MAILDIR=/home/arce2euser/mail/e2e-mail.example/$RESET_MAILBOX
RESET_STATE=/root/.arcpanel-e2e-password-reset
reset_clean() {
	"$B/v-delete-user" "$RESET_USER" > /dev/null 2>&1 || true
	"$B/v-delete-mail-account" arce2euser e2e-mail.example "$RESET_MAILBOX" > /dev/null 2>&1 || true
	if [ -f "$RESET_STATE" ]; then
		"$B/v-change-sys-config-value" POLICY_SYSTEM_PASSWORD_RESET "$(cat "$RESET_STATE")"
		rm -f "$RESET_STATE"
		forget_config_cache
	fi
}

# POLICY_SYSTEM_PASSWORD_RESET=yes while the reset spec runs; reset_clean restores it
reset_enable() {
	if [ ! -f "$RESET_STATE" ]; then
		grep -o "^POLICY_SYSTEM_PASSWORD_RESET='[a-z]*'" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2 > "$RESET_STATE" || echo yes > "$RESET_STATE"
	fi
	"$B/v-change-sys-config-value" POLICY_SYSTEM_PASSWORD_RESET yes
	forget_config_cache
}

# E5: the catalog entry of the system packages spec and its package
syspackages_clean() {
	if grep -q "^SLUG='arctest-e2e' " "$ARCPANEL/data/sys-packages.conf" 2> /dev/null; then
		"$B/v-delete-sys-package" arctest-e2e force > /dev/null 2>&1 || true
	fi
	rm -f "$ARCPANEL/install/common/apps/system/arctest-e2e.json"
}

# E41: the state the PHP Manager spec changes
PHP_SAVE=/root/.arcpanel-e2e-php
php_reset() {
	"$B/v-delete-sys-php-profile" arce2e yes > /dev/null 2>&1 || true
	if [ "$(dpkg-query -W -f='${db:Status-Status}' php8.4-uuid 2> /dev/null || true)" = installed ]; then
		"$B/v-delete-sys-php-extension" 8.4 uuid > /dev/null 2>&1 || true
	fi
	"$B/v-change-package-tenancy" default PHP_PROFILES default > /dev/null 2>&1 || true
	"$B/v-change-package-tenancy" default PHP_DEFAULT_PROFILE default > /dev/null 2>&1 || true
	"$B/v-change-package-tenancy" default PHP_VERSIONS '*' > /dev/null 2>&1 || true
}

case "${1:-}" in
	php)
		php_reset
		mkdir -p "$PHP_SAVE"
		cp -p /etc/php/8.4/fpm/php.ini "$PHP_SAVE/fpm.ini"
		cp -p /etc/php/8.4/cli/php.ini "$PHP_SAVE/cli.ini"
		if [ ! -f /etc/init.d/php8.3-fpm ]; then
			touch "$PHP_SAVE/remove-8.3"
		fi
		exit 0
		;;
	php-clean)
		php_reset
		if [ -f "$PHP_SAVE/fpm.ini" ]; then
			cp -p "$PHP_SAVE/fpm.ini" /etc/php/8.4/fpm/php.ini
			cp -p "$PHP_SAVE/cli.ini" /etc/php/8.4/cli/php.ini
			systemctl restart php8.4-fpm
		fi
		if [ -f "$PHP_SAVE/remove-8.3" ] && [ -f /etc/init.d/php8.3-fpm ]; then
			for domain in $("$B/v-list-web-domains" arce2euser plain | cut -f1); do
				"$B/v-change-web-domain-backend-tpl" arce2euser "$domain" default > /dev/null 2>&1 || true
			done
			"$B/v-delete-web-php" 8.3 > /dev/null 2>&1 || true
		fi
		rm -rf "$PHP_SAVE"
		exit 0
		;;
	syspackages)
		syspackages_clean
		if [ "$(dpkg-query -W -f='${db:Status-Status}' hello 2> /dev/null || true)" = installed ]; then
			DEBIAN_FRONTEND=noninteractive apt-get -y -qq remove hello > /dev/null
		fi
		# Next to this script when it runs as a file; from the checkout (the
		# working directory) when server.js passes it on stdin (bash -s)
		for fixtures in "$(dirname "$(readlink -f "$0")")/../fixtures" "$PWD/test/fixtures"; do
			[ -f "$fixtures/sys-packages-e2e/arctest-e2e.json" ] && break
		done
		install -m 0644 "$fixtures/sys-packages-e2e/arctest-e2e.json" "$ARCPANEL/install/common/apps/system/"
		exit 0
		;;
	syspackages-clean)
		syspackages_clean
		exit 0
		;;
	terminal-on)
		if [ ! -f "$TERMINAL_STATE" ]; then
			grep -o "^WEB_TERMINAL='[a-z]*'" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2 > "$TERMINAL_STATE" || echo false > "$TERMINAL_STATE"
		fi
		"$B/v-change-sys-config-value" WEB_TERMINAL true
		"$B/v-change-user-shell" arce2euser bash > /dev/null
		forget_config_cache
		exit 0
		;;
	terminal-off)
		terminal_off
		forget_config_cache
		exit 0
		;;
	quickinstall)
		"$B/v-delete-web-domain" arce2euser "$QUICKINSTALL_DOMAIN" > /dev/null 2>&1 || true
		"$B/v-add-web-domain" arce2euser "$QUICKINSTALL_DOMAIN" > /dev/null
		exit 0
		;;
	quickinstall-http)
		# The DokuWiki start page of the domain over HTTP
		curl -sL --noproxy '*' --max-time 15 --resolve "$QUICKINSTALL_DOMAIN:80:$(domain_ip "$QUICKINSTALL_DOMAIN")" "http://$QUICKINSTALL_DOMAIN/doku.php" || true
		exit 0
		;;
	quickinstall-clean)
		"$B/v-delete-web-domain" arce2euser "$QUICKINSTALL_DOMAIN" > /dev/null 2>&1 || true
		exit 0
		;;
	ssh-key)
		# A fresh public key with the comment e2e-key@arcpanel
		dir="$(mktemp -d)"
		ssh-keygen -q -t ed25519 -N '' -C e2e-key@arcpanel -f "$dir/key"
		cat "$dir/key.pub"
		rm -rf "$dir"
		exit 0
		;;
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
		staging="$ARCPANEL/web-app/storage/app/files"
		token=e2e0000000000000000000000000beef
		install -d -o arcpanelweb -g arcpanelweb -m 700 "$staging/uploads/$token" "$staging/uploads/$token/chunks"
		printf '{"created":%s}' "$(($(date +%s) - 90000))" > "$staging/uploads/$token/meta.json"
		touch "$staging/downloads/$token" "$staging/downloads/$token.json"
		chown arcpanelweb:arcpanelweb "$staging/uploads/$token/meta.json" "$staging/downloads/$token" "$staging/downloads/$token.json"
		touch -d '2 hours ago' "$staging/downloads/$token" "$staging/downloads/$token.json"
		runuser -u arcpanelweb -- "$ARCPANEL/php/bin/php" "$ARCPANEL/web-app/artisan" files:gc > /dev/null
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
		grep "^NAME='${2:?name}' " "$ARCPANEL/data/users/arce2edev/dockerapps.conf" 2> /dev/null \
			| grep -oE "WEB_PORT='[0-9]*'" | cut -d"'" -f2 || true
		exit 0
		;;
	backups)
		backups_prepare
		exit 0
		;;
	backups-clear-queue)
		sed -i "/'arce2ebackup'/d; / arce2ebackup /d" "$ARCPANEL/data/queue/backup.pipe" 2> /dev/null || true
		exit 0
		;;
	backups-change-file)
		runuser -u arce2ebackup -- sh -c 'echo changed > /home/arce2ebackup/tmp/e2e-note.txt'
		exit 0
		;;
	backups-run-queue)
		# The queued file restore of arce2ebackup, run as v-update-sys-queue would
		file=$(mktemp)
		grep "v-restore-file-restic 'arce2ebackup' " "$ARCPANEL/data/queue/backup.pipe" > "$file" || true
		bash "$file" > /dev/null 2>&1 || true
		rm -f "$file"
		cat /home/arce2ebackup/tmp/e2e-note.txt
		exit 0
		;;
	port-app)
		# The registry app of PORT of arce2edev, empty when it is free
		grep "^PORT='${2:?port}' " "$ARCPANEL/data/ports/arce2edev.conf" 2> /dev/null | grep -oE "APP='[^']*'" | cut -d"'" -f2 || true
		exit 0
		;;
	reset-user)
		# E7 batch 3: arce2ereset with the contact reset@e2e-mail.example, a
		# mailbox of arce2euser that receives the reset mail
		reset_clean
		file="$(mktemp)"
		printf '%s\n' "${2:?password}" > "$file"
		"$B/v-add-user" "$RESET_USER" "$2" "$RESET_MAILBOX@e2e-mail.example" default > /dev/null
		"$B/v-add-mail-account" arce2euser e2e-mail.example "$RESET_MAILBOX" "$file" > /dev/null
		rm -f "$file"
		reset_enable
		exit 0
		;;
	reset-code)
		# The reset code of the newest mail in the reset mailbox (empty until it arrives)
		latest=$(ls -t "$RESET_MAILDIR"/new/* "$RESET_MAILDIR"/cur/* 2> /dev/null | head -n1 || true)
		if [ -n "$latest" ]; then
			"$ARCPANEL/php/bin/php" -r 'echo quoted_printable_decode(file_get_contents($argv[1]));' "$latest" \
				| grep -oE 'code=[A-Za-z0-9]{12}' | tail -n1 | cut -d= -f2 || true
		fi
		exit 0
		;;
	reset-clean)
		reset_clean
		exit 0
		;;
esac

if [ "${1:-}" = cleanup ]; then
	stop_listeners
	syspackages_clean
	reset_clean
	terminal_off
	keys_reset
	if [ -f "$API_STATE" ]; then
		"$B/v-change-sys-config-value" API_SYSTEM "$(cat "$API_STATE")"
		rm -f "$API_STATE"
	fi
	delete_users
	if [ -n "$(grep -o "^FIREWALL_SYSTEM='[^']\+'" "$ARCPANEL/conf/arcpanel.conf" || true)" ]; then
		firewall_reset
		rm -f "$FIREWALL_IPSET_FILE"
	fi
	if [ -f "$RESTIC_STATE" ]; then
		"$B/v-delete-backup-host-restic" > /dev/null 2>&1 || rm -f "$ARCPANEL/conf/restic.conf"
		"$B/v-change-sys-config-value" BACKUP_INCREMENTAL no > /dev/null 2>&1 || true
		rm -rf "$RESTIC_REPO" "$RESTIC_STATE"
	fi
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
# Backups (E7): a package with incremental backups
if ! "$B/v-list-user-package" "$BACKUP_PACKAGE" > /dev/null 2>&1; then
	file="$(mktemp)"
	sed "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$ARCPANEL/data/packages/default.pkg" > "$file"
	grep -q '^BACKUPS_INCREMENTAL=' "$file" || echo "BACKUPS_INCREMENTAL='yes'" >> "$file"
	"$B/v-add-user-package" "$file" "$BACKUP_PACKAGE" > /dev/null
	rm -f "$file"
fi
# Node.js manager (E13): the developer package
"$B/v-add-sys-node" > /dev/null
for user in $USERS; do
	if ! "$B/v-list-user" "$user" > /dev/null 2>&1; then
		package=default
		if [ "$user" = arce2ereseller ]; then package="$PLAN"; fi
		if [ "$user" = arce2edev ]; then package=developer; fi
		if [ "$user" = arce2ebackup ]; then package="$BACKUP_PACKAGE"; fi
		"$B/v-add-user" "$user" "$password" "$user@example.invalid" "$package" > /dev/null
	fi
	file="$(mktemp)"
	printf '%s\n' "$password" > "$file"
	"$B/v-change-user-password" "$user" "$file" > /dev/null
	rm -f "$file"
	for domain in $("$B/v-list-web-domains" "$user" plain | awk '{print $1}'); do
		"$B/v-delete-web-domain" "$user" "$domain" no > /dev/null
	done
	# E7 batch 1: every run starts without databases
	for database in $("$B/v-list-databases" "$user" plain | awk '{print $1}'); do
		"$B/v-delete-database" "$user" "$database" > /dev/null
	done
	# ... and without cron jobs and cron e-mail reports (the reports command
	# also restarts cron after the deletions with RESTART=no)
	for job in $("$B/v-list-cron-jobs" "$user" plain | awk '{print $1}'); do
		"$B/v-delete-cron-job" "$user" "$job" no > /dev/null
	done
	"$B/v-delete-cron-reports" "$user" > /dev/null
	# ... and without DNS zones (DNS restarts when the known zone is added)
	for domain in $("$B/v-list-dns-domains" "$user" plain | awk '{print $1}'); do
		"$B/v-delete-dns-domain" "$user" "$domain" no > /dev/null
	done
	# ... and without mail domains
	for domain in $("$B/v-list-mail-domains" "$user" plain | awk '{print $1}'); do
		"$B/v-delete-mail-domain" "$user" "$domain" > /dev/null
	done
	# ... and without secrets (E38): a failed run must not leave one behind
	for name in $("$B/v-list-user-secret-store" "$user" plain 2> /dev/null | cut -f1); do
		"$B/v-delete-user-secret" "$user" "$name" yes > /dev/null
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
# Databases spec (E7): arce2euser_e2edb
if [ -n "$(grep -o "^DB_SYSTEM='[^']\+'" "$ARCPANEL/conf/arcpanel.conf" || true)" ]; then
	file="$(mktemp)"
	printf '%s\n' "$password" > "$file"
	"$B/v-add-database" arce2euser e2edb e2edb "$file" > /dev/null
	rm -f "$file"
fi
# Cron spec (E7): one known job
"$B/v-add-cron-job" arce2euser 0 3 '*' '*' '*' 'echo e2e-cron' > /dev/null
# DNS spec (E7): one known zone on the address of e2e-site.example
if [ -n "$(grep -o "^DNS_SYSTEM='[^']\+'" "$ARCPANEL/conf/arcpanel.conf" || true)" ]; then
	"$B/v-add-dns-domain" arce2euser e2e-zone.example "$(domain_ip e2e-site.example)" > /dev/null
fi
# Mail spec (E7): one known domain with the account info
if [ -n "$(grep -o "^MAIL_SYSTEM='[^']\+'" "$ARCPANEL/conf/arcpanel.conf" || true)" ]; then
	"$B/v-add-mail-domain" arce2euser e2e-mail.example > /dev/null
	file="$(mktemp)"
	printf '%s\n' "$password" > "$file"
	"$B/v-add-mail-account" arce2euser e2e-mail.example info "$file" > /dev/null
	rm -f "$file"
fi

# Keys spec (E7 batch 2)
keys_reset
if [ ! -f "$API_STATE" ]; then
	grep -o "^API_SYSTEM='[0-9]*'" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2 > "$API_STATE" || echo 0 > "$API_STATE"
fi
"$B/v-change-sys-config-value" API_SYSTEM 2

# Firewall spec (E7 batch 2)
if [ -n "$(grep -o "^FIREWALL_SYSTEM='[^']\+'" "$ARCPANEL/conf/arcpanel.conf" || true)" ]; then
	firewall_reset
fi

# The style guide is part of the accessibility run.
if [ ! -f "$STATE" ]; then
	grep -o "^DEBUG_MODE='[a-z]*'" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2 > "$STATE" || echo false > "$STATE"
fi
"$B/v-change-sys-config-value" DEBUG_MODE true
forget_config_cache

dir="$(mktemp -d)"
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=e2e-site.example" \
	-keyout "$dir/key.pem" -out "$dir/crt.pem" > /dev/null 2>&1
"$ARCPANEL/php/bin/php" -r 'echo json_encode(["certificate" => file_get_contents($argv[1]), "key" => file_get_contents($argv[2])]);' \
	"$dir/crt.pem" "$dir/key.pem"
rm -rf "$dir"
