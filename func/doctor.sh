#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: Server and Site Doctor (E17,
# docs/features/specs/2026-11-05-003-e17-doctor-design.md).
#
# A check is one file func/doctor/<scope>-<name>.sh (server, web or mail)
# that sets DOCTOR_ID, DOCTOR_SCOPE, DOCTOR_TITLE, DOCTOR_DOCS and
# DOCTOR_FIX_ALLOWED and defines doctor_check USER OBJECT. doctor_check reads
# only: it sets DR_STATUS (ok, warn, fail, skip), DR_MESSAGE, DR_VALUE,
# DR_FIX (the argv of an allow-listed fix, an array) with DR_FIX_LABEL and
# DR_LINK (a page of the panel: dns, php, updates, panel-ssl, ports). Each
# check runs in its own process (func/doctor-check.sh) under a time budget.
#
# Results: data/doctor/server.json for the server, data/users/U/doctor/
# DOMAIN.json for the web and mail checks of a domain (root 0640, group
# arcpanelweb). v-run-sys-doctor writes them, v-list-sys-doctor reads them.
#
# Test hooks, honoured from root's environment only (sudo never passes
# them): DOCTOR_FIXTURES (a folder; the sources of doctor_src read NAME from
# it, NAME.fail fails), DOCTOR_DNS_PORT (the port of the resolver),
# DOCTOR_CA_FILE (an extra trusted CA), DOCTOR_NOW (the clock, epoch),
# DOCTOR_BUDGET (seconds per check), DOCTOR_HOSTNAME.

# Sourced once
if [ -n "${DOCTOR_SH_LOADED-}" ]; then
	return 0
fi
DOCTOR_SH_LOADED=1

#----------------------------------------------------------#
#                       Locations                          #
#----------------------------------------------------------#

DOCTOR_DIR="$ARCPANEL/data/doctor"
DOCTOR_CHECK_DIR="$ARCPANEL/func/doctor"
# Seconds per check (spec §3.2) and domains checked side by side
DOCTOR_BUDGET=${DOCTOR_BUDGET:-30}
DOCTOR_PARALLEL=${DOCTOR_PARALLEL:-4}
DOCTOR_SCOPES='server web mail'

# The result file of SCOPE (system or a user) and DOMAIN
doctor_result_file() {
	if [ "$1" = 'system' ]; then
		echo "$DOCTOR_DIR/server.json"
	else
		echo "$ARCPANEL/data/users/$1/doctor/$2.json"
	fi
}

# Folder root:arcpanelweb 0750
doctor_dir_mode() {
	if getent group arcpanelweb > /dev/null 2>&1; then
		chown root:arcpanelweb "$1"
	fi
	chmod 750 "$1"
}

# File root:arcpanelweb 0640
doctor_file_mode() {
	if getent group arcpanelweb > /dev/null 2>&1; then
		chown root:arcpanelweb "$1"
	else
		chown root:root "$1"
	fi
	chmod 640 "$1"
}

doctor_now() {
	if [[ "${DOCTOR_NOW-}" =~ ^[0-9]+$ ]]; then
		echo "$DOCTOR_NOW"
	else
		date +%s
	fi
}

doctor_hostname() {
	if [ -n "${DOCTOR_HOSTNAME-}" ]; then
		echo "$DOCTOR_HOSTNAME"
	else
		hostname -f 2> /dev/null || hostname
	fi
}

#----------------------------------------------------------#
#                        Checks                            #
#----------------------------------------------------------#

# The check files of SCOPE (server, web or mail), sorted
doctor_check_files() {
	local file
	for file in "$DOCTOR_CHECK_DIR/$1"-*.sh; do
		[ -f "$file" ] && echo "$file"
	done
}

# The file of check ID (web.ssl-aliases → func/doctor/web-ssl-aliases.sh)
doctor_check_file() {
	local id="$1"
	[[ "$id" =~ ^(server|web|mail)\.[a-z0-9-]+$ ]] || return 1
	[ -f "$DOCTOR_CHECK_DIR/${id/./-}.sh" ] || return 1
	echo "$DOCTOR_CHECK_DIR/${id/./-}.sh"
}

# Print the metadata of a check file as one JSON object (id, scope, title,
# docs, fix_allowed). Runs the file's top level only.
doctor_check_meta() {
	(
		DOCTOR_ID='' DOCTOR_SCOPE='' DOCTOR_TITLE='' DOCTOR_DOCS='' DOCTOR_FIX_ALLOWED=''
		# shellcheck disable=SC1090
		source "$1" || exit 1
		jq -nc --arg id "$DOCTOR_ID" --arg scope "$DOCTOR_SCOPE" --arg title "$DOCTOR_TITLE" \
			--arg docs "$DOCTOR_DOCS" --arg fix "$DOCTOR_FIX_ALLOWED" \
			'{id: $id, scope: $scope, title: $title, docs: $docs, fix_allowed: ($fix | split(" ") | map(select(. != "")))}'
	)
}

# Results of a check, used by the check files
dr_result() {
	DR_STATUS=$1
	DR_MESSAGE=$2
	DR_VALUE=${3-}
}
dr_ok() { dr_result ok "$@"; }
dr_warn() { dr_result warn "$@"; }
dr_fail() { dr_result fail "$@"; }
dr_skip() { dr_result skip "$@"; }

# The fix of a failing check: dr_fix LABEL COMMAND ARG...
dr_fix() {
	DR_FIX_LABEL=$1
	shift
	DR_FIX=("$@")
}

# Run the check of FILE for USER and OBJECT in a process of its own with
# the time budget and print its result object. A check that does not
# finish in time, fails or prints nothing answers skip.
doctor_run_check() {
	local file="$1" user="$2" object="$3" out meta
	out=$(timeout -k 2 "$DOCTOR_BUDGET" bash "$ARCPANEL/func/doctor-check.sh" "$file" "$user" "$object" 2> /dev/null)
	if jq -e 'type == "object" and has("status")' <<< "$out" > /dev/null 2>&1; then
		jq -c . <<< "$out"
		return 0
	fi
	meta=$(doctor_check_meta "$file") || return 1
	jq -c --arg budget "$DOCTOR_BUDGET" '{id, title, docs,
		status: "skip", message: ("The check did not finish within " + $budget + " seconds."),
		value: "", fix: null, link: ""}' <<< "$meta"
}

# Objects of skipped checks: doctor_skip_all FILE... with DOCTOR_SKIP_REASON
doctor_skip_all() {
	local file
	for file in "$@"; do
		doctor_check_meta "$file" | jq -c --arg m "$DOCTOR_SKIP_REASON" \
			'{id, title, docs, status: "skip", message: $m, value: "", fix: null, link: ""}'
	done
}

#----------------------------------------------------------#
#                        Sources                           #
#----------------------------------------------------------#

# The checks read the server through this; with DOCTOR_FIXTURES they read
# the file NAME of that folder instead (NAME.fail: the source fails)
doctor_src() {
	local name="$1"
	shift
	if [ -n "${DOCTOR_FIXTURES-}" ]; then
		if [ -f "$DOCTOR_FIXTURES/$name" ]; then
			cat "$DOCTOR_FIXTURES/$name"
			return 0
		fi
		if [ -f "$DOCTOR_FIXTURES/$name.fail" ]; then
			return 1
		fi
	fi
	"$@"
}

# Whether the fixture NAME is set (a check that depends on a tool skips
# without it unless a fixture stands in)
doctor_has_fixture() {
	[ -n "${DOCTOR_FIXTURES-}" ] && { [ -f "$DOCTOR_FIXTURES/$1" ] || [ -f "$DOCTOR_FIXTURES/$1.fail" ]; }
}

# Value of KEY in a KEY='value' line
doctor_field() {
	local line="$1" key="$2"
	if [[ " $line" =~ [[:space:]]$key=\'([^\']*)\' ]]; then
		echo "${BASH_REMATCH[1]}"
	fi
}

# The line of DOMAIN in the KIND (web, mail, dns) records of USER
doctor_domain_line() {
	local user="$1" kind="$2" domain="$3"
	grep -m1 "^DOMAIN='$domain'" "$ARCPANEL/data/users/$user/$kind.conf" 2> /dev/null
}

doctor_domain_exists() {
	[ -n "$(doctor_domain_line "$1" "$2" "$3")" ]
}

# The domains of USER that have a web or a mail part, sorted
doctor_user_domains() {
	local user="$1"
	cat "$ARCPANEL/data/users/$user/web.conf" "$ARCPANEL/data/users/$user/mail.conf" 2> /dev/null \
		| grep -oE "^DOMAIN='[^']+'" | cut -d\' -f2 | sort -u
}

#----------------------------------------------------------#
#                       Addresses                          #
#----------------------------------------------------------#

doctor_is_ipv4() {
	[[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] \
		&& [ "${BASH_REMATCH[1]}" -le 255 ] && [ "${BASH_REMATCH[2]}" -le 255 ] \
		&& [ "${BASH_REMATCH[3]}" -le 255 ] && [ "${BASH_REMATCH[4]}" -le 255 ]
}

doctor_is_ipv6() {
	[[ "$1" =~ ^[0-9A-Fa-f:]+$ ]] && [[ "$1" == *:* ]]
}

# An IPv4 address as a number
doctor_ip4_num() {
	local IFS=.
	# shellcheck disable=SC2086
	set -- $1
	echo $((($1 << 24) + ($2 << 16) + ($3 << 8) + $4))
}

# Whether IPv4 ADDRESS is in CIDR (a.b.c.d/n or a single address)
doctor_ip4_in() {
	local ip="$1" cidr="$2" net bits mask
	doctor_is_ipv4 "$ip" || return 1
	net=${cidr%/*}
	bits=${cidr#*/}
	[ "$bits" = "$cidr" ] && bits=32
	doctor_is_ipv4 "$net" || return 1
	[[ "$bits" =~ ^[0-9]{1,2}$ ]] && [ "$bits" -le 32 ] || return 1
	if [ "$bits" -eq 0 ]; then
		return 0
	fi
	mask=$(((0xFFFFFFFF << (32 - bits)) & 0xFFFFFFFF))
	[ $(($(doctor_ip4_num "$ip") & mask)) -eq $(($(doctor_ip4_num "$net") & mask)) ]
}

# Private, loopback, link-local and shared ranges
doctor_ip4_private() {
	local cidr
	for cidr in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.0/8 169.254.0.0/16 100.64.0.0/10; do
		doctor_ip4_in "$1" "$cidr" && return 0
	done
	return 1
}

# Proxy ranges of known CDNs (spec §7): an A record in them is a proxy, not
# a wrong address
DOCTOR_PROXY_RANGES='173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22'

doctor_ip_is_proxy() {
	local cidr
	for cidr in $DOCTOR_PROXY_RANGES; do
		doctor_ip4_in "$1" "$cidr" && return 0
	done
	return 1
}

# The address the world uses for IP: its NAT address when IP is a local
# address with one, else IP (spec D5)
doctor_public_ip() {
	local nat
	if [ -f "$ARCPANEL/data/ips/$1" ]; then
		nat=$(grep -m1 "^NAT=" "$ARCPANEL/data/ips/$1" | cut -d\' -f2)
		if [ -n "$nat" ]; then
			echo "$nat"
			return
		fi
	fi
	echo "$1"
}

# The local address of IP (a NAT address maps back to its local address)
doctor_local_ip() {
	local file
	if [ -f "$ARCPANEL/data/ips/$1" ]; then
		echo "$1"
		return
	fi
	file=$(grep -l "^NAT='$1'" "$ARCPANEL"/data/ips/* 2> /dev/null | head -n1)
	if [ -n "$file" ]; then
		basename "$file"
	else
		echo "$1"
	fi
}

# Every address of the server, local and NAT, one per line
doctor_server_ips() {
	local file nat
	for file in "$ARCPANEL"/data/ips/*; do
		[ -f "$file" ] || continue
		basename "$file"
		nat=$(grep -m1 "^NAT=" "$file" | cut -d\' -f2)
		[ -n "$nat" ] && echo "$nat"
	done
}

# The main address of the server: the first address of data/ips/ (NAT
# address when set)
doctor_main_ip() {
	local file
	for file in "$ARCPANEL"/data/ips/*; do
		[ -f "$file" ] || continue
		doctor_public_ip "$(basename "$file")"
		return 0
	done
	return 1
}

#----------------------------------------------------------#
#                          DNS                             #
#----------------------------------------------------------#

# The resolver of the DNS checks (D5): DOCTOR_RESOLVER, 1.1.1.1 by default;
# system uses the server's resolver
doctor_resolver() {
	echo "${DOCTOR_RESOLVER:-1.1.1.1}"
}

doctor_resolver_text() {
	if [ "$(doctor_resolver)" = 'system' ]; then
		echo "the server's resolver"
	else
		echo "the resolver $(doctor_resolver)"
	fi
}

# Answers of TYPE (A, AAAA, MX, TXT, PTR, CNAME) for NAME, one per line:
# addresses, "PRIORITY HOST" for MX, the joined text of each TXT record.
# Returns 2 when the resolver did not answer. Answers are kept for the run
# in DOCTOR_CACHE.
doctor_dig() {
	local type="$1" name="$2" key out rc resolver
	local -a args=(+short +time=3 +tries=2)
	key="${DOCTOR_CACHE-}/dns-$type-${name//[^A-Za-z0-9._-]/_}"
	if [ -n "${DOCTOR_CACHE-}" ] && [ -f "$key" ]; then
		cat "$key"
		return 0
	fi
	if [ -n "${DOCTOR_CACHE-}" ] && [ -f "$key.fail" ]; then
		return 2
	fi
	if ! command -v dig > /dev/null 2>&1; then
		return 2
	fi
	resolver=$(doctor_resolver)
	[ "$resolver" != 'system' ] && args+=("@$resolver")
	[[ "${DOCTOR_DNS_PORT-}" =~ ^[0-9]{1,5}$ ]] && args+=(-p "$DOCTOR_DNS_PORT")
	if [ "$type" = 'PTR' ]; then
		out=$(dig "${args[@]}" -x "$name" 2> /dev/null)
	else
		out=$(dig "${args[@]}" "$type" "$name" 2> /dev/null)
	fi
	rc=$?
	if [ "$rc" -ne 0 ] || grep -qE '^;; (connection timed out|communications error|no servers)' <<< "$out"; then
		[ -n "${DOCTOR_CACHE-}" ] && : > "$key.fail"
		return 2
	fi
	out=$(grep -v '^;' <<< "$out" | doctor_dig_filter "$type")
	if [ -n "${DOCTOR_CACHE-}" ]; then
		printf '%s\n' "$out" | sed '/^$/d' > "$key.$$"
		mv -f "$key.$$" "$key"
	fi
	printf '%s\n' "$out" | sed '/^$/d'
	return 0
}

# Keep the lines of TYPE from dig +short (a CNAME chain prints its names)
doctor_dig_filter() {
	local line
	while IFS= read -r line; do
		case "$1" in
			A) doctor_is_ipv4 "$line" && echo "$line" ;;
			AAAA) doctor_is_ipv6 "$line" && echo "${line,,}" ;;
			MX) [[ "$line" =~ ^[0-9]+\ [^\ ]+$ ]] && echo "${line%.}" | tr 'A-Z' 'a-z' ;;
			TXT)
				[[ "$line" == \"* ]] || continue
				# "part one" "part two" → part onepart two
				line=${line#\"}
				line=${line%\"}
				echo "${line//\" \"/}"
				;;
			PTR | CNAME) [ -n "$line" ] && echo "${line%.}" | tr 'A-Z' 'a-z' ;;
		esac
	done
}

# IPv4 addresses NAME resolves to (an address is itself)
doctor_resolve_a() {
	if doctor_is_ipv4 "$1"; then
		echo "$1"
		return 0
	fi
	doctor_dig A "$1"
}

# Whether the zone of DOMAIN (or of a parent) is on this server for USER;
# prints the zone
doctor_dns_zone() {
	local user="$1" domain="$2" name="$2"
	while [[ "$name" == *.* ]]; do
		if doctor_domain_exists "$user" dns "$name"; then
			echo "$name"
			return 0
		fi
		name=${name#*.}
	done
	return 1
}

#----------------------------------------------------------#
#                     Certificates                         #
#----------------------------------------------------------#

# Extra -CAfile of root's environment (tests)
doctor_verify_args() {
	DOCTOR_VERIFY_ARGS=()
	if [ -n "${DOCTOR_CA_FILE-}" ] && [ -f "$DOCTOR_CA_FILE" ]; then
		DOCTOR_VERIFY_ARGS+=(-CAfile "$DOCTOR_CA_FILE")
	fi
	if [[ "${DOCTOR_NOW-}" =~ ^[0-9]+$ ]]; then
		DOCTOR_VERIFY_ARGS+=(-attime "$DOCTOR_NOW")
	fi
}

# The certificates served on HOST:PORT for the name SNI: sets CERT_PEM (PEM,
# leaf first) and DOCTOR_VERIFY (the verify code and text of openssl, 0 (ok)
# when the chain is trusted). Returns 2 when nothing answered.
doctor_served_certs() {
	local host="$1" port="$2" sni="$3" out
	doctor_verify_args
	out=$(timeout 10 openssl s_client -connect "$host:$port" -servername "$sni" -showcerts \
		"${DOCTOR_VERIFY_ARGS[@]}" < /dev/null 2> /dev/null)
	DOCTOR_VERIFY=$(grep -m1 -oE 'Verify return code: [0-9]+ \(.*\)' <<< "$out" | sed 's/^Verify return code: //')
	CERT_PEM=$(sed -n '/BEGIN CERTIFICATE/,/END CERTIFICATE/p' <<< "$out")
	[ -n "$CERT_PEM" ] || return 2
}

# Verify the certificate FILE (PEM, leaf first, maybe with its chain) and
# set DOCTOR_VERIFY as doctor_served_certs does
doctor_verify_file() {
	local file="$1" chain out
	doctor_verify_args
	chain=$(mktemp)
	sed -n '/BEGIN CERTIFICATE/,/END CERTIFICATE/p' "$file" > "$chain"
	if [ -n "${2-}" ] && [ -f "$2" ]; then
		cat "$2" >> "$chain"
	fi
	out=$(openssl verify "${DOCTOR_VERIFY_ARGS[@]}" -untrusted "$chain" "$file" 2>&1)
	if grep -q ': OK$' <<< "$out"; then
		DOCTOR_VERIFY='0 (ok)'
	else
		DOCTOR_VERIFY=$(grep -m1 -oE 'error [0-9]+ at [0-9]+ depth lookup: .*' <<< "$out" | sed -E 's/^error ([0-9]+) at [0-9]+ depth lookup: (.*)$/\1 (\2)/')
		DOCTOR_VERIFY=${DOCTOR_VERIFY:-1 (unknown)}
	fi
	rm -f "$chain"
}

# Fields of the first certificate of PEM text on stdin: END (epoch),
# SUBJECT, ISSUER, NAMES (DNS names, one line, space separated)
doctor_cert_fields() {
	local pem end
	pem=$(sed -n '1,/END CERTIFICATE/p')
	end=$(openssl x509 -noout -enddate <<< "$pem" 2> /dev/null | cut -d= -f2-)
	CERT_END=$(date -d "$end" +%s 2> /dev/null || echo 0)
	CERT_SUBJECT=$(openssl x509 -noout -subject -nameopt RFC2253 <<< "$pem" 2> /dev/null | sed 's/^subject=//')
	CERT_ISSUER=$(openssl x509 -noout -issuer -nameopt RFC2253 <<< "$pem" 2> /dev/null | sed 's/^issuer=//')
	CERT_NAMES=$(openssl x509 -noout -ext subjectAltName <<< "$pem" 2> /dev/null \
		| grep -oE 'DNS:[^, ]+' | cut -d: -f2 | tr 'A-Z' 'a-z' | tr '\n' ' ')
	CERT_NAMES=${CERT_NAMES% }
	if [ -z "$CERT_NAMES" ]; then
		CERT_NAMES=$(sed -n 's/.*CN=\([^,]*\).*/\1/p' <<< "$CERT_SUBJECT" | tr 'A-Z' 'a-z')
	fi
}

# Whether NAME is covered by one of the certificate names (a wildcard covers
# one label)
doctor_cert_covers() {
	local name="${1,,}" names="$2" n
	for n in $names; do
		[ "$n" = "$name" ] && return 0
		if [[ "$n" == \*.* ]] && [ "${name#*.}" = "${n#\*.}" ] && [[ "${name%%.*}" != "$name" ]]; then
			return 0
		fi
	done
	return 1
}

# Days (rounded down) until EPOCH
doctor_days_left() {
	echo $((($1 - $(doctor_now)) / 86400))
}

#----------------------------------------------------------#
#                        Results                           #
#----------------------------------------------------------#

# The jq function of the score (D7): (ok + 0.5 × warn) / (checks not
# skipped) × 100, rounded; null when every check skipped
DOCTOR_JQ_SCORE='def score: [.[] | select(.status != "skip")] as $r
	| if ($r | length) == 0 then null
	else ((([$r[] | select(.status == "ok")] | length) + 0.5 * ([$r[] | select(.status == "warn")] | length))
		/ ($r | length) * 100 | round) end;'

# Write the result FILE from check objects (JSON lines on stdin)
doctor_write_result() {
	local file="$1" tmp
	mkdir -p "$(dirname "$file")"
	doctor_dir_mode "$(dirname "$file")"
	tmp=$(mktemp "$file.XXXXXX")
	if ! jq -s --arg time "$(date -d "@$(doctor_now)" +'%F %T')" "$DOCTOR_JQ_SCORE"'
		{checked_at: $time, score: (. | score), checks: .}' > "$tmp"; then
		rm -f "$tmp"
		return 1
	fi
	doctor_file_mode "$tmp"
	mv -f "$tmp" "$file"
}

# An empty result
DOCTOR_EMPTY='{"checked_at": null, "score": null, "checks": []}'

# The result of FILE, or the empty result
doctor_read_result() {
	if [ -f "$1" ] && jq -e 'type == "object"' "$1" > /dev/null 2>&1; then
		cat "$1"
	else
		echo "$DOCTOR_EMPTY"
	fi
}

# Replace the object of one check in the result FILE (a fix runs the check
# again) and compute the score again
doctor_update_check() {
	local file="$1" check="$2" tmp
	[ -f "$file" ] || return 1
	tmp=$(mktemp "$file.XXXXXX")
	if ! jq --argjson c "$check" "$DOCTOR_JQ_SCORE"'
		.checks = ([.checks[] | if .id == $c.id then $c else . end]
			+ (if any(.checks[]; .id == $c.id) then [] else [$c] end))
		| .score = (.checks | score)' "$file" > "$tmp"; then
		rm -f "$tmp"
		return 1
	fi
	doctor_file_mode "$tmp"
	mv -f "$tmp" "$file"
}

# Forget the result of DOMAIN of USER (the domain lost its web or mail part)
doctor_forget() {
	rm -f "$(doctor_result_file "$1" "$2")"
}

#----------------------------------------------------------#
#                         Runs                             #
#----------------------------------------------------------#

# One line for the job log: SCOPE score N (F failed, W warnings)
doctor_summary_line() {
	local label="$1" file="$2"
	jq -r --arg l "$label" '"\($l): score \(.score // "-") (\([.checks[] | select(.status == "fail")] | length) failed, \([.checks[] | select(.status == "warn")] | length) warnings)"' "$file" 2> /dev/null
}

# Run the server checks and write data/doctor/server.json
doctor_run_server() {
	local file result
	result=$(doctor_result_file system)
	DOCTOR_PREVIOUS=$(doctor_read_result "$result")
	for file in $(doctor_check_files server); do
		doctor_run_check "$file" system system
	done | doctor_write_result "$result" || return 1
	doctor_summary_line server "$result"
	doctor_after_result system system "$result"
}

# Run the web and mail checks of DOMAIN of USER and write its result
doctor_run_domain() {
	local user="$1" domain="$2" line result file kind
	local -a files=()
	result=$(doctor_result_file "$user" "$domain")
	DOCTOR_PREVIOUS=$(doctor_read_result "$result")
	{
		for kind in web mail; do
			line=$(doctor_domain_line "$user" "$kind" "$domain")
			[ -n "$line" ] || continue
			mapfile -t files < <(doctor_check_files "$kind")
			if [ "$(doctor_field "$line" SUSPENDED)" = 'yes' ]; then
				DOCTOR_SKIP_REASON="The $kind domain is suspended."
				doctor_skip_all "${files[@]}"
				continue
			fi
			for file in "${files[@]}"; do
				doctor_run_check "$file" "$user" "$domain"
			done
		done
	} | doctor_write_result "$result" || return 1
	doctor_summary_line "$domain" "$result"
	doctor_after_result "$user" "$domain" "$result"
}

# Run the checks of every domain of the USERs, DOCTOR_PARALLEL domains at a
# time, and drop the results of domains that no longer exist
doctor_run_users() {
	local user domain file running=0
	for user in "$@"; do
		for file in "$ARCPANEL/data/users/$user/doctor"/*.json; do
			[ -f "$file" ] || continue
			domain=$(basename "$file" .json)
			if ! doctor_domain_exists "$user" web "$domain" && ! doctor_domain_exists "$user" mail "$domain"; then
				rm -f "$file"
			fi
		done
		for domain in $(doctor_user_domains "$user"); do
			doctor_run_domain "$user" "$domain" &
			running=$((running + 1))
			if [ "$running" -ge "$DOCTOR_PARALLEL" ]; then
				wait -n
				running=$((running - 1))
			fi
		done
	done
	wait
}

# Load the alert library for alert_event (E16); returns 1 without it
doctor_alerts_loaded() {
	declare -F alert_event > /dev/null && return 0
	[ -f "$ARCPANEL/func/alerts.sh" ] || return 1
	# shellcheck source=/usr/local/arcpanel/func/alerts.sh
	source "$ARCPANEL/func/alerts.sh" > /dev/null 2>&1 || return 1
	declare -F alert_event > /dev/null
}

# After a result FILE was written for SCOPE (system or the user) and OBJECT
# (system or the domain): every check that changed to fail since
# DOCTOR_PREVIOUS (or that fails in a first result) emits doctor.failed
# (spec D8), once per change
doctor_after_result() {
	local scope="$1" object="$2" file="$3" changed id title message label user
	changed=$(jq -r --argjson prev "${DOCTOR_PREVIOUS:-$DOCTOR_EMPTY}" '
		([$prev.checks[]? | {(.id): .status}] | add // {}) as $p
		| .checks[] | select(.status == "fail" and ($p[.id] // "") != "fail")
		| [.id, .title, .message] | @tsv' "$file" 2> /dev/null)
	[ -n "$changed" ] || return 0
	doctor_alerts_loaded || return 0
	if [ "$scope" = 'system' ]; then
		label='the server' user=system
	else
		label=$object user=$scope
	fi
	while IFS=$'\t' read -r id title message; do
		[ -n "$id" ] || continue
		echo "Alert: $id fails on $label"
		alert_event doctor.failed "$user" "doctor:$object:$id" warning \
			"Doctor: \"$title\" fails on $label. $message" "${JOB_ID-}"
	done <<< "$changed"
}

# The weekly digest (spec D8): one doctor.digest event per scope (the
# server and every user with domains) that lists the checks that are not
# ok. Scopes where everything passes send nothing.
# "1 check" or "N checks"; with a second argument, "1 check is" or "N checks are"
doctor_checks_text() {
	if [ "$1" -eq 1 ]; then
		echo "1 check${2:+ is}"
	else
		echo "$1 checks${2:+ are}"
	fi
}

doctor_digest() {
	local user domain file lines count text
	doctor_alerts_loaded || return 0
	lines=$(jq -r '.checks[]? | select(.status == "fail" or .status == "warn") | "\(.id) (\(.status))"' \
		"$(doctor_result_file system)" 2> /dev/null)
	if [ -n "$lines" ]; then
		count=$(wc -l <<< "$lines")
		text=$(paste -sd, - <<< "$lines" | sed 's/,/, /g')
		echo "Digest: the server, $(doctor_checks_text "$count")"
		alert_event doctor.digest system doctor:system info \
			"Doctor weekly digest of the server: $(doctor_checks_text "$count" verb) not ok: $text." "${JOB_ID-}"
	fi
	for user in "$@"; do
		lines=''
		for domain in $(doctor_user_domains "$user"); do
			file=$(doctor_result_file "$user" "$domain")
			[ -f "$file" ] || continue
			lines="$lines$(jq -r --arg d "$domain" '.checks[]? | select(.status == "fail" or .status == "warn") | "\($d) \(.id) (\(.status))"' "$file" 2> /dev/null)"$'\n'
		done
		lines=$(grep -v '^$' <<< "$lines")
		[ -n "$lines" ] || continue
		count=$(wc -l <<< "$lines")
		text=$(paste -sd, - <<< "$lines" | sed 's/,/, /g')
		echo "Digest: $user, $(doctor_checks_text "$count")"
		ALERT_EVENT_USER_ONLY=yes alert_event doctor.digest "$user" "doctor:$user" info \
			"Doctor weekly digest of $user: $(doctor_checks_text "$count" verb) not ok: $text." "${JOB_ID-}"
	done
}

# Whether today is the day of the digest (Monday, DOCTOR_NOW aware)
doctor_digest_day() {
	[ "$(date -d "@$(doctor_now)" +%u)" = '1' ]
}

# Print the stored result of SCOPE and DOMAIN as JSON
doctor_list_result() {
	doctor_read_result "$(doctor_result_file "$1" "${2-}")"
}

# Print the summary of the domains of the users given, one JSON array:
# user, domain, checked_at, score, fail, warn, failing (ids)
doctor_list_summary() {
	local user domain file
	for user in "$@"; do
		for domain in $(doctor_user_domains "$user"); do
			file=$(doctor_result_file "$user" "$domain")
			doctor_read_result "$file" | jq -c --arg user "$user" --arg domain "$domain" \
				'{user: $user, domain: $domain, checked_at, score,
				fail: ([.checks[] | select(.status == "fail")] | length),
				warn: ([.checks[] | select(.status == "warn")] | length),
				failing: [.checks[] | select(.status == "fail") | .id]}'
		done
	done | jq -s 'sort_by(.score // 101, .user, .domain)'
}

#----------------------------------------------------------#
#                    Domain helpers                        #
#----------------------------------------------------------#

# Output of a command kept for the run in DOCTOR_CACHE: doctor_cached NAME
# COMMAND... (with a fixture NAME it is the fixture)
doctor_cached() {
	local name="$1" file out
	shift
	file="${DOCTOR_CACHE-}/cmd-$name"
	if [ -n "${DOCTOR_CACHE-}" ] && [ -f "$file" ]; then
		cat "$file"
		return 0
	fi
	out=$(doctor_src "$name" "$@") || return 1
	if [ -n "${DOCTOR_CACHE-}" ]; then
		printf '%s\n' "$out" > "$file.$$"
		mv -f "$file.$$" "$file"
	fi
	printf '%s\n' "$out"
}

# The aliases of a web line, one per line; ALL=yes keeps wildcards
doctor_aliases() {
	local line="$1" alias
	for alias in $(doctor_field "$line" ALIAS | tr ',' ' '); do
		if [[ "$alias" == \** ]] && [ "${2-}" != 'yes' ]; then
			continue
		fi
		echo "${alias,,}"
	done
}

# The aliases a Let's Encrypt fix asks for (no wildcards), comma separated
doctor_le_aliases() {
	doctor_aliases "$1" | paste -sd, -
}

# The ports of HTTP and HTTPS of the web domains (the proxy's when there is
# one)
doctor_http_port() {
	if [ -n "${PROXY_SYSTEM-}" ]; then
		echo "${PROXY_PORT:-80}"
	else
		echo "${WEB_PORT:-80}"
	fi
}
doctor_https_port() {
	if [ -n "${PROXY_SYSTEM-}" ]; then
		echo "${PROXY_SSL_PORT:-443}"
	else
		echo "${WEB_SSL_PORT:-443}"
	fi
}

# The certificate the server serves for the web DOMAIN with the local
# address of IP: sets CERT_PEM and DOCTOR_VERIFY (kept for the run).
# Returns 2 when nothing answered.
doctor_domain_cert() {
	local domain="$1" ip="$2" key rc=0
	key="${DOCTOR_CACHE-}/cert-$domain"
	if [ -n "${DOCTOR_CACHE-}" ] && [ -f "$key.pem" ]; then
		CERT_PEM=$(cat "$key.pem")
		DOCTOR_VERIFY=$(cat "$key.verify")
		[ -n "$CERT_PEM" ] || return 2
		return 0
	fi
	doctor_served_certs "$(doctor_local_ip "$ip")" "$(doctor_https_port)" "$domain" || rc=2
	if [ -n "${DOCTOR_CACHE-}" ]; then
		printf '%s\n' "$CERT_PEM" > "$key.pem.$$"
		printf '%s\n' "$DOCTOR_VERIFY" > "$key.verify"
		mv -f "$key.pem.$$" "$key.pem"
	fi
	[ "$rc" -eq 0 ] || return 2
}

# The verify result of openssl for people: "self-signed" for 18 and 19
doctor_verify_text() {
	case "${1%% *}" in
		10) echo 'it has expired' ;;
		18 | 19) echo 'it is self-signed' ;;
		2 | 20 | 21) echo 'its chain is incomplete or its issuer is not trusted' ;;
		*) echo "${1#* }" | tr -d '()' ;;
	esac
}
