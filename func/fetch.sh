#!/bin/bash
# shellcheck disable=SC2034 # the limits are read by the fetch commands

#===========================================================================#
#                                                                           #
# ArcPanel upload from URL (E3)                                             #
#                                                                           #
# A fetch downloads one URL into a folder of a user's home. curl runs as    #
# the user, so permissions and the disk quota apply; the address of every   #
# hop is checked first (func/fetch.php) and curl is pinned to it. Records:  #
#   $ARCPANEL/data/users/USER/fetches/ID.conf      the state, one line      #
#   $ARCPANEL/data/users/USER/fetches/ID.request   URL, headers and         #
#                                                 credentials (root, 0600) #
# See docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md.      #
#                                                                           #
#===========================================================================#

FETCH_HELPER="$ARCPANEL/func/fetch.php"
FETCH_KEYS="ID URL DIR NAME ON_CONFLICT PRIVATE STATUS BYTES TOTAL ERROR HTTP CREATED START END UNIT PID TEMP"
# Fetches that run at once per account, and that wait or run at most (D8)
FETCH_RUNNING_MAX=3
FETCH_ACTIVE_MAX=20
# Requests per fetch: the first one and 5 redirects
FETCH_HOPS=6
# Seconds a fetch may take, and that a finished record is kept
FETCH_TIMEOUT=3600
FETCH_KEEP=86400
FETCH_MAX_DEFAULT=2G

#----------------------------------------------------------#
#                        Records                           #
#----------------------------------------------------------#

fetch_dir() {
	echo "$ARCPANEL/data/users/$1/fetches"
}

fetch_conf() {
	echo "$(fetch_dir "$1")/$2.conf"
}

# A fetch ID: seconds and four digits
fetch_id_valid() {
	[[ "$1" =~ ^[0-9]{10,14}$ ]]
}

fetch_field() {
	if [[ "$1" =~ (^| )$2=\'([^\']*)\' ]]; then
		echo "${BASH_REMATCH[2]}"
	fi
}

# KEY of the record ID of USER
fetch_get() {
	local file
	file=$(fetch_conf "$1" "$2")
	if [ -f "$file" ]; then
		fetch_field "$(head -n1 "$file")" "$3"
	fi
}

# Sets KEY=VALUE pairs in the record ID of USER; the other keys keep their
# values. Writers take the records lock, so the runner, the list and the
# cancel never lose each other's changes.
fetch_set() {
	local user=$1 id=$2 file pair key line='' tmp lock
	local -A values=()
	shift 2
	file=$(fetch_conf "$user" "$id")
	exec {lock}> "$(fetch_dir "$user")/.records.lock"
	flock -x "$lock"
	if [ -f "$file" ]; then
		line=$(head -n1 "$file")
		for key in $FETCH_KEYS; do
			values[$key]=$(fetch_field "$line" "$key")
		done
	fi
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	line=''
	for key in $FETCH_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 600 "$tmp"
	mv -f "$tmp" "$file"
	flock -u "$lock"
	exec {lock}>&-
}

# IDs of USER's fetches, oldest first
fetch_ids() {
	local dir file
	dir=$(fetch_dir "$1")
	for file in "$dir"/*.conf; do
		[ -f "$file" ] || continue
		file=${file##*/}
		echo "${file%.conf}"
	done | sort -n
}

# Number of USER's fetches in one of the STATUS values
fetch_count() {
	local user=$1 id status n=0
	shift
	for id in $(fetch_ids "$user"); do
		status=$(fetch_get "$user" "$id" STATUS)
		for wanted in "$@"; do
			if [ "$status" = "$wanted" ]; then
				n=$((n + 1))
			fi
		done
	done
	echo "$n"
}

fetch_finished() {
	case "$1" in
		done | skipped | failed | cancelled) return 0 ;;
	esac
	return 1
}

# Removes the files of a fetch that ends: request, headers, error, markers
fetch_forget() {
	local dir
	dir=$(fetch_dir "$1")
	rm -f "$dir/$2.request" "$dir/$2.headers" "$dir/$2.err" "$dir/$2.stop" "$dir/$2.pid"
}

#----------------------------------------------------------#
#                         Values                           #
#----------------------------------------------------------#

# Bytes of a size such as 2G, 500M, 64K or 1024 (FILE_FETCH_MAX_SIZE)
fetch_bytes() {
	local value=${1:-$FETCH_MAX_DEFAULT} number unit
	if [[ ! "$value" =~ ^([0-9]+)([KkMmGgTt]?)$ ]]; then
		value=$FETCH_MAX_DEFAULT
		[[ "$value" =~ ^([0-9]+)([KkMmGgTt]?)$ ]]
	fi
	number=${BASH_REMATCH[1]}
	unit=${BASH_REMATCH[2]}
	case "$unit" in
		K | k) echo $((10#$number * 1024)) ;;
		M | m) echo $((10#$number * 1024 * 1024)) ;;
		G | g) echo $((10#$number * 1024 * 1024 * 1024)) ;;
		T | t) echo $((10#$number * 1024 * 1024 * 1024 * 1024)) ;;
		*) echo $((10#$number)) ;;
	esac
}

fetch_php() {
	"$ARCPANEL_PHP" "$FETCH_HELPER" "$@"
}

# Addresses of the server itself, refused like private ones (§6.4)
fetch_own_addresses() {
	ip -o addr show 2> /dev/null | awk '{ print $4 }'
}

fetch_now() {
	date +'%F %T'
}

#----------------------------------------------------------#
#                         Queue                            #
#----------------------------------------------------------#

fetch_unit() {
	echo "arcpanel-fetch-$1-$2"
}

# Whether the runner of a running fetch is still there
fetch_alive() {
	local user=$1 id=$2 pid
	pid=$(fetch_get "$user" "$id" PID)
	[ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] && tr '\0' ' ' < "/proc/$pid/cmdline" 2> /dev/null | grep -q "v-run-fs-fetch $user $id"
}

# Starts the runner of a queued fetch (the queue lock is held)
fetch_launch() {
	local user=$1 id=$2
	fetch_set "$user" "$id" STATUS=running "START=$(fetch_now)" "UNIT=$(fetch_unit "$user" "$id")"
	# Detached: new session, no descriptor shared with the caller, so the
	# panel's request returns at once and the fetch survives it
	setsid "$BIN/v-run-fs-fetch" "$user" "$id" < /dev/null > /dev/null 2>&1 {FETCH_QUEUE_LOCK}>&- &
	fetch_set "$user" "$id" "PID=$!"
}

# Starts the oldest queued fetches while fewer than FETCH_RUNNING_MAX run
fetch_start_next() {
	local user=$1 id running
	exec {FETCH_QUEUE_LOCK}> "$(fetch_dir "$user")/.queue.lock"
	flock -x "$FETCH_QUEUE_LOCK"
	running=$(fetch_count "$user" running)
	for id in $(fetch_ids "$user"); do
		[ "$running" -lt "$FETCH_RUNNING_MAX" ] || break
		if [ "$(fetch_get "$user" "$id" STATUS)" = 'queued' ]; then
			fetch_launch "$user" "$id"
			running=$((running + 1))
		fi
	done
	flock -u "$FETCH_QUEUE_LOCK"
	exec {FETCH_QUEUE_LOCK}>&-
}

# Stops the transfer of a running fetch: its unit, or its curl process
fetch_stop_transfer() {
	local user=$1 id=$2 unit pid
	unit=$(fetch_get "$user" "$id" UNIT)
	if [ -n "$unit" ] && command -v systemctl > /dev/null 2>&1; then
		systemctl stop "$unit.service" > /dev/null 2>&1
	fi
	pid=$(cat "$(fetch_dir "$user")/$id.pid" 2> /dev/null)
	if [[ "$pid" =~ ^[0-9]+$ ]]; then
		kill "$pid" > /dev/null 2>&1
	fi
}

# Ends a fetch whose runner is gone: partial file and request removed
fetch_abandon() {
	local user=$1 id=$2 status=$3 error=$4 temp
	temp=$(fetch_get "$user" "$id" TEMP)
	if [ -n "$temp" ]; then
		user_exec rm -f -- "$temp" > /dev/null 2>&1
	fi
	fetch_forget "$user" "$id"
	fetch_set "$user" "$id" "STATUS=$status" "ERROR=$error" "END=$(fetch_now)" TEMP= PID=
}

#----------------------------------------------------------#
#                       Transfer                           #
#----------------------------------------------------------#

# One request of a fetch, run as the user: the curl config comes on standard
# input, the response headers go to standard output, unbuffered (stdbuf) so
# that the size is known while the body arrives, the body to TEMP.
# Usage: fetch_curl USER ID TEMP HOST PORT ADDRESSES SECONDS MAX
fetch_curl() {
	local user=$1 id=$2 temp=$3 host=$4 port=$5 addresses=$6 seconds=$7 max=$8 home
	local -a args=(stdbuf -o0 curl -q -sS --proto '=http,https' --max-redirs 0 --noproxy '*' --connect-timeout 20
		--speed-limit 1024 --speed-time 60 --max-time "$seconds" --max-filesize "$max" -D - -o "$temp" -K -)
	# A literal address needs no pinning: no name is resolved
	if [ "$host" != "${addresses//[\[\]]/}" ]; then
		args+=(--resolve "$host:$port:$addresses")
	fi
	home=$(fs_home "$user")
	if [ -d /run/systemd/system ] && command -v systemd-run > /dev/null 2>&1; then
		systemd-run --quiet --pipe --wait --collect --unit="$(fetch_unit "$user" "$id")" \
			--uid="$user" --gid="$user" --slice="user-$(id -u "$user").slice" \
			-p RuntimeMaxSec="$((seconds + 30))" -p UMask=0077 \
			--working-directory="$home" -E HOME="$home" -E USER="$user" -E LOGNAME="$user" \
			-E PATH=/usr/local/bin:/usr/bin:/bin -E LANG=C.UTF-8 -- "${args[@]}"
	else
		local groups
		groups=$(id -G "$user")
		(
			umask 077
			cd "$home" || exit 1
			exec env -i HOME="$home" USER="$user" LOGNAME="$user" PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8 \
				timeout "$((seconds + 30))" setpriv --groups "${groups// /,}" --reuid "$user" --regid "$user" -- "${args[@]}"
			# Without job control a background job reads /dev/null unless its
			# standard input is named: curl reads its config from it
		) 0<&0 &
		echo "$!" > "$(fetch_dir "$user")/$id.pid"
		wait "$!"
	fi
}

# Status code of the last response in a header dump
fetch_header_status() {
	awk 'toupper($1) ~ /^HTTP\// { code = $2 } END { print code }' "$1" 2> /dev/null
}

# Value of header NAME (case-insensitive) of the last response in a header dump
fetch_header_value() {
	awk -v name="$2" '
		toupper($1) ~ /^HTTP\// { value = "" }
		{ sub(/\r$/, "") }
		tolower(substr($0, 1, length(name) + 1)) == tolower(name) ":" { value = substr($0, length(name) + 2); sub(/^[ \t]+/, "", value) }
		END { print value }' "$1" 2> /dev/null
}

# Error code of §7 for a curl exit code
fetch_curl_error() {
	case "$1" in
		6) echo dns ;;
		23 | 27) echo disk ;;
		28) echo timeout ;;
		63) echo too-large ;;
		*) echo network ;;
	esac
}

# First free "name_(n).ext" in DIR for NAME (D7)
fetch_free_name() {
	local dir=$1 name=$2 base ext n candidate
	if [[ "$name" == ?*.* ]]; then
		base=${name%.*}
		ext=".${name##*.}"
	else
		base=$name
		ext=''
	fi
	for ((n = 1; n < 1000; n++)); do
		candidate="${base}_($n)$ext"
		if ! fs_user_test -e "$dir/$candidate" && ! fs_user_test -L "$dir/$candidate"; then
			echo "$candidate"
			return 0
		fi
	done
	return 1
}
