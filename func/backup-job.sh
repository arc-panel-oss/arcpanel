#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel backup jobs (E48): what runs (archive or snapshot), when (a      #
# schedule), for whom (a scope of users) and where to (targets, each a      #
# destination with its retention). Also the archive records of the users    #
# per destination, the records of job runs and the server-wide slots that   #
# cap parallel backups. See                                                 #
# docs/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md #
# §5 and §6.                                                                #
#                                                                           #
#===========================================================================#

BJ_DIR="$ARCPANEL/data/backup/jobs"
BJ_RUNS="$ARCPANEL/data/backup/runs"
BJ_SLOTS="$ARCPANEL/data/backup/slots"
BJ_SCHEDULE_HELPER="$ARCPANEL/func/backup-schedule.php"
BJ_RUN_KEYS=(RUN TRIGGER STATUS START END USERS DONE FAILED SKIPPED PID)
BJ_RUN_KEEP=50

#----------------------------------------------------------#
#                     Job records                          #
#----------------------------------------------------------#

bj_id_valid() {
	[[ "$1" =~ ^[0-9]{10,16}$ ]]
}

bj_conf() {
	echo "$BJ_DIR/$1.conf"
}

bj_exists() {
	bj_id_valid "$1" && [ -f "$(bj_conf "$1")" ]
}

bj_check_exists() {
	if ! bj_exists "$1"; then
		check_result "$E_NOTEXIST" "backup job $1 doesn't exist"
	fi
}

# IDs of every job, oldest first
bj_ids() {
	local IFS=$' \t\n'
	local file
	for file in "$BJ_DIR"/*.conf; do
		[ -f "$file" ] || continue
		basename -- "$file" .conf
	done | sort -n
}

bj_value() {
	grep -m1 "^$2='" "$(bj_conf "$1")" 2> /dev/null | cut -d"'" -f2
}

# Set KEY=VALUE pairs in the record of job ID
bj_set() {
	local IFS=$' \t\n'
	local id="$1" file pair key value tmp
	shift
	file=$(bj_conf "$id")
	tmp="$file.$$"
	cp -p -- "$file" "$tmp" 2> /dev/null || : > "$tmp"
	for pair in "$@"; do
		key=${pair%%=*}
		value=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
		if grep -q "^$key='" "$tmp"; then
			BJ_KEY="$key" BJ_VALUE="$value" awk -F= \
				'$1 == ENVIRON["BJ_KEY"] { print ENVIRON["BJ_KEY"] "='\''" ENVIRON["BJ_VALUE"] "'\''"; next } { print }' \
				"$tmp" > "$tmp.new" && mv -f "$tmp.new" "$tmp"
		else
			printf "%s='%s'\n" "$key" "$value" >> "$tmp"
		fi
	done
	chmod 600 "$tmp"
	mv -f "$tmp" "$file"
}

bj_init_dirs() {
	mkdir -p "$BJ_DIR" "$BJ_RUNS" "$BJ_SLOTS"
	chown root:root "$ARCPANEL/data/backup" "$BJ_DIR" "$BJ_RUNS" "$BJ_SLOTS"
	chmod 700 "$ARCPANEL/data/backup" "$BJ_DIR" "$BJ_RUNS" "$BJ_SLOTS"
}

# A new job ID: seconds and two random digits, unused
bj_new_id() {
	local id
	id="$(date +%s)$(printf '%02d' $((RANDOM % 100)))"
	while [ -e "$(bj_conf "$id")" ]; do
		id="$(date +%s)$(printf '%02d' $((RANDOM % 100)))"
	done
	echo "$id"
}

#----------------------------------------------------------#
#                     Validation                           #
#----------------------------------------------------------#

bj_name_valid() {
	[ -n "$1" ] && [ ${#1} -le 64 ] && [ "$(printf '%s' "$1" | tr -d "\"'\\\\\`\$" | tr -d '[:cntrl:]')" = "$1" ]
}

bj_engine_valid() {
	[[ "$1" =~ ^(archive|snapshot)$ ]]
}

# The server's time zone for schedules
bj_tz() {
	local tz
	tz=$(timedatectl show -p Timezone --value 2> /dev/null)
	if [ -z "$tz" ] && [ -L /etc/localtime ]; then
		tz=$(readlink -f /etc/localtime | sed 's#^.*/zoneinfo/##')
	fi
	echo "${tz:-UTC}"
}

bj_schedule_php() {
	ARC_TZ="$(bj_tz)" "$ARCPANEL_PHP" "$BJ_SCHEDULE_HELPER" "$@"
}

bj_schedule_valid() {
	[[ "$1" =~ ^[a-z]+:[0-9\ \*,/:-]{1,120}$ ]] && bj_schedule_php valid "$1" 2> /dev/null
}

# bj_next SCHEDULE FROM: the epoch of the first run after FROM
bj_next() {
	bj_schedule_php next "$1" "$2" 1 2> /dev/null | cut -f1
}

# Exit unless SCOPE is all, packages:A,B or users:X,Y of existing objects
bj_check_scope() {
	local IFS=$' \t\n'
	local scope="$1" kind list item
	if [ "$scope" = all ]; then
		return 0
	fi
	if [[ ! "$scope" =~ ^(packages|users):([A-Za-z0-9][A-Za-z0-9_.,-]*)$ ]]; then
		check_result "$E_INVALID" "invalid scope"
	fi
	kind=${BASH_REMATCH[1]}
	list=${BASH_REMATCH[2]}
	for item in ${list//,/ }; do
		if [ "$kind" = packages ] && [ ! -f "$ARCPANEL/data/packages/$item.pkg" ]; then
			check_result "$E_NOTEXIST" "package $item doesn't exist"
		fi
		if [ "$kind" = users ] && [ ! -f "$ARCPANEL/data/users/$item/user.conf" ]; then
			check_result "$E_NOTEXIST" "user $item doesn't exist"
		fi
	done
}

bj_check_exclude() {
	if [ -n "$1" ] && [[ ! "$1" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*(,[A-Za-z0-9][A-Za-z0-9_.-]*)*$ ]]; then
		check_result "$E_INVALID" "invalid list of excluded users"
	fi
}

# bj_normalize_targets TARGETS: check DEST[:KEEP_LAST[:DAILY:WEEKLY:MONTHLY:YEARLY]]
# items and print them with every number (-1 keeps all)
bj_normalize_targets() {
	local IFS=$' \t\n'
	local targets="$1" item dest rest out='' seen=' ' n value
	local -a parts
	if [ -z "$targets" ]; then
		echo "at least one target is required"
		return "$E_ARGS"
	fi
	for item in ${targets//,/ }; do
		dest=${item%%:*}
		rest=''
		[[ "$item" == *:* ]] && rest=${item#*:}
		if ! bd_exists "$dest"; then
			echo "backup destination $dest doesn't exist"
			return "$E_NOTEXIST"
		fi
		if [[ "$seen" == *" $dest "* ]]; then
			echo "backup destination $dest is named twice"
			return "$E_INVALID"
		fi
		seen="$seen$dest "
		IFS=: read -r -a parts <<< "$rest"
		if [ "${#parts[@]}" -gt 5 ]; then
			echo "invalid target $item"
			return "$E_INVALID"
		fi
		out="$out${out:+,}$dest"
		for n in 0 1 2 3 4; do
			value=${parts[$n]:--1}
			if [[ ! "$value" =~ ^(-1|[0-9]{1,4})$ ]]; then
				echo "invalid retention in $item"
				return "$E_INVALID"
			fi
			out="$out:$value"
		done
	done
	echo "$out"
}

#----------------------------------------------------------#
#                       Scope                              #
#----------------------------------------------------------#

# Users of job ID, one per line
bj_users() {
	local IFS=$' \t\n'
	local id="$1" scope exclude kind list user package
	scope=$(bj_value "$id" SCOPE)
	exclude=",$(bj_value "$id" EXCLUDE_USERS),"
	case "$scope" in
		all) list=$("$BIN/v-list-users" list 2> /dev/null) ;;
		users:*) list=$(tr ',' '\n' <<< "${scope#users:}") ;;
		packages:*)
			kind=",${scope#packages:},"
			list=$(for user in $("$BIN/v-list-users" list 2> /dev/null); do
				package=$(grep -m1 "^PACKAGE='" "$ARCPANEL/data/users/$user/user.conf" 2> /dev/null | cut -d"'" -f2)
				if [[ "$kind" == *",$package,"* ]]; then
					echo "$user"
				fi
			done)
			;;
	esac
	for user in $list; do
		[ -f "$ARCPANEL/data/users/$user/user.conf" ] || continue
		[[ "$exclude" == *",$user,"* ]] && continue
		echo "$user"
	done
}

bj_covers() {
	bj_users "$1" | grep -qx -- "$2"
}

# The job that backs up USER with ENGINE when no job is named: the first
# enabled job of the engine that covers the user, else the first one
bj_default_job() {
	local IFS=$' \t\n'
	local owner="$1" engine="$2" id fallback=''
	for id in $(bj_ids); do
		[ "$(bj_value "$id" ENGINE)" = "$engine" ] || continue
		bj_covers "$id" "$owner" || continue
		if [ "$(bj_value "$id" STATE)" = enabled ]; then
			echo "$id"
			return 0
		fi
		[ -z "$fallback" ] && fallback=$id
	done
	[ -n "$fallback" ] && echo "$fallback"
}

# Destinations of job ID, one per line
bj_target_names() {
	bj_value "$1" TARGETS | tr ',' '\n' | cut -d: -f1 | sed '/^$/d'
}

# bj_target_keep ID DEST FIELD: KEEP_LAST (2), DAILY (3) … YEARLY (6)
bj_target_keep() {
	bj_value "$1" TARGETS | tr ',' '\n' | awk -F: -v d="$2" -v f="$3" '$1 == d { print $f; exit }'
}

#----------------------------------------------------------#
#                  Archive records                         #
#----------------------------------------------------------#

bj_registry() {
	echo "$ARCPANEL/data/users/$1/backup.conf"
}

# The value of KEY in a record line
bj_line_value() {
	printf '%s\n' "$1" | grep -oE "(^| )$2='[^']*'" | head -n1 | cut -d\' -f2
}

# Destinations that hold ARCHIVE of USER. Records of earlier versions have
# only TYPE: its drivers are the names the upgrade gave their destinations.
bj_archive_destinations() {
	local IFS=$' \t\n'
	local line dests
	line=$(grep -m1 "^BACKUP='$2' " "$(bj_registry "$1")" 2> /dev/null)
	[ -n "$line" ] || return 0
	if [[ "$line" == *" DESTINATIONS='"* ]]; then
		bj_line_value "$line" DESTINATIONS
	else
		dests=$(bj_line_value "$line" TYPE)
		for dest in ${dests//,/ }; do
			bd_exists "$dest" && echo "$dest"
		done | tr '\n' ',' | sed 's/,$//'
	fi
}

# Whether ARCHIVE of USER is encrypted on DEST
bj_archive_encrypted() {
	local line
	line=$(grep -m1 "^BACKUP='$2' " "$(bj_registry "$1")" 2> /dev/null)
	[[ ",$(bj_line_value "$line" ENCRYPTED)," == *",$3,"* ]]
}

# Set KEY=VALUE pairs of the record of ARCHIVE of USER
bj_registry_set() {
	local IFS=$' \t\n'
	local owner="$1" archive="$2" file pair key value
	shift 2
	file=$(bj_registry "$owner")
	for pair in "$@"; do
		key=${pair%%=*}
		value=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
		if grep -q "^BACKUP='$archive' .* $key='" "$file"; then
			sed -i "/^BACKUP='$archive' /s/ $key='[^']*'/ $key='$value'/" "$file"
		else
			sed -i "/^BACKUP='$archive' /s/\$/ $key='$value'/" "$file"
		fi
	done
}

# Remove DEST from the record of ARCHIVE of USER; the record goes when no
# destination holds the archive any more
bj_registry_drop() {
	local owner="$1" archive="$2" dest="$3" dests encrypted
	dests=$(bj_archive_destinations "$owner" "$archive" | tr ',' '\n' | grep -vx -- "$dest" | tr '\n' ',' | sed 's/,$//')
	if [ -z "$dests" ]; then
		sed -i "/^BACKUP='$archive' /d" "$(bj_registry "$owner")"
		return 0
	fi
	encrypted=$(grep -m1 "^BACKUP='$archive' " "$(bj_registry "$owner")" | grep -oE " ENCRYPTED='[^']*'" | cut -d\' -f2 \
		| tr ',' '\n' | grep -vx -- "$dest" | tr '\n' ',' | sed 's/,$//')
	bj_registry_set "$owner" "$archive" "DESTINATIONS=$dests" "ENCRYPTED=$encrypted"
}

# Every record of USER forgets DEST (the destination was deleted)
bj_registry_forget_destination() {
	local IFS=$' \t\n'
	local owner="$1" dest="$2" archive
	[ -f "$(bj_registry "$owner")" ] || return 0
	for archive in $(grep -oE "^BACKUP='[^']*'" "$(bj_registry "$owner")" | cut -d\' -f2); do
		if [[ ",$(bj_archive_destinations "$owner" "$archive")," == *",$dest,"* ]]; then
			bj_registry_drop "$owner" "$archive" "$dest"
		fi
	done
	bj_update_counter "$owner"
}

bj_update_counter() {
	local count
	count=$(grep -c "^BACKUP=" "$(bj_registry "$1")" 2> /dev/null)
	update_user_value "$1" '$U_BACKUPS' "${count:-0}"
}

# The file name of ARCHIVE on DEST
bj_archive_file() {
	if bj_archive_encrypted "$1" "$2" "$3"; then
		echo "$2.age"
	else
		echo "$2"
	fi
}

# bj_delete_archive USER ARCHIVE DEST: delete the file on DEST and update
# the record
bj_delete_archive() {
	local owner="$1" archive="$2" dest="$3"
	if bd_exists "$dest"; then
		bd_delete "$dest" "$(bj_archive_file "$owner" "$archive" "$dest")" > /dev/null 2>&1
	fi
	bj_registry_drop "$owner" "$archive" "$dest"
}

# bj_retention_archives USER JOB DEST KEEP: keep the newest KEEP archives
# of JOB on DEST, at most the package's BACKUPS (KEEP -1: BACKUPS)
bj_retention_archives() {
	local IFS=$' \t\n'
	local owner="$1" job="$2" dest="$3" keep="$4" limit archives count drop archive
	limit=$(grep -m1 "^BACKUPS='" "$ARCPANEL/data/users/$owner/user.conf" | cut -d"'" -f2)
	limit=${limit:-1}
	if [ "$keep" -lt 0 ] || [ "$keep" -gt "$limit" ]; then
		keep=$limit
	fi
	archives=$(grep "^BACKUP='[^']*' .* JOB='$job'" "$(bj_registry "$owner")" 2> /dev/null | while IFS= read -r line; do
		if [[ ",$(bj_line_value "$line" DESTINATIONS)," == *",$dest,"* ]]; then
			bj_line_value "$line" BACKUP
		fi
	done)
	count=$(printf '%s\n' "$archives" | sed '/^$/d' | wc -l)
	drop=$((count - keep))
	[ "$drop" -gt 0 ] || return 0
	for archive in $(printf '%s\n' "$archives" | sed '/^$/d' | sort | head -n "$drop"); do
		echo -e "$(date "+%F %T") Rotated on $dest: $archive"
		bj_delete_archive "$owner" "$archive" "$dest"
	done
}

# bj_fetch_archive USER ARCHIVE: make $BACKUP/ARCHIVE exist, copying it
# from a destination that holds it (local first), decrypted
bj_fetch_archive() {
	local IFS=$' \t\n'
	local owner="$1" archive="$2" dest dests ordered='' target
	target="$BACKUP/$archive"
	if [ -f "$target" ]; then
		return 0
	fi
	dests=$(bj_archive_destinations "$owner" "$archive")
	for dest in ${dests//,/ }; do
		if [ "$(bd_value "$dest" DRIVER)" = local ]; then
			ordered="$dest $ordered"
		else
			ordered="$ordered $dest"
		fi
	done
	for dest in $ordered; do
		bd_exists "$dest" || continue
		if bj_archive_encrypted "$owner" "$archive" "$dest"; then
			bd_get "$dest" "$archive.age" - 2> /dev/null | bd_decrypt "$dest" > "$target.part" 2> /dev/null
		else
			bd_get "$dest" "$archive" "$target.part" > /dev/null 2>&1
		fi
		if [ "${PIPESTATUS[0]}" -eq 0 ] && [ -s "$target.part" ] && tar -tf "$target.part" > /dev/null 2>&1; then
			mv -f "$target.part" "$target"
			chmod 640 "$target"
			chown "arcpanelweb:$owner" "$target" 2> /dev/null
			return 0
		fi
		rm -f "$target.part"
	done
	return 1
}

#----------------------------------------------------------#
#                       Job runs                           #
#----------------------------------------------------------#

bj_runs_file() {
	echo "$BJ_RUNS/$1.conf"
}

# Set KEY=VALUE pairs of the run RUN of job ID, appending it when new
bj_run_set() {
	local IFS=$' \t\n'
	local id="$1" run="$2" file line key pair new='' tmp
	local -A values=()
	shift 2
	file=$(bj_runs_file "$id")
	exec {BJ_RUN_FD}> "$file.lock"
	flock -x "$BJ_RUN_FD"
	line=$(grep -m1 "^RUN='$run' " "$file" 2> /dev/null)
	for key in "${BJ_RUN_KEYS[@]}"; do
		values[$key]=$(bj_line_value "$line" "$key")
	done
	values[RUN]=$run
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in "${BJ_RUN_KEYS[@]}"; do
		new="$new$key='${values[$key]}' "
	done
	new=${new% }
	tmp="$file.$$"
	if [ -n "$line" ]; then
		BJ_LINE="$new" BJ_PREFIX="RUN='$run' " awk \
			'index($0, ENVIRON["BJ_PREFIX"]) == 1 { print ENVIRON["BJ_LINE"]; next } { print }' "$file" > "$tmp"
	else
		{
			[ -f "$file" ] && cat "$file"
			printf '%s\n' "$new"
		} > "$tmp"
	fi
	tail -n "$BJ_RUN_KEEP" "$tmp" > "$tmp.keep"
	rm -f "$tmp"
	chmod 600 "$tmp.keep"
	mv -f "$tmp.keep" "$file"
	flock -u "$BJ_RUN_FD"
	exec {BJ_RUN_FD}>&-
}

# Runs left running by a process that is gone failed
bj_runs_reap() {
	local id="$1" file line run pid
	file=$(bj_runs_file "$id")
	[ -f "$file" ] || return 0
	while IFS= read -r line; do
		run=$(bj_line_value "$line" RUN)
		pid=$(bj_line_value "$line" PID)
		if [ -n "$run" ] && { ! [[ "$pid" =~ ^[0-9]+$ ]] || ! kill -0 "$pid" 2> /dev/null; }; then
			bj_run_set "$id" "$run" STATUS=failed "END=$(date +'%F %T')" PID=
		fi
	done < <(grep "STATUS='running'" "$file")
}

# Hold the lock of job ID (non-blocking); fails when the job runs
bj_lock() {
	exec {BJ_LOCK_FD}> "$BJ_DIR/$1.lock"
	flock -n "$BJ_LOCK_FD"
}

bj_running() {
	local fd rc
	[ -f "$BJ_DIR/$1.lock" ] || return 1
	exec {fd}> "$BJ_DIR/$1.lock"
	flock -n "$fd"
	rc=$?
	exec {fd}>&-
	[ "$rc" -ne 0 ]
}

#----------------------------------------------------------#
#                        Slots                             #
#----------------------------------------------------------#

# Take one of the BACKUP_PARALLEL slots of the server, waiting for one.
# Sets BJ_SLOT_FD; the slot is free again when the process exits.
bj_slot_take() {
	local IFS=$' \t\n'
	local slots="${BACKUP_PARALLEL:-1}" n
	[[ "$slots" =~ ^[0-9]+$ ]] && [ "$slots" -ge 1 ] || slots=1
	mkdir -p "$BJ_SLOTS"
	while true; do
		for ((n = 1; n <= slots; n++)); do
			exec {BJ_SLOT_FD}> "$BJ_SLOTS/$n.lock"
			if flock -n "$BJ_SLOT_FD"; then
				return 0
			fi
			exec {BJ_SLOT_FD}>&-
		done
		sleep 5
	done
}

#----------------------------------------------------------#
#                     Frequency cap                        #
#----------------------------------------------------------#

# Seconds of the package's BACKUP_FREQUENCY of USER (hourly, daily, weekly)
bj_frequency_seconds() {
	local frequency package
	if ! declare -F tenancy_pkg_get > /dev/null; then
		# shellcheck source=/usr/local/arcpanel/func/tenancy.sh
		source "$ARCPANEL/func/tenancy.sh"
	fi
	package=$(grep -m1 "^PACKAGE='" "$ARCPANEL/data/users/$1/user.conf" 2> /dev/null | cut -d"'" -f2)
	frequency=$(tenancy_pkg_get "$package" BACKUP_FREQUENCY)
	case "$frequency" in
		hourly) echo 3600 ;;
		weekly) echo 604800 ;;
		*) echo 86400 ;;
	esac
}

# Whether a scheduled run of JOB may back up USER: its last successful run
# of the job started at least the package's interval (less 5 minutes) ago
bj_frequency_allows() {
	local owner="$1" job="$2" last start
	last=$(grep "JOB='$job'" "$ARCPANEL/data/users/$owner/backup-runs.conf" 2> /dev/null \
		| grep "STATUS='succeeded'" | tail -n1)
	[ -n "$last" ] || return 0
	start=$(date -d "$(bj_line_value "$last" START)" +%s 2> /dev/null) || return 0
	[ $(($(date +%s) - start)) -ge $(($(bj_frequency_seconds "$owner") - 300)) ]
}

#----------------------------------------------------------#
#                       Output                             #
#----------------------------------------------------------#

BJ_KEYS='ID NAME ENGINE SCOPE EXCLUDE_USERS SCHEDULE TARGETS STATE LAST_RUN NEXT_RUN NEXT_RUN_TS CREATED UPDATED'

# JSON object of job ID with the state of its last run
bj_json() {
	local IFS=$' \t\n'
	local id="$1" key json='{}' last
	for key in $BJ_KEYS; do
		if [ "$key" = ID ]; then
			json=$(jq -c --arg v "$id" '. + {ID: $v}' <<< "$json")
			continue
		fi
		json=$(jq -c --arg k "$key" --arg v "$(bj_value "$id" "$key")" '. + {($k): $v}' <<< "$json")
	done
	last=$(tail -n1 "$(bj_runs_file "$id")" 2> /dev/null)
	json=$(jq -c --arg s "$(bj_line_value "$last" STATUS)" --arg e "$(bj_line_value "$last" END)" \
		--arg r "$(bj_running "$id" && echo yes || echo no)" '. + {LAST_STATUS: $s, LAST_END: $e, RUNNING: $r}' <<< "$json")
	printf '%s\n' "$json"
}

#----------------------------------------------------------#
#                  Starting and notices                    #
#----------------------------------------------------------#

# Start a whole run of job ID in the background (TRIGGER schedule or request)
bj_start_detached() {
	local id="$1" trigger="$2"
	if command -v systemd-run > /dev/null 2>&1 && [ -d /run/systemd/system ]; then
		systemd-run --unit "arcpanel-backup-job-$id-$(date +%s)" --collect --quiet \
			--setenv=HOME=/root --property=Nice=10 \
			"$BIN/v-run-backup-job" "$id" '' "$trigger" > /dev/null 2>&1 && return 0
	fi
	setsid nohup "$BIN/v-run-backup-job" "$id" '' "$trigger" > /dev/null 2>&1 < /dev/null &
}

# Administrators of the server, one per line
bj_admins() {
	local IFS=$' \t\n'
	local file
	for file in "$ARCPANEL"/data/users/*/user.conf; do
		if grep -q "^ROLE='admin'" "$file" 2> /dev/null; then
			basename -- "$(dirname -- "$file")"
		fi
	done
}

# A run ended partial or failed: a notification to each administrator and
# the mail to the administrator contact (spec §7)
bj_notify_admins() {
	local IFS=$' \t\n'
	local id="$1" run="$2" status="$3" details="$4" name admin email subj
	name=$(bj_value "$id" NAME)
	for admin in $(bj_admins); do
		"$BIN/v-add-user-notification" "$admin" "Backup job $status" \
			"<p>Job <code>$(sed 's/[<>&]//g' <<< "$name")</code> ended $status. $(sed 's/[<>&]//g' <<< "$details")</p>" > /dev/null 2>&1
	done
	if [ "${BACKUP_REPORT:-failures}" != off ]; then
		email=$(grep -m1 "^CONTACT='" "$ARCPANEL/data/users/$ROOT_USER/user.conf" | cut -d"'" -f2)
		subj="Backup job $name: $status"
		if [ -n "$email" ] && [ -n "$SENDMAIL" ]; then
			printf 'The backup job %s (run %s) ended %s.\n%s\n' "$name" "$run" "$status" "$details" \
				| $SENDMAIL -s "$subj" "$email" yes > /dev/null 2>&1
		fi
	fi
}

# A destination became failing: tell the administrators once per change
bj_notify_destination() {
	local IFS=$' \t\n'
	local name="$1" admin
	for admin in $(bj_admins); do
		"$BIN/v-add-user-notification" "$admin" "Backup destination failing" \
			"<p>Transfers to the backup destination <code>$name</code> failed three times in a row. Check it on the Backups page of the server.</p>" > /dev/null 2>&1
	done
}

# The daily summary mail (BACKUP_REPORT='daily'), once a day after 07:00
bj_daily_report() {
	local IFS=$' \t\n'
	local stamp="$ARCPANEL/data/backup/report.last" today since id line email body='' name
	[ "${BACKUP_REPORT:-failures}" = daily ] || return 0
	today=$(date +%F)
	[ "$(date +%H)" -ge 7 ] || return 0
	[ "$(cat "$stamp" 2> /dev/null)" = "$today" ] && return 0
	echo "$today" > "$stamp"
	since=$(date -d '24 hours ago' +'%F %T')
	for id in $(bj_ids); do
		name=$(bj_value "$id" NAME)
		while IFS= read -r line; do
			[[ "$(bj_line_value "$line" START)" > "$since" ]] || continue
			body="$body$(printf '%s  %s  %s  users %s, done %s, failed %s, skipped %s' "$(bj_line_value "$line" START)" "$name" \
				"$(bj_line_value "$line" STATUS)" "$(bj_line_value "$line" USERS)" "$(bj_line_value "$line" DONE)" \
				"$(bj_line_value "$line" FAILED)" "$(bj_line_value "$line" SKIPPED)")"$'\n'
		done < <(cat "$(bj_runs_file "$id")" 2> /dev/null)
	done
	for name in $(bd_names); do
		body="$body$(printf 'Destination %s: %s (checked %s)' "$name" "$(bd_value "$name" STATE)" "$(bd_value "$name" CHECKED)")"$'\n'
	done
	email=$(grep -m1 "^CONTACT='" "$ARCPANEL/data/users/$ROOT_USER/user.conf" | cut -d"'" -f2)
	if [ -n "$email" ] && [ -n "$SENDMAIL" ]; then
		printf 'Backups of the last 24 hours on %s\n\n%s' "$(hostname)" "${body:-No backup job ran.}" \
			| $SENDMAIL -s "Backup report $(hostname) $today" "$email" yes > /dev/null 2>&1
	fi
}

#----------------------------------------------------------#
#                   Archive delivery                       #
#----------------------------------------------------------#

# bj_put_archive USER FILE ARCHIVE DEST: store FILE as ARCHIVE on DEST,
# encrypted when the destination says so. Sets BJ_ENCRYPTED (yes/no) and,
# on failure, BJ_ERROR.
bj_put_archive() {
	local owner="$1" file="$2" archive="$3" dest="$4" driver path target rc
	BJ_ERROR=''
	BJ_ENCRYPTED=no
	driver=$(bd_value "$dest" DRIVER)
	if [ "$(bd_value "$dest" ENCRYPT)" = yes ]; then
		BJ_ENCRYPTED=yes
		bd_encrypt "$dest" < "$file" | bd_put "$dest" - "$archive.age" > "$BD_RUN/put.log" 2>&1
		rc=$((PIPESTATUS[0] | PIPESTATUS[1]))
	elif [ "$driver" = local ]; then
		path=$(bd_value "$dest" PATH)
		target="$path/$archive"
		mkdir -p -- "$path"
		# A hard link when the folder is on the same disk, else a copy
		rm -f -- "$target"
		ln -- "$file" "$target" 2> /dev/null || cp -f -- "$file" "$target"
		rc=$?
		if [ "$rc" -eq 0 ]; then
			chmod 640 -- "$target"
			chown "arcpanelweb:$owner" -- "$target" 2> /dev/null
		fi
	else
		bd_put "$dest" "$file" "$archive" > "$BD_RUN/put.log" 2>&1
		rc=$?
	fi
	if [ "$rc" -ne 0 ]; then
		BJ_ERROR=$(grep -iE 'error|failed|denied|refused' "$BD_RUN/put.log" 2> /dev/null | tail -n1 | cut -c1-200)
		BJ_ERROR="$dest: ${BJ_ERROR:-upload failed}"
		bd_record_transfer "$dest" failed
		return 1
	fi
	bd_record_transfer "$dest" ok
	return 0
}

#----------------------------------------------------------#
#                 Snapshot repositories                    #
#----------------------------------------------------------#

# Destinations that may hold snapshots of USER: the targets of the snapshot
# jobs that cover the user, then those that already hold a repository
bj_snapshot_destinations() {
	local IFS=$' \t\n'
	local owner="$1" id file
	{
		for id in $(bj_ids); do
			[ "$(bj_value "$id" ENGINE)" = snapshot ] || continue
			bj_covers "$id" "$owner" || continue
			bj_target_names "$id"
		done
		for file in "$ARCPANEL/data/users/$owner/restic"/*.age; do
			[ -f "$file" ] && basename -- "$file" .age
		done
	} | awk '!seen[$0]++' | while IFS= read -r dest; do
		bd_exists "$dest" && echo "$dest"
	done | grep . || for id in $(bj_ids); do
		# A user no job covers yet (an import on a new server): every snapshot target
		[ "$(bj_value "$id" ENGINE)" = snapshot ] && bj_target_names "$id"
	done | awk '!seen[$0]++'
}

bj_snapshot_map() {
	echo "$ARCPANEL/data/users/$1/restic/snapshots.map"
}

# Remember which destination holds the snapshots listed (SHORT ID DEST)
bj_snapshot_map_write() {
	local IFS=$' \t\n'
	local owner="$1" dest="$2" json="$3" file
	file=$(bj_snapshot_map "$owner")
	mkdir -p "$(dirname -- "$file")"
	{
		grep -v " $dest$" "$file" 2> /dev/null
		jq -r --arg d "$dest" '.[] | "\(.short_id) \(.id) \($d)"' <<< "$json" 2> /dev/null
	} > "$file.tmp"
	mv -f "$file.tmp" "$file"
	chmod 600 "$file"
}

# restic_select USER [SNAPSHOT] [DEST]: export the repository of USER on
# DEST, on the destination that holds SNAPSHOT, or on the first one
restic_select() {
	local IFS=$' \t\n'
	local owner="$1" snapshot="$2" dest="$3" dests found=''
	if [ -z "$dest" ]; then
		dests=$(bj_snapshot_destinations "$owner")
		if [ -n "$snapshot" ]; then
			found=$(awk -v s="$snapshot" '$1 == s || $2 == s { print $3; exit }' "$(bj_snapshot_map "$owner")" 2> /dev/null)
			if [ -z "$found" ]; then
				for dest in $dests; do
					bd_restic_env "$owner" "$dest" 2> /dev/null || continue
					if restic snapshots --json "$snapshot" > /dev/null 2>&1; then
						found=$dest
						break
					fi
				done
			fi
		fi
		dest=${found:-$(head -n1 <<< "$dests")}
	fi
	if [ -z "$dest" ] || ! bd_exists "$dest"; then
		check_result "$E_NOTEXIST" "no snapshot destination for $owner"
	fi
	RESTIC_DEST=$dest
	if ! bd_restic_env "$owner" "$dest"; then
		check_result "$E_CONNECT" "the repository of $owner on $dest could not be opened"
	fi
}

# BACKUP_INCREMENTAL says whether a snapshot job exists, for the pages and
# commands that read it
bj_sync_incremental() {
	local IFS=$' \t\n'
	local id value=no
	for id in $(bj_ids); do
		if [ "$(bj_value "$id" ENGINE)" = snapshot ]; then
			value=yes
			break
		fi
	done
	if grep -q '^BACKUP_INCREMENTAL=' "$ARCPANEL/conf/arcpanel.conf"; then
		sed -i "s/^BACKUP_INCREMENTAL=.*/BACKUP_INCREMENTAL='$value'/" "$ARCPANEL/conf/arcpanel.conf"
	else
		echo "BACKUP_INCREMENTAL='$value'" >> "$ARCPANEL/conf/arcpanel.conf"
	fi
	BACKUP_INCREMENTAL=$value
}
