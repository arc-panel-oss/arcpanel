#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: alerts and notification channels (E16,
# docs/features/specs/2026-11-05-002-e16-alerts-design.md).
#
# Files are the record (spec §3): the server scope lives in data/alerts/
# (rules.conf, channels.conf), the scope of a user or reseller U in
# data/users/U/alerts/. The engine's own files are server-wide in
# data/alerts/: state.conf (open alerts), events.log (written only by
# alert_event), history/YYYY-MM.jsonl, outbox/ and last-run.conf. Root
# writes them under data/alerts/.lock; the panel reads them through the
# v-list-alert* commands.
#
# The engine (v-check-sys-alerts, cron every minute) evaluates the metric
# rules whose interval has passed (func/alerts/RULE.sh, one evaluator each,
# printing OBJECT VALUE LEVEL [DETAIL] lines), reads the new events, sends
# the messages due, retries the outbox and appends the history.

# Sourced once
if [ -n "${ALERTS_SH_LOADED-}" ]; then
	return 0
fi
ALERTS_SH_LOADED=1

#----------------------------------------------------------#
#                       Locations                          #
#----------------------------------------------------------#

ALERTS_DIR="$ARCPANEL/data/alerts"
ALERTS_LOG="$ARCPANEL/log/alerts.log"
ALERTS_RULE_KEYS="RULE ENABLED WARNING CRITICAL FOR SEVERITY COOLDOWN CHANNELS OPTIONS"
ALERTS_CHANNEL_KEYS="ID NAME TYPE TARGET SECRET ENABLED STATUS LAST_SENT FAILS RATE_HOUR RATE_COUNT RATE_DROPPED"
ALERTS_STATE_KEYS="ID KIND RULE SCOPE OBJECT SEVERITY VALUE DETAIL SINCE FIRED LAST_NOTIFIED COUNT CLEAR FOLDED"
# Run budget in seconds (spec §5.1)
ALERTS_BUDGET=${ALERTS_BUDGET:-50}
# Messages per channel and hour (spec §5.2)
ALERTS_RATE_MAX=${ALERTS_RATE_MAX:-30}
# Retries of a failed delivery (spec §5.3) and failures before a channel is
# disabled
ALERTS_RETRIES=3
ALERTS_DISABLE_AFTER=10
# Failed runs of a probe before the panel channel says so (spec §10)
ALERTS_PROBE_FAILS=10
# Months of history (spec §3.3)
ALERTS_HISTORY_MONTHS=12
# Events older than this are recorded without sending (spec §10)
ALERTS_EVENT_MAX_AGE=86400

# The folder of SCOPE (system or a user)
alerts_scope_dir() {
	if [ "$1" = 'system' ]; then
		echo "$ALERTS_DIR"
	else
		echo "$ARCPANEL/data/users/$1/alerts"
	fi
}

alerts_rules_file() {
	echo "$(alerts_scope_dir "$1")/rules.conf"
}

alerts_channels_file() {
	echo "$(alerts_scope_dir "$1")/channels.conf"
}

# The user whose secret store holds the channel secrets of SCOPE (spec D3)
alerts_secret_owner() {
	if [ "$1" = 'system' ]; then
		echo "${ROOT_USER:-admin}"
	else
		echo "$1"
	fi
}

# Mode 0640 (0750 for folders), owner root, group arcpanelweb when it exists
alerts_mode() {
	local mode=640
	[ -d "$1" ] && mode=750
	chown root:root "$1" 2> /dev/null
	chmod "$mode" "$1"
	if getent group arcpanelweb > /dev/null 2>&1; then
		chgrp arcpanelweb "$1" 2> /dev/null
	fi
}

alerts_dir_init() {
	local dir
	for dir in "$ALERTS_DIR" "$ALERTS_DIR/history" "$ALERTS_DIR/outbox"; do
		[ -d "$dir" ] || mkdir -p "$dir"
		alerts_mode "$dir"
	done
	# The outbox holds message texts only, root's
	chmod 700 "$ALERTS_DIR/outbox"
}

alerts_now() {
	if [[ "${ALERTS_NOW-}" =~ ^[0-9]+$ ]]; then
		echo "$ALERTS_NOW"
	else
		date +%s
	fi
}

# YYYY-MM-DD HH:MM:SS of an epoch
alerts_ts() {
	date -d "@$1" +'%F %T'
}

# Epoch of YYYY-MM-DD HH:MM:SS (0 when empty or invalid)
alerts_epoch() {
	[ -n "$1" ] && date -d "$1" +%s 2> /dev/null || echo 0
}

alerts_log() {
	echo "$(date +'%F %T') $*" >> "$ALERTS_LOG" 2> /dev/null
}

#----------------------------------------------------------#
#                     Rule catalog                         #
#----------------------------------------------------------#

declare -gA ALERT_KIND=() ALERT_SCOPES=() ALERT_INTERVAL=() ALERT_DEF_WARNING=() ALERT_DEF_CRITICAL=() \
ALERT_DEF_FOR=() ALERT_TITLE=() ALERT_UNIT=() ALERT_LEVELS=()
ALERT_TYPES=''

# alert_type_add RULE KIND SCOPES INTERVAL_MIN WARNING CRITICAL FOR TITLE UNIT
# LEVELS: KIND metric or event; SCOPES server or server,user; UNIT the unit
# of the thresholds (%, per core, days, empty); LEVELS the thresholds the
# rule takes: both, warning, below (both, lower values are worse) or none
alert_type_add() {
	ALERT_TYPES="$ALERT_TYPES $1"
	ALERT_KIND[$1]=$2
	ALERT_SCOPES[$1]=$3
	ALERT_INTERVAL[$1]=$4
	ALERT_DEF_WARNING[$1]=$5
	ALERT_DEF_CRITICAL[$1]=$6
	ALERT_DEF_FOR[$1]=$7
	ALERT_TITLE[$1]=$8
	ALERT_UNIT[$1]=$9
	ALERT_LEVELS[$1]=${10}
}

# The catalog of spec §4
alert_type_add disk.usage metric server 1 90 95 0 'Disk usage' '%' both
alert_type_add inode.usage metric server 1 90 95 0 'Inode usage' '%' both
alert_type_add load.average metric server 1 2.0 4.0 10 'Load average' 'per core' both
alert_type_add memory.usage metric server 1 90 '' 5 'Memory usage' '%' warning
alert_type_add service.down metric server 1 '' '' 0 'Service down' '' none
alert_type_add ports.orphan metric server 60 '' '' 0 'Port registry findings' '' none
alert_type_add ssl.expiring metric server,user 360 14 3 0 'Certificate expiring' 'days' below
alert_type_add quota.disk metric server,user 60 90 '' 0 'Disk quota' '%' warning
alert_type_add letsencrypt.failed event server,user 1 '' '' 0 "Let's Encrypt failed" '' none
alert_type_add backup.failed event server,user 1 '' '' 0 'Backup failed' '' none
alert_type_add job.failed event server,user 1 '' '' 0 'Job failed' '' none
alert_type_add update.result event server 1 '' '' 0 'ArcPanel update' '' none
alert_type_add doctor.failed event server,user 1 '' '' 0 'Doctor check failed' '' none
alert_type_add doctor.digest event server,user 10080 '' '' 0 'Doctor weekly digest' '' none

alert_type_valid() {
	[ -n "$1" ] && [ -n "${ALERT_KIND[$1]+x}" ]
}

# Whether RULE applies to SCOPE (system or a user)
alert_type_in_scope() {
	alert_type_valid "$1" || return 1
	if [ "$2" = 'system' ]; then
		return 0
	fi
	[[ ",${ALERT_SCOPES[$1]}," == *",user,"* ]]
}

# Rule types of SCOPE, in catalog order
alert_types_of() {
	local rule
	for rule in $ALERT_TYPES; do
		alert_type_in_scope "$rule" "$1" && echo "$rule"
	done
}

alert_severity_rank() {
	case "$1" in
		info) echo 1 ;;
		warning) echo 2 ;;
		critical) echo 3 ;;
		*) echo 0 ;;
	esac
}

#----------------------------------------------------------#
#                        Lock                              #
#----------------------------------------------------------#

ALERTS_LOCK_FD=
ALERTS_LOCK_DEPTH=0

# Hold data/alerts/.lock (nested calls count). Waits up to 120 seconds; a
# running engine holds it for at most its budget.
alerts_lock() {
	if [ "$ALERTS_LOCK_DEPTH" -gt 0 ]; then
		ALERTS_LOCK_DEPTH=$((ALERTS_LOCK_DEPTH + 1))
		return 0
	fi
	alerts_dir_init
	exec {ALERTS_LOCK_FD}> "$ALERTS_DIR/.lock"
	if ! flock -w 120 "$ALERTS_LOCK_FD"; then
		exec {ALERTS_LOCK_FD}>&-
		ALERTS_LOCK_FD=
		return 1
	fi
	ALERTS_LOCK_DEPTH=1
}

alerts_unlock() {
	if [ "$ALERTS_LOCK_DEPTH" -gt 1 ]; then
		ALERTS_LOCK_DEPTH=$((ALERTS_LOCK_DEPTH - 1))
		return 0
	fi
	if [ -n "$ALERTS_LOCK_FD" ]; then
		flock -u "$ALERTS_LOCK_FD"
		exec {ALERTS_LOCK_FD}>&-
	fi
	ALERTS_LOCK_FD=
	ALERTS_LOCK_DEPTH=0
}

# For the commands: hold the lock for the rest of the command or exit
alerts_check_lock() {
	if ! alerts_lock; then
		check_result "$E_LA" "the alert files are busy, try again"
	fi
}

#----------------------------------------------------------#
#                     Record lines                         #
#----------------------------------------------------------#

# Parse a record line into the associative array named by $2
alerts_parse() {
	local line="$1"
	local -n __alerts_out="$2"
	__alerts_out=()
	while [[ "$line" =~ ^[[:space:]]*([A-Z_]+)=\'([^\']*)\' ]]; do
		__alerts_out[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
		line=${line:${#BASH_REMATCH[0]}}
	done
}

# A record line of the KEYS (a list) from the associative array named by $2
alerts_format() {
	local keys="$1" key out=''
	local -n __alerts_in="$2"
	for key in $keys; do
		out+="$key='$(alerts_clean "${__alerts_in[$key]-}")' "
	done
	printf '%s' "${out% }"
}

# A value without quotes and line breaks
alerts_clean() {
	printf '%s' "$1" | tr -d "'\n\r"
}

# The first line of FILE that contains MATCH (a fixed string)
alerts_line_of() {
	[ -f "$1" ] || return 1
	grep -F -m1 -- "$2" "$1"
}

# Replace the line of FILE that contains MATCH with LINE (append it when
# there is none; drop it when LINE is empty). Caller holds the lock.
alerts_line_put() {
	local file="$1" match="$2" line="$3" tmp
	tmp="$file.$$"
	if [ -f "$file" ]; then
		grep -vF -- "$match" "$file" > "$tmp" || true
	else
		: > "$tmp"
	fi
	if [ -n "$line" ]; then
		printf '%s\n' "$line" >> "$tmp"
	fi
	alerts_mode "$tmp"
	mv -f "$tmp" "$file"
}

# 14-digit ID (as the job IDs, E15 D10) not used in FILE
alerts_new_id() {
	local id
	while :; do
		id="$(date +%s)$(printf '%04d' $((RANDOM % 10000)))"
		if [ -z "${1-}" ] || ! grep -qF "ID='$id'" "$1" 2> /dev/null; then
			echo "$id"
			return
		fi
	done
}

#----------------------------------------------------------#
#                         Scopes                           #
#----------------------------------------------------------#

# Exit unless SCOPE is system or an existing user
alerts_check_scope() {
	if [ "$1" = 'system' ]; then
		return 0
	fi
	if ! [[ "$1" =~ ^[A-Za-z0-9][-A-Za-z0-9_.]{0,63}$ ]] || [ ! -f "$ARCPANEL/data/users/$1/user.conf" ]; then
		check_result "$E_NOTEXIST" "scope $1 does not exist"
	fi
}

alerts_user_exists() {
	[ -f "$ARCPANEL/data/users/$1/user.conf" ]
}

alerts_user_role() {
	grep -m1 "^ROLE='" "$ARCPANEL/data/users/$1/user.conf" 2> /dev/null | cut -d"'" -f2
}

# Every user, one per line
alerts_all_users() {
	local dir
	for dir in "$ARCPANEL"/data/users/*/; do
		dir=${dir%/}
		[ -f "$dir/user.conf" ] && basename "$dir"
	done
}

# The reseller that owns USER (empty for an administrator, a reseller or a
# customer of the administrator)
alerts_reseller_of() {
	local owner
	[ "$(alerts_user_role "$1")" = 'user' ] || return 0
	owner=$(grep -m1 "^OWNER='" "$ARCPANEL/data/users/$1/tenancy.conf" 2> /dev/null | cut -d"'" -f2)
	if [ -n "$owner" ] && [ "$owner" != "${ROOT_USER:-admin}" ] && [ "$(alerts_user_role "$owner")" = 'reseller' ]; then
		echo "$owner"
	fi
}

# The users whose objects SCOPE sees: every user for system, the user and,
# for a reseller, its customers (spec §4)
alerts_scope_users() {
	local user
	if [ "$1" = 'system' ]; then
		alerts_all_users
		return
	fi
	echo "$1"
	if [ "$(alerts_user_role "$1")" = 'reseller' ]; then
		while IFS= read -r user; do
			[ "$(alerts_reseller_of "$user")" = "$1" ] && echo "$user"
		done < <(alerts_all_users)
	fi
}

# The scopes that see an object of USER: system, the user, its reseller
alerts_scopes_of_user() {
	local reseller
	echo system
	if [ -n "$1" ] && [ "$1" != 'system' ] && alerts_user_exists "$1"; then
		echo "$1"
		reseller=$(alerts_reseller_of "$1")
		[ -n "$reseller" ] && echo "$reseller"
	fi
	return 0
}

# Scopes with alert files: system and every user with an alerts folder
alerts_scopes() {
	local user
	echo system
	while IFS= read -r user; do
		[ -d "$ARCPANEL/data/users/$user/alerts" ] && echo "$user"
	done < <(alerts_all_users)
	return 0
}

# The administrators (the panel channel of the server scope)
alerts_admins() {
	local user
	while IFS= read -r user; do
		[ "$(alerts_user_role "$user")" = 'admin' ] && echo "$user"
	done < <(alerts_all_users)
	return 0
}

#----------------------------------------------------------#
#                        Channels                          #
#----------------------------------------------------------#

ALERTS_CHANNEL_TYPES='email telegram slack discord mattermost panel'

alerts_channel_type_valid() {
	[[ " $ALERTS_CHANNEL_TYPES " == *" $1 "* ]]
}

# The record line of channel ID of SCOPE
alerts_channel_line() {
	alerts_line_of "$(alerts_channels_file "$1")" "ID='$2' "
}

alerts_channel_exists() {
	[[ "$2" =~ ^[0-9]{14}$ ]] && [ -n "$(alerts_channel_line "$1" "$2")" ]
}

# Read channel ID of SCOPE into CH
declare -gA CH=()
alerts_channel_read() {
	local line
	CH=()
	line=$(alerts_channel_line "$1" "$2") || return 1
	alerts_parse "$line" CH
}

# Write CH (the channel in it) back to SCOPE. Caller holds the lock.
alerts_channel_write() {
	alerts_line_put "$(alerts_channels_file "$1")" "ID='${CH[ID]}' " "$(alerts_format "$ALERTS_CHANNEL_KEYS" CH)"
}

# Set KEY=VALUE pairs of channel ID of SCOPE. Caller holds the lock.
alerts_channel_set() {
	local scope="$1" id="$2" pair
	shift 2
	alerts_channel_read "$scope" "$id" || return 1
	for pair in "$@"; do
		CH[${pair%%=*}]=${pair#*=}
	done
	alerts_channel_write "$scope"
}

# IDs of the channels of SCOPE
alerts_channel_ids() {
	grep -oE "^ID='[0-9]{14}'" "$(alerts_channels_file "$1")" 2> /dev/null | cut -d"'" -f2
}

# ID of the panel channel of SCOPE, created when missing. Caller holds the
# lock.
alerts_panel_channel() {
	local file id
	file=$(alerts_channels_file "$1")
	id=$(grep -E "TYPE='panel'" "$file" 2> /dev/null | grep -oE "^ID='[0-9]{14}'" | head -n1 | cut -d"'" -f2)
	if [ -z "$id" ]; then
		mkdir -p "$(dirname "$file")"
		alerts_mode "$(dirname "$file")"
		id=$(alerts_new_id "$file")
		CH=([ID]=$id [NAME]=Panel [TYPE]=panel [TARGET]='' [SECRET]='' [ENABLED]=yes [STATUS]=ok [FAILS]=0)
		alerts_channel_write "$1"
	fi
	echo "$id"
}

# The secret-store name of channel ID (spec §3.2)
alerts_secret_name() {
	echo "/arcpanel/alerts/$1"
}

#----------------------------------------------------------#
#                         Rules                            #
#----------------------------------------------------------#

# Read the rule RULE of SCOPE into RL: its line, or the catalog default with
# ENABLED='no' and the scope's panel channel (spec §3.1)
declare -gA RL=()
alerts_rule_read() {
	local scope="$1" rule="$2" line
	RL=()
	line=$(alerts_line_of "$(alerts_rules_file "$scope")" "RULE='$rule' ")
	if [ -n "$line" ]; then
		alerts_parse "$line" RL
		RL[CUSTOM]=yes
		return 0
	fi
	RL=([RULE]=$rule [ENABLED]=no [WARNING]=${ALERT_DEF_WARNING[$rule]} [CRITICAL]=${ALERT_DEF_CRITICAL[$rule]}
		[FOR]=${ALERT_DEF_FOR[$rule]} [SEVERITY]='' [COOLDOWN]=1440 [CHANNELS]='' [OPTIONS]='' [CUSTOM]=no)
	if [ "${ALERT_KIND[$rule]}" = 'event' ]; then
		RL[SEVERITY]=info
	fi
	RL[CHANNELS]=$(grep -E "TYPE='panel'" "$(alerts_channels_file "$scope")" 2> /dev/null | grep -oE "^ID='[0-9]{14}'" | head -n1 | cut -d"'" -f2)
}

# Write RL to SCOPE. Caller holds the lock.
alerts_rule_write() {
	local file
	file=$(alerts_rules_file "$1")
	mkdir -p "$(dirname "$file")"
	alerts_mode "$(dirname "$file")"
	alerts_line_put "$file" "RULE='${RL[RULE]}' " "$(alerts_format "$ALERTS_RULE_KEYS" RL)"
}

# Validate the values of RL for SCOPE; sets ALERT_REASON and returns 1 when
# they are wrong. Call it without a subshell: it normalises RL.
alerts_rule_check() {
	local scope="$1" rule=${RL[RULE]} levels id
	levels=${ALERT_LEVELS[$rule]}
	if [ "${RL[ENABLED]}" != 'yes' ] && [ "${RL[ENABLED]}" != 'no' ]; then
		ALERT_REASON="ENABLED must be yes or no"
		return 1
	fi
	if [ "$levels" = 'none' ]; then
		RL[WARNING]=''
		RL[CRITICAL]=''
	else
		if ! [[ "${RL[WARNING]}" =~ ^[0-9]{1,6}(\.[0-9]{1,2})?$ ]]; then
			ALERT_REASON="invalid warning threshold :: ${RL[WARNING]}"
			return 1
		fi
		if [ "$levels" = 'warning' ] && [ -n "${RL[CRITICAL]}" ] && ! [[ "${RL[CRITICAL]}" =~ ^[0-9]{1,6}(\.[0-9]{1,2})?$ ]]; then
			ALERT_REASON="invalid critical threshold :: ${RL[CRITICAL]}"
			return 1
		fi
		if [ "$levels" != 'warning' ] && ! [[ "${RL[CRITICAL]}" =~ ^[0-9]{1,6}(\.[0-9]{1,2})?$ ]]; then
			ALERT_REASON="invalid critical threshold :: ${RL[CRITICAL]}"
			return 1
		fi
		if [ "${ALERT_UNIT[$rule]}" = '%' ]; then
			if awk -v w="${RL[WARNING]}" -v c="${RL[CRITICAL]:-0}" 'BEGIN { exit !(w < 1 || w > 100 || c > 100) }'; then
				ALERT_REASON="thresholds are percentages from 1 to 100"
				return 1
			fi
		fi
		if [ -n "${RL[CRITICAL]}" ]; then
			if [ "$levels" = 'below' ]; then
				if awk -v w="${RL[WARNING]}" -v c="${RL[CRITICAL]}" 'BEGIN { exit !(c > w) }'; then
					ALERT_REASON="the critical threshold must not be above the warning threshold"
					return 1
				fi
			elif awk -v w="${RL[WARNING]}" -v c="${RL[CRITICAL]}" 'BEGIN { exit !(c < w) }'; then
				ALERT_REASON="the critical threshold must not be below the warning threshold"
				return 1
			fi
		fi
	fi
	if [ "${ALERT_KIND[$rule]}" = 'event' ]; then
		RL[FOR]=0
		case "${RL[SEVERITY]}" in
			info | warning | critical) ;;
			*)
				ALERT_REASON="SEVERITY must be info, warning or critical"
				return 1
				;;
		esac
	else
		RL[SEVERITY]=''
		if ! [[ "${RL[FOR]}" =~ ^[0-9]{1,4}$ ]] || [ "${RL[FOR]}" -gt 1440 ]; then
			ALERT_REASON="FOR must be 0 to 1440 minutes"
			return 1
		fi
	fi
	if ! [[ "${RL[COOLDOWN]}" =~ ^[0-9]{1,5}$ ]] || [ "${RL[COOLDOWN]}" -lt 1 ] || [ "${RL[COOLDOWN]}" -gt 43200 ]; then
		ALERT_REASON="COOLDOWN must be 1 to 43200 minutes"
		return 1
	fi
	if ! [[ "${RL[CHANNELS]}" =~ ^([0-9]{14}(,[0-9]{14}){0,19})?$ ]]; then
		ALERT_REASON="invalid channel list :: ${RL[CHANNELS]}"
		return 1
	fi
	for id in ${RL[CHANNELS]//,/ }; do
		if ! alerts_channel_exists "$scope" "$id"; then
			ALERT_REASON="channel $id does not exist in scope $scope"
			return 1
		fi
	done
	if ! [[ "${RL[OPTIONS]}" =~ ^[A-Za-z0-9_./@,-]{0,255}$ ]]; then
		ALERT_REASON="invalid options :: ${RL[OPTIONS]} (a comma list of names or mount points)"
		return 1
	fi
	return 0
}

#----------------------------------------------------------#
#                        Levels                            #
#----------------------------------------------------------#

# The level of VALUE against WARNING and CRITICAL: ok, warning or critical.
# With BELOW=below, lower values are worse.
alert_level() {
	awk -v v="$1" -v w="$2" -v c="$3" -v below="${4-}" 'BEGIN {
		if (v == "" || w == "") { print "ok"; exit }
		if (below == "below") {
			if (c != "" && v + 0 <= c + 0) print "critical"
			else if (v + 0 <= w + 0) print "warning"
			else print "ok"
		} else {
			if (c != "" && v + 0 >= c + 0) print "critical"
			else if (v + 0 >= w + 0) print "warning"
			else print "ok"
		}
	}'
}

#----------------------------------------------------------#
#                     Probe sources                        #
#----------------------------------------------------------#

# The evaluators read the server through these; with ALERTS_FIXTURES (root's
# environment only: sudo never passes it) they read files of that folder
alerts_src() {
	local name="$1"
	shift
	if [ -n "${ALERTS_FIXTURES-}" ]; then
		if [ -f "$ALERTS_FIXTURES/$name" ]; then
			cat "$ALERTS_FIXTURES/$name"
			return 0
		fi
		if [ -f "$ALERTS_FIXTURES/$name.fail" ]; then
			return 1
		fi
	fi
	timeout 30 "$@"
}

#----------------------------------------------------------#
#                         Times                            #
#----------------------------------------------------------#

# Duration in seconds for people: 2 h 5 min, 3 min, 2 d 4 h
alerts_duration() {
	local s="$1" d h m
	d=$((s / 86400))
	h=$(((s % 86400) / 3600))
	m=$(((s % 3600) / 60))
	if [ "$d" -gt 0 ]; then
		echo "$d d $h h"
	elif [ "$h" -gt 0 ]; then
		echo "$h h $m min"
	else
		echo "$((m > 0 ? m : 1)) min"
	fi
}

#----------------------------------------------------------#
#                        Messages                          #
#----------------------------------------------------------#

alerts_hostname() {
	if [ -n "${ALERTS_HOSTNAME-}" ]; then
		echo "$ALERTS_HOSTNAME"
	else
		hostname -f 2> /dev/null || hostname
	fi
}

# The panel URL of OBJECT seen from SCOPE (spec §5.2)
alerts_object_url() {
	local scope="$1" object="$2" path
	case "$object" in
		service:*) path=/server/services ;;
		mount:* | system) path=/server/rrd ;;
		ports:* | ports) path=/server/ports ;;
		job:*) path="/jobs/${object#job:}" ;;
		backup-job:*) path="/server/backups/jobs/${object#backup-job:}" ;;
		backup:*) path=/backups ;;
		update:*) path=/server/updates ;;
		doctor:system | doctor:system:*) path=/server/doctor ;;
		doctor:*)
			if [ "$scope" = 'system' ]; then
				path=/server/doctor
			else
				path=/doctor
			fi
			;;
		*)
			if [ "$scope" = 'system' ]; then
				path=/server/alerts
			else
				path=/alerts
			fi
			;;
	esac
	echo "https://$(alerts_hostname):${BACKEND_PORT:-8083}$path"
}

# The value of an alert against its thresholds: 93% (warning 90%, critical 95%)
alerts_value_text() {
	local rule="$1" value="$2" warning="$3" critical="$4" unit sep=''
	unit=${ALERT_UNIT[$rule]-}
	[ -n "$value" ] || return 0
	[ "$unit" = '%' ] || sep=' '
	[ -n "$unit" ] || sep=''
	if [ -z "$warning" ]; then
		echo "$value"
		return
	fi
	if [ "$unit" = '%' ]; then
		printf '%s%% (warning %s%%%s)' "$value" "$warning" "${critical:+, critical $critical%}"
	else
		printf '%s%s%s (warning %s%s%s%s)' "$value" "$sep" "$unit" "$warning" "$sep" "$unit" "${critical:+, critical $critical$sep$unit}"
	fi
}

# Build MSG_TITLE and MSG_TEXT: KIND (fired, raised, reminded, resolved,
# event, test, summary) SCOPE RULE OBJECT SEVERITY VALUE_TEXT DETAIL SINCE
# [COUNT]
alerts_message() {
	local kind="$1" scope="$2" rule="$3" object="$4" severity="$5" value="$6" detail="$7" since="${8-}" count="${9-}"
	local host title severity_text now
	host=$(alerts_hostname)
	now=$(alerts_now)
	title=${ALERT_TITLE[$rule]:-$rule}
	severity_text=$(printf '%s' "$severity" | tr '[:lower:]' '[:upper:]')
	case "$kind" in
		resolved)
			MSG_TITLE="[RESOLVED] $title: $object on $host"
			;;
		reminded)
			MSG_TITLE="[$severity_text] $title: $object on $host (still open)"
			;;
		raised)
			MSG_TITLE="[$severity_text] $title: $object on $host (raised)"
			;;
		*)
			MSG_TITLE="[$severity_text] $title: $object on $host"
			;;
	esac
	MSG_TEXT="Server: $host"$'\n'"Severity: $severity"$'\n'"Rule: $title ($rule)"$'\n'"Object: $object"
	[ -n "$value" ] && MSG_TEXT+=$'\n'"Value: $value"
	[ -n "$detail" ] && MSG_TEXT+=$'\n'"Details: $detail"
	if [ -n "$count" ] && [ "$count" -gt 1 ] 2> /dev/null; then
		MSG_TEXT+=$'\n'"Events: $count since $(alerts_ts "$(alerts_epoch "$since")")"
	fi
	if [ "$kind" = 'resolved' ] && [ -n "$since" ]; then
		MSG_TEXT+=$'\n'"Lasted: $(alerts_duration $((now - $(alerts_epoch "$since"))))"
	elif [ "$kind" != 'event' ] && [ -n "$since" ]; then
		MSG_TEXT+=$'\n'"Since: $since"
	fi
	MSG_TEXT+=$'\n'"Time: $(alerts_ts "$now")"$'\n'"Panel: $(alerts_object_url "$scope" "$object")"
}

#----------------------------------------------------------#
#                        Delivery                          #
#----------------------------------------------------------#

# HTML of a plain text: escaped, line breaks as <br>
alerts_html() {
	printf '%s' "$1" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' | awk 'BEGIN { ORS = "" } NR > 1 { print "<br>" } { print }'
}

# Send TITLE and TEXT to the panel channel of SCOPE: the user, or every
# administrator for the server scope (spec §5.3)
alerts_send_panel() {
	local scope="$1" title="$2" text="$3" user notice rc=0 sent=0
	local -a users=()
	if [ "$scope" = 'system' ]; then
		mapfile -t users < <(alerts_admins)
	else
		users=("$scope")
	fi
	title=$(printf '%s' "${title:0:250}" | tr -d "\n\r'")
	notice="<p>$(alerts_html "$text" | tr -d "'")</p>"
	for user in "${users[@]}"; do
		[ -n "$user" ] || continue
		if "$BIN/v-add-user-notification" "$user" "$title" "${notice:0:3990}" > /dev/null 2>&1; then
			sent=$((sent + 1))
		else
			rc=1
		fi
	done
	if [ "$sent" -eq 0 ]; then
		ALERT_SEND_ERROR='no panel account to notify'
		[ "$rc" -eq 0 ] || ALERT_SEND_ERROR='the panel notification could not be written'
		return 1
	fi
	return 0
}

# Send to channel CH (already read) of SCOPE; sets ALERT_SEND_ERROR on a
# failure
alerts_send_type() {
	local scope="$1" title="$2" text="$3"
	ALERT_SEND_ERROR=''
	case "${CH[TYPE]}" in
		panel) alerts_send_panel "$scope" "$title" "$text" ;;
		email) alerts_send_email "$scope" "$title" "$text" ;;
		telegram) alerts_send_telegram "$scope" "$title" "$text" ;;
		slack | mattermost) alerts_send_webhook "$scope" "$(jq -nc --arg t "$title"$'\n'"$text" '{text: $t}')" ;;
		discord) alerts_send_webhook "$scope" "$(jq -nc --arg t "$title"$'\n'"$text" '{content: ($t | .[0:1990])}')" ;;
		*)
			ALERT_SEND_ERROR="channel type ${CH[TYPE]} cannot send"
			return 1
			;;
	esac
}

# Mail TITLE and TEXT to the addresses of CH (spec §5.3): $SENDMAIL, the
# server SMTP account when USE_SERVER_SMTP is on. The wrapper exits 0 and
# says ERROR on a failure.
alerts_send_email() {
	local title="$2" text="$3" mailer address err rc=0
	mailer=${ALERTS_SENDMAIL:-$SENDMAIL}
	if [ -z "$mailer" ] || [ -z "${CH[TARGET]}" ]; then
		ALERT_SEND_ERROR='no mailer or no address'
		return 1
	fi
	err=$(mktemp)
	for address in ${CH[TARGET]//,/ }; do
		printf '%s\n' "$text" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' \
			| timeout 60 "$mailer" -s "$title" "$address" > /dev/null 2> "$err"
		if [ "${PIPESTATUS[2]}" -ne 0 ] || grep -q 'ERROR' "$err"; then
			rc=1
			[ -n "$ALERT_SEND_ERROR" ] || ALERT_SEND_ERROR="mail to $address failed: $(grep -m1 . "$err" | sed 's/^ERROR: *//' | cut -c1-150)"
		fi
	done
	rm -f "$err"
	[ "$rc" -eq 0 ] || [ -n "$ALERT_SEND_ERROR" ] || ALERT_SEND_ERROR='mail failed'
	return "$rc"
}

# Load the secret store functions (E38) when the caller has not
alerts_secret_load() {
	if ! declare -F secret_current_value > /dev/null; then
		# shellcheck source=/usr/local/arcpanel/func/secret.sh
		source "$ARCPANEL/func/secret.sh"
	fi
}

# Put the secret value of channel CH of SCOPE into the variable named $2
# (root only; never printed). Sets ALERT_SEND_ERROR on a failure.
alerts_channel_secret_into() {
	local scope="$1" owner name __value
	local -n __alerts_secret_out="$2"
	alerts_secret_load
	owner=$(alerts_secret_owner "$scope")
	name=${CH[SECRET]}
	if [ -z "$name" ] || ! secret_store_ready || ! secret_exists "$owner" "$name"; then
		ALERT_SEND_ERROR='the token or webhook URL of the channel is missing from the secret store'
		return 1
	fi
	__value=$(secret_current_value "$owner" "$name" && printf x)
	if [ "${__value: -1}" != 'x' ]; then
		ALERT_SEND_ERROR='the secret of the channel could not be decrypted'
		return 1
	fi
	__alerts_secret_out=${__value%x}
	__alerts_secret_out=${__alerts_secret_out%$'\n'}
}

# POST the JSON BODY to URL (a secret: it goes to curl in a 0600 config
# file, never on the command line) with a 10 second limit. Sets
# ALERT_SEND_ERROR, with the URL masked, on a failure.
alerts_post() {
	local url="$1" body="$2" cfg out err code rc
	if [[ "$url" == *[[:space:]\"\\]* ]]; then
		ALERT_SEND_ERROR='the stored URL is not valid'
		return 1
	fi
	cfg=$(umask 077 && mktemp)
	out=$(mktemp)
	err=$(mktemp)
	printf 'url = "%s"\n' "$url" > "$cfg"
	code=$(printf '%s' "$body" | curl -sS --max-time 10 --proto '=https,http' -K "$cfg" -X POST \
		-H 'Content-Type: application/json' --data-binary @- -o "$out" -w '%{http_code}' 2> "$err")
	rc=$?
	rm -f "$cfg"
	if [ "$rc" -ne 0 ]; then
		ALERT_SEND_ERROR=$(head -n1 "$err" | sed -e 's/^curl: ([0-9]*) //')
		ALERT_SEND_ERROR=${ALERT_SEND_ERROR//"$url"/***}
		ALERT_SEND_ERROR="connection failed: ${ALERT_SEND_ERROR:0:150}"
	elif ! [[ "$code" =~ ^2[0-9][0-9]$ ]]; then
		ALERT_SEND_ERROR="HTTP $code"
		local description
		description=$(jq -r '.description // .message // empty' "$out" 2> /dev/null | head -n1 | tr -d '\r')
		description=${description//"$url"/***}
		[ -n "$description" ] && ALERT_SEND_ERROR+=": ${description:0:150}"
		rc=1
	fi
	rm -f "$out" "$err"
	return "$rc"
}

# Slack, Discord and Mattermost: POST BODY to the webhook URL of CH
alerts_send_webhook() {
	local scope="$1" body="$2" url=''
	alerts_channel_secret_into "$scope" url || return 1
	alerts_post "$url" "$body"
}

# Telegram: sendMessage of the bot (the token is the secret) to the chat id
# in TARGET, in HTML mode with escaped values
alerts_send_telegram() {
	local scope="$1" title="$2" text="$3" token='' html
	alerts_channel_secret_into "$scope" token || return 1
	html="<b>$(printf '%s' "$title" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g')</b>"$'\n'
	html+=$(printf '%s' "$text" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g')
	alerts_post "${ALERTS_TELEGRAM_API:-https://api.telegram.org}/bot$token/sendMessage" \
		"$(jq -nc --arg chat "${CH[TARGET]}" --arg text "${html:0:4000}" \
			'{chat_id: $chat, text: $text, parse_mode: "HTML", disable_web_page_preview: true}')"
}

#----------------------------------------------------------#
#                    Channel commands                      #
#----------------------------------------------------------#

ALERTS_CHANNELS_MAX=20

# Exit unless NAME is a channel label: 1-64 characters without quotes, $,
# `, \ or control characters
alerts_check_channel_name() {
	if [ -z "$1" ] || [ "${#1}" -gt 64 ] || [[ "$1" == *[\'\"\$\`\\]* ]] || [[ "$1" == *[[:cntrl:]]* ]]; then
		check_result "$E_INVALID" "invalid channel name (1 to 64 characters, without quotes, \$, \` or \\)"
	fi
}

# Exit unless TARGET suits a channel of TYPE
alerts_check_target() {
	local type="$1" target="$2" address n=0
	case "$type" in
		email)
			[ -n "$target" ] || check_result "$E_INVALID" "an email channel needs one to five addresses"
			for address in ${target//,/ }; do
				is_email_format_valid "$address"
				n=$((n + 1))
			done
			if [ "$n" -gt 5 ] || [[ "$target" == *' '* ]]; then
				check_result "$E_INVALID" "an email channel takes one to five addresses, separated by commas"
			fi
			;;
		telegram)
			if ! [[ "$target" =~ ^(-?[0-9]{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$ ]]; then
				check_result "$E_INVALID" "invalid Telegram chat id :: $target"
			fi
			;;
		*)
			if [ -n "$target" ]; then
				check_result "$E_INVALID" "a $type channel takes no target"
			fi
			;;
	esac
}

# Whether a channel of TYPE keeps a secret
alerts_type_has_secret() {
	case "$1" in
		telegram | slack | discord | mattermost) return 0 ;;
		*) return 1 ;;
	esac
}

# Read the secret of a channel of TYPE from FILE into ALERT_SECRET_VALUE and
# delete the file; exit when it is not usable (a bot token for Telegram, a
# webhook URL for the others)
alerts_read_secret_file() {
	local type="$1" file="$2" value
	alerts_secret_load
	if [ ! -f "$file" ] || [ -L "$file" ]; then
		check_result "$E_NOTEXIST" "file $file does not exist"
	fi
	secret_check_value_file "$file"
	value=$(secret_file_value "$file" && printf x)
	value=${value%x}
	rm -f "$file"
	case "$type" in
		telegram)
			if ! [[ "$value" =~ ^[0-9]{3,20}:[A-Za-z0-9_-]{20,100}$ ]]; then
				check_result "$E_INVALID" "the Telegram bot token is not valid (digits, a colon and the key)"
			fi
			;;
		*)
			if [ "${#value}" -gt 2048 ] || ! [[ "$value" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?/[^[:space:]\"\\]*$ ]]; then
				check_result "$E_INVALID" "the webhook URL is not valid (http or https)"
			fi
			;;
	esac
	ALERT_SECRET_VALUE=$value
}

# Store ALERT_SECRET_VALUE as the secret of channel ID of SCOPE (spec D3)
alerts_store_secret() {
	local scope="$1" id="$2" name="$3" owner secret
	alerts_secret_load
	secret_check_system
	owner=$(alerts_secret_owner "$scope")
	secret_check_user "$owner"
	secret_lock "$owner"
	secret=$(alerts_secret_name "$id")
	printf '%s' "$ALERT_SECRET_VALUE" | secret_store_version "$owner" "$secret" "Alert channel $name" > /dev/null
	check_result $? "the secret could not be stored" "$E_FORBIDEN"
	exec {SECRET_LOCK_FD}>&-
	ALERT_SECRET_VALUE=''
}

# Remove the secret of channel ID of SCOPE
alerts_remove_secret() {
	local scope="$1" id="$2" owner secret
	alerts_secret_load
	owner=$(alerts_secret_owner "$scope")
	secret=$(alerts_secret_name "$id")
	[ -d "$(secret_dir "$owner")" ] || return 0
	secret_lock "$owner"
	if secret_exists "$owner" "$secret"; then
		secret_remove "$owner" "$secret"
	fi
	exec {SECRET_LOCK_FD}>&-
}

# Log a channel change in the history log of SCOPE's user (system: the
# activity log)
alerts_log_action() {
	if [ "$1" = 'system' ]; then
		"$BIN/v-log-action" system Info Alerts "$2"
	else
		"$BIN/v-log-action" "$1" Info Alerts "$2"
	fi
}

# Deliveries of the last alerts_notify call, a JSON array
ALERT_DELIVERIES='[]'

# Add one delivery to ALERT_DELIVERIES: CHANNEL NAME TYPE RESULT ERROR
alerts_delivery_add() {
	ALERT_DELIVERIES=$(jq -c --arg c "$1" --arg n "$2" --arg t "$3" --arg r "$4" --arg e "$5" \
		'. + [{channel: $c, name: $n, type: $t, result: $r, error: $e}]' <<< "$ALERT_DELIVERIES")
}

# Record the result of a send on channel CH of SCOPE: STATUS, LAST_SENT and
# FAILS; the tenth failure in a row disables the channel and says so on the
# panel channel (spec §5.3). Caller holds the lock.
alerts_channel_result() {
	local scope="$1" rc="$2" error="$3" fails panel
	if [ "$rc" -eq 0 ]; then
		CH[STATUS]=ok
		CH[FAILS]=0
		CH[LAST_SENT]=$(alerts_ts "$(alerts_now)")
		alerts_channel_write "$scope"
		return 0
	fi
	fails=$((${CH[FAILS]:-0} + 1))
	CH[FAILS]=$fails
	CH[STATUS]=${error:0:200}
	if [ "$fails" -ge "$ALERTS_DISABLE_AFTER" ] && [ "${CH[TYPE]}" != 'panel' ]; then
		CH[ENABLED]=no
		alerts_channel_write "$scope"
		alerts_log "channel ${CH[ID]} of $scope disabled after $fails failures: $error"
		local name=${CH[NAME]} type=${CH[TYPE]}
		panel=$(alerts_panel_channel "$scope")
		alerts_channel_read "$scope" "$panel" && alerts_send_type "$scope" "Alert channel $name disabled" \
			"The $type channel $name was disabled after $fails failed deliveries in a row. Last error: $error. Fix it and turn it on again on the Alerts page."
		return 0
	fi
	alerts_channel_write "$scope"
}

# Rate limit (spec §5.2): whether channel CH may send one more message this
# hour. A message over the limit counts in RATE_DROPPED; the first send of a
# new hour first says how many were dropped. Caller holds the lock.
alerts_rate_allow() {
	local scope="$1" hour dropped
	hour=$(date -d "@$(alerts_now)" +'%F %H')
	if [ "${CH[RATE_HOUR]-}" != "$hour" ]; then
		dropped=${CH[RATE_DROPPED]:-0}
		CH[RATE_HOUR]=$hour
		CH[RATE_COUNT]=0
		CH[RATE_DROPPED]=0
		alerts_channel_write "$scope"
		if [ "$dropped" -gt 0 ] 2> /dev/null; then
			if alerts_send_type "$scope" "$dropped more alerts on $(alerts_hostname)" \
				"$dropped more alerts were not sent in the last hour: at most $ALERTS_RATE_MAX messages per hour go to a channel. See the Alerts page for all of them."; then
				CH[RATE_COUNT]=1
				alerts_channel_write "$scope"
			fi
		fi
	fi
	if [ "${CH[RATE_COUNT]:-0}" -ge "$ALERTS_RATE_MAX" ]; then
		CH[RATE_DROPPED]=$((${CH[RATE_DROPPED]:-0} + 1))
		alerts_channel_write "$scope"
		return 1
	fi
	CH[RATE_COUNT]=$((${CH[RATE_COUNT]:-0} + 1))
	alerts_channel_write "$scope"
	return 0
}

# Send TITLE and TEXT to the channels CHANNELS (comma list) of SCOPE for
# alert ALERT_ID; fills ALERT_DELIVERIES. A failed delivery goes to the
# outbox. Caller holds the lock.
alerts_notify() {
	local scope="$1" channels="$2" title="$3" text="$4" alert="${5-}" id rc name type error
	ALERT_DELIVERIES='[]'
	for id in ${channels//,/ }; do
		if ! alerts_channel_read "$scope" "$id"; then
			alerts_delivery_add "$id" '' '' skipped 'channel does not exist'
			continue
		fi
		if [ "${CH[ENABLED]}" != 'yes' ]; then
			alerts_delivery_add "$id" "${CH[NAME]}" "${CH[TYPE]}" skipped 'channel disabled'
			continue
		fi
		if ! alerts_rate_allow "$scope"; then
			alerts_delivery_add "$id" "${CH[NAME]}" "${CH[TYPE]}" limited 'rate limit of the channel reached'
			continue
		fi
		name=${CH[NAME]}
		type=${CH[TYPE]}
		alerts_send_type "$scope" "$title" "$text"
		rc=$?
		error=$ALERT_SEND_ERROR
		alerts_channel_read "$scope" "$id"
		alerts_channel_result "$scope" "$rc" "$error"
		if [ "$rc" -eq 0 ]; then
			alerts_delivery_add "$id" "$name" "$type" sent ''
		else
			alerts_outbox_add "$scope" "$id" "$title" "$text" "$alert"
			alerts_delivery_add "$id" "$name" "$type" failed "$error"
		fi
	done
}

#----------------------------------------------------------#
#                         Outbox                           #
#----------------------------------------------------------#

# Keep a failed delivery for the next runs (spec §5.3)
alerts_outbox_add() {
	local scope="$1" channel="$2" title="$3" text="$4" alert="$5" file
	alerts_dir_init
	file="$ALERTS_DIR/outbox/$(alerts_now)-$channel-$RANDOM.json"
	(umask 077 && jq -nc --arg s "$scope" --arg c "$channel" --arg t "$title" --arg x "$text" --arg a "$alert" \
		'{scope: $s, channel: $c, title: $t, text: $x, alert: $a, attempts: 0}' > "$file")
}

# Retry every delivery of the outbox once; after ALERTS_RETRIES failed
# retries it is dropped and the history says so. Caller holds the lock.
alerts_outbox_retry() {
	local file scope channel title text alert attempts rc name type error
	for file in "$ALERTS_DIR"/outbox/*.json; do
		[ -f "$file" ] || continue
		scope=$(jq -r .scope "$file")
		channel=$(jq -r .channel "$file")
		title=$(jq -r .title "$file")
		text=$(jq -r .text "$file")
		alert=$(jq -r .alert "$file")
		attempts=$(($(jq -r '.attempts // 0' "$file") + 1))
		ALERT_DELIVERIES='[]'
		if [ "$scope" != 'system' ] && ! alerts_user_exists "$scope"; then
			rm -f "$file"
			continue
		fi
		if ! alerts_channel_read "$scope" "$channel" || [ "${CH[ENABLED]}" != 'yes' ]; then
			alerts_delivery_add "$channel" "${CH[NAME]-}" "${CH[TYPE]-}" dropped 'channel disabled or deleted'
			alerts_history_add delivery "$alert" "$scope" '' '' '' '' "$title" ''
			rm -f "$file"
			continue
		fi
		name=${CH[NAME]}
		type=${CH[TYPE]}
		alerts_send_type "$scope" "$title" "$text"
		rc=$?
		error=$ALERT_SEND_ERROR
		alerts_channel_read "$scope" "$channel"
		alerts_channel_result "$scope" "$rc" "$error"
		if [ "$rc" -eq 0 ]; then
			alerts_delivery_add "$channel" "$name" "$type" sent ''
			alerts_history_add delivery "$alert" "$scope" '' '' '' '' "$title" ''
			rm -f "$file"
		elif [ "$attempts" -ge "$ALERTS_RETRIES" ]; then
			alerts_delivery_add "$channel" "$name" "$type" dropped "$error"
			alerts_history_add delivery "$alert" "$scope" '' '' '' '' "$title" ''
			rm -f "$file"
		else
			jq -c --argjson n "$attempts" '.attempts = $n' "$file" > "$file.tmp" && mv -f "$file.tmp" "$file"
		fi
	done
}

#----------------------------------------------------------#
#                        History                           #
#----------------------------------------------------------#

# Append one history line (spec §3.3): EVENT (fired, raised, reminded,
# resolved, closed, event, delivery, test) ID SCOPE RULE OBJECT SEVERITY
# VALUE MESSAGE SINCE [COUNT], with ALERT_DELIVERIES
alerts_history_add() {
	local event="$1" id="$2" scope="$3" rule="$4" object="$5" severity="$6" value="$7" message="$8" since="$9" count="${10-}"
	local now file user='' owner=''
	now=$(alerts_now)
	alerts_dir_init
	file="$ALERTS_DIR/history/$(date -d "@$now" +%Y-%m).jsonl"
	if [ "$scope" != 'system' ]; then
		user=$scope
		owner=$(alerts_reseller_of "$scope")
		[ -n "$owner" ] || owner=${ROOT_USER:-admin}
	fi
	jq -nc --arg time "$(alerts_ts "$now")" --arg event "$event" --arg id "$id" --arg scope "$scope" --arg user "$user" \
		--arg owner "$owner" --arg rule "$rule" --arg object "$object" --arg severity "$severity" --arg value "$value" \
		--arg message "$message" --arg since "$since" --arg count "$count" --argjson deliveries "${ALERT_DELIVERIES:-[]}" \
		'{time: $time, event: $event, id: $id, scope: $scope, user: $user, owner: $owner, rule: $rule, object: $object,
		severity: $severity, value: $value, message: $message, since: $since, count: ($count | tonumber? // 0),
		deliveries: $deliveries}' >> "$file"
	alerts_mode "$file"
}

# Delete history files older than ALERTS_HISTORY_MONTHS months
alerts_history_prune() {
	local file month cutoff
	cutoff=$(date -d "$(date +%Y-%m-01) -$ALERTS_HISTORY_MONTHS months" +%Y-%m)
	for file in "$ALERTS_DIR"/history/*.jsonl; do
		[ -f "$file" ] || continue
		month=$(basename "$file" .jsonl)
		if [[ "$month" < "$cutoff" ]]; then
			rm -f "$file"
		fi
	done
}

#----------------------------------------------------------#
#                          State                           #
#----------------------------------------------------------#

ALERTS_STATE="$ALERTS_DIR/state.conf"

alerts_state_match() {
	printf " RULE='%s' SCOPE='%s' OBJECT='%s' " "$1" "$2" "$3"
}

# Read the state of RULE SCOPE OBJECT into ST; returns 1 when none
declare -gA ST=()
alerts_state_read() {
	local line
	ST=()
	line=$(alerts_line_of "$ALERTS_STATE" "$(alerts_state_match "$1" "$2" "$3")") || return 1
	[ -n "$line" ] || return 1
	alerts_parse "$line" ST
}

alerts_state_write() {
	alerts_line_put "$ALERTS_STATE" "$(alerts_state_match "${ST[RULE]}" "${ST[SCOPE]}" "${ST[OBJECT]}")" \
		"$(alerts_format "$ALERTS_STATE_KEYS" ST)"
}

alerts_state_delete() {
	alerts_line_put "$ALERTS_STATE" "$(alerts_state_match "$1" "$2" "$3")" ''
}

# Objects with a state line of RULE in SCOPE
alerts_state_objects() {
	[ -f "$ALERTS_STATE" ] || return 0
	grep -F " RULE='$1' SCOPE='$2' " "$ALERTS_STATE" | grep -oE " OBJECT='[^']*'" | cut -d"'" -f2
}

#----------------------------------------------------------#
#                     Metric alerts                        #
#----------------------------------------------------------#

# Fire, raise, remind or resolve the alert of one evaluated object (spec
# §4): SCOPE RULE OBJECT VALUE LEVEL DETAIL, with RL read. Caller holds the
# lock.
alerts_metric_apply() {
	local scope="$1" rule="$2" object="$3" value="$4" level="$5" detail="$6"
	local now nowts value_text since cooldown
	now=$(alerts_now)
	nowts=$(alerts_ts "$now")
	value_text=$(alerts_value_text "$rule" "$value" "${RL[WARNING]}" "${RL[CRITICAL]}")
	cooldown=$((${RL[COOLDOWN]:-1440} * 60))
	if [ "$level" = 'ok' ]; then
		alerts_state_read "$rule" "$scope" "$object" || return 0
		if [ -z "${ST[FIRED]}" ]; then
			# The condition cleared before FOR was reached
			alerts_state_delete "$rule" "$scope" "$object"
			return 0
		fi
		ST[CLEAR]=$((${ST[CLEAR]:-0} + 1))
		ST[VALUE]=$value_text
		if [ "${ST[CLEAR]}" -lt 2 ]; then
			alerts_state_write
			return 0
		fi
		alerts_message resolved "$scope" "$rule" "$object" "${ST[SEVERITY]}" "$value_text" "$detail" "${ST[FIRED]}"
		alerts_notify "$scope" "${RL[CHANNELS]}" "$MSG_TITLE" "$MSG_TEXT" "${ST[ID]}"
		alerts_history_add resolved "${ST[ID]}" "$scope" "$rule" "$object" "${ST[SEVERITY]}" "$value_text" "$MSG_TITLE" "${ST[FIRED]}" "${ST[COUNT]}"
		alerts_state_delete "$rule" "$scope" "$object"
		return 0
	fi
	if ! alerts_state_read "$rule" "$scope" "$object"; then
		ST=([ID]=$(alerts_new_id "$ALERTS_STATE") [KIND]=metric [RULE]=$rule [SCOPE]=$scope [OBJECT]=$object
		[SEVERITY]=$level [VALUE]=$value_text [DETAIL]=$detail [SINCE]=$nowts [FIRED]='' [LAST_NOTIFIED]=''
		[COUNT]=0 [CLEAR]=0 [FOLDED]=0)
	fi
	ST[CLEAR]=0
	ST[VALUE]=$value_text
	ST[DETAIL]=$detail
	if [ -z "${ST[FIRED]}" ]; then
		ST[SEVERITY]=$level
		since=$(alerts_epoch "${ST[SINCE]}")
		if [ $((now - since)) -lt $((${RL[FOR]:-0} * 60)) ]; then
			alerts_state_write
			return 0
		fi
		alerts_message fired "$scope" "$rule" "$object" "$level" "$value_text" "$detail" "${ST[SINCE]}"
		alerts_notify "$scope" "${RL[CHANNELS]}" "$MSG_TITLE" "$MSG_TEXT" "${ST[ID]}"
		ST[FIRED]=$nowts
		ST[LAST_NOTIFIED]=$nowts
		ST[COUNT]=1
		alerts_state_write
		alerts_history_add fired "${ST[ID]}" "$scope" "$rule" "$object" "$level" "$value_text" "$MSG_TITLE" "${ST[FIRED]}" 1
		return 0
	fi
	if [ "$(alert_severity_rank "$level")" -gt "$(alert_severity_rank "${ST[SEVERITY]}")" ]; then
		ST[SEVERITY]=$level
		alerts_message raised "$scope" "$rule" "$object" "$level" "$value_text" "$detail" "${ST[FIRED]}"
		alerts_notify "$scope" "${RL[CHANNELS]}" "$MSG_TITLE" "$MSG_TEXT" "${ST[ID]}"
		ST[LAST_NOTIFIED]=$nowts
		ST[COUNT]=$((${ST[COUNT]:-0} + 1))
		alerts_state_write
		alerts_history_add raised "${ST[ID]}" "$scope" "$rule" "$object" "$level" "$value_text" "$MSG_TITLE" "${ST[FIRED]}" "${ST[COUNT]}"
		return 0
	fi
	# A lower level keeps the alert open at the new level, without a message
	ST[SEVERITY]=$level
	if [ $((now - $(alerts_epoch "${ST[LAST_NOTIFIED]}"))) -ge "$cooldown" ]; then
		alerts_message reminded "$scope" "$rule" "$object" "$level" "$value_text" "$detail" "${ST[FIRED]}"
		alerts_notify "$scope" "${RL[CHANNELS]}" "$MSG_TITLE" "$MSG_TEXT" "${ST[ID]}"
		ST[LAST_NOTIFIED]=$nowts
		ST[COUNT]=$((${ST[COUNT]:-0} + 1))
		alerts_state_write
		alerts_history_add reminded "${ST[ID]}" "$scope" "$rule" "$object" "$level" "$value_text" "$MSG_TITLE" "${ST[FIRED]}" "${ST[COUNT]}"
		return 0
	fi
	alerts_state_write
}

# Close the alert of RULE SCOPE OBJECT without a message (its object is gone
# or its rule was turned off, spec §10). Caller holds the lock.
alerts_close() {
	local rule="$1" scope="$2" object="$3"
	alerts_state_read "$rule" "$scope" "$object" || return 0
	if [ -n "${ST[FIRED]}" ]; then
		ALERT_DELIVERIES='[]'
		alerts_history_add closed "${ST[ID]}" "$scope" "$rule" "$object" "${ST[SEVERITY]}" "${ST[VALUE]}" \
			"${ALERT_TITLE[$rule]:-$rule}: $object closed" "${ST[FIRED]}" "${ST[COUNT]}"
	fi
	alerts_state_delete "$rule" "$scope" "$object"
}

# Run the evaluator of RULE for SCOPE (RL read); prints its lines. Returns
# non-zero when the probe failed.
alerts_evaluate() {
	local scope="$1" rule="$2" file func
	file="$ARCPANEL/func/alerts/$rule.sh"
	func="alert_eval_${rule//./_}"
	[ -f "$file" ] || return 1
	(
		# shellcheck disable=SC1090
		source "$file" || exit 1
		declare -F "$func" > /dev/null || exit 1
		AE_SCOPE=$scope
		AE_WARNING=${RL[WARNING]}
		AE_CRITICAL=${RL[CRITICAL]}
		AE_OPTIONS=${RL[OPTIONS]}
		"$func"
	)
}

# last-run.conf: when RULE was last evaluated for SCOPE, and its failed runs
alerts_lastrun_get() {
	alerts_line_of "$ALERTS_DIR/last-run.conf" " RULE='$1' SCOPE='$2' " | grep -oE "(^| )$3='[^']*'" | cut -d"'" -f2
}

alerts_lastrun_set() {
	alerts_line_put "$ALERTS_DIR/last-run.conf" " RULE='$1' SCOPE='$2' " \
		"TIME='$3' RULE='$1' SCOPE='$2' FAILS='$4' "
}

# Evaluate metric RULE for SCOPE now and apply the results. Caller holds
# the lock.
alerts_run_metric() {
	local scope="$1" rule="$2" out object value level detail fails panel
	local -A seen=()
	alerts_rule_read "$scope" "$rule"
	if [ "${RL[ENABLED]}" != 'yes' ]; then
		for object in $(alerts_state_objects "$rule" "$scope"); do
			alerts_close "$rule" "$scope" "$object"
		done
		return 0
	fi
	out=$(alerts_evaluate "$scope" "$rule")
	if [ $? -ne 0 ]; then
		fails=$(($(alerts_lastrun_get "$rule" "$scope" FAILS) + 1))
		alerts_log "probe of $rule ($scope) failed ($fails runs in a row)"
		if [ "$fails" -ge "$ALERTS_PROBE_FAILS" ]; then
			panel=$(alerts_panel_channel "$scope")
			alerts_channel_read "$scope" "$panel" && alerts_send_type "$scope" "Alert rule $rule cannot check the server" \
				"The probe of the alert rule ${ALERT_TITLE[$rule]} ($rule) failed $fails runs in a row on $(alerts_hostname). See $ALERTS_LOG."
			fails=0
		fi
		alerts_lastrun_set "$rule" "$scope" "$(alerts_now)" "$fails"
		return 1
	fi
	while read -r object value level detail; do
		[ -n "$object" ] || continue
		case "$level" in
			ok | warning | critical) ;;
			*) continue ;;
		esac
		seen[$object]=1
		alerts_metric_apply "$scope" "$rule" "$object" "$value" "$level" "$detail"
	done <<< "$out"
	# Objects that are gone (a deleted domain or user, an unmounted disk)
	for object in $(alerts_state_objects "$rule" "$scope"); do
		[ -n "${seen[$object]-}" ] || alerts_close "$rule" "$scope" "$object"
	done
	alerts_lastrun_set "$rule" "$scope" "$(alerts_now)" 0
	return 0
}

# Whether the interval of RULE has passed for SCOPE
alerts_due() {
	local last
	last=$(alerts_lastrun_get "$1" "$2" TIME)
	[[ "$last" =~ ^[0-9]+$ ]] || return 0
	# A margin of 15 seconds, so that a run every minute evaluates every minute
	[ $(($(alerts_now) - last + 15)) -ge $((${ALERT_INTERVAL[$1]} * 60)) ]
}

#----------------------------------------------------------#
#                        Defaults                          #
#----------------------------------------------------------#

# Write the line of RULE in SCOPE unless it exists: ENABLED CHANNELS.
# Caller holds the lock.
alerts_default_rule() {
	local scope="$1" rule="$2" enabled="$3" channels="$4"
	alerts_rule_read "$scope" "$rule"
	[ "${RL[CUSTOM]}" = 'yes' ] && return 0
	RL[ENABLED]=$enabled
	RL[CHANNELS]=$channels
	alerts_rule_write "$scope"
}

# The defaults of a user scope (spec §9): ssl.expiring and backup.failed on
# with its panel channel. Caller holds the lock.
alerts_user_defaults() {
	local user="$1" panel
	alerts_user_exists "$user" || return 0
	[ -d "$(alerts_scope_dir "$user")" ] && [ -f "$(alerts_rules_file "$user")" ] && return 0
	panel=$(alerts_panel_channel "$user")
	alerts_default_rule "$user" ssl.expiring yes "$panel"
	alerts_default_rule "$user" backup.failed yes "$panel"
}

# Users that have no alert files yet get the defaults. Caller holds the lock.
alerts_users_init() {
	local user
	while IFS= read -r user; do
		[ -f "$ARCPANEL/data/users/$user/alerts/rules.conf" ] || alerts_user_defaults "$user"
	done < <(alerts_all_users)
	return 0
}

#----------------------------------------------------------#
#                       JSON output                        #
#----------------------------------------------------------#

# The jq program that turns record lines into objects
ALERTS_JQ_RECORD='[scan("([A-Z_]+)='"'"'([^'"'"']*)'"'"'")] | map({key: .[0], value: .[1]}) | from_entries'

#----------------------------------------------------------#
#                         Events                           #
#----------------------------------------------------------#

ALERTS_EVENTS="$ALERTS_DIR/events.log"

# Record an event for the event rules (spec §6, D9); the only writer of
# data/alerts/events.log. The engine sends it on its next run.
#
#   alert_event TYPE USER OBJECT SEVERITY MESSAGE [REF]
#
# TYPE is an event rule of the catalog (job.failed, backup.failed,
# letsencrypt.failed, update.result, doctor.failed, doctor.digest), USER
# system or the user the object belongs to (its scopes, its reseller's and
# the server scope see the event), OBJECT what it is about (job:ID,
# backup-job:ID, web:example.com), SEVERITY info, warning or critical,
# MESSAGE one line for people, REF a job or run ID. With
# ALERT_EVENT_USER_ONLY=yes in the environment the server scope does not see
# it (the per-user results of a backup job run, which sends its own). Never
# fails its caller: returns 1 only for an unknown TYPE.
alert_event() {
	local type="$1" user="$2" object="$3" severity="$4" message="$5" ref="${6-}" lock line system=yes
	if ! alert_type_valid "$type" || [ "${ALERT_KIND[$type]}" != 'event' ]; then
		return 1
	fi
	case "$severity" in
		info | warning | critical) ;;
		*) severity=warning ;;
	esac
	[[ "$user" =~ ^[A-Za-z0-9][-A-Za-z0-9_.]{0,63}$ ]] || user=system
	object=$(printf '%s' "$object" | tr -c 'A-Za-z0-9_.:@/-' '_')
	object=${object:0:255}
	[ -n "$object" ] || object=system
	message=$(printf '%s' "$message" | tr '\n\r\t' '   ' | tr -d '\000-\037\177')
	message=${message:0:1000}
	ref=$(printf '%s' "$ref" | tr -cd 'A-Za-z0-9_.:-')
	[ "${ALERT_EVENT_USER_ONLY-}" = 'yes' ] && system=no
	(
		alerts_dir_init 2> /dev/null
		line=$(jq -nc --arg time "$(alerts_ts "$(alerts_now)")" --arg type "$type" --arg user "$user" \
			--arg object "$object" --arg severity "$severity" --arg message "$message" --arg ref "$ref" \
			--arg system "$system" \
			'{time: $time, type: $type, user: $user, object: $object, severity: $severity, message: $message, ref: $ref}
			+ (if $system == "no" then {system: false} else {} end)') || exit 0
		exec {lock}> "$ALERTS_DIR/.events.lock"
		flock -w 30 "$lock" || exit 0
		printf '%s\n' "$line" >> "$ALERTS_EVENTS"
		alerts_mode "$ALERTS_EVENTS"
	) 2> /dev/null
	return 0
}

# Send or fold one event in SCOPE (spec §4): EVENT_JSON (a line of
# events.log). Caller holds the lock.
alerts_event_apply() {
	local scope="$1" event="$2" rule object severity message ref etime now nowts age cooldown id
	rule=$(jq -r .type <<< "$event")
	object=$(jq -r .object <<< "$event")
	severity=$(jq -r .severity <<< "$event")
	message=$(jq -r .message <<< "$event")
	ref=$(jq -r '.ref // ""' <<< "$event")
	etime=$(jq -r .time <<< "$event")
	alerts_rule_read "$scope" "$rule"
	[ "${RL[ENABLED]}" = 'yes' ] || return 0
	now=$(alerts_now)
	nowts=$(alerts_ts "$now")
	age=$((now - $(alerts_epoch "$etime")))
	id=$(alerts_new_id "$ALERTS_STATE")
	ALERT_DELIVERIES='[]'
	# Below the rule's severity, or too old (the engine was stopped): kept in
	# the history without a message
	if [ "$(alert_severity_rank "$severity")" -lt "$(alert_severity_rank "${RL[SEVERITY]:-info}")" ] \
		|| [ "$age" -gt "$ALERTS_EVENT_MAX_AGE" ]; then
		alerts_history_add event "$id" "$scope" "$rule" "$object" "$severity" "$ref" "$message" "$etime" 1
		return 0
	fi
	cooldown=$((${RL[COOLDOWN]:-1440} * 60))
	if alerts_state_read "$rule" "$scope" "$object" && [ "${ST[KIND]}" = 'event' ] \
		&& [ $((now - $(alerts_epoch "${ST[LAST_NOTIFIED]}"))) -lt "$cooldown" ]; then
		# Folded into the next message of this rule and object
		ST[FOLDED]=$((${ST[FOLDED]:-0} + 1))
		ST[DETAIL]=$message
		ST[VALUE]=$ref
		if [ "$(alert_severity_rank "$severity")" -gt "$(alert_severity_rank "${ST[SEVERITY]}")" ]; then
			ST[SEVERITY]=$severity
		fi
		alerts_state_write
		alerts_history_add event "$id" "$scope" "$rule" "$object" "$severity" "$ref" "$message" "$etime" 1
		return 0
	fi
	alerts_message event "$scope" "$rule" "$object" "$severity" "${ref:+Ref: $ref}" "$message" "$etime"
	alerts_notify "$scope" "${RL[CHANNELS]}" "$MSG_TITLE" "$MSG_TEXT" "$id"
	alerts_history_add event "$id" "$scope" "$rule" "$object" "$severity" "$ref" "$message" "$etime" 1
	ST=([ID]=$id [KIND]=event [RULE]=$rule [SCOPE]=$scope [OBJECT]=$object [SEVERITY]=$severity [VALUE]=$ref
		[DETAIL]=$message [SINCE]=$nowts [FIRED]=$nowts [LAST_NOTIFIED]=$nowts [COUNT]=1 [CLEAR]=0 [FOLDED]=0)
	alerts_state_write
}

# Send the folded events whose cooldown has passed as one message with a
# count, and forget the quiet ones. Caller holds the lock.
alerts_event_flush() {
	local line now cooldown count id key
	local -A E=()
	[ -f "$ALERTS_STATE" ] || return 0
	now=$(alerts_now)
	while IFS= read -r line; do
		alerts_parse "$line" E
		[ "${E[KIND]-}" = 'event' ] || continue
		if [ "${E[SCOPE]}" != 'system' ] && ! alerts_user_exists "${E[SCOPE]}"; then
			alerts_state_delete "${E[RULE]}" "${E[SCOPE]}" "${E[OBJECT]}"
			continue
		fi
		alerts_rule_read "${E[SCOPE]}" "${E[RULE]}"
		cooldown=$((${RL[COOLDOWN]:-1440} * 60))
		[ $((now - $(alerts_epoch "${E[LAST_NOTIFIED]}"))) -ge "$cooldown" ] || continue
		count=${E[FOLDED]:-0}
		if [ "$count" -gt 0 ] && [ "${RL[ENABLED]}" = 'yes' ]; then
			id=$(alerts_new_id "$ALERTS_STATE")
			alerts_message event "${E[SCOPE]}" "${E[RULE]}" "${E[OBJECT]}" "${E[SEVERITY]}" \
				"${E[VALUE]:+Ref: ${E[VALUE]}}" "$count more since the last message; the last: ${E[DETAIL]}" "${E[LAST_NOTIFIED]}" "$count"
			alerts_notify "${E[SCOPE]}" "${RL[CHANNELS]}" "$MSG_TITLE" "$MSG_TEXT" "$id"
			alerts_history_add folded "$id" "${E[SCOPE]}" "${E[RULE]}" "${E[OBJECT]}" "${E[SEVERITY]}" "${E[VALUE]}" \
				"$MSG_TITLE" "${E[LAST_NOTIFIED]}" "$count"
			ST=()
			for key in "${!E[@]}"; do
				ST[$key]=${E[$key]}
			done
			ST[FOLDED]=0
			ST[LAST_NOTIFIED]=$(alerts_ts "$now")
			ST[COUNT]=$((${E[COUNT]:-1} + 1))
			alerts_state_write
		else
			alerts_state_delete "${E[RULE]}" "${E[SCOPE]}" "${E[OBJECT]}"
		fi
	done < <(grep -F "KIND='event'" "$ALERTS_STATE")
}

# Read the events written since the last run (events.offset) and apply each
# to the scopes that see it; rotate events.log at the start of a month once
# it is read. Caller holds the lock.
alerts_process_events() {
	local offset size chunk length cut line user scope lock month
	alerts_event_flush
	[ -f "$ALERTS_EVENTS" ] || return 0
	offset=$(cat "$ALERTS_DIR/events.offset" 2> /dev/null)
	[[ "$offset" =~ ^[0-9]+$ ]] || offset=0
	size=$(stat -c %s "$ALERTS_EVENTS")
	[ "$offset" -le "$size" ] || offset=0
	if [ "$offset" -lt "$size" ]; then
		chunk=$(mktemp)
		tail -c +$((offset + 1)) "$ALERTS_EVENTS" | head -c $((size - offset)) > "$chunk"
		# Complete lines only; a line being written waits for the next run
		length=$(stat -c %s "$chunk")
		if [ "$(tail -c1 "$chunk" | od -An -tx1 | tr -d ' ')" != '0a' ]; then
			cut=$(LC_ALL=C grep -boa $'\n' "$chunk" | tail -n1 | cut -d: -f1)
			length=$((${cut:--1} + 1))
			truncate -s "$length" "$chunk"
		fi
		while IFS= read -r line; do
			jq -e 'type == "object" and has("type")' > /dev/null 2>&1 <<< "$line" || continue
			user=$(jq -r '.user // "system"' <<< "$line")
			for scope in $(alerts_scopes_of_user "$user"); do
				if [ "$scope" = 'system' ] && [ "$(jq -r 'if has("system") then .system else true end' <<< "$line")" = 'false' ]; then
					continue
				fi
				alert_type_in_scope "$(jq -r .type <<< "$line")" "$scope" || continue
				[ "$scope" = 'system' ] || alerts_user_defaults "$scope"
				alerts_event_apply "$scope" "$line"
			done
		done < "$chunk"
		rm -f "$chunk"
		offset=$((offset + length))
		echo "$offset" > "$ALERTS_DIR/events.offset"
		alerts_mode "$ALERTS_DIR/events.offset"
	fi
	# A new month starts a new log once the old one is read
	month=$(date -d "@$(alerts_now)" +%Y-%m)
	if [ "$offset" -ge "$(stat -c %s "$ALERTS_EVENTS")" ] && [ "$(date -r "$ALERTS_EVENTS" +%Y-%m)" != "$month" ]; then
		exec {lock}> "$ALERTS_DIR/.events.lock"
		if flock -w 10 "$lock"; then
			if [ "$offset" -ge "$(stat -c %s "$ALERTS_EVENTS")" ]; then
				mv -f "$ALERTS_EVENTS" "$ALERTS_EVENTS.1"
				echo 0 > "$ALERTS_DIR/events.offset"
			fi
			flock -u "$lock"
		fi
		exec {lock}>&-
	fi
}

#----------------------------------------------------------#
#                 Server defaults (spec §9)                #
#----------------------------------------------------------#

# The contact address of the administrator account
alerts_admin_contact() {
	grep -m1 "^CONTACT='" "$ARCPANEL/data/users/${ROOT_USER:-admin}/user.conf" 2> /dev/null | cut -d"'" -f2
}

# ID of the server's email channel to the administrator (kept in
# .admin-email), created when CREATE is yes and CONTACT is set. Caller holds
# the lock.
alerts_admin_email_channel() {
	local create="${1-no}" id contact file
	file=$(alerts_channels_file system)
	id=$(cat "$ALERTS_DIR/.admin-email" 2> /dev/null)
	if [[ "$id" =~ ^[0-9]{14}$ ]] && alerts_channel_exists system "$id"; then
		echo "$id"
		return 0
	fi
	[ "$create" = 'yes' ] || return 1
	contact=$(alerts_admin_contact)
	if [ -z "$contact" ] || ! [[ "$contact" =~ ^[A-Za-z0-9._%+-]+@[[:alnum:].-]+\.[A-Za-z]{2,63}$ ]]; then
		return 1
	fi
	id=$(alerts_new_id "$file")
	CH=([ID]=$id [NAME]='Administrator email' [TYPE]=email [TARGET]=$contact [SECRET]='' [ENABLED]=yes [STATUS]='' [FAILS]=0)
	alerts_channel_write system
	echo "$id" > "$ALERTS_DIR/.admin-email"
	alerts_mode "$ALERTS_DIR/.admin-email"
	echo "$id"
}

# Add (on=yes) or remove the administrator's email channel from RULE of the
# server scope. Caller holds the lock.
alerts_rule_email() {
	local rule="$1" on="$2" email channels
	if [ "$on" = 'yes' ]; then
		email=$(alerts_admin_email_channel yes) || return 0
	else
		email=$(alerts_admin_email_channel no) || return 0
	fi
	alerts_rule_read system "$rule"
	channels=",${RL[CHANNELS]},"
	channels=${channels//,$email,/,}
	if [ "$on" = 'yes' ]; then
		channels="$channels$email,"
	fi
	channels=${channels#,}
	channels=${channels%,}
	channels=${channels#,}
	RL[CHANNELS]=$channels
	alerts_rule_write system
}

# The server defaults (spec §9): the rules of the catalog on with the panel
# channel and the administrator's email channel; backup.failed and
# update.result follow BACKUP_REPORT and UPGRADE_SEND_EMAIL. Caller holds the
# lock.
alerts_server_defaults() {
	local panel email rule channels
	panel=$(alerts_panel_channel system)
	email=$(alerts_admin_email_channel yes) || email=''
	channels=$panel${email:+,$email}
	for rule in disk.usage inode.usage load.average memory.usage service.down ssl.expiring letsencrypt.failed \
		job.failed ports.orphan; do
		alerts_default_rule system "$rule" yes "$channels"
	done
	case "${BACKUP_REPORT:-failures}" in
		off) alerts_default_rule system backup.failed yes "$panel" ;;
		*) alerts_default_rule system backup.failed yes "$channels" ;;
	esac
	if [ "${UPGRADE_SEND_EMAIL:-true}" = 'true' ]; then
		alerts_default_rule system update.result yes "$channels"
	else
		alerts_default_rule system update.result yes "$panel"
	fi
}

# The aliases of 2.7 (spec §9): changing BACKUP_REPORT or UPGRADE_SEND_EMAIL
# changes the administrator's email channel of backup.failed or
# update.result. KEY VALUE; does nothing before alerts are set up.
alerts_config_alias() {
	local key="$1" value="$2"
	[ -f "$ALERTS_DIR/.defaults" ] || return 0
	alerts_lock || return 0
	case "$key" in
		BACKUP_REPORT)
			if [ "$value" = 'off' ]; then
				alerts_rule_email backup.failed no
			else
				alerts_rule_email backup.failed yes
			fi
			;;
		UPGRADE_SEND_EMAIL)
			if [ "$value" = 'true' ]; then
				alerts_rule_email update.result yes
			else
				alerts_rule_email update.result no
			fi
			;;
	esac
	alerts_unlock
}

# Close the open alerts of scopes whose user was deleted (spec §10). Caller
# holds the lock.
alerts_state_prune() {
	local line
	local -A P=()
	[ -f "$ALERTS_STATE" ] || return 0
	while IFS= read -r line; do
		alerts_parse "$line" P
		[ -n "${P[SCOPE]-}" ] && [ "${P[SCOPE]}" != 'system' ] || continue
		alerts_user_exists "${P[SCOPE]}" && continue
		alerts_state_delete "${P[RULE]}" "${P[SCOPE]}" "${P[OBJECT]}"
	done < <(cat "$ALERTS_STATE")
}
