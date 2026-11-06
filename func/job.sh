#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: jobs (E15 Job Center,
# docs/features/specs/2026-11-05-001-e15-job-center-design.md).
#
# Every long operation is one job: a record data/jobs/ID.conf (one line of
# KEY='value' pairs), its log data/jobs/ID.log and the command it runs,
# data/jobs/ID.argv (NUL separated, root only). Root writes and runs jobs;
# the panel reads them with v-list-job and v-list-jobs.
#
# Life of a job: job_create writes it queued (after the concurrency check),
# job_dispatch starts the jobs that may run (a transient systemd unit
# arcpanel-job-ID running v-run-job ID, or setsid nohup without systemd),
# v-run-job runs the command and records the result, job_finish calls the
# finish hooks. v-update-sys-jobs (cron, every minute) dispatches, reaps
# dead runners and cleans up.
#
# Adding a job type: call job_type_add in the table below, or from a file
# func/job.d/NAME.sh of the epic that owns the type (sourced at the end of
# this library):
#
#   job_type_add TYPE COMMANDS CLASS KEY RETRYABLE LIMIT [HOOK]
#
#   TYPE       dotted name, [a-z0-9-] parts: backup.user, doctor.run
#   COMMANDS   comma list of the v-* commands of $BIN the job may run
#   CLASS      immediate (starts at once), backup or letsencrypt (a queue
#              with JOB_BACKUP_PARALLEL / JOB_LETSENCRYPT_PARALLEL slots,
#              started in ID order) or deploy (one running per key, a newer
#              request replaces the queued one)
#   KEY        object (the concurrency key is the job's OBJECT) or sys (one
#              job of every sys-keyed type on the server at a time)
#   RETRYABLE  yes or no (v-retry-job)
#   LIMIT      time limit in seconds
#   HOOK       optional FILE:FUNCTION called by job_finish with the job ID,
#              FILE relative to $ARCPANEL (func/deploy.sh:deploy_job_finished)

# Sourced once (func/systask.sh and the other source libraries load it too)
if [ -n "${JOB_SH_LOADED-}" ]; then
	return 0
fi
JOB_SH_LOADED=1

#----------------------------------------------------------#
#                       Locations                          #
#----------------------------------------------------------#

JOB_DIR="$ARCPANEL/data/jobs"
JOB_KEYS="ID TYPE USER OWNER ACTOR OBJECT ARGS STATUS EXIT ERROR UNIT PID QUEUED START END HEARTBEAT ATTEMPTS RETRY_OF RESOURCE UPDATED"

# Log cap (spec §3.2): beyond JOB_LOG_MAX the first JOB_LOG_HEAD bytes and
# the last JOB_LOG_MAX - JOB_LOG_HEAD bytes are kept
JOB_LOG_MAX=${JOB_LOG_MAX:-5242880}
JOB_LOG_HEAD=${JOB_LOG_HEAD:-1048576}
# Retention (spec §3.3)
JOB_KEEP_DAYS=${JOB_KEEP_DAYS:-30}
JOB_KEEP_MAX=${JOB_KEEP_MAX:-500}
# A running job without a heartbeat for this long is reaped (spec §5.2)
JOB_HEARTBEAT_MAX=${JOB_HEARTBEAT_MAX:-300}
JOB_HEARTBEAT_EVERY=${JOB_HEARTBEAT_EVERY:-30}
# Free space job_create needs on the data folder, in KB (spec §8)
JOB_MIN_FREE_KB=${JOB_MIN_FREE_KB:-10240}

job_conf() {
	echo "$JOB_DIR/$1.conf"
}

job_log() {
	echo "$JOB_DIR/$1.log"
}

job_argv_file() {
	echo "$JOB_DIR/$1.argv"
}

job_mask_file() {
	echo "$JOB_DIR/$1.mask"
}

job_unit() {
	echo "arcpanel-job-$1"
}

# The request of a job (spec §3.6): input its command reads, root 0600,
# never in ARGS; removed when the job succeeds, kept a day after a failure
# so that a retry can use it
job_request_file() {
	echo "$JOB_DIR/$1.request"
}

# Copy FILE into the request of job ID
job_request_store() {
	local file
	file=$(job_request_file "$1")
	(umask 077 && cp -- "$2" "$file") || return 1
	chown root:root "$file"
	chmod 600 "$file"
}

# Create data/jobs/ (root:arcpanelweb 0750)
job_dir_init() {
	if [ ! -d "$JOB_DIR" ]; then
		mkdir -p "$JOB_DIR"
	fi
	if getent group arcpanelweb > /dev/null 2>&1; then
		chown root:arcpanelweb "$JOB_DIR"
	else
		chown root:root "$JOB_DIR"
	fi
	chmod 750 "$JOB_DIR"
}

# Mode 0640, group arcpanelweb when it exists
job_file_mode() {
	chmod 640 "$1"
	if getent group arcpanelweb > /dev/null 2>&1; then
		chgrp arcpanelweb "$1" 2> /dev/null
	fi
}

#----------------------------------------------------------#
#                       Job types                          #
#----------------------------------------------------------#

declare -gA JOB_TYPE_CMDS=() JOB_TYPE_CLASS=() JOB_TYPE_KEY=() JOB_TYPE_RETRY=() JOB_TYPE_LIMIT=() JOB_TYPE_HOOK=()

# Register a job type (see the header)
job_type_add() {
	local type="$1"
	if ! [[ "$type" =~ ^[a-z0-9-]+(\.[a-z0-9-]+)*$ ]]; then
		echo "job_type_add: invalid type $type" >&2
		return 1
	fi
	case "$3" in
		immediate | backup | letsencrypt | deploy) ;;
		*)
			echo "job_type_add: invalid class $3 of $type" >&2
			return 1
			;;
	esac
	JOB_TYPE_CMDS[$type]=$2
	JOB_TYPE_CLASS[$type]=$3
	JOB_TYPE_KEY[$type]=$4
	JOB_TYPE_RETRY[$type]=$5
	JOB_TYPE_LIMIT[$type]=$6
	JOB_TYPE_HOOK[$type]=${7-}
}

# The job types of spec §3.4. A type added later adds its line here or in
# func/job.d/.
job_type_add backup.user v-backup-user backup object yes 86400
job_type_add backup.restic v-backup-user-restic backup object yes 86400
job_type_add backup.download v-download-backup backup object yes 86400
job_type_add restore.user v-restore-user backup object yes 86400
job_type_add restore.restic v-restore-user-restic,v-restore-web-domain-restic,v-restore-dns-domain-restic,v-restore-mail-domain-restic,v-restore-database-restic,v-restore-cron-job-restic backup object yes 86400
job_type_add restore.file v-restore-file-restic backup object yes 86400
job_type_add letsencrypt.domain v-add-letsencrypt-domain letsencrypt object yes 1800
job_type_add deploy.run v-run-web-domain-deploy deploy object yes 1500
job_type_add deploy.rollback v-run-web-domain-deploy deploy object yes 1500
job_type_add sys.php v-run-sys-task immediate sys no 7200
job_type_add sys.php-ext v-run-sys-task immediate sys no 7200
job_type_add sys.plugin v-run-sys-task immediate sys no 7200
job_type_add sys.package v-run-sys-task immediate sys no 7200
job_type_add sys.arcpanel-update v-run-sys-task immediate sys no 7200
job_type_add sys.arcpanel-release v-run-sys-task immediate sys no 7200
job_type_add sys.arcpanel-rollback v-run-sys-task immediate sys no 7200
job_type_add docker.engine v-run-sys-docker-task immediate sys no 7200
job_type_add docker.install v-run-docker-app-task immediate object yes 2100
job_type_add docker.update v-run-docker-app-task immediate object yes 2100
job_type_add docker.rollback v-run-docker-app-task immediate object no 2100
job_type_add docker.snapshot v-run-docker-app-task immediate object no 2100
job_type_add node.install v-run-user-node-task immediate object no 1800
job_type_add node.build v-run-user-node-task immediate object yes 1800
job_type_add node.npm v-run-user-node-task immediate object yes 1800
job_type_add app-process.composer-install v-run-app-process-task immediate object no 2100
job_type_add app-process.migrate v-run-app-process-task immediate object no 2100
job_type_add app-process.pip-install v-run-app-process-task immediate object no 2100
job_type_add app-process.bundle-install v-run-app-process-task immediate object no 2100
job_type_add app-process.custom v-run-app-process-task immediate object no 2100
job_type_add quick-install v-run-user-quick-install immediate object yes 1800
job_type_add malware.scan v-scan-user-web immediate object yes 7200
job_type_add backup.job v-run-backup-job immediate object yes 86400
job_type_add fs.fetch v-run-fs-fetch immediate object no 3600
# E17 Doctor (docs/features/specs/2026-11-05-003-e17-doctor-design.md)
job_type_add doctor.run v-run-sys-doctor immediate object yes 1800
job_type_add doctor.fix v-fix-sys-doctor immediate object no 1800

job_type_valid() {
	[ -n "$1" ] && [ -n "${JOB_TYPE_CLASS[$1]+x}" ]
}

job_type_class() {
	echo "${JOB_TYPE_CLASS[$1]-}"
}

job_type_retryable() {
	[ "${JOB_TYPE_RETRY[$1]-}" = 'yes' ]
}

# Whether COMMAND (a basename) may run as a job of TYPE
job_command_allowed() {
	local type="$1" command="$2"
	job_type_valid "$type" || return 1
	[[ ",${JOB_TYPE_CMDS[$type]}," == *",$command,"* ]] || return 1
	[ -x "$BIN/$command" ]
}

# Time limit of a job of TYPE on OBJECT in seconds
job_type_limit() {
	local type="$1" object="${2-}" value
	case "$type" in
		deploy.*)
			value="${DEPLOY_BUILD_TIMEOUT:-900}"
			[[ "$value" =~ ^[0-9]{1,5}$ ]] || value=900
			echo $((value + 600))
			return
			;;
		fs.fetch)
			value="${FETCH_TIMEOUT:-3600}"
			[[ "$value" =~ ^[0-9]{1,6}$ ]] || value=3600
			# The transfer stops itself at FETCH_TIMEOUT; the margin lets
			# the runner record why
			echo $((value + 300))
			return
			;;
		doctor.run)
			if [ "$object" = 'doctor:all' ]; then
				echo 7200
				return
			fi
			;;
	esac
	echo "${JOB_TYPE_LIMIT[$type]:-1800}"
}

# A limit in seconds for people: 30 min, 2 h
job_limit_text() {
	local s="$1"
	if [ "$s" -ge 3600 ] && [ $((s % 3600)) -eq 0 ]; then
		echo "$((s / 3600)) h"
	else
		echo "$(((s + 59) / 60)) min"
	fi
}

#----------------------------------------------------------#
#                         Lock                             #
#----------------------------------------------------------#

JOB_LOCK_FD=
JOB_LOCK_DEPTH=0

# Hold data/jobs/.lock. Nested calls only count.
job_lock() {
	if [ "$JOB_LOCK_DEPTH" -gt 0 ]; then
		JOB_LOCK_DEPTH=$((JOB_LOCK_DEPTH + 1))
		return 0
	fi
	job_dir_init
	exec {JOB_LOCK_FD}> "$JOB_DIR/.lock"
	flock -x "$JOB_LOCK_FD"
	JOB_LOCK_DEPTH=1
}

job_unlock() {
	if [ "$JOB_LOCK_DEPTH" -gt 1 ]; then
		JOB_LOCK_DEPTH=$((JOB_LOCK_DEPTH - 1))
		return 0
	fi
	if [ -n "$JOB_LOCK_FD" ]; then
		flock -u "$JOB_LOCK_FD"
		exec {JOB_LOCK_FD}>&-
	fi
	JOB_LOCK_FD=
	JOB_LOCK_DEPTH=0
}

#----------------------------------------------------------#
#                        Records                           #
#----------------------------------------------------------#

job_id_valid() {
	[[ "$1" =~ ^[0-9]{14}$ ]]
}

job_exists() {
	job_id_valid "$1" && [ -f "$(job_conf "$1")" ]
}

# Read the record of ID into the associative array JOB
declare -gA JOB=()
job_read() {
	local file line
	JOB=()
	file=$(job_conf "$1")
	[ -f "$file" ] || return 1
	line=$(head -n1 "$file")
	while [[ "$line" =~ ^[[:space:]]*([A-Z_]+)=\'([^\']*)\' ]]; do
		JOB[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
		line=${line:${#BASH_REMATCH[0]}}
	done
	return 0
}

# Value of KEY in the record of ID
job_get() {
	local file
	file=$(job_conf "$1")
	if [ -f "$file" ]; then
		grep -oE "(^| )$2='[^']*'" "$file" | head -n1 | cut -d\' -f2
	fi
}

# Set KEY=VALUE pairs in the record of ID; the other keys keep their values
# and UPDATED is set. Quotes and line breaks are dropped from values.
job_set() {
	local id="$1" pair key line='' tmp file
	local -A values=()
	shift
	job_lock
	if job_read "$id"; then
		for key in "${!JOB[@]}"; do
			values[$key]=${JOB[$key]}
		done
	fi
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	values[UPDATED]=$(date +'%F %T')
	for key in $JOB_KEYS; do
		line="$line$key='${values[$key]-}' "
	done
	file=$(job_conf "$id")
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	job_file_mode "$tmp"
	mv -f "$tmp" "$file"
	job_unlock
}

# The concurrency key of the job in JOB
job_key_of_read() {
	if [ "${JOB_TYPE_KEY[${JOB[TYPE]-}]-}" = 'sys' ]; then
		echo 'sys'
	else
		echo "${JOB[OBJECT]-}"
	fi
}

# IDs of the queued and running jobs, oldest first
job_active_ids() {
	[ -d "$JOB_DIR" ] || return 0
	grep -rlE --include='*.conf' "STATUS='(queued|running)'" "$JOB_DIR" 2> /dev/null \
		| sed -E 's|.*/([0-9]{14})\.conf$|\1|' | grep -E '^[0-9]{14}$' | sort
}

# ID of a queued or running job with concurrency KEY, STATUSES a regular
# expression of the states that count (queued|running)
job_active_with_key() {
	local key="$1" states="${2:-queued|running}" id
	for id in $(job_active_ids); do
		job_read "$id" || continue
		[[ "${JOB[STATUS]}" =~ ^($states)$ ]] || continue
		if [ "$(job_key_of_read)" = "$key" ]; then
			echo "$id"
			return 0
		fi
	done
	return 1
}

# A new 14-digit ID (spec D10): the epoch second and a 4-digit sequence.
# IDs only grow, so the newest job is the one with the highest ID, also
# for jobs created in the same second (job_newest, the queue order). The
# last ID is kept in data/jobs/.last-id under the job lock; a clock that
# goes back or a sequence past 9999 keeps counting from the last ID.
job_new_id() {
	local id last='' base
	job_lock
	[ -s "$JOB_DIR/.last-id" ] && last=$(< "$JOB_DIR/.last-id")
	[[ "$last" =~ ^[0-9]{14}$ ]] || last=0
	base=$(($(date +%s) * 10000))
	id=$((last + 1))
	[ "$base" -gt "$last" ] && id=$base
	while [ -e "$(job_conf "$id")" ]; do
		id=$((id + 1))
	done
	echo "$id" > "$JOB_DIR/.last-id"
	job_unlock
	echo "$id"
}

# The ARGS text of a command line: words quoted for reading, quotes dropped
job_args_text() {
	local word out=''
	for word in "$@"; do
		if [[ "$word" =~ ^[A-Za-z0-9_./:=,@%+-]+$ ]]; then
			out+="$word "
		else
			out+="\"${word//\"/\\\"}\" "
		fi
	done
	printf '%s' "${out% }"
}

# The owner of USER for the record: ROOT_USER, a reseller or system
job_owner_of() {
	local owner
	if [ "$1" = 'system' ]; then
		echo system
		return
	fi
	if declare -F tenancy_get_owner > /dev/null; then
		owner=$(tenancy_get_owner "$1")
	else
		owner=$(grep -oE "^OWNER='[^']*'" "$ARCPANEL/data/users/$1/user.conf" 2> /dev/null | cut -d\' -f2)
	fi
	echo "${owner:-${ROOT_USER:-admin}}"
}

# Create a queued job. Sets JOB_ID; returns E_INVALID, E_FORBIDEN, E_DISK or
# E_INUSE with JOB_REASON (and JOB_INUSE, the ID of the job that holds the
# key). Takes the lock itself.
#
#   job_create TYPE USER OBJECT ACTOR [RESOURCE] -- COMMAND [ARG...]
#
# COMMAND is a v-* command of TYPE (a name or $BIN/name).
job_create() {
	local type="$1" juser="$2" object="$3" actor="$4" resource='' command base key id existing
	shift 4
	if [ "${1-}" != '--' ]; then
		resource=$1
		shift
	fi
	JOB_ID=
	JOB_REASON=
	JOB_INUSE=
	JOB_REPLACED=
	if [ "${1-}" != '--' ] || [ $# -lt 2 ]; then
		JOB_REASON="missing -- COMMAND"
		return "$E_ARGS"
	fi
	shift
	command=$1
	shift
	base=$(basename -- "$command")
	if ! job_type_valid "$type"; then
		JOB_REASON="invalid job type :: $type"
		return "$E_INVALID"
	fi
	if [[ "$command" == */* && "$command" != "$BIN/$base" ]] || ! job_command_allowed "$type" "$base"; then
		JOB_REASON="command $base is not allowed for jobs of type $type"
		return "$E_FORBIDEN"
	fi
	if [ "$juser" != 'system' ] && [ ! -f "$ARCPANEL/data/users/$juser/user.conf" ]; then
		JOB_REASON="user $juser does not exist"
		return "$E_NOTEXIST"
	fi
	if ! job_actor_valid "$actor"; then
		JOB_REASON="invalid actor :: $actor"
		return "$E_INVALID"
	fi
	if ! [[ "$object" =~ ^[A-Za-z0-9_.:@/-]{1,255}$ ]]; then
		JOB_REASON="invalid object :: $object"
		return "$E_INVALID"
	fi
	job_dir_init
	if [ "$(df -Pk "$JOB_DIR" 2> /dev/null | awk 'NR==2 { print $4 }')" -lt "$JOB_MIN_FREE_KB" ] 2> /dev/null; then
		JOB_REASON="not enough free disk space for the job"
		return "$E_DISK"
	fi
	job_lock
	JOB=([TYPE]=$type [OBJECT]=$object)
	key=$(job_key_of_read)
	if [ "$(job_type_class "$type")" = 'deploy' ]; then
		# E20 D15: one running per key, the newest request replaces the
		# queued one
		existing=$(job_active_with_key "$key" queued || true)
	else
		existing=$(job_active_with_key "$key" || true)
		if [ -n "$existing" ]; then
			job_unlock
			JOB_INUSE=$existing
			JOB_REASON="job $existing ($key) is already queued or running"
			return "$E_INUSE"
		fi
	fi
	id=$(job_new_id)
	printf '%s\0' "$BIN/$base" "$@" > "$(job_argv_file "$id")"
	chmod 600 "$(job_argv_file "$id")"
	: > "$(job_log "$id")"
	job_file_mode "$(job_log "$id")"
	job_set "$id" "ID=$id" "TYPE=$type" "USER=$juser" "OWNER=$(job_owner_of "$juser")" \
		"ACTOR=$actor" "OBJECT=$object" "ARGS=$(job_args_text "$base" "$@")" STATUS=queued \
		"QUEUED=$(date +'%F %T')" ATTEMPTS=0 "RESOURCE=$resource"
	if [ -n "$existing" ]; then
		job_set "$existing" STATUS=cancelled "ERROR=replaced by $id" "END=$(date +'%F %T')"
		JOB_REPLACED=$existing
	fi
	job_unlock
	JOB_ID=$id
	return 0
}

job_actor_valid() {
	[[ "$1" =~ ^[A-Za-z0-9_.:@-]{1,64}$ ]]
}

# Print a new job ID. FORMAT json prints {"ID":"…"}.
job_emit_id() {
	case "${2:-shell}" in
		json) jq -nc --arg id "$1" '{ID: $id}' ;;
		*) printf '%s\n' "$1" ;;
	esac
}

#----------------------------------------------------------#
#                       Starting                           #
#----------------------------------------------------------#

job_has_systemd() {
	[ -z "${JOB_NO_SYSTEMD-}" ] && [ -d /run/systemd/system ] && command -v systemd-run > /dev/null 2>&1
}

# Start the runner of queued job ID now. Caller holds the lock.
job_launch() {
	local id="$1" unit slice uid limit pid
	local -a props=()
	job_read "$id" || return 1
	[ "${JOB[STATUS]}" = 'queued' ] || return 1
	limit=$(job_type_limit "${JOB[TYPE]}" "${JOB[OBJECT]}")
	if job_has_systemd; then
		unit=$(job_unit "$id")
		# A job queued again by its own runner starts once that unit is gone
		case "$(systemctl show -p ActiveState --value "$unit.service" 2> /dev/null)" in
			active | activating | deactivating | reloading) return 1 ;;
		esac
		systemctl reset-failed "$unit.service" > /dev/null 2>&1
		slice=system.slice
		if [ "${JOB[USER]}" != 'system' ] && uid=$(id -u "${JOB[USER]}" 2> /dev/null); then
			slice="user-$uid.slice"
		fi
		case "$(job_type_class "${JOB[TYPE]}")" in
			backup) props+=(-p Nice=10) ;;
			deploy) props+=(-p Nice=10 -p IOSchedulingClass=idle) ;;
		esac
		case "${JOB[TYPE]}" in
			backup.job) props+=(-p Nice=10) ;;
		esac
		# The catalog overrides of root's environment (tests, mirrors) reach
		# the command as they did when the sources ran it with setsid; sudo
		# never passes them from the panel
		local name
		while IFS= read -r name; do
			[[ "$name" =~ ^ARC_[A-Z0-9_]*(CATALOG|RELEASE|ROOT)$ ]] && props+=(-E "$name=${!name}")
		done < <(compgen -e)
		job_set "$id" STATUS=running "START=$(date +'%F %T')" "UNIT=$unit" PID= HEARTBEAT=
		# The runner applies the limit itself; RuntimeMaxSec is the backstop
		if ! systemd-run --quiet --collect --unit="$unit" --slice="$slice" \
			-p RuntimeMaxSec=$((limit + 300)) -p TimeoutStopSec=10 "${props[@]}" \
			-E LANG=C.UTF-8 -E HOME=/root \
			"$BIN/v-run-job" "$id" > /dev/null 2>&1 {JOB_LOCK_FD}>&-; then
			job_set "$id" STATUS=failed EXIT=1 "END=$(date +'%F %T')" UNIT= \
				"ERROR=the job could not be started"
			return 1
		fi
	else
		job_set "$id" STATUS=running "START=$(date +'%F %T')" UNIT= PID= HEARTBEAT=
		(
			# The runner keeps no descriptor of its caller (locks above all)
			for fd in /proc/$BASHPID/fd/*; do
				fd=${fd##*/}
				if [[ "$fd" =~ ^[0-9]+$ ]] && [ "$fd" -gt 2 ]; then
					eval "exec $fd>&-" 2> /dev/null
				fi
			done
			LANG=C.UTF-8 HOME=/root exec setsid nohup "$BIN/v-run-job" "$id" > /dev/null 2>&1 < /dev/null
		) &
		pid=$!
		job_set "$id" "PID=$pid"
	fi
	return 0
}

# Parallel slots of a queued class
job_class_slots() {
	local value
	case "$1" in
		backup) value=${JOB_BACKUP_PARALLEL:-1} ;;
		letsencrypt) value=${JOB_LETSENCRYPT_PARALLEL:-1} ;;
		*) value=1 ;;
	esac
	[[ "$value" =~ ^[0-9]{1,2}$ ]] && [ "$value" -ge 1 ] || value=1
	echo "$value"
}

# Start every queued job that may run now: immediate jobs at once, the
# backup and letsencrypt queues while their class has a free slot, a deploy
# when no deploy of its key runs. In ID order. Takes the lock.
job_dispatch() {
	local id class key
	local -a queued=()
	local -A running_class=() running_key=()
	job_lock
	for id in $(job_active_ids); do
		job_read "$id" || continue
		class=$(job_type_class "${JOB[TYPE]}")
		if [ "${JOB[STATUS]}" = 'running' ]; then
			running_class[$class]=$((${running_class[$class]:-0} + 1))
			running_key[$(job_key_of_read)]=1
		else
			queued+=("$id")
		fi
	done
	for id in "${queued[@]}"; do
		# A runner that queued its own job again leaves it to the next pass
		[ "$id" != "${JOB_SKIP_DISPATCH-}" ] || continue
		job_read "$id" || continue
		[ "${JOB[STATUS]}" = 'queued' ] || continue
		class=$(job_type_class "${JOB[TYPE]}")
		key=$(job_key_of_read)
		case "$class" in
			backup | letsencrypt)
				[ "${running_class[$class]:-0}" -lt "$(job_class_slots "$class")" ] || continue
				[ -z "${running_key[$key]-}" ] || continue
				;;
			deploy)
				[ -z "${running_key[$key]-}" ] || continue
				;;
		esac
		if job_launch "$id"; then
			running_class[$class]=$((${running_class[$class]:-0} + 1))
			running_key[$key]=1
		fi
	done
	job_unlock
}

# Create a job and start it when it may run (job_create, then job_dispatch).
# Same arguments and results as job_create.
job_start() {
	job_create "$@" || return $?
	job_dispatch
	return 0
}

#----------------------------------------------------------#
#                  Reaping and finishing                   #
#----------------------------------------------------------#

# Whether the runner of the job in JOB is alive
job_runner_alive() {
	local pid=${JOB[PID]-}
	if [ -n "${JOB[UNIT]-}" ]; then
		systemctl is-active --quiet "${JOB[UNIT]}.service" 2> /dev/null && return 0
		# The unit may be starting
		[ "$(systemctl show -p ActiveState --value "${JOB[UNIT]}.service" 2> /dev/null)" = 'activating' ]
		return
	fi
	[[ "$pid" =~ ^[0-9]+$ ]] && [ -r "/proc/$pid/cmdline" ] \
		&& tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'v-run-job'
}

# Seconds since a timestamp YYYY-MM-DD HH:MM:SS (empty: very old)
job_age() {
	local ts
	ts=$(date -d "$1" +%s 2> /dev/null) || ts=0
	echo $(($(date +%s) - ts))
}

# Mark failed a running job of ID whose runner is gone or silent (spec §5.2).
# Takes the lock. Returns 0 when it reaped the job.
job_reap_one() {
	local id="$1" beat limit error
	job_lock
	if ! job_read "$id" || [ "${JOB[STATUS]}" != 'running' ]; then
		job_unlock
		return 1
	fi
	beat=${JOB[HEARTBEAT]:-${JOB[START]}}
	if job_runner_alive && [ "$(job_age "$beat")" -le "$JOB_HEARTBEAT_MAX" ]; then
		job_unlock
		return 1
	fi
	limit=$(job_type_limit "${JOB[TYPE]}" "${JOB[OBJECT]}")
	if [ "$(job_age "${JOB[START]}")" -ge "$limit" ]; then
		error="time limit of $(job_limit_text "$limit") reached"
	else
		error='runner stopped'
	fi
	if [ -n "${JOB[UNIT]-}" ]; then
		systemctl stop "${JOB[UNIT]}.service" > /dev/null 2>&1
	fi
	job_set "$id" STATUS=failed "ERROR=$error" "END=$(date +'%F %T')"
	job_unlock
	job_finish "$id"
	return 0
}

# Cancel queued or running job ID with the reason ERROR (spec §4.1): a queued
# job at once, a running one by stopping its unit (KILL after 10 seconds) or,
# without systemd, its runner's session. Returns E_INVALID with JOB_REASON
# for a finished job. Takes the lock.
job_cancel() {
	local id="$1" error="$2" status unit pid
	job_reap_one "$id" > /dev/null 2>&1 || true
	job_lock
	if ! job_read "$id"; then
		job_unlock
		JOB_REASON="job $id does not exist"
		return "$E_NOTEXIST"
	fi
	status=${JOB[STATUS]}
	if [ "$status" != 'queued' ] && [ "$status" != 'running' ]; then
		job_unlock
		JOB_REASON="job $id is $status and cannot be cancelled"
		return "$E_INVALID"
	fi
	job_set "$id" STATUS=cancelled "ERROR=$error" "END=$(date +'%F %T')"
	unit=${JOB[UNIT]}
	pid=${JOB[PID]}
	job_unlock
	if [ "$status" = 'running' ]; then
		if [ -n "$unit" ]; then
			systemctl stop "$unit.service" > /dev/null 2>&1
		elif [[ "$pid" =~ ^[0-9]+$ ]]; then
			# The runner leads its own session (setsid); its command runs in
			# a process group of that session
			pkill -TERM -s "$pid" 2> /dev/null
			for _ in $(seq 1 20); do
				kill -0 "$pid" 2> /dev/null || break
				sleep 0.5
			done
			pkill -KILL -s "$pid" 2> /dev/null
		fi
	fi
	# A runner that was killed before it recorded the end leaves the hooks
	# to the canceller
	job_read "$id"
	if [ "$status" = 'queued' ] || [ -z "${JOB[EXIT]}" ]; then
		job_finish "$id"
	fi
	job_dispatch
	return 0
}

# Reap every running job
job_reap() {
	local id
	for id in $(job_active_ids); do
		job_reap_one "$id" || true
	done
}

# One line of reason from the log of ID: the last `Error:` line without the
# prefix, else its last non-empty line; at most 300 characters
job_error_line() {
	local log line
	log=$(job_log "$1")
	[ -f "$log" ] || return 0
	line=$(tail -c 65536 "$log" | tr -d '\000-\010\013-\037\177' | grep -E '^[[:space:]]*Error:' | tail -n1 \
		| sed -E 's/^[[:space:]]*Error:[[:space:]]*//')
	if [ -z "$line" ]; then
		line=$(tail -c 65536 "$log" | tr -d '\000-\010\013-\037\177' | grep -vE '^[[:space:]]*$' | tail -n1)
	fi
	line=$(printf '%s' "$line" | tr '\t' ' ' | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')
	printf '%s' "${line:0:300}"
}

# Finish hooks of a finished job (spec §5.3): the type's own hook, then the
# alert event. A failing hook does not stop the next.
job_finish() {
	local id="$1" hook file func status type
	job_read "$id" || return 0
	type=${JOB[TYPE]}
	status=${JOB[STATUS]}
	hook=${JOB_TYPE_HOOK[$type]-}
	if [ -n "$hook" ]; then
		file=${hook%%:*}
		func=${hook#*:}
		(
			# shellcheck disable=SC1090
			[ -f "$ARCPANEL/$file" ] && source "$ARCPANEL/$file"
			declare -F "$func" > /dev/null && "$func" "$id"
		) || true
	fi
	if [ "$status" = 'failed' ]; then
		job_alert "$id" || true
	fi
	return 0
}

# The E16 event of a failed job: letsencrypt.failed for a certificate,
# nothing for the backup types (their runs send backup.failed), job.failed
# for the others. A no-op until func/alerts.sh exists.
job_alert() {
	local id="$1" type scope event message
	job_read "$id" || return 0
	type=${JOB[TYPE]}
	case "$type" in
		backup.* | backup.job) return 0 ;;
		letsencrypt.*) event=letsencrypt.failed ;;
		*) event=job.failed ;;
	esac
	if ! declare -F alert_event > /dev/null; then
		[ -f "$ARCPANEL/func/alerts.sh" ] || return 0
		# shellcheck source=/usr/local/arcpanel/func/alerts.sh
		source "$ARCPANEL/func/alerts.sh" || return 0
		declare -F alert_event > /dev/null || return 0
	fi
	scope=${JOB[USER]}
	message="Job $type on ${JOB[OBJECT]} failed${JOB[ERROR]:+: ${JOB[ERROR]}}"
	alert_event "$event" "$scope" "job:$id" warning "$message" "$id"
}

#----------------------------------------------------------#
#                        Retention                         #
#----------------------------------------------------------#

job_remove_files() {
	rm -f "$(job_conf "$1")" "$(job_log "$1")" "$(job_argv_file "$1")" "$(job_mask_file "$1")" \
		"$JOB_DIR/$1.noretry" "$(job_request_file "$1")"
}

# Delete finished jobs older than JOB_KEEP_DAYS and, per user, the oldest
# finished jobs beyond JOB_KEEP_MAX (spec §3.3). Takes the lock.
job_cleanup() {
	local cutoff file id status end owner
	local -A count=()
	[ -d "$JOB_DIR" ] || return 0
	cutoff=$(date -d "$JOB_KEEP_DAYS days ago" +'%F %T')
	job_lock
	# Newest first, so the count keeps the newest JOB_KEEP_MAX
	while IFS= read -r file; do
		id=$(basename "$file" .conf)
		job_id_valid "$id" || continue
		job_read "$id" || continue
		status=${JOB[STATUS]}
		owner=${JOB[USER]}
		[ "$status" = 'queued' ] || [ "$status" = 'running' ] && continue
		end=${JOB[END]:-${JOB[QUEUED]}}
		count[$owner]=$((${count[$owner]:-0} + 1))
		if [[ "$end" < "$cutoff" ]] || [ "${count[$owner]}" -gt "$JOB_KEEP_MAX" ]; then
			job_remove_files "$id"
		fi
	done < <(find "$JOB_DIR" -maxdepth 1 -name '[0-9]*.conf' -printf '%f\n' | sort -r | sed "s|^|$JOB_DIR/|")
	# Logs and files without a record
	find "$JOB_DIR" -maxdepth 1 -type f \( -name '[0-9]*.log' -o -name '[0-9]*.argv' -o -name '[0-9]*.mask' -o -name '[0-9]*.noretry' -o -name '[0-9]*.request' \) -printf '%f\n' \
		| while IFS= read -r file; do
			id=${file%%.*}
			[ -f "$JOB_DIR/$id.conf" ] || rm -f "${JOB_DIR:?}/$file"
		done
	# A request of a finished job is kept a day for a retry (spec §3.6)
	find "$JOB_DIR" -maxdepth 1 -type f -name '[0-9]*.request' -mmin +1440 -printf '%f\n' \
		| while IFS= read -r file; do
			id=${file%%.*}
			case "$(job_get "$id" STATUS)" in
				queued | running) ;;
				*) rm -f "${JOB_DIR:?}/$file" ;;
			esac
		done
	job_unlock
}

#----------------------------------------------------------#
#                     Output and log                       #
#----------------------------------------------------------#

# The awk filter of v-run-job: masks the secret values listed in MASK (one
# per line, re-read for every line, so values resolved while the command
# runs count) and the credentials of URLs, writes to LOG and keeps it under
# MAX bytes (spec §3.2, §3.6).
job_filter() {
	local log="$1" mask="$2"
	LC_ALL=C awk -v lf="$log" -v mask="$mask" -v max="$JOB_LOG_MAX" -v head="$JOB_LOG_HEAD" '
	function maskurl(s,   out, seg, p) {
		out = ""
		while (match(s, /[A-Za-z][A-Za-z0-9+.-]*:\/\/[^\/@ \t"]+@/)) {
			seg = substr(s, RSTART, RLENGTH)
			p = index(seg, "://")
			out = out substr(s, 1, RSTART - 1) substr(seg, 1, p + 2) "***@"
			s = substr(s, RSTART + RLENGTH)
		}
		return out s
	}
	function repl(s, v,   out, i) {
		out = ""
		while ((i = index(s, v)) > 0) {
			out = out substr(s, 1, i - 1) "***"
			s = substr(s, i + length(v))
		}
		return out s
	}
	function size(   cmd, n) {
		cmd = "stat -c %s \"" lf "\" 2>/dev/null"
		n = 0
		cmd | getline n
		close(cmd)
		return n + 0
	}
	function trim(   cmd) {
		close(lf)
		cmd = "f=\"" lf "\"; { head -c " head " \"$f\"; printf \"\\n[... log trimmed: the first %s and the last %s bytes are kept ...]\\n\" " head " " (max - head) "; tail -c " (max - head) " \"$f\"; } > \"$f.trim\" && cat \"$f.trim\" > \"$f\"; rm -f \"$f.trim\""
		system(cmd)
		written = size()
	}
	BEGIN { written = size() }
	{
		line = $0
		if (mask != "") {
			while ((getline v < mask) > 0) {
				if (length(v) >= 3) line = repl(line, v)
			}
			close(mask)
		}
		line = maskurl(line)
		print line >> lf
		fflush(lf)
		written += length(line) + 1
		if (written > max + head) trim()
	}
	END {
		if (written > max) trim()
	}'
}

# Mask the secrets of job ID and URL credentials in standard input (used on
# ARGS and on log chunks)
job_mask() {
	local mask=''
	[ -n "${1-}" ] && mask=$(job_mask_file "$1")
	[ -f "$mask" ] || mask=''
	LC_ALL=C awk -v mask="$mask" '
	function maskurl(s,   out, seg, p) {
		out = ""
		while (match(s, /[A-Za-z][A-Za-z0-9+.-]*:\/\/[^\/@ \t"]+@/)) {
			seg = substr(s, RSTART, RLENGTH)
			p = index(seg, "://")
			out = out substr(s, 1, RSTART - 1) substr(seg, 1, p + 2) "***@"
			s = substr(s, RSTART + RLENGTH)
		}
		return out s
	}
	function repl(s, v,   out, i) {
		out = ""
		while ((i = index(s, v)) > 0) {
			out = out substr(s, 1, i - 1) "***"
			s = substr(s, i + length(v))
		}
		return out s
	}
	BEGIN { n = 0; if (mask != "") { while ((getline v < mask) > 0) if (length(v) >= 3) vals[++n] = v; close(mask) } }
	{ line = $0; for (i = 1; i <= n; i++) line = repl(line, vals[i]); print maskurl(line) }'
}

# Register VALUE as a secret of the running job: job_filter replaces it with
# *** in the log (secret_resolve_into calls it when JOB_MASK_FILE is set)
job_mask_register() {
	[ -n "${JOB_MASK_FILE-}" ] && [ -n "$1" ] || return 0
	printf '%s\n' "$1" >> "$JOB_MASK_FILE" 2> /dev/null || true
}

# A command run by a backup or letsencrypt job says that a failure is final
# (the queue does not run it again), as removing its line from the queue
# pipe did before E15
if ! declare -F job_no_retry > /dev/null; then
	job_no_retry() {
		if job_id_valid "${JOB_ID-}" && [ -d "$JOB_DIR" ]; then
			: > "$JOB_DIR/$JOB_ID.noretry"
		fi
		return 0
	}
fi

# Runs of a retried queued job before it fails: QUEUE_MAX_ATTEMPTS of
# arcpanel.conf, 3 when unset or not a number, at least 1
job_max_attempts() {
	local max="${QUEUE_MAX_ATTEMPTS-}"
	[[ "$max" =~ ^[0-9]+$ ]] || max=3
	max=$((10#$max))
	[ "$max" -ge 1 ] || max=1
	echo "$max"
}

# Write the log of ID from byte OFFSET (at most 256 KB, cleaned of colours
# and progress carriage returns, masked) to FILE. Sets JOB_LOG_NEXT (the
# offset of the next call) and JOB_LOG_SIZE.
JOB_CHUNK_MAX=262144
job_log_chunk() {
	local id="$1" offset="$2" out="$3" log size chunk length cut
	log=$(job_log "$id")
	size=0
	[ -f "$log" ] && size=$(stat -c %s "$log")
	offset=$((10#$offset))
	# A log trimmed under the reader (spec §3.2): go on from its end
	if [ "$offset" -gt "$size" ]; then
		offset=$size
	fi
	chunk=$(mktemp)
	if [ "$offset" -lt "$size" ]; then
		tail -c +$((offset + 1)) "$log" | head -c "$JOB_CHUNK_MAX" > "$chunk"
	fi
	length=$(stat -c %s "$chunk")
	# A full chunk ends at its last line break, so the next call starts on a
	# line (and never inside a character)
	if [ "$length" -eq "$JOB_CHUNK_MAX" ]; then
		cut=$(LC_ALL=C grep -boa $'\n' "$chunk" 2> /dev/null | tail -n1 | cut -d: -f1)
		if [ -n "$cut" ]; then
			truncate -s $((cut + 1)) "$chunk"
			length=$((cut + 1))
		fi
	fi
	JOB_LOG_NEXT=$((offset + length))
	JOB_LOG_SIZE=$size
	job_clean_text "$id" < "$chunk" > "$out"
	# job_mask ends every line with a line break; keep an open last line open
	if [ "$length" -gt 0 ] && [ "$(tail -c1 "$chunk" | od -An -tx1 | tr -d ' ')" != '0a' ]; then
		truncate -s -1 "$out"
	fi
	rm -f "$chunk"
}

# Standard input without colours, progress carriage returns and backspaces,
# masked for job ID
job_clean_text() {
	sed -e 's/\x1b\[[0-9;?]*[A-Za-z]//g' -e 's/\r$//' -e 's/.*\r//' -e 's/.\x08//g' | job_mask "$1"
}

# The last LINES lines of the log of ID, cleaned and masked
job_log_tail() {
	local log
	log=$(job_log "$1")
	[ -f "$log" ] || return 0
	tail -n "$2" "$log" | job_clean_text "$1"
}

#----------------------------------------------------------#
#            Sources that keep their old commands          #
#----------------------------------------------------------#

# ID of the newest job whose TYPE matches the extended regular expression
# TYPES and, when given, whose OBJECT is OBJECT (the v-list-*-task commands
# of the single-slot sources, spec §4.2)
job_newest() {
	local types="$1" object="${2-}" juser="${3-}"
	[ -d "$JOB_DIR" ] || return 1
	local -a files=()
	mapfile -t files < <(grep -rlE --include='*.conf' "(^| )TYPE='($types)'" "$JOB_DIR" 2> /dev/null)
	[ "${#files[@]}" -gt 0 ] || return 1
	if [ -n "$object" ]; then
		mapfile -t files < <(grep -lF " OBJECT='$object' " "${files[@]}" 2> /dev/null)
		[ "${#files[@]}" -gt 0 ] || return 1
	fi
	if [ -n "$juser" ]; then
		mapfile -t files < <(grep -lF " USER='$juser' " "${files[@]}" 2> /dev/null)
		[ "${#files[@]}" -gt 0 ] || return 1
	fi
	printf '%s\n' "${files[@]}" | sed -E 's|.*/([0-9]{14})\.conf$|\1|' | grep -E '^[0-9]{14}$' | sort | tail -n1
}

# The STATUS of the old task records: running, done or failed
job_legacy_status() {
	case "$1" in
		queued | running) echo running ;;
		succeeded) echo done ;;
		failed | cancelled) echo failed ;;
		*) echo '' ;;
	esac
}

# The arguments of the command of job ID from the Nth (1 = the first after
# the command), space-separated, as the old task records kept them
job_legacy_args() {
	local -a argv=()
	[ -f "$(job_argv_file "$1")" ] || return 0
	mapfile -d '' argv < "$(job_argv_file "$1")"
	printf '%s' "${argv[*]:$2}"
}

# Word N of the command of job ID (0 = the command)
job_argv_word() {
	local -a argv=()
	[ -f "$(job_argv_file "$1")" ] || return 0
	mapfile -d '' argv < "$(job_argv_file "$1")"
	printf '%s' "${argv[$2]-}"
}

# The common part of the old task records for job ID (empty without a job):
# ID, STATUS (running, done or failed), EXIT, START, END and LOG, the log
# from byte OFFSET with the next OFFSET (a string, as before) when MODE is
# offset, or its last N lines when MODE is lines
job_legacy_json() {
	local id="$1" mode="$2" value="$3" log='' next='0' tmp
	if [ -z "$id" ] || ! job_read "$id"; then
		JOB=()
		if [ "$mode" = offset ]; then
			jq -nc '{ID: "", STATUS: "", EXIT: "", START: "", END: "", LOG: "", OFFSET: "0"}'
		else
			jq -nc '{ID: "", STATUS: "", EXIT: "", START: "", END: "", LOG: ""}'
		fi
		return 0
	fi
	tmp=$(mktemp)
	if [ "$mode" = offset ]; then
		job_log_chunk "$id" "$value" "$tmp"
		next=$JOB_LOG_NEXT
	else
		job_log_tail "$id" "$value" > "$tmp"
	fi
	job_read "$id"
	jq -nc --arg id "$id" --arg status "$(job_legacy_status "${JOB[STATUS]}")" --arg exit "${JOB[EXIT]}" \
		--arg start "${JOB[START]}" --arg end "${JOB[END]}" --rawfile log "$tmp" --arg offset "$next" --arg mode "$mode" '
		{ID: $id, STATUS: $status, EXIT: $exit, START: $start, END: $end, LOG: ($log | if $mode == "lines" then sub("\n$"; "") else . end)}
		+ (if $mode == "offset" then {OFFSET: $offset} else {} end)'
	rm -f "$tmp"
}

# ACTOR of a job started by a v-start-* command that has no ACTOR argument:
# the panel (sudo from arcpanelweb) or the shell
job_default_actor() {
	if [ "${SUDO_USER-}" = 'arcpanelweb' ]; then
		echo panel
	else
		echo cli
	fi
}

# Whether this process runs as job JOB_ID of a type matching TYPES (the
# runners refuse to run outside their job)
job_is_current() {
	local types="$1"
	job_id_valid "${JOB_ID-}" || return 1
	job_read "$JOB_ID" || return 1
	[ "${JOB[STATUS]}" = 'running' ] && [[ "${JOB[TYPE]}" =~ ^($types)$ ]]
}

# Print the result of job_create/job_start for a v-start-* command and exit
# on a failure: MESSAGE for E_INUSE (the job that holds the key is named)
job_check_started() {
	local rc="$1" inuse_message="$2"
	[ "$rc" -eq 0 ] && return 0
	if [ "$rc" -eq "$E_INUSE" ] && [ -n "$inuse_message" ]; then
		check_result "$E_INUSE" "$inuse_message (job $JOB_INUSE)"
	fi
	check_result "$rc" "$JOB_REASON"
}

#----------------------------------------------------------#
#                        JSON                              #
#----------------------------------------------------------#

# The jq program that turns record lines into objects
JOB_JQ_RECORD='[scan("([A-Z_]+)='"'"'([^'"'"']*)'"'"'")] | map({key: .[0], value: .[1]}) | from_entries'

#----------------------------------------------------------#
#                     Drop-in types                        #
#----------------------------------------------------------#

if [ -d "$ARCPANEL/func/job.d" ]; then
	for __job_type_file in "$ARCPANEL"/func/job.d/*.sh; do
		# shellcheck disable=SC1090
		[ -f "$__job_type_file" ] && source "$__job_type_file"
	done
	unset __job_type_file
fi
