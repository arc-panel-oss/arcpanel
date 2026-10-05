#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel file manager functions (E3)                                      #
#                                                                           #
# Every read and write of a user's file runs as that user (user_exec), so   #
# the user's permissions and disk quota apply as they do over SSH. Root     #
# only opens files in the panel's staging tree, which hestiaweb owns:       #
#   $HESTIA/web-app/storage/app/files/uploads/<token>/   upload sessions,   #
#                                                        editor saves       #
#   $HESTIA/web-app/storage/app/files/downloads/<ticket> download tickets   #
# See docs/features/specs/2026-10-05-008-e3-file-manager-design.md.         #
#                                                                           #
#===========================================================================#

FS_STAGING="$HESTIA/web-app/storage/app/files"
FS_UPLOADS="$FS_STAGING/uploads"
FS_DOWNLOADS="$FS_STAGING/downloads"
FS_STAGING_OWNER="hestiaweb"

# Entries returned by one v-list-fs-directory-json call at most
FS_LIST_MAX=5000

#----------------------------------------------------------#
#                         Paths                            #
#----------------------------------------------------------#

# Home directory of a user, from /etc/passwd
fs_home() {
	awk -F: -v u="$1" '$1 == u { print $6; exit }' /etc/passwd
}

# Whether REAL (a resolved path) is HOME or below it. The trailing slash
# matters: "^$homedir" in the upstream checks lets /home/bob match /home/bobby.
fs_is_below() {
	local home=$1 real=$2
	[ -n "$home" ] && [ -n "$real" ] || return 1
	[ "$real" = "$home" ] || [ "${real#"$home"/}" != "$real" ]
}

# Resolved form of an absolute path that has no newline; the last component
# may not exist yet. Prints nothing when the path cannot be resolved.
fs_resolve() {
	local path=$1
	case "$path" in
		/*) ;;
		*) return 1 ;;
	esac
	case "$path" in
		*$'\n'* | *$'\r'*) return 1 ;;
	esac
	readlink -f -- "$path"
}

# Resolves PATH and checks that it stays in the home directory of $user;
# stops the command with E_FORBIDEN otherwise. Sets FS_REAL.
fs_check_path() {
	local path=$1 home
	home=$(fs_home "$user")
	if [ -z "$home" ] || [ ! -d "$home" ]; then
		check_result "$E_NOTEXIST" "home directory of $user doesn't exist"
	fi
	FS_REAL=$(fs_resolve "$path")
	if [ -z "$FS_REAL" ] && [ "${path#/}" != "$path" ] && [ "$path" = "${path//$'\n'/}" ]; then
		# Below a folder that does not exist: E_NOTEXIST when the part that
		# exists is in the home directory
		local ancestor=$path
		while [ "$ancestor" != '/' ] && [ ! -e "$ancestor" ]; do
			ancestor=$(dirname -- "$ancestor")
		done
		if fs_is_below "$home" "$(readlink -f -- "$ancestor")"; then
			check_result "$E_NOTEXIST" "$path doesn't exist"
		fi
	fi
	if ! fs_is_below "$home" "$FS_REAL"; then
		check_result "$E_FORBIDEN" "invalid path $path"
	fi
}

# A single file name: not empty, no slash, not . or .., no newline
fs_name_valid() {
	local name=$1
	[ -n "$name" ] && [ "$name" != '.' ] && [ "$name" != '..' ] || return 1
	case "$name" in
		*/* | *$'\n'* | *$'\r'*) return 1 ;;
	esac
	[ "${#name}" -le 255 ]
}

# Octal permission mode: 644, 0644, 2775
fs_mode_valid() {
	[[ "$1" =~ ^[0-7]?[0-7]{3}$ ]]
}

#----------------------------------------------------------#
#                     Staging tree                         #
#----------------------------------------------------------#

# A staged file: a regular file (not a symlink) below the uploads directory,
# owned by hestiaweb
fs_staged_valid() {
	local file=$1 real uploads
	[ -n "$file" ] && [ ! -L "$file" ] || return 1
	real=$(fs_resolve "$file") || return 1
	uploads=$(readlink -f -- "$FS_UPLOADS") || return 1
	[ -d "$uploads" ] || return 1
	[ "${real#"$uploads"/}" != "$real" ] || return 1
	[ -f "$real" ] && [ "$(stat -c %U -- "$real")" = "$FS_STAGING_OWNER" ]
}

# Download ticket: 32 lowercase hexadecimal characters
fs_ticket_valid() {
	[[ "$1" =~ ^[a-f0-9]{32}$ ]]
}

# Free bytes on the file system of the staging tree
fs_staging_free() {
	df --output=avail -B1 -- "$FS_STAGING" 2> /dev/null | tail -n 1 | tr -d ' '
}

#----------------------------------------------------------#
#                    Running as the user                   #
#----------------------------------------------------------#

# user_exec with a time limit in seconds (user_exec is a shell function, so
# timeout(1) cannot wrap it)
fs_user_exec_timeout() {
	local seconds=$1 user_groups
	shift
	user_groups=$(id -G "$user")
	user_groups=${user_groups//\ /,}
	timeout "$seconds" setpriv --groups "$user_groups" --reuid "$user" --regid "$user" -- "$@"
}

fs_user_test() {
	user_exec test "$@" 2> /dev/null
}

# Whether an error message means a full disk or an exhausted quota
fs_is_disk_error() {
	grep -qiE 'quota|no space|space left' <<< "$1"
}

# Copies SRC (opened by root) into DST (written by the user, mode 0600 until
# the caller changes it). Returns 0, E_DISK or E_FORBIDEN; a partial DST is
# removed.
fs_write_as_user() {
	local src=$1 dst=$2 err rc
	# shellcheck disable=SC2016
	err=$(user_exec sh -c 'umask 077; cat > "$1"' sh "$dst" < "$src" 2>&1)
	rc=$?
	if [ "$rc" -eq 0 ] && [ "$(stat -c %s -- "$dst" 2> /dev/null)" = "$(stat -c %s -- "$src")" ]; then
		return 0
	fi
	user_exec rm -f -- "$dst" > /dev/null 2>&1
	if fs_is_disk_error "$err"; then
		return "$E_DISK"
	fi
	# A short write without a message is a full disk as well
	if [ "$rc" -eq 0 ]; then
		return "$E_DISK"
	fi
	return "$E_FORBIDEN"
}

# Name of a temporary file next to PATH
fs_temp_beside() {
	local path=$1
	echo "$(dirname -- "$path")/.$(basename -- "$path").arc-$(tr -dc 'a-z0-9' < /dev/urandom 2> /dev/null | head -c 10)"
}

# First free "name (n).ext" in DIR for NAME
fs_free_name() {
	local dir=$1 name=$2 base ext n candidate
	if [[ "$name" == ?*.* ]]; then
		base=${name%.*}
		ext=".${name##*.}"
	else
		base=$name
		ext=''
	fi
	for ((n = 1; n < 1000; n++)); do
		candidate="$base ($n)$ext"
		if ! fs_user_test -e "$dir/$candidate" && ! fs_user_test -L "$dir/$candidate"; then
			echo "$candidate"
			return 0
		fi
	done
	return 1
}

#----------------------------------------------------------#
#                         Listing                          #
#----------------------------------------------------------#

# JSON array of the entries found by `find ARGS…` run as the user, one object
# per entry with NAME, TYPE, SIZE, MODE, OWNER, GROUP, MTIME, LINK,
# LINK_TYPE, LINK_INSIDE, VALID_NAME. HOME decides LINK_INSIDE.
fs_entries_json() {
	local home=$1 base links link target inside
	shift
	base=$(mktemp)
	user_exec find "$@" -printf '%y\0%Y\0%s\0%m\0%u\0%g\0%T@\0%f\0%l\0' > "$base" 2> /dev/null

	# Symlinks: whether the target stays in the home directory
	links='{}'
	while IFS= read -r -d '' link; do
		target=$(readlink -f -- "$link" 2> /dev/null)
		inside=no
		if fs_is_below "$home" "$target"; then
			inside=yes
		fi
		links=$(jq -c --arg k "$(basename -- "$link")" --arg v "$inside" '. + {($k): $v}' <<< "$links")
	done < <(user_exec find "$@" -type l -print0 2> /dev/null)

	jq -Rs --argjson links "$links" '
		split("\u0000") | .[0:(length - length % 9)]
		| [range(0; length; 9) as $i | .[$i:$i + 9]]
		| map({
			NAME: .[7],
			TYPE: (if .[0] == "d" then "d" elif .[0] == "l" then "l" elif .[0] == "f" then "f" else "o" end),
			SIZE: (.[2] | tonumber),
			MODE: (.[3] | if length < 4 then ("0" * (4 - length)) + . else . end),
			OWNER: .[4],
			GROUP: .[5],
			MTIME: (.[6] | tonumber | floor),
			LINK: .[8],
			LINK_TYPE: (if .[0] == "l" then (if .[1] == "d" then "d" elif .[1] == "f" then "f" elif .[1] == "N" then "missing" else "o" end) else "" end),
			LINK_INSIDE: (if .[0] == "l" then ($links[.[7]] // "no") else "" end),
			VALID_NAME: (if (.[7] | contains("�")) then "no" else "yes" end)
		})' "$base"
	rm -f "$base"
}

#----------------------------------------------------------#
#                         Hashing                          #
#----------------------------------------------------------#

fs_sha256() {
	sha256sum -- "$1" | cut -d ' ' -f 1
}

# JSON header line of a file read or saved: SIZE, MTIME, MIME, HASH of COPY
# (a root-owned copy of what the user read), MTIME of REAL (read as the user)
fs_header_json() {
	local copy=$1 real=$2 mtime
	mtime=$(user_exec stat -c %Y -- "$real" 2> /dev/null)
	jq -cn --argjson size "$(stat -c %s -- "$copy")" --argjson mtime "${mtime:-0}" \
		--arg mime "$(file -b --mime-type -- "$copy")" --arg hash "$(fs_sha256 "$copy")" \
		'{SIZE: $size, MTIME: $mtime, MIME: $mime, HASH: $hash}'
}
