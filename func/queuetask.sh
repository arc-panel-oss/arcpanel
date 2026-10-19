#!/bin/bash
# ArcPanel: records for commands that wait in a queue pipe (E34 batch 3,
# docs/features/specs/2026-10-08-001-e34-api-v2-batch3-design.md §4).
# A schedule command writes data/users/USER/tasks/ID.conf (STATUS=queued)
# and queues `v-run-queue-task USER ID -- <the line it used to queue>`.
# The wrapper runs that command and stores the exit code. Records and logs
# are kept for 7 days.
#
# Retries keep the queue's behaviour from before the records: a worker that
# fails and leaves its line in the pipe runs again on the next pass (the
# Let's Encrypt worker does so on purpose while DNS propagates). Such a
# record goes back to `queued` with ERROR and ATTEMPTS until it has run
# QUEUE_MAX_ATTEMPTS times; then the wrapper removes the line and the
# record is `failed`, like a failed worker whose line is gone. A failed
# record carries the reason from its log in ERROR (Fleet PD1,
# docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md §4).
# A line whose record is gone or finished is dead: the wrapper removes it
# instead of running it.

#----------------------------------------------------------#
#                       Locations                          #
#----------------------------------------------------------#

QT_KEYS="ID KIND ARGS STATUS EXIT ERROR QUEUED START END PID ATTEMPTS"

# ERROR of a queued record whose line a worker removed (qt_reap_unlocked).
# The line may still run in the pass that read the pipe before the removal,
# so the wrapper still runs such a record.
QT_REMOVED_ERROR="removed from the queue"

# Commands a schedule script is allowed to hand to the wrapper.
qt_command_allowed() {
	case "$1" in
		v-backup-user) return 0 ;;
		v-restore-user) return 0 ;;
		v-download-backup) return 0 ;;
		v-backup-user-restic) return 0 ;;
		v-restore-user-restic) return 0 ;;
		v-restore-web-domain-restic) return 0 ;;
		v-restore-dns-domain-restic) return 0 ;;
		v-restore-mail-domain-restic) return 0 ;;
		v-restore-database-restic) return 0 ;;
		v-restore-cron-job-restic) return 0 ;;
		v-restore-file-restic) return 0 ;;
		v-add-letsencrypt-domain) return 0 ;;
		*) return 1 ;;
	esac
}

qt_dir() {
	echo "$ARCPANEL/data/users/$1/tasks"
}

qt_conf() {
	echo "$(qt_dir "$1")/$2.conf"
}

qt_log() {
	echo "$(qt_dir "$1")/$2.log"
}

#----------------------------------------------------------#
#                         Lock                             #
#----------------------------------------------------------#

# Hold the user's task lock. Do not call again before qt_unlock: a second
# flock on a new descriptor waits for this one forever.
qt_lock() {
	local dir="$ARCPANEL/data/users/$user"
	[ -d "$dir" ] || return 1
	exec {QT_LOCK_FD}> "$dir/tasks.lock"
	flock -x "$QT_LOCK_FD"
}

qt_unlock() {
	if [ -n "${QT_LOCK_FD:-}" ]; then
		flock -u "$QT_LOCK_FD"
		exec {QT_LOCK_FD}>&-
		QT_LOCK_FD=
	fi
}

#----------------------------------------------------------#
#                        Records                           #
#----------------------------------------------------------#

# Value of KEY in the task record of USER ID
qt_get() {
	local file
	file=$(qt_conf "$1" "$2")
	if [ -f "$file" ]; then
		grep -oE "(^| )$3='[^']*'" "$file" | head -n1 | cut -d\' -f2
	fi
}

# Set KEY=VALUE pairs. The other keys keep their values. Quotes and line
# breaks are dropped from values. Caller holds the lock.
qt_set() {
	local owner="$1" task="$2" file pair key line='' tmp
	local -A values=()
	shift 2
	for key in $QT_KEYS; do
		values[$key]=$(qt_get "$owner" "$task" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	line=''
	for key in $QT_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	file=$(qt_conf "$owner" "$task")
	mkdir -p "$(dirname "$file")"
	chmod 750 "$(dirname "$file")"
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
}

# New queued record. Sets QT_ID. Caller holds the lock.
qt_create_unlocked() {
	local kind="$1" args="$2" dir id
	dir=$(qt_dir "$user")
	mkdir -p "$dir"
	chmod 750 "$dir"
	id="$(date +%s)$(printf '%04d' $((RANDOM % 10000)))"
	while [ -e "$dir/$id.conf" ]; do
		id="$(date +%s)$(printf '%04d' $((RANDOM % 10000)))"
	done
	QT_ID=$id
	qt_set "$user" "$id" "ID=$id" "KIND=$kind" "ARGS=$args" STATUS=queued EXIT= ERROR= \
		"QUEUED=$(date +'%F %T')" START= END= PID= ATTEMPTS=0
}

# `v-run-queue-task USER ID -- ` for the record just created
qt_prefix() {
	printf '%s/v-run-queue-task %s %s -- ' "$BIN" "$user" "$QT_ID"
}

# Runs of a retried record before it fails: QUEUE_MAX_ATTEMPTS of
# arcpanel.conf, 3 when unset or not a number, at least 1.
qt_max_attempts() {
	local max="${QUEUE_MAX_ATTEMPTS-}"
	if ! [[ "$max" =~ ^[0-9]+$ ]]; then
		max=3
	fi
	max=$((10#$max))
	if [ "$max" -lt 1 ]; then
		max=1
	fi
	echo "$max"
}

# Reason of a failed record of USER ID: the last `Error:` line of its log
# without the prefix, else its last non-empty line; control characters
# removed, at most 300 characters.
qt_error_line() {
	local log line
	log=$(qt_log "$1" "$2")
	[ -f "$log" ] || return 0
	line=$(tr -d '\000-\010\013-\037\177' < "$log" | grep -E '^[[:space:]]*Error:' | tail -n1 \
		| sed -E 's/^[[:space:]]*Error:[[:space:]]*//')
	if [ -z "$line" ]; then
		line=$(tr -d '\000-\010\013-\037\177' < "$log" | grep -vE '^[[:space:]]*$' | tail -n1)
	fi
	line=$(printf '%s' "$line" | tr '\t' ' ' | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')
	printf '%s' "${line:0:300}"
}

# Print the id of the record just created. FORMAT json prints {"ID":"…"}.
qt_emit_id() {
	local id="$1" fmt="${2:-shell}"
	case "$fmt" in
		json) jq -nc --arg id "$id" '{ID: $id}' ;;
		*) printf '%s\n' "$id" ;;
	esac
}

# Create the record and append PREFIX + LINE to PIPE. LINE is the shell
# command the schedule script used to queue, redirections included.
qt_queue() {
	local kind="$1" args="$2" pipe="$3" line="$4" fmt="${format:-shell}"
	qt_lock
	qt_create_unlocked "$kind" "$args"
	mkdir -p "$(dirname "$pipe")"
	printf '%s%s\n' "$(qt_prefix)" "$line" >> "$pipe"
	qt_cleanup_unlocked
	qt_unlock
	qt_emit_id "$QT_ID" "$fmt"
}

# Mark every queued record whose line matches PATTERN as replaced. A record
# that already ran keeps its own result. Caller holds the lock. PATTERN is
# an extended regular expression.
qt_fail_replaced() {
	local file="$1" pattern="$2" line id
	[ -f "$file" ] || return 0
	while IFS= read -r line; do
		[ -n "$line" ] || continue
		id=$(printf '%s\n' "$line" | grep -oE "v-run-queue-task ${user} [0-9]+" | awk 'NR==1 { print $3 }')
		if [ -n "$id" ] && [ "$(qt_get "$user" "$id" STATUS)" = "queued" ]; then
			qt_set "$user" "$id" STATUS=failed "ERROR=replaced by a newer request" \
				"END=$(date +'%F %T')"
		fi
	done < <(grep -E "$pattern" "$file" || true)
}

#----------------------------------------------------------#
#                     Queue and age                        #
#----------------------------------------------------------#

# Whether the queue still holds the wrapped line of USER ID
qt_line_queued() {
	local owner="$1" task="$2" pipe
	local -a pipes=()
	shopt -s nullglob
	pipes=("$ARCPANEL/data/queue"/*.pipe)
	shopt -u nullglob
	for pipe in "${pipes[@]}"; do
		if grep -qF "v-run-queue-task $owner $task " "$pipe"; then
			return 0
		fi
	done
	return 1
}

# Remove the wrapped line of USER ID from every queue pipe. sed -i writes a
# new file, so a pass that is reading the old one is not disturbed (the
# workers remove their lines the same way). Caller holds the lock, which
# the schedule commands also hold when they add or replace lines.
qt_remove_line() {
	local owner="$1" task="$2" pipe
	local -a pipes=()
	shopt -s nullglob
	pipes=("$ARCPANEL/data/queue"/*.pipe)
	shopt -u nullglob
	for pipe in "${pipes[@]}"; do
		if grep -qF "v-run-queue-task $owner $task " "$pipe"; then
			sed -i "\|v-run-queue-task ${owner//./\\.} $task |d" "$pipe"
		fi
	done
}

# Whether the wrapper may run the record of USER ID: it is queued, or a
# worker removed its line from the pipe while a pass still holds it
# (qt_reap_unlocked) and it never ran since. Caller holds the lock.
qt_runnable_unlocked() {
	local owner="$1" task="$2" status
	[ -f "$(qt_conf "$owner" "$task")" ] || return 1
	status=$(qt_get "$owner" "$task" STATUS)
	if [ "$status" = "queued" ]; then
		return 0
	fi
	[ "$status" = "failed" ] \
		&& [ "$(qt_get "$owner" "$task" ERROR)" = "$QT_REMOVED_ERROR" ] \
		&& [ -z "$(qt_get "$owner" "$task" EXIT)" ]
}

# A queued record whose line a worker removed does not run again unless
# a pass still holds the line (see qt_runnable_unlocked).
# Caller holds the lock.
qt_reap_unlocked() {
	local dir file id status
	dir=$(qt_dir "$user")
	[ -d "$dir" ] || return 0
	shopt -s nullglob
	for file in "$dir"/*.conf; do
		id=$(basename "$file" .conf)
		status=$(qt_get "$user" "$id" STATUS)
		if [ "$status" = "queued" ] && ! qt_line_queued "$user" "$id"; then
			qt_set "$user" "$id" STATUS=failed "ERROR=$QT_REMOVED_ERROR" \
				"END=$(date +'%F %T')"
		fi
	done
	shopt -u nullglob
}

# Delete this user's records and logs older than 7 days. A running task is
# kept, and so is a queued task whose line is still in a pipe (a request
# that is retried, as Let's Encrypt waiting for DNS). Caller holds the lock.
qt_cleanup_unlocked() {
	local dir file id queued ts cutoff status
	dir=$(qt_dir "$user")
	[ -d "$dir" ] || return 0
	cutoff=$(date -d '7 days ago' +%s)
	shopt -s nullglob
	for file in "$dir"/*.conf; do
		id=$(basename "$file" .conf)
		status=$(qt_get "$user" "$id" STATUS)
		[ "$status" = "running" ] && continue
		queued=$(qt_get "$user" "$id" QUEUED)
		ts=$(date -d "$queued" +%s 2> /dev/null || echo 0)
		if [ -n "$ts" ] && [ "$ts" -lt "$cutoff" ]; then
			if [ "$status" = "queued" ] && qt_line_queued "$user" "$id"; then
				continue
			fi
			rm -f "$file" "$dir/$id.log"
		fi
	done
	for file in "$dir"/*.log; do
		id=$(basename "$file" .log)
		if [ ! -f "$dir/$id.conf" ]; then
			rm -f "$file"
		fi
	done
	shopt -u nullglob
}

# Every user, as v-update-sys-queue daily does.
qt_cleanup_all() {
	local home name
	shopt -s nullglob
	for home in "$ARCPANEL/data/users"/*; do
		[ -d "$home/tasks" ] || continue
		name=$(basename "$home")
		user=$name
		qt_lock || continue
		qt_cleanup_unlocked
		qt_unlock
	done
	shopt -u nullglob
}

#----------------------------------------------------------#
#                       Invocation                         #
#----------------------------------------------------------#

# Resolve the command the wrapper may run into QT_ARGV.
# A restore line starts with the fixed `nice -n 19 ionice -c 3` prefix;
# the v-* command after it, or the first argument otherwise, must be one
# of QT_ALLOWED and must live in $BIN.
qt_prepare_argv() {
	local -a args=("$@")
	local i=0 base
	QT_ARGV=()
	if [ "${args[0]-}" = "nice" ] && [ "${args[1]-}" = "-n" ] && [ "${args[2]-}" = "19" ] \
		&& [ "${args[3]-}" = "ionice" ] && [ "${args[4]-}" = "-c" ] && [ "${args[5]-}" = "3" ]; then
		QT_ARGV=(nice -n 19 ionice -c 3)
		i=6
	fi
	if [ "$i" -ge ${#args[@]} ]; then
		return 1
	fi
	base=$(basename "${args[$i]}")
	if ! qt_command_allowed "$base"; then
		return 1
	fi
	if [[ "${args[$i]}" == */* && "${args[$i]}" != "$BIN/$base" ]]; then
		return 1
	fi
	QT_ARGV+=("$BIN/$base")
	while [ "$((i + 1))" -lt ${#args[@]} ]; do
		i=$((i + 1))
		QT_ARGV+=("${args[$i]}")
	done
	return 0
}
