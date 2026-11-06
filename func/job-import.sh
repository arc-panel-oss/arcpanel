#!/bin/bash
# shellcheck disable=SC2034

#===========================================================================#
#                                                                           #
# ArcPanel: import of the task records of 2.6 into jobs (E15 spec §9)       #
# docs/features/specs/2026-11-05-001-e15-job-center-design.md               #
#                                                                           #
# The upgrade to 3.0.0 runs job_import_start: the finished records of the   #
# nine old sources become jobs with the same IDs (their files are removed), #
# the lines of the backup and Let's Encrypt pipes become queued jobs, and   #
# data/jobs/.import-pending marks records that still run. v-update-sys-jobs #
# calls job_import every minute while the marker exists; the marker goes    #
# when nothing is left or after 24 hours.                                   #
#                                                                           #
#===========================================================================#

[ -n "${JOB_IMPORT_SH_LOADED-}" ] && return 0
JOB_IMPORT_SH_LOADED=1

JOB_IMPORT_MARKER_HOURS=24

# Value of KEY in a KEY='value' file
job_import_value() {
	grep -oE "(^| )$2='[^']*'" "$1" 2> /dev/null | head -n1 | cut -d\' -f2
}

# Map the STATUS of an old record: done, failed, partial and the queue's
# succeeded and failed; anything else (running, queued) is not finished
job_import_status() {
	case "$1" in
		done | succeeded) echo succeeded ;;
		failed | partial) echo failed ;;
		cancelled) echo cancelled ;;
		*) echo '' ;;
	esac
}

# Write job ID from an old record: TYPE USER OBJECT ARGS STATUS EXIT ERROR
# QUEUED START END ATTEMPTS and the old LOG file. Returns 1 when a job with
# this ID exists already (the record is then left alone).
job_import_write() {
	local id="$1" type="$2" juser="$3" object="$4" args="$5" status="$6" exit_code="$7" error="$8" \
		queued="$9" start="${10}" end="${11}" attempts="${12}" log="${13}"
	[[ "$id" =~ ^[0-9]{14}$ ]] || return 1
	[ -e "$(job_conf "$id")" ] && return 1
	if [ -n "$log" ] && [ -f "$log" ]; then
		tail -c "$JOB_LOG_MAX" "$log" | job_mask > "$(job_log "$id")" 2> /dev/null || : > "$(job_log "$id")"
	else
		: > "$(job_log "$id")"
	fi
	job_file_mode "$(job_log "$id")"
	if [ -z "$error" ] && [ "$status" = 'failed' ] && [ -s "$(job_log "$id")" ]; then
		error=$(job_error_line "$id" 2> /dev/null || true)
	fi
	job_set "$id" "ID=$id" "TYPE=$type" "USER=$juser" "OWNER=$(job_owner_of "$juser")" ACTOR=upgrade \
		"OBJECT=$object" "ARGS=$args" "STATUS=$status" "EXIT=$exit_code" "ERROR=$error" \
		"QUEUED=${queued:-$start}" "START=$start" "END=$end" "ATTEMPTS=${attempts:-1}"
	return 0
}

# Import a single-record source: FILE LOG TYPE USER OBJECT ARGS; prints
# "pending" when the record still runs. The files go once imported.
job_import_single() {
	local file="$1" log="$2" type="$3" juser="$4" object="$5" args="$6" id status
	[ -f "$file" ] || return 0
	id=$(job_import_value "$file" ID)
	status=$(job_import_status "$(job_import_value "$file" STATUS)")
	if [ -z "$id" ]; then
		rm -f "$file" "$log"
		return 0
	fi
	if [ -z "$status" ]; then
		echo pending
		return 0
	fi
	if job_import_write "$id" "$type" "$juser" "$object" "$args" "$status" \
		"$(job_import_value "$file" EXIT)" "$(job_import_value "$file" ERROR)" '' \
		"$(job_import_value "$file" START)" "$(job_import_value "$file" END)" 1 "$log"; then
		echo "[ * ] E15: imported $type $id"
	fi
	rm -f "$file" "$log"
}

# The type of a queue record: KIND and ARGS
job_import_queue_type() {
	case "$1" in
		backup) echo backup.user ;;
		backup-download) echo backup.download ;;
		snapshot) echo backup.restic ;;
		restore) echo restore.user ;;
		file-restore) echo restore.file ;;
		snapshot-restore) echo restore.restic ;;
		letsencrypt) echo letsencrypt.domain ;;
		deploy)
			case " $2 " in
				*" rollback "*) echo deploy.rollback ;;
				*) echo deploy.run ;;
			esac
			;;
		*) echo '' ;;
	esac
}

# The object of a queue record: TYPE USER ARGS
job_import_queue_object() {
	local first="${3%% *}"
	case "$1" in
		letsencrypt.domain)
			case " $3 " in
				*" mail "*) echo "mail:$first" ;;
				*) echo "web:$first" ;;
			esac
			;;
		deploy.*) echo "deploy:$first" ;;
		*) echo "user:$2" ;;
	esac
}

# The finished records of data/users/USER/tasks/ (backups, restores, Let's
# Encrypt, deploys); prints "pending" for each record still queued or running
job_import_queue_records() {
	local juser="$1" dir="$ARCPANEL/data/users/$1/tasks" file id kind args status type
	[ -d "$dir" ] || return 0
	for file in "$dir"/*.conf; do
		[ -f "$file" ] || continue
		id=$(basename "$file" .conf)
		kind=$(job_import_value "$file" KIND)
		args=$(job_import_value "$file" ARGS)
		status=$(job_import_status "$(job_import_value "$file" STATUS)")
		type=$(job_import_queue_type "$kind" "$args")
		if [ -z "$type" ]; then
			rm -f "$file" "$dir/$id.log"
			continue
		fi
		if [ -z "$status" ]; then
			echo pending
			continue
		fi
		if job_import_write "$id" "$type" "$juser" "$(job_import_queue_object "$type" "$juser" "$args")" "$args" \
			"$status" "$(job_import_value "$file" EXIT)" "$(job_import_value "$file" ERROR)" \
			"$(job_import_value "$file" QUEUED)" "$(job_import_value "$file" START)" \
			"$(job_import_value "$file" END)" "$(job_import_value "$file" ATTEMPTS)" "$dir/$id.log"; then
			echo "[ * ] E15: imported $type $id of $juser"
		fi
		rm -f "$file" "$dir/$id.log"
	done
	rmdir "$dir" 2> /dev/null || true
}

# One pass over every old source; prints the import lines and "pending" for
# each record that still runs
job_import_pass() {
	local juser dir name
	job_import_single "$ARCPANEL/data/sys-task/task.conf" "$ARCPANEL/data/sys-task/task.log" \
		"sys.$(job_import_value "$ARCPANEL/data/sys-task/task.conf" KIND)" system \
		"$(job_import_value "$ARCPANEL/data/sys-task/task.conf" KIND)" \
		"v-run-sys-task $(job_import_value "$ARCPANEL/data/sys-task/task.conf" KIND) $(job_import_value "$ARCPANEL/data/sys-task/task.conf" ARGS)"
	job_import_single "$ARCPANEL/data/docker/engine-task.state" "$ARCPANEL/data/docker/engine-task.log" \
		docker.engine system docker:engine "v-run-sys-docker-task $(job_import_value "$ARCPANEL/data/docker/engine-task.state" ARGS)"
	for dir in "$ARCPANEL"/data/users/*/; do
		[ -d "$dir" ] || continue
		juser=$(basename "$dir")
		job_import_single "$dir/node-task.conf" "$dir/node-task.log" \
			"$(job_import_node_type "$(job_import_value "$dir/node-task.conf" ACTION)")" "$juser" "node:$juser" \
			"v-run-user-node-task $juser $(job_import_value "$dir/node-task.conf" ACTION) $(job_import_value "$dir/node-task.conf" ARGS)"
		job_import_single "$dir/app-process-task.conf" "$dir/app-process-task.log" \
			"app-process.$(job_import_value "$dir/app-process-task.conf" TASK)" "$juser" \
			"app-process:$juser:$(job_import_value "$dir/app-process-task.conf" DOMAIN):$(job_import_value "$dir/app-process-task.conf" NAME)" \
			"v-run-app-process-task $juser $(job_import_value "$dir/app-process-task.conf" DOMAIN) $(job_import_value "$dir/app-process-task.conf" NAME) $(job_import_value "$dir/app-process-task.conf" TASK)"
		job_import_single "$dir/quick-install-task.conf" "$dir/quick-install-task.log" quick-install "$juser" \
			"web:$(job_import_value "$dir/quick-install-task.conf" DOMAIN)" \
			"v-run-user-quick-install $juser $(job_import_value "$dir/quick-install-task.conf" DOMAIN) $(job_import_value "$dir/quick-install-task.conf" APP)"
		job_import_single "$dir/malware/task.conf" "$dir/malware/task.log" malware.scan "$juser" "malware:$juser" \
			"v-scan-user-web $juser $(job_import_value "$dir/malware/task.conf" DOMAIN) full manual"
		for name in "$dir"/docker/*/; do
			[ -f "$name/task.state" ] || continue
			job_import_single "$name/task.state" "$name/task.log" \
				"docker.$(job_import_value "$name/task.state" ACTION)" "$juser" "docker:$juser:$(basename "$name")" \
				"v-run-docker-app-task $juser $(basename "$name") $(job_import_value "$name/task.state" ACTION) $(job_import_value "$name/task.state" ARGS)"
		done
		job_import_queue_records "$juser"
	done
}

job_import_node_type() {
	case "$1" in
		add-version) echo node.install ;;
		app-build) echo node.build ;;
		*) echo node.npm ;;
	esac
}

# The lines of the backup and Let's Encrypt pipes as queued jobs. A line is
# `…/v-run-queue-task USER ID -- …/COMMAND ARG…` (2.6) or a plain command
# line; only the commands of the queue sources are taken.
job_import_pipes() {
	local pipe line words cmd base juser id type object
	for pipe in "$ARCPANEL/data/queue/backup.pipe" "$ARCPANEL/data/queue/letsencrypt.pipe"; do
		[ -f "$pipe" ] || continue
		while IFS= read -r line || [ -n "$line" ]; do
			line=${line%%>*}
			line=${line%%;*}
			# Words as the shell read them (the Let's Encrypt aliases are quoted)
			job_import_words "$line"
			[ "${#words[@]}" -gt 0 ] || continue
			juser=''
			id=''
			if [ "$(basename "${words[0]}")" = 'v-run-queue-task' ]; then
				juser=${words[1]-}
				id=${words[2]-}
				words=("${words[@]:4}")
			fi
			[ "${#words[@]}" -gt 0 ] || continue
			base=$(basename "${words[0]}")
			cmd=("$base" "${words[@]:1}")
			[ -n "$juser" ] || juser=${words[1]-}
			is_format_valid_silent_user "$juser" || continue
			case "$base" in
				v-backup-user) type=backup.user ;;
				v-download-backup) type=backup.download ;;
				v-backup-user-restic) type=backup.restic ;;
				v-restore-user) type=restore.user ;;
				v-restore-file-restic) type=restore.file ;;
				v-restore-*-restic) type=restore.restic ;;
				v-add-letsencrypt-domain) type=letsencrypt.domain ;;
				*)
					echo "[ ! ] E15: skipped a queue line of an unknown command: $base"
					continue
					;;
			esac
			object="user:$juser"
			if [ "$type" = 'letsencrypt.domain' ]; then
				# v-add-letsencrypt-domain USER DOMAIN ALIASES MAIL [KEY]
				object="web:${cmd[2]-}"
				[ "${cmd[4]-}" = 'yes' ] && object="mail:${cmd[2]-}"
			fi
			if job_create "$type" "$juser" "$object" upgrade -- "${cmd[@]}"; then
				echo "[ * ] E15: queued $type of $juser as job $JOB_ID"
			else
				echo "[ ! ] E15: $type of $juser was not queued: $JOB_REASON"
			fi
			if [ -n "$id" ]; then
				rm -f "$ARCPANEL/data/users/$juser/tasks/$id.conf" "$ARCPANEL/data/users/$juser/tasks/$id.log"
			fi
		done < "$pipe"
		rm -f "$pipe"
	done
}

# Split LINE into the array words as the shell would: blanks separate words,
# single quotes keep blanks and may enclose an empty word (the pipe lines of
# 2.6 use no other quoting)
job_import_words() {
	local s="$1" w='' c i quoted=0 started=0
	words=()
	for ((i = 0; i < ${#s}; i++)); do
		c=${s:i:1}
		if [ "$quoted" -eq 1 ]; then
			if [ "$c" = "'" ]; then
				quoted=0
			else
				w+=$c
			fi
		elif [ "$c" = "'" ]; then
			quoted=1
			started=1
		elif [[ "$c" == [[:space:]] ]]; then
			if [ "$started" -eq 1 ]; then
				words+=("$w")
				w=''
				started=0
			fi
		else
			w+=$c
			started=1
		fi
	done
	if [ "$started" -eq 1 ]; then
		words+=("$w")
	fi
	return 0
}

# A user name of the panel (no output)
is_format_valid_silent_user() {
	[[ "$1" =~ ^[[:alnum:]][-.[:alnum:]_]{0,28}[[:alnum:]]$ ]] && [ -d "$ARCPANEL/data/users/$1" ]
}

# The upgrade step: the pipes first (their records are then queued jobs),
# then every finished record; the marker stays while records run.
job_import_start() {
	mkdir -p "$JOB_DIR"
	chown root:arcpanelweb "$JOB_DIR" 2> /dev/null || true
	chmod 750 "$JOB_DIR"
	job_import_pipes
	date +%s > "$JOB_DIR/.import-pending"
	job_import
}

# One import pass (v-update-sys-jobs while the marker exists)
job_import() {
	local out since
	out=$(job_import_pass)
	printf '%s\n' "$out" | grep -v '^pending$' | grep -v '^$' || true
	since=$(cat "$JOB_DIR/.import-pending" 2> /dev/null || echo 0)
	[[ "$since" =~ ^[0-9]+$ ]] || since=0
	if ! printf '%s\n' "$out" | grep -qx pending \
		|| [ $(($(date +%s) - since)) -ge $((JOB_IMPORT_MARKER_HOURS * 3600)) ]; then
		rm -f "$JOB_DIR/.import-pending"
	fi
}
