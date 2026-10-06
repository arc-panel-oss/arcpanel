#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel port registry functions (E12)                                    #
#                                                                           #
# Every port of the server range belongs to at most one user:               #
#   $ARCPANEL/data/ports/<user>.conf      the user's entries                  #
#   $ARCPANEL/data/ports/.reserved.conf   ports the registry never hands out  #
# One entry per line, ArcPanel style:                                         #
#   PORT='30001' or '30010-30019', PROTO, APP, PUBLIC, PUBLIC_OK, COMMENT,  #
#   SUSPENDED, TIME, DATE                                                   #
# A port number belongs to its user for both protocols; PROTO names the     #
# protocol an assigned port is used with (firewall rule, live check).       #
# The firewall rule of a public port is found by its comment                #
# arcpanel-port-<port>-<user>: v-delete-firewall-rule renumbers the rules,  #
# so rule ids are never stored.                                             #
# Callers source func/main.sh and func/tenancy.sh first.                    #
# See docs/features/specs/2026-10-05-004-e12-port-registry-design.md.       #
#                                                                           #
#===========================================================================#

PORTS_DIR="$ARCPANEL/data/ports"
PORTS_RESERVED="$PORTS_DIR/.reserved.conf"
PORTS_FW_TAG="arcpanel-port"

# Internal record format, one per line, "|" separated (values never contain
# "|" or quotes):
#   START|END|PROTO|APP|PUBLIC|PUBLIC_OK|FW_RULE|COMMENT|SUSPENDED|TIME|DATE
# FW_RULE is the current id of the port's firewall rule, looked up when the
# records are read; it is not written to the files.

#----------------------------------------------------------#
#                     Files and locking                    #
#----------------------------------------------------------#

ports_file() {
	echo "$PORTS_DIR/$1.conf"
}

ports_users() {
	local file name
	for file in "$PORTS_DIR"/*.conf; do
		if [ -f "$file" ]; then
			name=$(basename "$file" .conf)
			echo "$name"
		fi
	done
}

# Serialise every writer. Nested port commands (hooks, internal calls) run
# inside the caller's lock.
ports_lock() {
	if [ -n "$ARC_PORTS_LOCKED" ]; then
		return 0
	fi
	mkdir -p "$PORTS_DIR"
	chmod 750 "$PORTS_DIR"
	exec {PORTS_LOCK_FD}> "$PORTS_DIR/.lock"
	if ! flock -w 60 "$PORTS_LOCK_FD"; then
		check_result "$E_LA" "port registry is locked by another command"
	fi
	export ARC_PORTS_LOCKED=1
}

ports_now() {
	PORTS_TIME=$(date +'%T')
	PORTS_DATE=$(date +'%F')
}

#----------------------------------------------------------#
#                     Server settings                      #
#----------------------------------------------------------#

ports_range_start() {
	echo "${PORT_RANGE_START:-30000}"
}

ports_range_end() {
	echo "${PORT_RANGE_END:-39999}"
}

ports_registry_enabled() {
	[ "${PORT_REGISTRY:-yes}" != 'no' ]
}

ports_firewall_enabled() {
	[ -n "$FIREWALL_SYSTEM" ]
}

#----------------------------------------------------------#
#                     Values and records                   #
#----------------------------------------------------------#

# Parse PORT or START-END into PSTART and PEND (exit E_INVALID otherwise)
ports_parse_range() {
	local value="$1"
	if [[ "$value" =~ ^([0-9]{1,5})-([0-9]{1,5})$ ]]; then
		PSTART=$((10#${BASH_REMATCH[1]}))
		PEND=$((10#${BASH_REMATCH[2]}))
	elif [[ "$value" =~ ^[0-9]{1,5}$ ]]; then
		PSTART=$((10#$value))
		PEND=$PSTART
	else
		check_result "$E_INVALID" "invalid port or range :: $value"
	fi
	if [ "$PSTART" -lt 1 ] || [ "$PEND" -gt 65535 ] || [ "$PSTART" -gt "$PEND" ]; then
		check_result "$E_INVALID" "invalid port or range :: $value"
	fi
}

ports_check_proto() {
	case "$1" in
		tcp | udp) ;;
		*) check_result "$E_INVALID" "invalid protocol :: $1" ;;
	esac
}

ports_check_yes_no() {
	case "$1" in
		yes | no) ;;
		*) check_result "$E_INVALID" "invalid $2 value :: $1" ;;
	esac
}

ports_check_comment() {
	if [ -n "$1" ]; then
		is_comment_format_valid "$1" 'comment'
	fi
}

# APP is empty, manual, admin or <kind>:<id>
ports_check_app() {
	if ! [[ "$1" =~ ^[a-z]+(:[[:alnum:]][[:alnum:]._-]{0,252})?$ ]]; then
		check_result "$E_INVALID" "invalid app :: $1"
	fi
}

# Records of one user (or of the reserved list with "@reserved")
ports_read() {
	local file
	if [ "$1" = '@reserved' ]; then
		file="$PORTS_RESERVED"
	else
		file=$(ports_file "$1")
	fi
	if [ ! -f "$file" ]; then
		return 0
	fi
	local rules
	rules=$(ports_fw_rules_file)
	[ -f "$rules" ] || rules=/dev/null
	awk -v q="'" -v rules="$rules" -v user="$1" -v tag="$PORTS_FW_TAG" '
		function v(k,   m) {
			if (match($0, "(^| )" k "=" q "[^" q "]*" q)) {
				m = substr($0, RSTART, RLENGTH)
				sub(/^ /, "", m)
				sub("^" k "=" q, "", m)
				sub(q "$", "", m)
				return m
			}
			return ""
		}
		FILENAME == rules {
			c = v("COMMENT")
			if (index(c, tag "-") == 1 && !(c in rule)) rule[c] = v("RULE")
			next
		}
		NF {
			p = v("PORT"); s = p; e = p
			if (index(p, "-")) { s = substr(p, 1, index(p, "-") - 1); e = substr(p, index(p, "-") + 1) }
			proto = v("PROTO"); if (proto == "") proto = "tcp"
			pub = v("PUBLIC"); if (pub == "") pub = "no"
			ok = v("PUBLIC_OK"); if (ok == "") ok = "no"
			sus = v("SUSPENDED"); if (sus == "") sus = "no"
			fw = (s == e && (tag "-" s "-" user) in rule) ? rule[tag "-" s "-" user] : ""
			print s "|" e "|" proto "|" v("APP") "|" pub "|" ok "|" fw "|" v("COMMENT") "|" sus "|" v("TIME") "|" v("DATE")
		}' "$rules" "$file"
}

# Sort, merge and write records from stdin as the entries of a user
ports_write() {
	local file
	if [ "$1" = '@reserved' ]; then
		file="$PORTS_RESERVED"
	else
		file=$(ports_file "$1")
	fi
	mkdir -p "$PORTS_DIR"
	ports_merge | awk -F'|' -v q="'" '
		NF >= 11 {
			port = ($1 == $2) ? $1 : $1 "-" $2
			printf "PORT=%s%s%s PROTO=%s%s%s APP=%s%s%s PUBLIC=%s%s%s PUBLIC_OK=%s%s%s COMMENT=%s%s%s SUSPENDED=%s%s%s TIME=%s%s%s DATE=%s%s%s\n", \
				q, port, q, q, $3, q, q, $4, q, q, $5, q, q, $6, q, q, $8, q, q, $9, q, q, $10, q, q, $11, q
		}' > "$file.tmp"
	mv -f "$file.tmp" "$file"
	chmod 640 "$file"
}

# Sort by port and join adjacent unassigned records with the same flags
# (the first comment is kept)
ports_merge() {
	grep -v '^$' | sort -t'|' -k1,1n | awk -F'|' -v OFS='|' '
		function flush() { if (have) print s, e, proto, app, pub, ok, fw, cm, sus, t, d; have = 0 }
		{
			free = ($4 == "" && $5 != "yes" && $7 == "")
			if (have && pfree && free && $1 == e + 1 && $6 == ok && $9 == sus) {
				e = $2
				if (cm == "") cm = $8
				next
			}
			flush()
			s = $1; e = $2; proto = $3; app = $4; pub = $5; ok = $6; fw = $7; cm = $8; sus = $9; t = $10; d = $11
			if (free) proto = "tcp"
			have = 1; pfree = free
		}
		END { flush() }'
}

# Split records (stdin) so that START and END are record boundaries
ports_carve() {
	awk -F'|' -v OFS='|' -v S="$1" -v E="$2" '
		NF {
			s = $1 + 0; e = $2 + 0
			n = 0
			if (s < S && S <= e) cut[++n] = S
			if (s < E + 1 && E + 1 <= e) cut[++n] = E + 1
			from = s
			for (i = 1; i <= n; i++) {
				$1 = from; $2 = cut[i] - 1; print
				from = cut[i]
			}
			$1 = from; $2 = e; print
		}'
}

# Number of ports in records (stdin); FILTER: all, free, assigned, public
ports_sum() {
	awk -F'|' -v f="${1:-all}" '
		NF {
			n = $2 - $1 + 1
			if (f == "free" && $4 != "") next
			if (f == "assigned" && $4 == "") next
			if (f == "public" && $5 != "yes") next
			total += n
		}
		END { print total + 0 }'
}

ports_count() {
	ports_read "$1" | ports_sum "${2:-all}"
}

# Number of ports of [START, END] covered by records (stdin)
ports_overlap_size() {
	awk -F'|' -v S="$1" -v E="$2" -v f="${3:-all}" '
		NF {
			if (f == "free" && $4 != "") next
			a = ($1 > S) ? $1 : S
			b = ($2 < E) ? $2 : E
			if (a <= b) total += b - a + 1
		}
		END { print total + 0 }'
}

# "START END" lines that hold the N lowest free ports of records (stdin)
ports_lowest_free() {
	awk -F'|' -v n="$1" '
		NF && $4 == "" && n > 0 {
			size = $2 - $1 + 1
			take = (size < n) ? size : n
			print $1, $1 + take - 1
			n -= take
		}'
}

#----------------------------------------------------------#
#                    Server-wide queries                   #
#----------------------------------------------------------#

# Records of every user as "USER|record", the reserved list as "@reserved|…"
ports_all_records() {
	local user
	while read -r user; do
		if [ -n "$user" ]; then
			ports_read "$user" | sed "s/^/$user|/"
		fi
	done < <(ports_users)
	ports_read '@reserved' | sed 's/^/@reserved|/'
}

# First holder (user or @reserved) of a port of [START, END], except EXCEPT
ports_holder() {
	ports_all_records | awk -F'|' -v S="$1" -v E="$2" -v x="$3" '
		$1 != x && $2 <= E && $3 >= S { print $1; exit }'
}

# First port of a free contiguous block of SIZE in the server range
ports_free_block() {
	local size="$1"
	ports_all_records | awk -F'|' '{ print $2, $3 }' | sort -k1,1n \
		| awk -v size="$size" -v RS_="$(ports_range_start)" -v RE_="$(ports_range_end)" '
			BEGIN { cand = RS_ }
			{
				if ($2 < cand) next
				if ($1 > cand + size - 1) exit
				cand = $2 + 1
			}
			END { if (cand + size - 1 <= RE_) print cand }'
}

#----------------------------------------------------------#
#                      Owners and limits                   #
#----------------------------------------------------------#

# PORTS of the user's package: integer or unlimited
ports_limit() {
	local package value
	package=$(tenancy_user_value "$1" PACKAGE)
	value=$(tenancy_pkg_get "$package" PORTS)
	echo "${value:-0}"
}

# Reseller whose tree the user belongs to (the reseller itself), else empty
ports_tree_of() {
	local owner
	if tenancy_is_reseller "$1"; then
		echo "$1"
		return
	fi
	owner=$(tenancy_get_owner "$1")
	if [ -n "$owner" ] && [ "$owner" != "$ROOT_USER" ] && tenancy_is_reseller "$owner"; then
		echo "$owner"
	fi
}

# Ports held by a reseller and its customers
ports_tree_total() {
	local reseller="$1" total customer
	total=$(ports_count "$reseller")
	while read -r customer; do
		if [ -n "$customer" ]; then
			total=$((total + $(ports_count "$customer")))
		fi
	done < <(tenancy_customers "$reseller")
	echo "$total"
}

# Ports in use in a reseller's tree: its assigned ports and everything its
# customers hold. Its free pool is not counted.
ports_tree_used() {
	local reseller="$1" total customer
	total=$(ports_count "$reseller" assigned)
	while read -r customer; do
		if [ -n "$customer" ]; then
			total=$((total + $(ports_count "$customer")))
		fi
	done < <(tenancy_customers "$reseller")
	echo "$total"
}

# Exit E_LIMIT when SIZE more ports would take a reseller's tree above its
# PORTS
ports_check_tree_room() {
	local reseller="$1" size="$2" limit total
	if [ -z "$reseller" ]; then
		return 0
	fi
	limit=$(ports_limit "$reseller")
	if [ "$limit" = 'unlimited' ]; then
		return 0
	fi
	total=$(ports_tree_total "$reseller")
	if [ $((total + size)) -gt "$limit" ]; then
		check_result "$E_LIMIT" "reseller $reseller has no room for PORTS ($total of $limit allocated)"
	fi
}

# Quota check for a customer on PACKAGE in a reseller's plan (tenancy D7
# applied to ports). Prints PORTS and returns E_LIMIT when it does not fit.
ports_quota_check() {
	local reseller="$1" package="$2" customer="$3" limit new held=0 used missing
	limit=$(ports_limit "$reseller")
	if [ "$limit" = 'unlimited' ]; then
		return 0
	fi
	new=$(tenancy_pkg_get "$package" PORTS)
	new=${new:-0}
	if [ "$new" = 'unlimited' ]; then
		echo "PORTS"
		return "$E_LIMIT"
	fi
	used=$(ports_tree_used "$reseller")
	if [ -n "$customer" ] && [ -f "$ARCPANEL/data/users/$customer/user.conf" ]; then
		held=$(ports_count "$customer")
		if [ "$(tenancy_raw_owner "$customer")" = "$reseller" ]; then
			used=$((used - held))
		fi
	fi
	missing=$((new > held ? new - held : 0))
	if [ $((used + held + missing)) -gt "$limit" ]; then
		echo "PORTS"
		return "$E_LIMIT"
	fi
	return 0
}

#----------------------------------------------------------#
#                         Firewall                         #
#----------------------------------------------------------#

ports_fw_rules_file() {
	echo "$ARCPANEL/data/firewall/rules.conf"
}

ports_fw_tag() {
	echo "$PORTS_FW_TAG-$2-$1"
}

# Value of KEY in the firewall rule RULE
ports_fw_rule_value() {
	grep -m1 "^RULE='$1' " "$(ports_fw_rules_file)" 2> /dev/null | grep -o " $2='[^']*'" | cut -d"'" -f2
}

ports_fw_rule_exists() {
	[ -n "$1" ] && grep -q "^RULE='$1' " "$(ports_fw_rules_file)" 2> /dev/null
}

# Current id of the rule with COMMENT, empty when there is none
ports_fw_find() {
	grep -m1 " COMMENT='$1' " "$(ports_fw_rules_file)" 2> /dev/null | cut -d"'" -f2
}

# Open PORT/PROTO for USER unless its tagged rule exists (nothing with the
# firewall off)
ports_fw_open() {
	local user="$1" port="$2" proto="$3" tag
	if ! ports_firewall_enabled; then
		return 0
	fi
	tag=$(ports_fw_tag "$user" "$port")
	if [ -n "$(ports_fw_find "$tag")" ]; then
		return 0
	fi
	"$BIN/v-add-firewall-rule" ACCEPT 0.0.0.0/0 "$port" "$proto" "$tag" > /dev/null || return "$E_UPDATE"
}

# Delete every rule with COMMENT, looking the id up again after each delete
# (v-delete-firewall-rule renumbers the rules)
ports_fw_delete_comment() {
	local rule guard=0
	rule=$(ports_fw_find "$1")
	while [ -n "$rule" ] && [ "$guard" -lt 20 ]; do
		"$BIN/v-delete-firewall-rule" "$rule" > /dev/null 2>&1 || return 0
		rule=$(ports_fw_find "$1")
		guard=$((guard + 1))
	done
}

ports_fw_close() {
	ports_fw_delete_comment "$(ports_fw_tag "$1" "$2")"
}

# Link the existing rule RULE (on PORT) to USER's port by giving it the tag
ports_fw_adopt() {
	local user="$1" port="$2" rule="$3"
	"$BIN/v-change-firewall-rule" "$rule" "$(ports_fw_rule_value "$rule" ACTION)" \
		"$(ports_fw_rule_value "$rule" IP)" "$port" "$(ports_fw_rule_value "$rule" PROTOCOL)" \
		"$(ports_fw_tag "$user" "$port")" > /dev/null || return "$E_UPDATE"
}

#----------------------------------------------------------#
#                      Record changes                      #
#----------------------------------------------------------#

# Close the firewall rules of USER's records inside [START, END] (all with
# no range)
ports_close_rules() {
	local user="$1" start="${2:-0}" end="${3:-65535}" port
	while read -r port; do
		ports_fw_close "$user" "$port"
	done < <(ports_read "$user" | awk -F'|' -v S="$start" -v E="$end" '$7 != "" && $1 >= S && $2 <= E { print $1 }')
}

# Add free ports [START, END] to USER's records
ports_add_free() {
	local user="$1" start="$2" end="$3" public_ok="${4:-no}" comment="$5"
	ports_now
	{
		ports_read "$user"
		echo "$start|$end|tcp||no|$public_ok||$comment|no|$PORTS_TIME|$PORTS_DATE"
	} | ports_write "$user"
}

# Remove [START, END] from USER's records, printing the removed records
ports_take() {
	local user="$1" start="$2" end="$3" records
	records=$(ports_read "$user" | ports_carve "$start" "$end")
	printf '%s\n' "$records" | awk -F'|' -v S="$start" -v E="$end" 'NF && $1 >= S && $2 <= E'
	printf '%s\n' "$records" | awk -F'|' -v S="$start" -v E="$end" 'NF && !($1 >= S && $2 <= E)' | ports_write "$user"
}

# Move free ports [START, END] from one user to another; the flags of the
# entries (PUBLIC_OK, COMMENT) move with them.
ports_move_free() {
	local from="$1" start="$2" end="$3" to="$4" moved
	moved=$(ports_take "$from" "$start" "$end")
	{
		ports_read "$to"
		printf '%s\n' "$moved" | awk -F'|' -v OFS='|' 'NF { $3 = "tcp"; $4 = ""; $5 = "no"; $7 = ""; $9 = "no"; print }'
	} | ports_write "$to"
}

# Give ports [START, END] taken from a user back to its reseller, or drop
# them when the user is not in a reseller's tree
ports_return_to_owner() {
	local user="$1" start="$2" end="$3" owner moved
	owner=$(ports_tree_of "$user")
	if [ "$owner" = "$user" ]; then
		owner=''
	fi
	ports_close_rules "$user" "$start" "$end"
	if [ -n "$owner" ]; then
		ports_move_free "$user" "$start" "$end" "$owner"
	else
		moved=$(ports_take "$user" "$start" "$end")
	fi
}

# Allocate SIZE ports to USER: from its reseller's free pool for a
# customer (topping the reseller up when its tree has room), from the server
# range otherwise, with PUBLIC_OK (default no). Exits E_LIMIT when there is
# no room.
ports_allocate_pool() {
	local user="$1" size="$2" public_ok="${3:-no}" owner free first range
	if [ "$size" -le 0 ]; then
		return 0
	fi
	owner=$(ports_tree_of "$user")
	if [ -n "$owner" ] && [ "$owner" != "$user" ]; then
		free=$(ports_count "$owner" free)
		if [ "$free" -lt "$size" ]; then
			ports_allocate_pool "$owner" $((size - free))
		fi
		while read -r range; do
			if [ -n "$range" ]; then
				ports_move_free "$owner" ${range% *} ${range#* } "$user"
			fi
		done < <(ports_read "$owner" | ports_lowest_free "$size")
		return 0
	fi
	ports_check_tree_room "$owner" "$size"
	first=$(ports_free_block "$size")
	if [ -z "$first" ]; then
		check_result "$E_LIMIT" "no free block of $size ports in the server range"
	fi
	ports_add_free "$user" "$first" $((first + size - 1)) "$public_ok"
}

# Ports a user is missing to reach its package PORTS (0 for unlimited);
# for a reseller, its whole tree counts
ports_missing() {
	local user="$1" limit held
	limit=$(ports_limit "$user")
	if [ "$limit" = 'unlimited' ]; then
		echo 0
		return
	fi
	if tenancy_is_reseller "$user"; then
		held=$(ports_tree_total "$user")
	else
		held=$(ports_count "$user")
	fi
	echo $((limit > held ? limit - held : 0))
}

# Assign PORT (a free port of USER) to APP; prints the port
ports_assign() {
	local user="$1" port="$2" app="$3" proto="$4" comment="$5"
	ports_now
	ports_read "$user" | ports_carve "$port" "$port" | awk -F'|' -v OFS='|' -v p="$port" -v app="$app" \
		-v proto="$proto" -v cm="$comment" -v t="$PORTS_TIME" -v d="$PORTS_DATE" '
		$1 == p && $2 == p { $3 = proto; $4 = app; $8 = cm; $10 = t; $11 = d }
		{ print }' | ports_write "$user"
	echo "$port"
}

# Set fields of USER's single-port record PORT: FIELD=VALUE pairs where FIELD
# is the record column (5 PUBLIC, 6 PUBLIC_OK, 7 FW_RULE, 9 SUSPENDED)
ports_set_fields() {
	local user="$1" port="$2"
	shift 2
	ports_read "$user" | ports_carve "$port" "$port" | awk -F'|' -v OFS='|' -v p="$port" -v sets="$*" '
		BEGIN { n = split(sets, pairs, " ") }
		$1 == p && $2 == p {
			for (i = 1; i <= n; i++) { split(pairs[i], kv, "="); $(kv[1]) = kv[2] }
		}
		{ print }' | ports_write "$user"
}

# The single-port record PORT of USER, empty when there is none
ports_record() {
	ports_read "$1" | awk -F'|' -v p="$2" '$1 == p && $2 == p { print; exit }'
}

#----------------------------------------------------------#
#                       Live sockets                       #
#----------------------------------------------------------#

# Listening sockets: PROTO|PORT|SCOPE|UID with SCOPE local (loopback) or
# any (every other address). Root sockets have uid 0.
ports_live() {
	ss -Hltune 2> /dev/null | awk '
		{
			proto = $1; addr = $5; uid = 0
			for (i = 6; i <= NF; i++) if ($i ~ /^uid:/) { uid = substr($i, 5) }
			port = addr; sub(/.*:/, "", port)
			host = addr; sub(/:[^:]*$/, "", host)
			scope = (host ~ /^127\./ || host == "[::1]" || host ~ /^127\.0\.0\.[0-9]+%/) ? "local" : "any"
			if (port ~ /^[0-9]+$/) print proto "|" port "|" scope "|" uid
		}' | sort -u
}

ports_uid_user() {
	getent passwd "$1" | cut -d: -f1
}

# Live state of [START, END] for a uid: no, local or any
ports_listen_state() {
	local start="$1" end="$2" live="$3"
	printf '%s\n' "$live" | awk -F'|' -v S="$start" -v E="$end" '
		NF && $2 >= S && $2 <= E { if ($3 == "any") st = "any"; else if (st != "any") st = "local" }
		END { print (st == "") ? "no" : st }'
}

#----------------------------------------------------------#
#                     Reconciliation                       #
#----------------------------------------------------------#

# Findings (spec §5.4): TYPE|USER|PORT|PROTO|RULE|DETAIL
ports_findings() {
	local live records rules users_dir="$ARCPANEL/data/users" user limit used owner
	live=$(ports_live)
	records=$(ports_all_records)
	rules=$(awk -v q="'" '
		function v(k,   m) {
			if (match($0, "(^| )" k "=" q "[^" q "]*" q)) {
				m = substr($0, RSTART, RLENGTH); sub(/^ /, "", m); sub("^" k "=" q, "", m); sub(q "$", "", m); return m
			}
			return ""
		}
		NF { print v("RULE") "|" v("ACTION") "|" tolower(v("PROTOCOL")) "|" v("PORT") "|" v("COMMENT") }' \
		"$(ports_fw_rules_file)" 2> /dev/null)

	# Sockets against entries
	while IFS='|' read -r proto port scope uid; do
		[ -n "$port" ] || continue
		if [ "$port" -lt "$(ports_range_start)" ] || [ "$port" -gt "$(ports_range_end)" ]; then
			continue
		fi
		local holder
		holder=$(printf '%s\n' "$records" | awk -F'|' -v p="$port" 'NF && $2 <= p && $3 >= p { print; exit }')
		if [ -z "$holder" ]; then
			echo "orphan-socket|$(ports_uid_user "$uid")|$port|$proto||listening without an entry"
			continue
		fi
		local huser hpublic
		huser=${holder%%|*}
		[ "$huser" = '@reserved' ] && continue
		hpublic=$(echo "$holder" | cut -d'|' -f6)
		if [ "$uid" != '0' ] && [ "$uid" != "$(id -u "$huser" 2> /dev/null)" ]; then
			echo "foreign-socket|$huser|$port|$proto||used by $(ports_uid_user "$uid")"
		fi
		if [ "$scope" = 'any' ] && [ "$hpublic" != 'yes' ] && ports_firewall_enabled; then
			echo "exposed-private|$huser|$port|$proto||listens on all addresses"
		fi
	done <<< "$live"

	# Public entries against firewall rules (FW_RULE is the live id of the
	# tagged rule)
	if ports_firewall_enabled; then
		printf '%s\n' "$records" | awk -F'|' '
			NF && $1 != "@reserved" && $6 == "yes" && $10 != "yes" && $8 == "" { print $1 "|" $2 "|" $4 }' \
			| while IFS='|' read -r user port proto; do
				echo "missing-rule|$user|$port|$proto||public without a firewall rule"
			done
	fi

	# Firewall rules against entries
	local linked
	linked=$(printf '%s\n' "$records" | awk -F'|' -v tag="$PORTS_FW_TAG" '
		NF && $1 != "@reserved" && $6 == "yes" && $10 != "yes" { print tag "-" $2 "-" $1 }')
	printf '%s\n' "$rules" | while IFS='|' read -r rule action proto port comment; do
		[ -n "$rule" ] || continue
		if [[ "$comment" == "$PORTS_FW_TAG-"* ]]; then
			if ! printf '%s\n' "$linked" | grep -qxF -- "$comment"; then
				echo "stale-rule||$port|$proto|$rule|$comment"
			fi
		elif [ "$action" = 'ACCEPT' ] && [[ "$port" =~ ^[0-9]+$ ]] \
			&& [ "$port" -ge "$(ports_range_start)" ] && [ "$port" -le "$(ports_range_end)" ]; then
			echo "unmanaged-rule||$port|$proto|$rule|$comment"
		fi
	done

	# Ports of web domains whose template no longer uses them (E6)
	if [ -f "$ARCPANEL/func/arc-template.sh" ]; then
		# shellcheck source=/usr/local/arcpanel/func/arc-template.sh
		source "$ARCPANEL/func/arc-template.sh"
		arc_tpl_port_findings "$records"
	fi

	# Files of users that are gone, trees above their limit
	while read -r user; do
		[ -n "$user" ] || continue
		if [ ! -f "$users_dir/$user/user.conf" ]; then
			echo "orphan-file|$user||||no such user"
			continue
		fi
		limit=$(ports_limit "$user")
		[ "$limit" = 'unlimited' ] && continue
		if tenancy_is_reseller "$user"; then
			used=$(ports_tree_used "$user")
			if [ "$used" -gt "$limit" ]; then
				echo "over-limit|$user||||$used of $limit in use"
			fi
		else
			owner=$(ports_tree_of "$user")
			used=$(ports_count "$user")
			if [ -n "$owner" ] && [ "$used" -gt "$limit" ]; then
				echo "over-limit|$user||||$used of $limit allocated"
			fi
		fi
	done < <(ports_users)
}

#----------------------------------------------------------#
#                           JSON                           #
#----------------------------------------------------------#

ports_json_escape() {
	tenancy_json_escape "$1"
}

# JSON objects for records (stdin) with LISTEN from LIVE; USER_COL=yes when
# records start with a USER column
ports_records_json() {
	local live="$1" user_col="${2:-no}" sep='' line user s e proto app pub ok fw cm sus t d port size
	while IFS= read -r line; do
		[ -n "$line" ] || continue
		user=''
		if [ "$user_col" = 'yes' ]; then
			user=${line%%|*}
			line=${line#*|}
		fi
		IFS='|' read -r s e proto app pub ok fw cm sus t d <<< "$line"
		port=$s
		[ "$s" != "$e" ] && port="$s-$e"
		size=$((e - s + 1))
		printf '%s        {' "$sep"
		if [ "$user_col" = 'yes' ]; then
			printf '"USER": "%s", ' "$(ports_json_escape "$user")"
		fi
		printf '"PORT": "%s", "START": "%s", "END": "%s", "SIZE": "%s", "PROTO": "%s", "APP": "%s", "PUBLIC": "%s", "PUBLIC_OK": "%s", "FW_RULE": "%s", "COMMENT": "%s", "SUSPENDED": "%s", "LISTEN": "%s", "TIME": "%s", "DATE": "%s"}' \
			"$port" "$s" "$e" "$size" "$proto" "$(ports_json_escape "$app")" "$pub" "$ok" "$fw" \
			"$(ports_json_escape "$cm")" "$sus" "$(ports_listen_state "$s" "$e" "$live")" "$t" "$d"
		sep=$',\n'
	done
	[ -n "$sep" ] && echo
}

ports_findings_json() {
	local sep='' type user port proto rule detail
	while IFS='|' read -r type user port proto rule detail; do
		[ -n "$type" ] || continue
		printf '%s        {"TYPE": "%s", "USER": "%s", "PORT": "%s", "PROTO": "%s", "RULE": "%s", "DETAIL": "%s"}' \
			"$sep" "$type" "$(ports_json_escape "$user")" "$port" "$proto" "$rule" "$(ports_json_escape "$detail")"
		sep=$',\n'
	done
	[ -n "$sep" ] && echo
}
