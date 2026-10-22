#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel records of backup runs (Fleet PD2): every run of v-backup-user  #
# and v-backup-user-restic, nightly, requested or manual, failed ones      #
# included, in data/users/USER/backup-runs.conf, newest last, at most 30.  #
# See                                                                      #
# docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md   #
# §5.                                                                      #
#                                                                           #
#===========================================================================#

# An array: the backup commands change IFS
BR_KEYS=(ID ENGINE TRIGGER TASK STATUS START END EXIT ERROR ARCHIVE SIZE PID JOB DESTS)
BR_KEEP=30

br_file() {
	echo "$ARCPANEL/data/users/$1/backup-runs.conf"
}

# Hold the lock of USER's runs. Do not call again before br_unlock.
br_lock() {
	local dir="$ARCPANEL/data/users/$1"
	[ -d "$dir" ] || return 1
	exec {BR_LOCK_FD}> "$dir/backup-runs.lock"
	flock -x "$BR_LOCK_FD"
}

br_unlock() {
	if [ -n "${BR_LOCK_FD:-}" ]; then
		flock -u "$BR_LOCK_FD"
		exec {BR_LOCK_FD}>&-
		BR_LOCK_FD=
	fi
}

# Value of KEY in one record line
br_value() {
	printf '%s\n' "$1" | grep -oE "(^| )$2='[^']*'" | head -n1 | cut -d\' -f2
}

# Set KEY=VALUE pairs of the run ID of USER, or append the run when it is
# new; the other keys keep their values. Quotes, line breaks and tabs are
# dropped from values. Caller holds the lock.
br_set_unlocked() {
	local owner="$1" run="$2" file line key pair new='' tmp
	local -A values=()
	shift 2
	file=$(br_file "$owner")
	line=$(grep -m1 "^ID='$run' " "$file" 2> /dev/null)
	for key in "${BR_KEYS[@]}"; do
		values[$key]=$(br_value "$line" "$key")
	done
	values[ID]=$run
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r" | tr '\t' ' ')
	done
	for key in "${BR_KEYS[@]}"; do
		new="$new$key='${values[$key]}' "
	done
	new=${new% }
	tmp="$file.$$"
	if [ -n "$line" ]; then
		BR_LINE="$new" BR_PREFIX="ID='$run' " awk \
			'index($0, ENVIRON["BR_PREFIX"]) == 1 { print ENVIRON["BR_LINE"]; next } { print }' "$file" > "$tmp"
	else
		{
			[ -f "$file" ] && cat "$file"
			printf '%s\n' "$new"
		} > "$tmp"
	fi
	tail -n "$BR_KEEP" "$tmp" > "$tmp.keep"
	rm -f "$tmp"
	chmod 640 "$tmp.keep"
	mv -f "$tmp.keep" "$file"
}

# Runs left `running` by a process that is gone (killed, power cut) failed.
# Caller holds the lock.
br_reap_unlocked() {
	local owner="$1" file line run pid
	file=$(br_file "$owner")
	[ -f "$file" ] || return 0
	while IFS= read -r line; do
		run=$(br_value "$line" ID)
		pid=$(br_value "$line" PID)
		if [ -n "$run" ] && { ! [[ "$pid" =~ ^[0-9]+$ ]] || ! kill -0 "$pid" 2> /dev/null; }; then
			br_set_unlocked "$owner" "$run" STATUS=failed "END=$(date +'%F %T')" PID= ERROR=interrupted
		fi
	done < <(grep "STATUS='running'" "$file")
}

# check_result callback while a run is recorded: keeps the reason for the
# record, then does what check_result does by default.
br_check_result() {
	BR_ERROR=$2
	echo "Error: $2"
	log_event "$1" "$ARGUMENTS"
}

# EXIT trap of a recorded run: succeeded when the command exits 0 without
# a reason, else failed with the exit code.
br_on_exit() {
	local rc="$1" status=succeeded
	if [ "$rc" -ne 0 ] || [ -n "${BR_ERROR-}" ]; then
		status=failed
	fi
	if [ "$status" = failed ]; then
		BR_ARCHIVE=
		BR_SIZE=
	fi
	br_lock "$BR_USER" || return 0
	br_set_unlocked "$BR_USER" "$BR_ID" "STATUS=$status" "END=$(date +'%F %T')" "EXIT=$rc" \
		"ERROR=$(printf '%.300s' "${BR_ERROR:-${BR_ERROR_PARTIAL-}}")" "ARCHIVE=${BR_ARCHIVE-}" "SIZE=${BR_SIZE-}" PID= "DESTS=${BR_DESTS-}"
	br_unlock
	# The user hears of a failed or partial backup (E48 spec §7)
	if declare -F bj_notify_user > /dev/null; then
		if [ "$status" = failed ]; then
			bj_notify_user "$BR_USER" failed "$(printf '%.300s' "${BR_ERROR:-exit code $rc}")"
		elif [ -n "${BR_ERROR_PARTIAL-}" ]; then
			bj_notify_user "$BR_USER" partial "$(printf '%.300s' "$BR_ERROR_PARTIAL")"
		fi
	fi
}

# br_begin USER ENGINE: record a running run of this process and finish it
# when the process exits. The command sets BR_ARCHIVE and BR_SIZE once the
# archive exists, and BR_ERROR for a failure that does not go through
# check_result. TRIGGER is `request` under v-run-queue-task (QT_TASK_ID),
# `schedule` under the nightly loops (ARC_BACKUP_TRIGGER), else `cli`.
br_begin() {
	local trigger=cli task=''
	BR_USER=$1
	BR_ERROR=
	BR_ARCHIVE=
	BR_SIZE=
	if [ -n "${QT_TASK_ID-}" ]; then
		trigger=request
		task=$QT_TASK_ID
	elif [ "${ARC_BACKUP_TRIGGER-}" = schedule ]; then
		trigger=schedule
	fi
	br_lock "$BR_USER" || return 0
	br_reap_unlocked "$BR_USER"
	BR_ID="$(date +%s)$(printf '%04d' $((RANDOM % 10000)))"
	while grep -q "^ID='$BR_ID' " "$(br_file "$BR_USER")" 2> /dev/null; do
		BR_ID="$(date +%s)$(printf '%04d' $((RANDOM % 10000)))"
	done
	br_set_unlocked "$BR_USER" "$BR_ID" "ENGINE=$2" "TRIGGER=$trigger" "TASK=$task" STATUS=running \
		"START=$(date +'%F %T')" END= EXIT= ERROR= ARCHIVE= SIZE= "PID=$$" "JOB=${BR_JOB-}" DESTS=
	br_unlock
	# shellcheck disable=SC2034
	CHECK_RESULT_CALLBACK=br_check_result
	trap 'br_on_exit $?' EXIT
}
