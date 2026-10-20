#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel tool ports functions (E46)                                       #
#                                                                           #
# The system NGINX serves the webmail client on WEBMAIL_PORT (8086) and     #
# phpMyAdmin and phpPgAdmin on DB_ADMIN_PORT (8087) of every address, with  #
# the panel certificate, so the links work without DNS for each domain:     #
#   /etc/nginx/conf.d/arcpanel-webmail-port.conf                            #
#   /etc/nginx/conf.d/arcpanel-db-admin-port.conf                           #
# An empty key, or no tool behind the port, removes the file. The ports are #
# reserved in the port registry and opened by firewall rules found by their #
# comments (arcpanel-webmail-port, arcpanel-db-admin-port).                 #
# Callers source func/main.sh, func/tenancy.sh and func/ports.sh first.     #
# See docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md. #
#                                                                           #
#===========================================================================#

TOOL_PORTS_TPL="$ARCPANEL/install/deb/nginx/tool-ports"
TOOL_PORTS_CONF="/etc/nginx/conf.d"

# Default port of TOOL (webmail, db-admin)
tool_ports_default() {
	case "$1" in
		webmail) echo 8086 ;;
		db-admin) echo 8087 ;;
	esac
}

# arcpanel.conf key of TOOL
tool_ports_key() {
	case "$1" in
		webmail) echo WEBMAIL_PORT ;;
		db-admin) echo DB_ADMIN_PORT ;;
	esac
}

# Comment of the firewall rule and of the reservation of TOOL
tool_ports_tag() {
	echo "arcpanel-$1-port"
}

tool_ports_file() {
	echo "$TOOL_PORTS_CONF/arcpanel-$1-port.conf"
}

# Current port of TOOL from arcpanel.conf (empty when off)
tool_ports_current() {
	local key
	key=$(tool_ports_key "$1")
	grep -m1 "^$key=" "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null | cut -d"'" -f2
}

# The webmail client served on the webmail port: the first installed client
# of WEBMAIL_SYSTEM (roundcube, snappymail), empty when there is none
tool_ports_webmail_client() {
	local client
	for client in ${WEBMAIL_SYSTEM//,/ }; do
		case "$client" in
			roundcube) [ -f /var/lib/roundcube/index.php ] && echo roundcube && return 0 ;;
			snappymail) [ -f /var/lib/snappymail/index.php ] && echo snappymail && return 0 ;;
		esac
	done
	return 0
}

# Database tools served on the database tools port, one "ALIAS INCLUDE" line
# each: phpMyAdmin first, then phpPgAdmin
tool_ports_db_tools() {
	if [ -f /usr/share/phpmyadmin/index.php ] && [ -f "$TOOL_PORTS_CONF/phpmyadmin.inc" ]; then
		echo "${DB_PMA_ALIAS:-phpmyadmin} $TOOL_PORTS_CONF/phpmyadmin.inc"
	fi
	if [ -f /usr/share/phppgadmin/index.php ] && [ -f "$TOOL_PORTS_CONF/phppgadmin.inc" ]; then
		echo "${DB_PGA_ALIAS:-phppgadmin} $TOOL_PORTS_CONF/phppgadmin.inc"
	fi
}

# Whether the tool behind TOOL's port is installed
tool_ports_available() {
	case "$1" in
		webmail) [ -n "$(tool_ports_webmail_client)" ] ;;
		db-admin) [ -n "$(tool_ports_db_tools)" ] ;;
		*) return 1 ;;
	esac
}

# Whether something listens on PORT (any address, TCP)
tool_ports_listening() {
	[ -n "$(ss -ltnH "( sport = :$1 )" 2> /dev/null)" ]
}

# Check PORT for TOOL (exit E_INVALID or E_INUSE otherwise): numeric,
# 1024-65535, not a port of the panel, the web terminal, the other tool, the
# web or proxy server, not held in the port registry by anyone else, and
# free unless TOOL already listens on it
tool_ports_check() {
	local tool="$1" port="$2" other holder
	if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || [ "$port" -lt 1024 ] || [ "$port" -gt 65535 ]; then
		check_result "$E_INVALID" "invalid port :: $port"
	fi
	other=db-admin
	[ "$tool" = 'db-admin' ] && other=webmail
	local taken
	for taken in "${BACKEND_PORT:-8083}" "${WEB_TERMINAL_PORT:-8085}" "$(tool_ports_current "$other")" \
		"$WEB_PORT" "$WEB_SSL_PORT" "$PROXY_PORT" "$PROXY_SSL_PORT" 80 443 8084; do
		if [ -n "$taken" ] && [ "$port" = "$taken" ]; then
			check_result "$E_INUSE" "port $port is used by the server"
		fi
	done
	holder=$(ports_holder "$port" "$port" '@reserved')
	if [ -n "$holder" ]; then
		check_result "$E_INUSE" "port $port is allocated to $holder"
	fi
	if grep -q "^PORT='$port' .*COMMENT='[^']*'" "$PORTS_RESERVED" 2> /dev/null \
		&& ! grep -q "^PORT='$port' .*COMMENT='$(tool_ports_tag "$tool")'" "$PORTS_RESERVED" 2> /dev/null; then
		check_result "$E_INUSE" "port $port is reserved"
	fi
	if [ "$port" != "$(tool_ports_current "$tool")" ] && tool_ports_listening "$port"; then
		check_result "$E_INUSE" "port $port is in use"
	fi
}

# Whether PORT is one of the tool ports (for the other port commands)
tool_ports_is_tool_port() {
	local tool
	for tool in webmail db-admin; do
		if [ -n "$1" ] && [ "$1" = "$(tool_ports_current "$tool")" ]; then
			return 0
		fi
	done
	return 1
}

# Reserve PORT for TOOL in the registry, dropping TOOL's older reservation
tool_ports_reserve() {
	local tool="$1" port="$2" tag old
	tag=$(tool_ports_tag "$tool")
	old=$(grep -m1 "COMMENT='$tag'" "$PORTS_RESERVED" 2> /dev/null | cut -d"'" -f2)
	if [ -n "$old" ] && [ "$old" != "$port" ]; then
		"$BIN/v-delete-sys-port-reserved" "$old" > /dev/null 2>&1
	fi
	if [ -n "$port" ] && [ "$old" != "$port" ]; then
		"$BIN/v-add-sys-port-reserved" "$port" "$tag" > /dev/null || return "$E_UPDATE"
	fi
}

# Open PORT for TOOL in the firewall, replacing TOOL's older rule; no rule
# with an empty PORT or the firewall off
tool_ports_firewall() {
	local tool="$1" port="$2" tag have want=''
	ports_firewall_enabled || return 0
	tag=$(tool_ports_tag "$tool")
	# The rules as they should be, one "PORT IP" line each
	if [ -n "$port" ]; then
		want="$port 0.0.0.0/0"
		[ "$FIREWALL_SYSTEM" = 'nftables' ] && want+=$'\n'"$port ::/0"
	fi
	have=$(grep -F " COMMENT='$tag' " "$(ports_fw_rules_file)" 2> /dev/null \
		| sed -E "s/.* PORT='([^']*)' IP='([^']*)'.*/\1 \2/" | sort)
	if [ "$have" = "$(sort <<< "$want" | sed '/^$/d')" ]; then
		return 0
	fi
	ports_fw_delete_comment "$tag"
	if [ -n "$port" ]; then
		"$BIN/v-add-firewall-rule" ACCEPT 0.0.0.0/0 "$port" TCP "$tag" > /dev/null || return "$E_UPDATE"
		# nftables filters IPv6 too (E40); iptables leaves it open
		if [ "$FIREWALL_SYSTEM" = 'nftables' ]; then
			"$BIN/v-add-firewall-rule" ACCEPT ::/0 "$port" TCP "$tag" > /dev/null || return "$E_UPDATE"
		fi
	fi
}

# Print the server block of TOOL on PORT
tool_ports_render_block() {
	local tool="$1" port="$2" root body listen_v6='' client line alias include includes='' first=''
	case "$tool" in
		webmail)
			client=$(tool_ports_webmail_client)
			root=/var/lib/snappymail
			[ "$client" = 'roundcube' ] && root=/var/lib/roundcube/public_html
			body=$(cat "$TOOL_PORTS_TPL/$client.tpl")
			;;
		db-admin)
			root=/var/www/document_errors
			while read -r alias include; do
				[ -z "$alias" ] && continue
				[ -z "$first" ] && first=$alias
				includes+="	include $include;"$'\n'
			done < <(tool_ports_db_tools)
			body=$(sed "s|%first_alias%|$first|" "$TOOL_PORTS_TPL/db-admin.tpl")
			body=${body//%includes%/${includes%$'\n'}}
			;;
	esac
	if [ -e /proc/net/if_inet6 ]; then
		listen_v6="	listen      [::]:$port ssl;"
	fi
	while IFS= read -r line; do
		line=${line//%tool%/$tool}
		line=${line//%port%/$port}
		line=${line//%root%/$root}
		line=${line//%cert%/$ARCPANEL/ssl/certificate.crt}
		line=${line//%key%/$ARCPANEL/ssl/certificate.key}
		if [ "$line" = '%listen_v6%' ]; then
			[ -n "$listen_v6" ] && echo "$listen_v6"
			continue
		fi
		echo "$line"
	done < "$TOOL_PORTS_TPL/frame-head.tpl"
	echo
	echo "$body"
}

# Write or remove the NGINX file of TOOL from its key; restore the previous
# file when the NGINX test fails. Returns 0 when nothing changed or the new
# file passed the test.
tool_ports_render() {
	local tool="$1" port file new backup=''
	port=$(tool_ports_current "$tool")
	file=$(tool_ports_file "$tool")
	if [ -z "$port" ] || ! tool_ports_available "$tool"; then
		if [ -e "$file" ]; then
			rm -f "$file"
			TOOL_PORTS_CHANGED=yes
		fi
		return 0
	fi
	new=$(tool_ports_render_block "$tool" "$port")
	if [ -e "$file" ] && [ "$new" = "$(cat "$file")" ]; then
		return 0
	fi
	if [ -e "$file" ]; then
		backup=$(mktemp)
		cp -f "$file" "$backup"
	fi
	echo "$new" > "$file"
	chmod 644 "$file"
	if ! nginx -t > /dev/null 2>&1; then
		if [ -n "$backup" ]; then
			mv -f "$backup" "$file"
		else
			rm -f "$file"
		fi
		return "$E_UPDATE"
	fi
	[ -n "$backup" ] && rm -f "$backup"
	TOOL_PORTS_CHANGED=yes
	return 0
}

# Reload NGINX after tool_ports_render changed a file
tool_ports_reload() {
	if [ "$TOOL_PORTS_CHANGED" = 'yes' ]; then
		if [ "$PROXY_SYSTEM" = 'nginx' ]; then
			"$BIN/v-restart-proxy" > /dev/null 2>&1
		else
			"$BIN/v-restart-web" > /dev/null 2>&1
		fi
	fi
}

# Serve TOOL on PORT, or turn it off with an empty PORT: key, reservation,
# firewall rule and NGINX file; everything is put back when the NGINX test
# fails. The caller holds ports_lock and has checked PORT.
tool_ports_change() {
	local tool="$1" port="$2" key old
	key=$(tool_ports_key "$tool")
	old=$(tool_ports_current "$tool")
	"$BIN/v-change-sys-config-value" "$key" "$port" > /dev/null || return "$E_UPDATE"
	tool_ports_reserve "$tool" "$port" || return "$E_UPDATE"
	tool_ports_firewall "$tool" "$port" || return "$E_UPDATE"
	if ! tool_ports_render "$tool"; then
		"$BIN/v-change-sys-config-value" "$key" "$old" > /dev/null
		tool_ports_reserve "$tool" "$old"
		tool_ports_firewall "$tool" "$old"
		return "$E_UPDATE"
	fi
	tool_ports_reload
}

# Whether arcpanel.conf has KEY at all (also when empty)
tool_ports_has_key() {
	grep -q "^$1=" "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null
}

# First setup of TOOL (install, upgrade): only when its key is missing, the
# default port when the tool is installed and the port is free, else the key
# stays empty with a warning
tool_ports_setup() {
	local tool="$1" key port
	key=$(tool_ports_key "$tool")
	tool_ports_has_key "$key" && return 0
	port=$(tool_ports_default "$tool")
	if ! tool_ports_available "$tool"; then
		"$BIN/v-change-sys-config-value" "$key" '' > /dev/null
		return 0
	fi
	if ! (tool_ports_check "$tool" "$port") > /dev/null 2>&1; then
		"$BIN/v-change-sys-config-value" "$key" '' > /dev/null
		echo "[ ! ] E46: port $port is in use, the $tool port is left off; set it with v-change-sys-$tool-port"
		return 0
	fi
	tool_ports_change "$tool" "$port" || echo "[ ! ] E46: the $tool port could not be set up and is left off"
}
