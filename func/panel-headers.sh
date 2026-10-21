#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: security headers of the panel's own web server and the
# open_basedir of its PHP pool (E39 W5 spec §9 and §10.1,
# docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md).
#
# The arcpanel-nginx package ships security-headers.conf, which includes two
# files of the configuration directory that ArcPanel writes:
#   csp.conf   the Content-Security-Policy, with the tool ports (E46) that
#              the single sign-on forms post to
#   hsts.conf  Strict-Transport-Security while PANEL_HSTS is yes, else empty
# Both are included through a glob, so the panel starts without them.

PANEL_NGINX_CONF_DIR="$ARCPANEL/nginx/conf"
PANEL_NGINX="$ARCPANEL/nginx/sbin/arcpanel-nginx"
PANEL_HSTS_MAX_AGE=86400

# The Content-Security-Policy of the panel (spec §9.2)
panel_csp_value() {
	local form="'self'" key port
	for key in WEBMAIL_PORT DB_ADMIN_PORT; do
		port=$(grep -m1 "^$key=" "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null | cut -d"'" -f2)
		if [[ "$port" =~ ^[1-9][0-9]{0,4}$ ]]; then
			form="$form https://\$host:$port"
		fi
	done
	echo "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'none'; form-action $form"
}

# Write CONTENT to FILE of the configuration directory when it differs; sets
# PANEL_HEADERS_CHANGED
panel_headers_put() {
	local file="$PANEL_NGINX_CONF_DIR/$1" content="$2" tmp
	if [ -f "$file" ] && [ "$(cat "$file")" = "$content" ]; then
		return 0
	fi
	tmp="$file.$$"
	printf '%s\n' "$content" > "$tmp"
	chmod 644 "$tmp"
	mv -f "$tmp" "$file"
	PANEL_HEADERS_CHANGED=yes
}

panel_csp_write() {
	panel_headers_put csp.conf "# Written by v-update-sys-panel-headers (E39 W5), do not edit
add_header Content-Security-Policy \"$(panel_csp_value)\" always;"
}

# Write hsts.conf for yes or no
panel_hsts_write() {
	local content="# Written by v-change-sys-arcpanel-hsts (E39 W5), do not edit"
	if [ "$1" = 'yes' ]; then
		content="$content
add_header Strict-Transport-Security \"max-age=$PANEL_HSTS_MAX_AGE\" always;"
	fi
	panel_headers_put hsts.conf "$content"
}

# Test the panel's NGINX configuration, then reload it when it runs
panel_nginx_reload() {
	"$PANEL_NGINX" -t -c "$PANEL_NGINX_CONF_DIR/nginx.conf" > /dev/null 2>&1 || return "$E_UPDATE"
	if [ -s /run/arcpanel-nginx.pid ]; then
		"$PANEL_NGINX" -c "$PANEL_NGINX_CONF_DIR/nginx.conf" -s reload > /dev/null 2>&1 || true
	fi
}

# Extra folders of the panel pool, one absolute path per line; the
# administrator's file, which upgrades leave alone (spec §10.1, risks)
PANEL_PHP_BASEDIR_EXTRA="${PANEL_PHP_BASEDIR_EXTRA:-/etc/arcpanel/panel-php-basedir.conf}"

# Whether $1 is a folder that may go into the open_basedir list
panel_php_basedir_valid() {
	[[ "$1" =~ ^/[A-Za-z0-9._/-]+/$ && "$1" != *..* ]]
}

# The open_basedir of the panel's PHP pool ends with the backup folder, BACKUP
# of arcpanel.conf (E39 W5 spec §10.1), and the folders of
# $PANEL_PHP_BASEDIR_EXTRA; a backup folder with characters that do not
# belong in the list falls back to /backup/, such an extra folder is left
# out. Sets PANEL_PHP_CHANGED
panel_php_basedir_write() {
	local conf="$ARCPANEL/php/etc/php-fpm.conf" dir line extra
	PANEL_PHP_CHANGED=no
	[ -f "$conf" ] || return 0
	grep -q '^php_admin_value\[open_basedir\]' "$conf" || return 0
	dir=$(grep -m1 "^BACKUP=" "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null | cut -d"'" -f2)
	dir="${dir%/}/"
	panel_php_basedir_valid "$dir" || dir=/backup/
	line="php_admin_value[open_basedir] = $ARCPANEL/web-app/:$ARCPANEL/lib/:$ARCPANEL/data/:/etc/arcpanel/:/tmp/:$dir"
	if [ -f "$PANEL_PHP_BASEDIR_EXTRA" ]; then
		while IFS= read -r extra || [ -n "$extra" ]; do
			extra="${extra%$'\r'}"
			[ -n "$extra" ] || continue
			extra="${extra%/}/"
			panel_php_basedir_valid "$extra" && line="$line:$extra"
		done < "$PANEL_PHP_BASEDIR_EXTRA"
	fi
	grep -qxF "$line" "$conf" && return 0
	sed -i "s|^php_admin_value\[open_basedir\].*|$line|" "$conf"
	PANEL_PHP_CHANGED=yes
}

# Reload the panel's PHP pool gracefully when it runs
panel_php_reload() {
	local pid
	pid=$(cat /run/arcpanel-php.pid 2> /dev/null)
	if [[ "$pid" =~ ^[0-9]+$ ]]; then
		kill -USR2 "$pid" 2> /dev/null || true
	fi
}

# Whether browsers trust the panel certificate: it verifies against the
# system's certificate authorities, with the chain of its own file
panel_cert_trusted() {
	local crt="$ARCPANEL/ssl/certificate.crt"
	[ -f "$crt" ] && openssl verify -untrusted "$crt" "$crt" > /dev/null 2>&1
}
