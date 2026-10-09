#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel safe file work of root commands (E39 W1)                         #
#                                                                           #
# Three rules for every root command that touches a user's files:           #
#   1. Root works only in directories of its own: safe_workdir creates      #
#      them below $BACKUP_TEMP/.arcpanel-work (root, 0700).                 #
#   2. The user writes what lands in the user's home: copy_into_home        #
#      streams a tar from root's directory into user_exec tar.              #
#   3. A backup is untrusted input: snapshot IDs, object names and          #
#      configuration records read from it are validated before use.        #
# See docs/features/specs/2026-10-10-001-e39-w1-root-in-user-paths-design.md #
#                                                                           #
#===========================================================================#

# shellcheck source=/usr/local/arcpanel/func/fs.sh
if ! declare -F fs_check_new_path > /dev/null; then
	source "$ARCPANEL/func/fs.sh"
fi

# Working directories created by this command, removed on exit
SAFE_WORKDIRS=()

#----------------------------------------------------------#
#                  Root's working space                    #
#----------------------------------------------------------#

# Parent of the working directories of root commands (spec D1)
safe_work_root() {
	echo "${BACKUP_TEMP:-${BACKUP:-/backup}}/.arcpanel-work"
}

# Removes the working directories of this command
safe_workdir_cleanup() {
	local dir
	for dir in "${SAFE_WORKDIRS[@]}"; do
		if [ -n "$dir" ] && [ -d "$dir" ] && [ ! -L "$dir" ]; then
			rm -rf -- "$dir"
		fi
	done
	SAFE_WORKDIRS=()
}

# Creates a root-only working directory and sets SAFE_WORKDIR to its path.
# Call it directly, not in $( ), so that the removal on exit is registered
# in the command's own shell. A command with its own EXIT trap calls
# safe_workdir_cleanup from it. Stops with E_FORBIDEN when the parent is not
# root's alone.
safe_workdir() {
	local root parent
	root=$(safe_work_root)
	parent=$(dirname -- "$root")
	if [ ! -d "$parent" ]; then
		mkdir -p -- "$parent" || check_result "$E_FORBIDEN" "$parent cannot be created"
	fi
	if [ "$(stat -L -c %u -- "$parent")" != 0 ] || [ -n "$(find -L "$parent" -maxdepth 0 -perm /022 2> /dev/null)" ]; then
		check_result "$E_FORBIDEN" "$parent must belong to root and be writable by root only"
	fi
	if [ ! -e "$root" ] && [ ! -L "$root" ]; then
		mkdir -m 700 -- "$root" 2> /dev/null
	fi
	if [ -L "$root" ] || [ ! -d "$root" ] || [ "$(stat -c %u -- "$root")" != 0 ]; then
		check_result "$E_FORBIDEN" "$root must be a folder of root, not a link"
	fi
	chmod 700 -- "$root"
	SAFE_WORKDIR=$(mktemp -d -p "$root" "${1:-work}.XXXXXXXX") || check_result "$E_FORBIDEN" "no working folder in $root"
	SAFE_WORKDIRS+=("$SAFE_WORKDIR")
	trap safe_workdir_cleanup EXIT
}

#----------------------------------------------------------#
#                 Values read from backups                 #
#----------------------------------------------------------#

# Callback of check_result that stays quiet (for validators run as tests)
safe_quiet_result() {
	:
}

# Whether NAME read from a snapshot or an archive is a valid TYPE: domain,
# database, user or segment (one folder name). Returns non-zero without
# stopping the command, so the caller can skip the object and log it.
is_restore_name_valid() {
	local type=$1 name=$2
	[ -n "$name" ] || return 1
	case "$name" in
		-* | */* | *..* | . | *\'* | *\"* | *\\*) return 1 ;;
	esac
	if [[ "$name" =~ [[:cntrl:]] ]] || [ "${#name}" -gt 255 ]; then
		return 1
	fi
	(
		# shellcheck disable=SC2034
		CHECK_RESULT_CALLBACK=safe_quiet_result
		case "$type" in
			domain) is_domain_format_valid "$name" ;;
			database) is_database_format_valid "$name" 'database' ;;
			user) is_user_format_valid "$name" 'user' ;;
			segment) : ;;
			*) exit 1 ;;
		esac
	) > /dev/null 2>&1
}

# Keys whose values are password hashes and may hold $
SAFE_HASH_KEYS=' MD5 FTP_MD5 STATS_CRYPT '
# Keys whose values are free text (a cron command, a relay password): only
# a single quote and control characters are refused
SAFE_FREE_KEYS=' CMD U_SMTP_RELAY_PASSWORD '

# Whether VALUE of KEY can be written into a configuration record: no
# quote, backslash, backtick or control character, $ only in hashes.
is_record_value_valid() {
	local key=$1 value=$2
	if [[ "$value" =~ [[:cntrl:]] ]] || [ "${#value}" -gt 4096 ]; then
		return 1
	fi
	if [[ "$SAFE_FREE_KEYS" == *" $key "* ]]; then
		[[ "$value" != *\'* ]]
		return
	fi
	case "$value" in
		*\'* | *\"* | *\\* | *\`*) return 1 ;;
	esac
	if [[ "$value" == *'$'* ]] && [[ "$SAFE_HASH_KEYS" != *" $key "* ]]; then
		return 1
	fi
	return 0
}

# Parses one KEY='value' record read from a backup and sets only the
# variables named in the allow-list, which must be upper case. Unknown keys
# are ignored; a malformed record or an invalid value returns non-zero and
# sets nothing. Unlike parse_object_kv_list it never assigns a variable the
# record names on its own (spec D6).
#   parse_backup_record "KEY1 KEY2 …" "$line"
parse_backup_record() {
	local LC_ALL=C
	local allowed=" $1 " rest=$2 key value len re blank=$' \t' i
	local -a keys=() values=()
	re="^[${blank}]*([A-Z][A-Z0-9_]*)='([^']*)'([${blank}]+|\$)"
	if [[ "$rest" == *$'\n'* ]] || [[ "$rest" == *$'\r'* ]]; then
		return 1
	fi
	while [[ -n "${rest//[${blank}]/}" ]]; do
		[[ "$rest" =~ $re ]] || return 1
		key=${BASH_REMATCH[1]}
		value=${BASH_REMATCH[2]}
		len=${#BASH_REMATCH[0]}
		if [[ "$allowed" == *" $key "* ]]; then
			is_record_value_valid "$key" "$value" || return 1
			keys+=("$key")
			values+=("$value")
		fi
		rest=${rest:len}
	done
	for i in "${!keys[@]}"; do
		printf -v "${keys[i]}" '%s' "${values[i]}"
	done
	return 0
}

# Allow-lists of the records rebuilt by restore_record (spec D6). The
# restore sets TIME, DATE, SUSPENDED and the counters itself.
SAFE_RECORD_KEYS_web='DOMAIN IP IP6 ALIAS CUSTOM_DOCROOT CUSTOM_PHPROOT REDIRECT REDIRECT_CODE FASTCGI_CACHE FASTCGI_DURATION TPL SSL SSL_HOME SSL_HSTS SSL_FORCE LETSENCRYPT FTP_USER FTP_MD5 FTP_PATH BACKEND PROXY PROXY_EXT STATS STATS_USER STATS_CRYPT U_DISK'
SAFE_RECORD_KEYS_dns='DOMAIN IP TPL TTL EXP SOA RECORDS DNSSEC KEY SLAVE MASTER'
SAFE_RECORD_KEYS_mail='DOMAIN ANTIVIRUS ANTISPAM REJECT DKIM WEBMAIL SSL LETSENCRYPT CATCHALL ACCOUNTS RATE_LIMIT U_DISK'
SAFE_RECORD_KEYS_db='DB DBUSER MD5 HOST TYPE CHARSET U_DISK'
SAFE_RECORD_KEYS_cron='JOB MIN HOUR DAY MONTH WDAY CMD'
# Keys written only when the record has them
SAFE_RECORD_OPTIONAL_mail='LETSENCRYPT_FAIL_COUNT SSL_KEY_ALGO U_SMTP_RELAY U_SMTP_RELAY_HOST U_SMTP_RELAY_PORT U_SMTP_RELAY_USERNAME U_SMTP_RELAY_PASSWORD'

# Keys of TYPE (web, dns, mail, db, cron), the optional ones included
restore_record_keys() {
	local var="SAFE_RECORD_KEYS_$1" opt="SAFE_RECORD_OPTIONAL_$1"
	echo "${!var} ${!opt}"
}

# Unsets the variables of the records of TYPE before the next record is
# parsed
restore_record_reset() {
	local key
	for key in $(restore_record_keys "$1"); do
		unset -v "$key"
	done
}

# Prints one record of TYPE built from the allow-listed variables that
# parse_backup_record set, followed by EXTRA (KEY='value' pairs the restore
# sets itself). Prints nothing and returns non-zero when a value is not
# valid.
restore_record() {
	local type=$1 extra=$2 key value out='' opt="SAFE_RECORD_OPTIONAL_$1"
	for key in $(restore_record_keys "$type"); do
		value=${!key}
		if [ -z "$value" ] && [[ " ${!opt} " == *" $key "* ]]; then
			continue
		fi
		is_record_value_valid "$key" "$value" || return 1
		out+="$key='$value' "
	done
	echo "${out}${extra}"
}

#----------------------------------------------------------#
#                       restic                             #
#----------------------------------------------------------#

# Runs restic on the repository of USER: restic_user USER COMMAND [FLAGS…]
# -- ARGUMENTS… Callers put fixed flags before "--" and every value that
# comes from input after it, quoted.
restic_user() {
	local owner=$1
	shift
	restic --repo "${REPO%/}/$owner" --password-file "$ARCPANEL/data/users/$owner/restic.conf" "$@"
}

# Whether PATH is /home/$user or below it, absolute and normalised: no "."
# or ".." segment, no empty segment, no control character. Paths inside a
# snapshot cannot be resolved on disk, so this is a textual check.
is_snapshot_path_valid() {
	local path=$1 home="$HOMEDIR/$user"
	if [[ "$path" =~ [[:cntrl:]] ]]; then
		return 1
	fi
	case "$path/" in
		/*/../* | /*/./* | /*//*) return 1 ;;
		/*) ;;
		*) return 1 ;;
	esac
	[ "$path" = "$home" ] || [ "${path#"$home"/}" != "$path" ]
}

#----------------------------------------------------------#
#                     Into the home                        #
#----------------------------------------------------------#

# Copies the content of SRC (a folder root owns) into DST in the home of
# $user. The copy runs as the user, so the user owns every file and nothing
# outside the user's permissions can be reached. DST must resolve inside
# the home; missing folders are created by the user.
copy_into_home() {
	local src=$1 dst=$2
	if [ -L "$src" ] || [ ! -d "$src" ]; then
		check_result "$E_NOTEXIST" "$src is not a folder"
	fi
	fs_check_new_path "$dst"
	user_exec mkdir -p -- "$dst" || return "$E_FORBIDEN"
	tar -C "$src" -cf - . | user_exec tar -C "$dst" -xpf - --no-same-owner --no-overwrite-dir
	return "${PIPESTATUS[1]}"
}

# Whether root may write in PATH, a folder below ~/conf of $user: every
# folder from ~/conf down exists, is not a link, is not owned by the user
# and is not writable by the user (spec D3).
root_dir_safe() {
	local path=$1 base="$HOMEDIR/$user/conf" cur rest seg uid
	uid=$(id -u "$user") || return 1
	case "$path" in
		"$base" | "$base"/*) ;;
		*) return 1 ;;
	esac
	cur=$base
	rest=${path#"$base"}
	while :; do
		if [ -L "$cur" ] || [ ! -d "$cur" ] || [ "$(stat -c %u -- "$cur")" = "$uid" ] || user_exec test -w "$cur" 2> /dev/null; then
			return 1
		fi
		rest=${rest#/}
		[ -n "$rest" ] || break
		seg=${rest%%/*}
		rest=${rest#"$seg"}
		case "$seg" in
			. | ..) return 1 ;;
		esac
		cur="$cur/$seg"
	done
	return 0
}
