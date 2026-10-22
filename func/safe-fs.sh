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

# Whether VALUE is an IPv4 or IPv6 address in form only (restores use it in
# sed patterns and paths)
is_restore_ip_valid() {
	[[ "$1" =~ ^[0-9A-Fa-f:.]{2,45}$ ]]
}

# Whether NAME can be a template name (it becomes part of a path)
is_restore_template_valid() {
	[[ "$1" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$ ]] && [[ "$1" != *..* ]]
}

# Whether PATH is empty or a folder below ~/web of $user (custom document
# and PHP roots of a web domain)
is_restore_web_root_valid() {
	[ -z "$1" ] && return 0
	[[ "$1" =~ ^[A-Za-z0-9_./-]+$ ]] && [[ "$1/" != */../* ]] && [[ "$1" == "$HOMEDIR/$user/web/"* ]]
}

# Keeps the FTP accounts of the parsed web record whose name carries the
# user's prefix and whose hash and path are plain; FTP_USER, FTP_MD5 and
# FTP_PATH are colon-separated lists of the same length. The hash ends up
# in a sed expression on /etc/shadow.
restore_web_ftp_filter() {
	local -a names hashes paths
	local i name hash path out_user='' out_md5='' out_path=''
	IFS=':' read -r -a names <<< "$FTP_USER"
	IFS=':' read -r -a hashes <<< "$FTP_MD5"
	IFS=':' read -r -a paths <<< "$FTP_PATH"
	for i in "${!names[@]}"; do
		name=${names[i]}
		hash=${hashes[i]}
		path=${paths[i]}
		[[ "$name" =~ ^[A-Za-z0-9_.-]{1,64}$ ]] && [[ "$name" == "${user}_"* ]] || continue
		[[ "$hash" =~ $SAFE_CRYPT_RE ]] || continue
		[[ "$path" =~ ^[A-Za-z0-9_./-]*$ ]] && [[ "/$path/" != */../* ]] || continue
		out_user+=":$name"
		out_md5+=":$hash"
		out_path+=":$path"
	done
	FTP_USER=${out_user#:}
	FTP_MD5=${out_md5#:}
	FTP_PATH=${out_path#:}
}

# Characters of password hashes (crypt, hex, {SCHEME} prefixes)
SAFE_HASH_RE='^[A-Za-z0-9./$*{}=+:_-]*$'
# Characters of one FTP password hash
SAFE_CRYPT_RE='^[A-Za-z0-9./$]*$'
# Keys whose values are password hashes and may hold $
SAFE_HASH_KEYS=' MD5 FTP_MD5 STATS_CRYPT '
# Keys whose values are free text (a cron command, a TXT record, a name, a
# relay password): only a single quote and control characters are refused
SAFE_FREE_KEYS=' CMD VALUE NAME U_SMTP_RELAY_PASSWORD '

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
	# Hashes reach sed expressions (/etc/shadow) and SQL: crypt and hex
	# characters only
	if [[ "$SAFE_HASH_KEYS" == *" $key "* ]]; then
		[[ "$value" =~ $SAFE_HASH_RE ]]
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
SAFE_RECORD_KEYS_cron='JOB MIN HOUR DAY MONTH WDAY CMD SUSPENDED TIME DATE'
# Lines of the per-domain files: DNS records and mail accounts
SAFE_RECORD_KEYS_dnsrec='ID RECORD TYPE PRIORITY VALUE SUSPENDED TIME DATE'
SAFE_RECORD_KEYS_mailacc='ACCOUNT ALIAS AUTOREPLY FWD FWD_ONLY MD5 QUOTA U_DISK SUSPENDED TIME DATE'
# The account record of a full restore; ROLE is never taken from a backup,
# QRCODE no longer (E39 W5 §11.2)
SAFE_RECORD_KEYS_user='NAME CONTACT PACKAGE MD5 RKEY TWOFA TWOFA_RECOVERY LANGUAGE THEME NOTIFICATIONS PREF_UI_SORT WEB_TEMPLATE BACKEND_TEMPLATE PROXY_TEMPLATE DNS_TEMPLATE WEB_DOMAINS WEB_ALIASES DNS_DOMAINS DNS_RECORDS MAIL_DOMAINS MAIL_ACCOUNTS RATE_LIMIT DATABASES CRON_JOBS DISK_QUOTA BANDWIDTH BACKUPS BACKUPS_INCREMENTAL NS SHELL CPU_QUOTA CPU_QUOTA_PERIOD MEMORY_LIMIT SWAP_LIMIT PHPCLI CRON_REPORTS LOGIN_DISABLED LOGIN_USE_IPLIST LOGIN_ALLOW_IPS SUSPENDED SUSPENDED_USERS SUSPENDED_WEB SUSPENDED_DNS SUSPENDED_MAIL SUSPENDED_DB SUSPENDED_CRON IP_AVAIL IP_OWNED U_USERS U_DISK U_DISK_DIRS U_DISK_WEB U_DISK_MAIL U_DISK_DB U_BANDWIDTH U_WEB_DOMAINS U_WEB_SSL U_WEB_ALIASES U_DNS_DOMAINS U_DNS_RECORDS U_MAIL_DOMAINS U_MAIL_DKIM U_MAIL_ACCOUNTS U_MAIL_SSL U_DATABASES U_CRON_JOBS U_BACKUPS TIME DATE'
# Keys written only when the record has them
SAFE_RECORD_OPTIONAL_mail='LETSENCRYPT_FAIL_COUNT SSL_KEY_ALGO U_SMTP_RELAY U_SMTP_RELAY_HOST U_SMTP_RELAY_PORT U_SMTP_RELAY_USERNAME U_SMTP_RELAY_PASSWORD'
SAFE_RECORD_OPTIONAL_dns='SERIAL SRC'
SAFE_RECORD_OPTIONAL_dnsrec='TTL'

# Keys of TYPE (web, dns, mail, db, cron, dnsrec, mailacc, user), the
# optional ones included
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
	out+="$extra"
	echo "${out% }"
}

# Rewrites SRC, a file of records of TYPE read from a backup, into DST:
# every line goes through parse_backup_record and restore_record, and lines
# that fail are dropped. Prints the number of dropped lines. DST is written
# by root, so it must be a path of root's (data/users/…).
restore_records_file() {
	local type=$1 src=$2 dst=$3 line out dropped=0
	if [ -L "$src" ] || [ ! -f "$src" ]; then
		echo 0
		return 1
	fi
	: > "$dst.restore"
	while IFS= read -r line || [ -n "$line" ]; do
		[ -n "${line//[[:space:]]/}" ] || continue
		restore_record_reset "$type"
		if parse_backup_record "$(restore_record_keys "$type")" "$line" && out=$(restore_record "$type") && [ -n "$out" ]; then
			echo "$out" >> "$dst.restore"
		else
			dropped=$((dropped + 1))
		fi
	done < "$src"
	mv -f -- "$dst.restore" "$dst"
	echo "$dropped"
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
	# The repository chosen by restic_select (E48), else the one of restic.conf
	if [ -n "${RESTIC_REPOSITORY-}" ]; then
		restic "$@"
	else
		restic --repo "${REPO%/}/$owner" --password-file "$ARCPANEL/data/users/$owner/restic.conf" "$@"
	fi
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

# Restores paths of SNAPSHOT of $user into DIR, a working folder:
#   restic_restore_config DIR SNAPSHOT --include=PATH…
# The configuration the backup staged in ~/backup is read by root, so every
# link and special file is removed from the result: root then reads only
# regular files and folders that came from the snapshot.
restic_restore_config() {
	local dir=$1 snapshot=$2
	shift 2
	restic_user "$user" restore --target "$dir" "$@" -- "$snapshot" > /dev/null || return "$E_NOTEXIST"
	find "$dir" -mindepth 1 \( -type l -o \( ! -type f ! -type d \) \) -delete
}

# The --include options that restore the home of $user from SNAPSHOT
# without the folders NAME… directly in it, one per line. restic before
# 0.17 (Ubuntu 24.04 has 0.16) refuses --include with --exclude, so the
# entries of the home are listed and included one by one
restic_home_includes() {
	local snapshot=$1 home="$HOMEDIR/$user" path name
	shift
	restic_user "$user" ls --json -- "$snapshot" "$home" 2> /dev/null | jq -r 'select(.path) | .path' 2> /dev/null \
		| while IFS= read -r path; do
			name=${path#"$home"/}
			if [ "$path" = "$home" ] || [ "$name" = "$path" ] || [[ "$name" == */* ]] || [[ "$name" =~ [[:cntrl:]] ]]; then
				continue
			fi
			case " $* " in
				*" $name "*) continue ;;
			esac
			printf -- '--include=%s\n' "$path"
		done
}

# Restores user data (web, mail, files) of SNAPSHOT into DIR, links kept:
# the result only reaches the home through copy_into_home.
restic_restore_data() {
	local dir=$1 snapshot=$2
	shift 2
	restic_user "$user" restore --target "$dir" "$@" -- "$snapshot" > /dev/null || return "$E_NOTEXIST"
}

# Reads ~/backup/backup.conf of SNAPSHOT into DIR and sets WEB, DNS, MAIL,
# DB, CRON, UDIR (lists of the objects in the snapshot) and BACKUP_MODE
# from it. Returns
# E_NOTEXIST when the snapshot cannot be read and E_PARSING when the file
# is not a valid record.
read_snapshot_index() {
	local dir=$1 snapshot=$2
	unset -v WEB DNS MAIL DB CRON UDIR BACKUP_MODE
	restic_user "$user" dump -- "$snapshot" "/home/$user/backup/backup.conf" > "$dir/backup.conf" 2> /dev/null || return "$E_NOTEXIST"
	parse_backup_record 'WEB DNS MAIL DB CRON UDIR BACKUP_MODE' "$(head -n 1 -- "$dir/backup.conf")" || return "$E_PARSING"
}

# Copies SRC, a regular file in a working folder, to DST, a path of root's.
# Links and other file types are refused.
copy_from_work() {
	local src=$1 dst=$2
	if [ -L "$src" ] || [ ! -f "$src" ]; then
		return 1
	fi
	cp -f --no-dereference -- "$src" "$dst"
}

# Rewrites SRC, a backup-excludes.conf read from a backup, into DST: one
# KEY='value' line per known key with a plain value. The file is sourced by
# the backups, so nothing else may reach it.
restore_excludes_file() {
	local src=$1 dst=$2 line key value re="^(WEB|DNS|MAIL|DB|CRON|USER)='([^']*)'$"
	if [ -L "$src" ] || [ ! -f "$src" ]; then
		return 1
	fi
	: > "$dst.restore"
	while IFS= read -r line || [ -n "$line" ]; do
		[[ "$line" =~ $re ]] || continue
		key=${BASH_REMATCH[1]}
		value=${BASH_REMATCH[2]}
		is_record_value_valid "$key" "$value" || continue
		echo "$key='$value'" >> "$dst.restore"
	done < "$src"
	mv -f -- "$dst.restore" "$dst"
}

# Restore log line: names an object of a backup that was skipped
restore_skip() {
	echo "[ ! ] Skipped $1 $2: $3"
}

#----------------------------------------------------------#
#                     Into the home                        #
#----------------------------------------------------------#

# Copies the content of SRC (a folder root owns) into DST in the home of
# $user. The copy runs as the user, so the user owns every file and nothing
# outside the user's permissions can be reached. DST must resolve inside
# the home; missing folders are created by the user. Existing files are
# overwritten in place; existing folders keep their owner.
#
# When DST is a folder of root the user cannot write in (the home of a user
# without a shell is root:root 0751), root first creates the missing
# top-level entries, owned by the user: the user cannot replace entries of
# a folder they cannot write, so this does not race. Links at the top of
# such a folder are not restored. EXCLUDE, a file of tar patterns, leaves
# entries out.
#   copy_into_home SRC DST [EXCLUDE]
copy_into_home() {
	local src=$1 dst=$2 exclude=${3:-/dev/null} name parent
	if [ -L "$src" ] || [ ! -d "$src" ]; then
		check_result "$E_NOTEXIST" "$src is not a folder"
	fi
	fs_check_new_path "$dst"
	if [ ! -e "$dst" ] && [ ! -L "$dst" ]; then
		parent=$(dirname -- "$dst")
		if [ -d "$parent" ] && [ ! -L "$parent" ] && [ "$(stat -c %u -- "$parent")" = 0 ] && ! user_exec test -w "$parent"; then
			mkdir -m 700 -- "$dst" && chown -h "$user:$user" -- "$dst" || return "$E_FORBIDEN"
		else
			user_exec mkdir -p -- "$dst" || return "$E_FORBIDEN"
		fi
	fi
	if [ ! -L "$dst" ] && [ "$(stat -c %u -- "$dst")" = 0 ] && ! user_exec test -w "$dst"; then
		while IFS= read -r -d '' name; do
			if [ -e "$dst/$name" ] || [ -L "$dst/$name" ] || [ -L "$src/$name" ]; then
				continue
			fi
			if [ -d "$src/$name" ]; then
				mkdir -m "$(stat -c %a -- "$src/$name")" -- "$dst/$name"
			elif [ -f "$src/$name" ]; then
				install -m "$(stat -c %a -- "$src/$name")" /dev/null "$dst/$name"
			else
				continue
			fi
			chown -h "$user:$user" -- "$dst/$name"
		done < <(find "$src" -mindepth 1 -maxdepth 1 -printf '%P\0')
	fi
	if [ -z "$(find "$src" -mindepth 1 -maxdepth 1 -printf x -quit)" ]; then
		return 0
	fi
	(cd -- "$src" && find . -mindepth 1 -maxdepth 1 -printf '%P\0' | tar --null -X "$exclude" -T - -cf -) \
		| user_exec tar -C "$dst" -xpf - --no-same-owner --overwrite
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

#----------------------------------------------------------#
#                Databases from snapshots                  #
#----------------------------------------------------------#

# Whether the database record set by parse_backup_record (DB, DBUSER, TYPE,
# HOST, CHARSET) may be restored for $user: both names carry the user's
# prefix, the type and host are configured on this server and the charset
# is a plain name. DB and CHARSET end up in SQL statements.
is_restore_database_valid() {
	is_restore_name_valid database "$DB" && is_restore_name_valid database "$DBUSER" || return 1
	[[ "$DB" == "${user}_"* ]] && [[ "$DBUSER" == "${user}_"* ]] || return 1
	case "$TYPE" in
		mysql | pgsql) ;;
		*) return 1 ;;
	esac
	[[ "$CHARSET" =~ ^[A-Za-z0-9_]{0,32}$ ]] || return 1
	[ -n "$HOST" ] && grep -qF "HOST='$HOST'" "$ARCPANEL/conf/$TYPE.conf" 2> /dev/null
}

# Whether database DB is a database of another user
is_database_of_other_user() {
	local file
	for file in "$ARCPANEL"/data/users/*/db.conf; do
		[ "$file" = "$ARCPANEL/data/users/$user/db.conf" ] && continue
		grep -qF "DB='$1'" "$file" 2> /dev/null && return 0
	done
	return 1
}

# Imports DUMP into $DB ($TYPE on $HOST) through a temporary account that
# holds privileges on $DB only and is removed afterwards. A dump read from a
# snapshot is untrusted: with the server's administrator account it could
# reach every database (E39 W1). Needs a working folder (safe_workdir).
restore_import_database() {
	local dump=$1 account secret
	account="arcrestore$(tr -dc 'a-z0-9' < /dev/urandom | head -c 12)"
	secret=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)
	case "$TYPE" in
		mysql) restore_import_mysql "$dump" "$account" "$secret" ;;
		pgsql) restore_import_pgsql "$dump" "$account" "$secret" ;;
		*) return 1 ;;
	esac
}

restore_import_mysql() {
	local dump=$1 account=$2 secret=$3 cnf client=mysql rc
	[ -f /usr/bin/mariadb ] && client=mariadb
	mysql_connect "$HOST"
	mysql_query "CREATE USER '$account'@'localhost' IDENTIFIED BY '$secret';
CREATE USER '$account'@'%' IDENTIFIED BY '$secret';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$account'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$account'@'%';" || return "$E_DB"
	cnf="$SAFE_WORKDIR/restore-$account.cnf"
	(
		umask 077
		printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$HOST" "${PORT:-3306}" "$account" "$secret" > "$cnf"
	)
	# Definers name other accounts, which the temporary one may not use
	sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' -- "$dump" | "$client" --defaults-file="$cnf" "$DB" > /dev/null 2>&1
	rc=${PIPESTATUS[1]}
	mysql_query "DROP USER IF EXISTS '$account'@'localhost';
DROP USER IF EXISTS '$account'@'%';" > /dev/null
	rm -f -- "$cnf"
	return "$rc"
}

restore_import_pgsql() {
	local dump=$1 account=$2 secret=$3 rc
	psql_connect "$HOST"
	psql -h "$HOST" -U "$USER" -p "$PORT" -q -c "CREATE ROLE $account LOGIN PASSWORD '$secret'" > /dev/null 2>&1 || return "$E_DB"
	psql -h "$HOST" -U "$USER" -p "$PORT" -q -c "GRANT \"$DBUSER\" TO $account" > /dev/null 2>&1
	# Objects are created as the database's own role
	PGPASSWORD="$secret" PGOPTIONS="-c role=$DBUSER" psql -h "$HOST" -U "$account" -p "$PORT" -d "$DB" -q -f "$dump" > /dev/null 2>&1
	rc=$?
	psql -h "$HOST" -U "$USER" -p "$PORT" -d "$DB" -q -c "REASSIGN OWNED BY $account TO \"$DBUSER\"; DROP OWNED BY $account" > /dev/null 2>&1
	psql -h "$HOST" -U "$USER" -p "$PORT" -q -c "DROP ROLE IF EXISTS $account" > /dev/null 2>&1
	return "$rc"
}
