#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel backup hosts of earlier versions on top of destinations and     #
# jobs (E48 §10.2): v-add-backup-host and its relatives keep their         #
# arguments and output. A host of TYPE is the destination named TYPE; the  #
# archive hosts are the targets of the job "Nightly archives", the restic   #
# host is the target of the job "Nightly snapshots".                        #
#                                                                           #
#===========================================================================#

BL_ARCHIVE_JOB='Nightly archives'
BL_SNAPSHOT_JOB='Nightly snapshots'

# The ID of the job NAME with ENGINE, created with SCHEDULE when missing
bl_job() {
	local name="$1" engine="$2" schedule="$3" id
	local IFS=$' \t\n'
	for id in $(bj_ids); do
		if [ "$(bj_value "$id" NAME)" = "$name" ] && [ "$(bj_value "$id" ENGINE)" = "$engine" ]; then
			echo "$id"
			return 0
		fi
	done
	return 1
}

# Add DEST to the targets of job NAME (created when missing) or change its
# retention: bl_target_set NAME ENGINE SCHEDULE DEST:KEEP…
bl_target_set() {
	local name="$1" engine="$2" schedule="$3" target="$4" dest id targets
	dest=${target%%:*}
	if ! id=$(bl_job "$name" "$engine"); then
		"$BIN/v-add-backup-job" "$name" "$engine" all "$schedule" "$target" > /dev/null
		return $?
	fi
	targets=$(bj_value "$id" TARGETS | tr ',' '\n' | awk -F: -v d="$dest" '$1 != d' | tr '\n' ',' | sed 's/,$//')
	"$BIN/v-change-backup-job" "$id" "$name" "$engine" "$(bj_value "$id" SCOPE)" "$(bj_value "$id" SCHEDULE)" \
		"${targets:+$targets,}$target" "$(bj_value "$id" EXCLUDE_USERS)" > /dev/null
}

# Remove DEST from every job; a job left without targets is deleted
bl_target_drop() {
	local dest="$1" id targets
	local IFS=$' \t\n'
	for id in $(bj_ids); do
		[[ ",$(bj_value "$id" TARGETS | tr ',' '\n' | cut -d: -f1 | tr '\n' ',')," == *",$dest,"* ]] || continue
		targets=$(bj_value "$id" TARGETS | tr ',' '\n' | awk -F: -v d="$dest" '$1 != d' | tr '\n' ',' | sed 's/,$//')
		if [ -z "$targets" ]; then
			"$BIN/v-delete-backup-job" "$id" > /dev/null
		else
			"$BIN/v-change-backup-job" "$id" "$(bj_value "$id" NAME)" "$(bj_value "$id" ENGINE)" "$(bj_value "$id" SCOPE)" \
				"$(bj_value "$id" SCHEDULE)" "$targets" "$(bj_value "$id" EXCLUDE_USERS)" > /dev/null
		fi
	done
}

# Add or change destination NAME: bl_destination NAME DRIVER OPTIONS SECRETS
bl_destination() {
	if bd_exists "$1"; then
		"$BIN/v-change-backup-destination" "$1" "$3" "$4"
	else
		"$BIN/v-add-backup-destination" "$1" "$2" "$3" "$4"
	fi
}

# Check NAME, pinning an SFTP host key on first use as the hosts of earlier
# versions did (they did not check host keys at all)
bl_check() {
	local name="$1" key
	if [ "$(bd_value "$name" DRIVER)" = sftp ] && [ -z "$(bd_value "$name" HOST_KEY)" ]; then
		key=$("$BIN/v-check-backup-destination" "$name" '' json 2> /dev/null | jq -r --arg n "$name" '.[$n].HOST_KEY // empty')
		if [ -n "$key" ]; then
			"$BIN/v-check-backup-destination" "$name" "$key" > /dev/null
			return $?
		fi
	fi
	"$BIN/v-check-backup-destination" "$name" > /dev/null
}

# The repository of the restic host: DRIVER<TAB>PATH<TAB>REMOTE for a
# folder (local), an rclone remote of root's configuration (rclone) or the
# S3 remote of E44 (s3)
bl_restic_repo() {
	local repo="$1"
	case "$repo" in
		rclone:arcpanel-s3:*) printf 's3\t%s\t\n' "${repo#rclone:arcpanel-s3:}" ;;
		rclone:*:*)
			repo=${repo#rclone:}
			printf 'rclone\t%s\t%s\n' "${repo#*:}" "${repo%%:*}"
			;;
		/*) printf 'local\t%s\t\n' "$repo" ;;
		*) return 1 ;;
	esac
}

# The section of REMOTE in root's rclone configuration, without its header
bl_rclone_section() {
	awk -v r="[$1]" '$0 == r { on = 1; next } /^\[/ { on = 0 } on && NF' /root/.config/rclone/rclone.conf 2> /dev/null
}

# The destination of the job "Nightly snapshots", if any
bl_snapshot_destination() {
	local id
	id=$(bl_job "$BL_SNAPSHOT_JOB" snapshot) || return 1
	bj_target_names "$id" | head -n1
}

# Set the variables of v-list-backup-host-restic from the job "Nightly
# snapshots": REPO as earlier versions wrote it, the retention, S3_*
bl_restic_values() {
	local id dest driver path bucket target
	REPO='' SNAPSHOTS='' KEEP_DAILY='' KEEP_WEEKLY='' KEEP_MONTHLY='' KEEP_YEARLY='' S3_ENDPOINT='' S3_REGION='' S3_KEY_ID=''
	id=$(bl_job "$BL_SNAPSHOT_JOB" snapshot) || return 1
	dest=$(bj_target_names "$id" | head -n1)
	[ -n "$dest" ] && bd_exists "$dest" || return 1
	target=$(bj_value "$id" TARGETS | tr ',' '\n' | head -n1)
	IFS=: read -r _ SNAPSHOTS KEEP_DAILY KEEP_WEEKLY KEEP_MONTHLY KEEP_YEARLY <<< "$target"
	driver=$(bd_value "$dest" DRIVER)
	path=$(bd_value "$dest" PATH)
	case "$driver" in
		local) REPO=$path ;;
		s3)
			bucket=$(bd_value "$dest" BUCKET)
			REPO="rclone:arcpanel-s3:$bucket${path:+/${path#/}}"
			S3_ENDPOINT=$(bd_value "$dest" ENDPOINT)
			S3_REGION=$(bd_value "$dest" REGION)
			S3_KEY_ID=$(bd_value "$dest" ACCESS_KEY_ID)
			;;
		rclone) REPO="rclone:$(bd_value "$dest" REMOTE):$path" ;;
		*) REPO="$driver:$dest" ;;
	esac
	if [ "$(bd_value "$dest" RESTIC_PREFIX)" != '' ]; then
		REPO="$REPO/$(bd_value "$dest" RESTIC_PREFIX)"
	fi
}

# Drop DEST from every job and delete it when no job targets it any more
bl_drop_destination() {
	bl_target_drop "$1"
	if bd_exists "$1" && [ -z "$(bd_jobs_of "$1")" ]; then
		"$BIN/v-delete-backup-destination" "$1" > /dev/null
	fi
}
