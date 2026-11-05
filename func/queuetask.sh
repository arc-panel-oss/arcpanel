#!/bin/bash
# ArcPanel: the queue tasks of E34 batch 3
# (docs/features/specs/2026-10-08-001-e34-api-v2-batch3-design.md §4) are
# jobs since E15 (func/job.sh): backups and restores in the backup queue,
# certificates in the letsencrypt queue, Git deploys in the deploy class.
# The two queue pipes and v-run-queue-task are gone. This file keeps the
# helpers of the v-schedule-* commands and the old terms of
# v-list-user-tasks (KIND, ARGS) for one release; it goes in 2.8.

# shellcheck source=/usr/local/arcpanel/func/job.sh
source "$ARCPANEL/func/job.sh"

# The job types that were queue tasks
QT_TYPES='backup\.(user|restic|download)|restore\.(user|restic|file)|letsencrypt\.domain|deploy\.(run|rollback)'

# Print the ID of a new job. FORMAT json prints {"ID":"…"}.
qt_emit_id() {
	job_emit_id "$1" "${2:-shell}"
}

# Start a job of TYPE on OBJECT for the global $user and print its ID in
# $format; exits with the reason when it cannot be created
#
#   qt_job TYPE OBJECT -- COMMAND [ARG...]
qt_job() {
	local type="$1" object="$2" rc
	shift 3
	job_start "$type" "$user" "$object" "$(job_default_actor)" -- "$@"
	rc=$?
	if [ "$rc" -eq "$E_INUSE" ]; then
		check_result "$E_INUSE" "job $JOB_INUSE is queued or running for $user ($(job_get "$JOB_INUSE" TYPE))"
	fi
	job_check_started "$rc" ''
	qt_emit_id "$JOB_ID" "${format:-shell}"
}

# The KIND of the old record for job type TYPE
qt_kind() {
	case "$1" in
		backup.user) echo backup ;;
		backup.download) echo backup-download ;;
		backup.restic) echo snapshot ;;
		restore.user) echo restore ;;
		restore.file) echo file-restore ;;
		restore.restic) echo snapshot-restore ;;
		letsencrypt.domain) echo letsencrypt ;;
		deploy.*) echo deploy ;;
		*) echo "$1" ;;
	esac
}

# The ARGS of the old record for job ID
qt_args() {
	local id="$1" type command
	local -a argv=()
	type=$(job_get "$id" TYPE)
	[ -f "$(job_argv_file "$id")" ] && mapfile -d '' argv < "$(job_argv_file "$id")"
	command=$(basename -- "${argv[0]-}")
	case "$type" in
		backup.download | restore.user) printf '%s' "${argv[2]-}" ;;
		restore.file) printf '%s %s' "${argv[2]-}" "${argv[3]-}" ;;
		restore.restic)
			case "$command" in
				v-restore-web-domain-restic) printf '%s web %s' "${argv[2]-}" "${argv[3]-}" ;;
				v-restore-dns-domain-restic) printf '%s dns %s' "${argv[2]-}" "${argv[3]-}" ;;
				v-restore-mail-domain-restic) printf '%s mail %s' "${argv[2]-}" "${argv[3]-}" ;;
				v-restore-database-restic) printf '%s db %s' "${argv[2]-}" "${argv[3]-}" ;;
				v-restore-cron-job-restic) printf '%s cron' "${argv[2]-}" ;;
				*) printf '%s' "${argv[2]-}" ;;
			esac
			;;
		letsencrypt.domain)
			if [ "${argv[4]-}" = 'yes' ]; then
				printf '%s mail' "${argv[2]-}"
			else
				printf '%s' "${argv[2]-}"
			fi
			;;
		deploy.*) printf '%s' "${argv[*]:2}" ;;
	esac
}
