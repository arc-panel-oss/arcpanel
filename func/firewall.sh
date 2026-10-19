#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel Control Panel - Firewall Function Library                          #
#                                                                           #
#===========================================================================#

# E40: the firewall is one nftables table, inet arcpanel, for IPv4 and IPv6,
# rendered from data/firewall and replaced in one nft transaction
# (docs/features/specs/2026-10-19-002-e40-nftables-firewall-design.md).

FW_NFT="/usr/sbin/nft"
FW_SAVED="/etc/arcpanel/firewall.nft"
FW_STATE="/etc/arcpanel/firewall.json"
FW_UNIT="/etc/systemd/system/arcpanel-firewall.service"
FW_LOCK="/run/arcpanel-firewall.lock"
FW_LEGACY_UNIT="/lib/systemd/system/arcpanel-iptables.service"
FW_LEGACY_RULES="/etc/iptables.rules"
FW_F2B_ACTION="/etc/fail2ban/action.d/arcpanel.conf"

# ICMPv6 types accepted before the rules (spec D4)
FW_ICMPV6_TYPES='destination-unreachable, packet-too-big, time-exceeded, parameter-problem, nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert, mld-listener-query, mld-listener-report, mld2-listener-report, ind-neighbor-solicit, ind-neighbor-advert'

heal_iptables_links() {
	packages="iptables iptables-save iptables-restore"
	for package in $packages; do
		if [ ! -e "/sbin/${package}" ]; then
			if which ${package}; then
				ln -s "$(which ${package})" /sbin/${package}
			elif [ -e "/usr/sbin/${package}" ]; then
				ln -s /usr/sbin/${package} /sbin/${package}
			elif whereis -B /bin /sbin /usr/bin /usr/sbin -f -b ${package}; then
				autoiptables=$(whereis -B /bin /sbin /usr/bin /usr/sbin -f -b ${package} | cut -d '' -f 2)
				if [ -x "$autoiptables" ]; then
					ln -s "$autoiptables" /sbin/${package}
				fi
			fi
		fi
	done
}

# Take the firewall lock unless the caller holds it; Fail2Ban's action takes
# it with flock and sets ARCPANEL_FIREWALL_LOCK_HELD
fw_lock() {
	if [ -z "${ARCPANEL_FIREWALL_LOCK_HELD:-}" ]; then
		exec 9> "$FW_LOCK"
		check_result $? "Unable to create firewall lock file"
		flock -w 30 9
		check_result $? "Unable to acquire firewall lock (timeout after 30s)"
		export ARCPANEL_FIREWALL_LOCK_HELD=1
		FW_LOCK_OWNED=1
	fi
}

# Release the lock taken by fw_lock; a lock held by the caller stays
fw_unlock() {
	if [ -n "${FW_LOCK_OWNED:-}" ]; then
		exec 9>&-
		unset ARCPANEL_FIREWALL_LOCK_HELD FW_LOCK_OWNED
	fi
}

# 6 for an IPv6 address or network, else 4
fw_family() {
	case "$1" in
		*:*) echo 6 ;;
		*) echo 4 ;;
	esac
}

# Whether an address field means every address of both families (spec D3)
fw_is_any() {
	[ "$1" = '0.0.0.0/0' ] || [ "$1" = '::/0' ]
}

# nft expression of a PORT field: 22, 80,443, 12000-12100 or 1:65535
fw_ports() {
	local port="${1//:/-}"
	if [[ "$port" == *,* ]]; then
		echo "{ ${port//,/, } }"
	else
		echo "$port"
	fi
}

# Set of an IP list or of a Fail2Ban chain: ipset_NAME_4, f2b_CHAIN_6
fw_list_set() {
	echo "ipset_${1}_${2#v}"
}
fw_ban_set() {
	echo "f2b_${1}_${2}"
}

# Whether the table is loaded
fw_loaded() {
	"$FW_NFT" list table inet arcpanel > /dev/null 2>&1
}

# The server's global IPv6 addresses (spec D5)
fw_own_ipv6() {
	ip -6 -o addr show scope global 2> /dev/null | awk '{ split($4, a, "/"); print a[1] }' | sort -u
}

# Whether an address is one of the server's own
fw_is_own_ip() {
	[ -e "$ARCPANEL/data/ips/$1" ] && return 0
	[ "$1" = '127.0.0.1' ] || [ "$1" = '::1' ] && return 0
	fw_own_ipv6 | grep -qixF -- "$1"
}

# A set with its elements, read one per line from stdin; empty lines skipped
fw_set_block() {
	local name="$1" family="$2" elements
	elements=$(grep -v '^[[:space:]]*$' | awk 'BEGIN { ORS = "" } NR > 1 { print ",\n\t\t\t" } { print }')
	printf '\tset %s {\n\t\ttype %s\n\t\tflags interval\n\t\tauto-merge\n' "$name" "$([ "$family" = 6 ] && echo ipv6_addr || echo ipv4_addr)"
	if [ -n "$elements" ]; then
		printf '\t\telements = { %s }\n' "$elements"
	fi
	printf '\t}\n'
}

# Address list of one family for an accept line, or nothing
fw_addr_list() {
	grep -v '^[[:space:]]*$' | paste -sd ',' - | sed 's/,/, /g'
}

# Write the whole table to stdout (spec §4)
fw_render() {
	local dir="$ARCPANEL/data/firewall"
	local rules="$dir/rules.conf" chains="$dir/chains.conf" banlist="$dir/banlist.conf" ipsets="$dir/ipset.conf"
	local line name ver file family proto port ip set own4 own6 rule_line
	declare -A lists=()

	echo "table inet arcpanel"
	echo "delete table inet arcpanel"
	echo "table inet arcpanel {"

	# IP lists that are active and have their file
	if [ -f "$ipsets" ]; then
		while IFS= read -r line; do
			[ -z "$line" ] && continue
			parse_object_kv_list "$line"
			[ "$SUSPENDED" = 'no' ] || continue
			name="$LISTNAME"
			ver="${IP_VERSION#v}"
			file="$dir/ipset/${name}.v${ver}.iplist"
			[ -s "$file" ] || continue
			lists[$name]="$ver"
			fw_set_block "$(fw_list_set "$name" "$ver")" "$ver" < "$file"
		done < "$ipsets"
	fi

	# Fail2Ban: two sets and two rules per chain (spec §7)
	if [ -n "$FIREWALL_EXTENSION" ] && [ -f "$chains" ]; then
		while IFS= read -r line; do
			[ -z "$line" ] && continue
			parse_object_kv_list "$line"
			for family in 4 6; do
				if [ -f "$banlist" ]; then
					grep -F "CHAIN='$CHAIN'" "$banlist" | sed -n "s/^IP='\([^']*\)'.*/\1/p" \
						| while IFS= read -r ip; do [ "$(fw_family "$ip")" = "$family" ] && echo "$ip"; done
				fi | fw_set_block "$(fw_ban_set "$CHAIN" "$family")" "$family"
			done
		done < "$chains"
		echo "	chain fail2ban {"
		while IFS= read -r line; do
			[ -z "$line" ] && continue
			parse_object_kv_list "$line"
			proto=$(echo "$PROTOCOL" | tr '[:upper:]' '[:lower:]')
			port=$(fw_ports "$PORT")
			echo "		meta l4proto $proto th dport $port ip saddr @$(fw_ban_set "$CHAIN" 4) drop"
			echo "		meta l4proto $proto th dport $port ip6 saddr @$(fw_ban_set "$CHAIN" 6) drop"
		done < "$chains"
		echo "	}"
	fi

	echo "	chain input {"
	echo "		type filter hook input priority filter; policy drop;"
	echo "		iif \"lo\" accept"
	own4=$(ls "$ARCPANEL/data/ips" 2> /dev/null | fw_addr_list)
	own6=$(fw_own_ipv6 | fw_addr_list)
	[ -n "$own4" ] && echo "		ip saddr { $own4 } accept"
	[ -n "$own6" ] && echo "		ip6 saddr { $own6 } accept"
	echo "		ct state established,related accept"
	echo "		icmpv6 type { $FW_ICMPV6_TYPES } accept"
	echo "		ip6 saddr fe80::/10 udp sport 547 udp dport 546 accept"
	if [ -n "$FIREWALL_EXTENSION" ] && [ -f "$chains" ]; then
		echo "		jump fail2ban"
	fi

	# Rules, highest RULE first as the iptables engine appended them
	if [ -f "$rules" ]; then
		while IFS= read -r line; do
			[ -z "$line" ] && continue
			parse_object_kv_list "$line"
			[ "$SUSPENDED" = 'no' ] || continue
			case "$PROTOCOL" in
				TCP) proto="meta l4proto tcp" ;;
				UDP) proto="meta l4proto udp" ;;
				ICMP) proto="meta l4proto { icmp, ipv6-icmp }" ;;
				*) continue ;;
			esac
			port=""
			if [ "$PROTOCOL" != 'ICMP' ] && [ "$PORT" != '0' ]; then
				if [ "$PORT" = '21' ]; then
					port="th dport { 20, 21, 12000-12100 }"
				else
					port="th dport $(fw_ports "$PORT")"
				fi
			fi
			ip=""
			if [[ "$IP" =~ ^ipset: ]]; then
				name="${IP#ipset:}"
				if [ -z "${lists[$name]:-}" ]; then
					log_event "$E_NOTEXIST" "IP list ($name) of firewall rule $RULE not found or suspended"
					continue
				fi
				set=$(fw_list_set "$name" "${lists[$name]}")
				ip="$([ "${lists[$name]}" = 6 ] && echo ip6 || echo ip) saddr @$set"
			elif ! fw_is_any "$IP"; then
				ip="$([ "$(fw_family "$IP")" = 6 ] && echo ip6 || echo ip) saddr $IP"
			fi
			rule_line="$proto"
			[ -n "$port" ] && rule_line="$rule_line $port"
			[ -n "$ip" ] && rule_line="$rule_line $ip"
			echo "		$rule_line $(echo "$ACTION" | tr '[:upper:]' '[:lower:]') comment \"rule $RULE\""
		done < <(sort -r -n -k 2 -t \' "$rules")
	fi
	echo "	}"
	echo "}"

	# Custom nft commands, in the same transaction (spec §10)
	if [ -f "$dir/custom.nft" ]; then
		echo "include \"$dir/custom.nft\""
	fi
}

# Normalised JSON of the loaded table: no handles, no metainfo, no ban
# elements, which change between loads (spec §10)
fw_state_json() {
	"$FW_NFT" -j list table inet arcpanel 2> /dev/null | jq -S -c '
		walk(if type == "object" then del(.handle) else . end)
		| .nftables
		| map(select(has("metainfo") | not))
		| map(if has("set") and (.set.name | startswith("f2b_")) then .set |= del(.elem) else . end)'
}

# Check and load a rendered file; save it for the boot unit (spec §4)
fw_load() {
	local file="$1" out
	out=$("$FW_NFT" -c -f "$file" 2>&1)
	if [ $? -ne 0 ]; then
		echo "Error: nftables refused the firewall rules: $(echo "$out" | grep -m 3 -v '^\s*\^*$' | tr '\n' ' ')"
		log_event "$E_UPDATE" "$ARGUMENTS"
		exit "$E_UPDATE"
	fi
	out=$("$FW_NFT" -f "$file" 2>&1)
	if [ $? -ne 0 ]; then
		echo "Error: unable to load the firewall rules: $(echo "$out" | head -n 3 | tr '\n' ' ')"
		log_event "$E_UPDATE" "$ARGUMENTS"
		exit "$E_UPDATE"
	fi
	# The saved copy carries custom.nft itself, so boot needs no data path
	if [ -f "$ARCPANEL/data/firewall/custom.nft" ]; then
		grep -v '^include "' "$file" > "$FW_SAVED.tmp"
		cat "$ARCPANEL/data/firewall/custom.nft" >> "$FW_SAVED.tmp"
	else
		cp -f "$file" "$FW_SAVED.tmp"
	fi
	chmod 600 "$FW_SAVED.tmp"
	mv -f "$FW_SAVED.tmp" "$FW_SAVED"
	fw_state_json > "$FW_STATE.tmp" && chmod 600 "$FW_STATE.tmp" && mv -f "$FW_STATE.tmp" "$FW_STATE"
}

# Render, then load (the body of v-update-firewall)
fw_reload() {
	local tmp
	tmp=$(mktemp)
	fw_render > "$tmp"
	fw_load "$tmp"
	rm -f "$tmp"
}

# ok, drift or missing (spec §10)
fw_check_state() {
	if ! fw_loaded; then
		echo "missing"
	elif [ -f "$FW_STATE" ] && [ "$(fw_state_json)" = "$(cat "$FW_STATE")" ]; then
		echo "ok"
	else
		echo "drift"
	fi
}

# Check the entries of an IP list file with nft before it is used (spec §6)
fw_check_list() {
	local file="$1" family="$2" tmp out
	tmp=$(mktemp)
	{
		echo "table inet arcpanel_check {"
		fw_set_block "check" "$family" < "$file"
		echo "}"
	} > "$tmp"
	out=$("$FW_NFT" -c -f "$tmp" 2>&1)
	local rc=$?
	rm -f "$tmp"
	if [ "$rc" -ne 0 ]; then
		echo "$out" | grep -m 1 'Error' | sed 's/^[^ ]* //'
		return 1
	fi
}

# The boot unit (spec §5); rewritten only when its content changes
fw_install_unit() {
	local tmp
	tmp=$(mktemp)
	cat > "$tmp" << EOF
[Unit]
Description=ArcPanel firewall (nftables)
DefaultDependencies=no
Wants=network-pre.target
Before=network-pre.target fail2ban.service shutdown.target
After=local-fs.target nftables.service
Conflicts=shutdown.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$FW_NFT -f $FW_SAVED
ExecStartPost=-/bin/sh -c 'test -x $ARCPANEL/data/firewall/custom.sh && exec /bin/bash $ARCPANEL/data/firewall/custom.sh || true'

[Install]
WantedBy=multi-user.target
EOF
	if ! cmp -s "$tmp" "$FW_UNIT"; then
		install -m 644 "$tmp" "$FW_UNIT"
		systemctl -q daemon-reload
	fi
	rm -f "$tmp"
	# Enabled for the next boot; not started, which would load the file and
	# run custom.sh a second time
	systemctl -q is-enabled arcpanel-firewall 2> /dev/null || systemctl -q enable arcpanel-firewall 2> /dev/null
}

# Remove the rules, ipsets and unit of the iptables engine, once (spec §9)
fw_legacy_cleanup() {
	local chain name
	if [ ! -e "$FW_LEGACY_RULES" ] && [ ! -e "$FW_LEGACY_UNIT" ]; then
		return 0
	fi
	heal_iptables_links > /dev/null 2>&1
	if [ -x /sbin/iptables ]; then
		/sbin/iptables -P INPUT ACCEPT 2> /dev/null
		/sbin/iptables -F INPUT 2> /dev/null
		for chain in $(/sbin/iptables -S 2> /dev/null | awk '$1 == "-N" && ($2 ~ /^fail2ban-/ || $2 == "arcpanel") { print $2 }'); do
			/sbin/iptables -F "$chain" 2> /dev/null
			/sbin/iptables -X "$chain" 2> /dev/null
		done
	fi
	if command -v ipset > /dev/null 2>&1 && [ -f "$ARCPANEL/data/firewall/ipset.conf" ]; then
		for name in $(sed -n "s/^LISTNAME='\([^']*\)'.*/\1/p" "$ARCPANEL/data/firewall/ipset.conf"); do
			ipset -quiet destroy "${name}-tmp" 2> /dev/null
			ipset -quiet destroy "$name" 2> /dev/null
		done
	fi
	if [ -e "$FW_LEGACY_UNIT" ]; then
		systemctl -q disable arcpanel-iptables 2> /dev/null
		rm -f "$FW_LEGACY_UNIT"
		systemctl -q daemon-reload
	fi
	rm -f "$FW_LEGACY_RULES"
}

# Whether custom.sh still calls iptables, whose rules cannot open what the
# nftables table drops (spec D11)
fw_custom_sh_uses_iptables() {
	[ -f "$ARCPANEL/data/firewall/custom.sh" ] && grep -qE '(^|[^[:alnum:]_-])(ip6?tables)([^[:alnum:]_-]|$)' "$ARCPANEL/data/firewall/custom.sh"
}

# Whether the Fail2Ban action differs from the packaged one; a 2.2 action
# waits 30 s on the lock and bans into iptables (spec §16 H1)
fw_f2b_action_stale() {
	[ -n "$FIREWALL_EXTENSION" ] && [ -d "$(dirname "$FW_F2B_ACTION")" ] || return 1
	! cmp -s "$ARCPANEL_INSTALL_DIR/fail2ban/action.d/arcpanel.conf" "$FW_F2B_ACTION"
}

# Replace a stale Fail2Ban action and restart Fail2Ban without waiting: its
# actions take the firewall lock, which the caller may still hold
fw_sync_f2b_action() {
	fw_f2b_action_stale || return 0
	install -m 644 "$ARCPANEL_INSTALL_DIR/fail2ban/action.d/arcpanel.conf" "$FW_F2B_ACTION"
	systemctl -q --no-block try-restart fail2ban 2> /dev/null
	return 0
}

# The distribution's nftables.service flushes the whole ruleset when it
# starts, reloads or stops (spec §16 H3). It is masked while nobody uses it;
# an enabled or running unit is the administrator's and is only reported.
fw_distro_unit_in_use() {
	case "$(systemctl is-enabled nftables.service 2> /dev/null)" in
		enabled | enabled-runtime) return 0 ;;
	esac
	systemctl -q is-active nftables.service 2> /dev/null
}
fw_mask_distro_unit() {
	[ "$(systemctl is-enabled nftables.service 2> /dev/null)" = 'disabled' ] || return 0
	systemctl -q is-active nftables.service 2> /dev/null && return 0
	systemctl -q mask nftables.service 2> /dev/null
	return 0
}
fw_unmask_distro_unit() {
	[ "$(systemctl is-enabled nftables.service 2> /dev/null)" = 'masked' ] || return 0
	systemctl -q unmask nftables.service 2> /dev/null
	return 0
}

# Whether a PORT field (22, 80,443, 12000-12100, 1:65535, 0) holds a port
fw_port_covers() {
	local field="$1" port="$2" part
	[ "$field" = '0' ] && return 0
	for part in ${field//,/ }; do
		part="${part//:/-}"
		if [[ "$part" == *-* ]]; then
			[ "$port" -ge "${part%-*}" ] && [ "$port" -le "${part#*-}" ] && return 0
		elif [ "$part" = "$port" ]; then
			return 0
		fi
	done
	return 1
}

# The families (4, 6) whose sources an active TCP ACCEPT rule opens a port
# to; rule order and DROP rules are not weighed (spec §16 H2)
fw_port_families() {
	local port="$1" dir="$ARCPANEL/data/firewall" line src list
	[ -f "$dir/rules.conf" ] || return 0
	while IFS= read -r line; do
		[ -z "$line" ] && continue
		parse_object_kv_list "$line"
		[ "$SUSPENDED" = 'no' ] && [ "$ACTION" = 'ACCEPT' ] && [ "$PROTOCOL" = 'TCP' ] || continue
		fw_port_covers "$PORT" "$port" || continue
		src="$IP"
		if fw_is_any "$src"; then
			printf '4\n6\n'
		elif [[ "$src" =~ ^ipset: ]]; then
			list=$(grep -F "LISTNAME='${src#ipset:}'" "$dir/ipset.conf" 2> /dev/null | head -n 1)
			[ -n "$list" ] || continue
			parse_object_kv_list "$list"
			[ "$SUSPENDED" = 'no' ] && echo "${IP_VERSION#v}"
		else
			fw_family "$src"
		fi
	done < "$dir/rules.conf" | sort -u
}

# Whether a port is opened to IPv4 sources but to no IPv6 source
fw_port_ipv4_only() {
	local families
	families=$(fw_port_families "$1")
	grep -qx 4 <<< "$families" && ! grep -qx 6 <<< "$families"
}

# ipv6-ssh and ipv6-panel: before 2.3 IPv6 was not filtered, so a port
# opened to IPv4 sources only is closed over IPv6 since the move (spec §16 H2)
fw_ipv6_warnings() {
	local port ssh=''
	[ "$FIREWALL_SYSTEM" = 'nftables' ] || return 0
	[ -n "$(fw_own_ipv6)" ] || return 0
	for port in $("$BIN/v-list-sys-sshd-port" plain 2> /dev/null); do
		fw_port_ipv4_only "$port" && ssh=1
	done
	[ -n "$ssh" ] && echo ipv6-ssh
	fw_port_ipv4_only "${BACKEND_PORT:-8083}" && echo ipv6-panel
	return 0
}

# Warning codes of v-check-firewall, one per line (spec §16)
fw_warnings() {
	fw_f2b_action_stale && echo fail2ban-action
	fw_distro_unit_in_use && echo nftables-service
	fw_ipv6_warnings
}

# The text of a warning code, for the upgrade output and the system log
fw_warning_text() {
	case "$1" in
		fail2ban-action) echo "The Fail2Ban action /etc/fail2ban/action.d/arcpanel.conf is not the packaged one; v-update-firewall replaces it." ;;
		nftables-service) echo "nftables.service is enabled or running: starting, reloading or stopping it flushes the whole ruleset, including the ArcPanel firewall and Docker's tables. Disable it (systemctl disable nftables)." ;;
		ipv6-ssh) echo "The SSH port is open to IPv4 sources only, so it is closed over IPv6 although the server has an IPv6 address. Add a firewall rule with an IPv6 source if you connect over IPv6." ;;
		ipv6-panel) echo "The panel port is open to IPv4 sources only, so it is closed over IPv6 although the server has an IPv6 address. Add a firewall rule with an IPv6 source if you connect over IPv6." ;;
		*) echo "$1" ;;
	esac
}
