#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel backup destinations (E48): named places that store archives and  #
# snapshots. Remote drivers run through rclone with a configuration written #
# for one process into a private folder below /run; secrets are kept        #
# encrypted to the secret store's master key (E38). Every destination has   #
# an age key pair that encrypts archives (when ENCRYPT='yes') and the       #
# restic passwords stored next to the repositories. See                     #
# docs/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md #
# §4.                                                                       #
#                                                                           #
#===========================================================================#

BD_DIR="$ARCPANEL/data/backup/destinations"
BD_DRIVERS='local ftp sftp s3 b2 gdrive dropbox onedrive webdav rclone'
BD_REMOTE=arcdest

# Plain fields of each driver, after the common ones (DESCRIPTION ENCRYPT BWLIMIT)
bd_driver_fields() {
	case "$1" in
		local) echo 'PATH' ;;
		ftp) echo 'HOST PORT USERNAME TLS TLS_VERIFY PATH' ;;
		sftp) echo 'HOST PORT USERNAME PATH' ;;
		s3) echo 'PROVIDER ENDPOINT REGION BUCKET ACCESS_KEY_ID PATH_STYLE PATH' ;;
		b2) echo 'BUCKET KEY_ID PATH' ;;
		gdrive) echo 'AUTH ROOT_FOLDER_ID SHARED_DRIVE_ID PATH' ;;
		dropbox) echo 'PATH' ;;
		onedrive) echo 'DRIVE_ID DRIVE_TYPE PATH' ;;
		webdav) echo 'URL VENDOR USERNAME PATH' ;;
		rclone) echo 'REMOTE PATH' ;;
	esac
}

# Secret fields of each driver
bd_driver_secrets() {
	case "$1" in
		ftp | webdav) echo 'PASSWORD' ;;
		sftp) echo 'PASSWORD PRIVATE_KEY PASSPHRASE' ;;
		s3) echo 'SECRET_ACCESS_KEY' ;;
		b2) echo 'APP_KEY' ;;
		gdrive) echo 'TOKEN SERVICE_ACCOUNT' ;;
		dropbox | onedrive) echo 'TOKEN' ;;
		rclone) echo 'CONFIG' ;;
	esac
}

# Keys every record has besides the driver's fields
BD_STATE_KEYS='NAME DRIVER DESCRIPTION ENCRYPT BWLIMIT RESTIC_PREFIX HOST_KEY STATE CHECKED FAILURES KIT CREATED UPDATED'

#----------------------------------------------------------#
#                       Records                            #
#----------------------------------------------------------#

bd_name_valid() {
	[[ "$1" =~ ^[a-z0-9][a-z0-9-]{0,31}$ ]]
}

bd_driver_valid() {
	[[ " $BD_DRIVERS " == *" $1 "* ]]
}

bd_conf() {
	echo "$BD_DIR/$1.conf"
}

bd_exists() {
	bd_name_valid "$1" && [ -f "$(bd_conf "$1")" ]
}

# Names of every destination, sorted
bd_names() {
	local IFS=$' \t\n'
	local file
	for file in "$BD_DIR"/*.conf; do
		[ -f "$file" ] || continue
		basename -- "$file" .conf
	done | sort
}

# Value of KEY in the record of NAME (no source: PATH is a field)
bd_value() {
	grep -m1 "^$2='" "$(bd_conf "$1")" 2> /dev/null | cut -d"'" -f2
}

# Value of KEY in the record of NAME, or DEFAULT when it is empty
bd_value_or() {
	local value
	value=$(bd_value "$1" "$2")
	echo "${value:-$3}"
}

# Set KEY=VALUE pairs in the record of NAME, keeping the others
bd_set() {
	local IFS=$' \t\n'
	local name="$1" file pair key value tmp
	shift
	file=$(bd_conf "$name")
	tmp="$file.$$"
	cp -p -- "$file" "$tmp" 2> /dev/null || : > "$tmp"
	for pair in "$@"; do
		key=${pair%%=*}
		value=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
		if grep -q "^$key='" "$tmp"; then
			BD_KEY="$key" BD_VALUE="$value" awk -F= \
				'$1 == ENVIRON["BD_KEY"] { print ENVIRON["BD_KEY"] "='\''" ENVIRON["BD_VALUE"] "'\''"; next } { print }' \
				"$tmp" > "$tmp.new" && mv -f "$tmp.new" "$tmp"
		else
			printf "%s='%s'\n" "$key" "$value" >> "$tmp"
		fi
	done
	chmod 600 "$tmp"
	mv -f "$tmp" "$file"
}

# Exit unless NAME is a destination
bd_check_exists() {
	if ! bd_exists "$1"; then
		check_result "$E_NOTEXIST" "backup destination $1 doesn't exist"
	fi
}

# The folder of the records, root-only
bd_init_dir() {
	mkdir -p "$BD_DIR"
	chown root:root "$ARCPANEL/data/backup" "$BD_DIR"
	chmod 700 "$ARCPANEL/data/backup" "$BD_DIR"
}

# BACKUP_SYSTEM lists the drivers of the destinations, for scripts that
# read it (spec §10.1)
bd_sync_backup_system() {
	local IFS=$' \t\n'
	local drivers name list=''
	drivers=$(for name in $(bd_names); do bd_value "$name" DRIVER; done | sort -u | tr '\n' ',' | sed 's/,$//')
	list=$drivers
	if grep -q '^BACKUP_SYSTEM=' "$ARCPANEL/conf/arcpanel.conf"; then
		sed -i "s/^BACKUP_SYSTEM=.*/BACKUP_SYSTEM='$list'/" "$ARCPANEL/conf/arcpanel.conf"
	else
		echo "BACKUP_SYSTEM='$list'" >> "$ARCPANEL/conf/arcpanel.conf"
	fi
	BACKUP_SYSTEM=$list
}

#----------------------------------------------------------#
#                     Validation                           #
#----------------------------------------------------------#

# bd_field_valid DRIVER KEY VALUE: whether VALUE fits KEY (empty is allowed
# here; bd_check_required decides what must be set)
bd_field_valid() {
	local driver="$1" key="$2" value="$3"
	[ -z "$value" ] && return 0
	case "$key" in
		DESCRIPTION) [ ${#value} -le 128 ] && [ "$(printf '%s' "$value" | tr -d "\"'\\\\\`\$" | tr -d '[:cntrl:]')" = "$value" ] ;;
		ENCRYPT | TLS_VERIFY | PATH_STYLE) [[ "$value" =~ ^(yes|no)$ ]] ;;
		BWLIMIT) [[ "$value" =~ ^[0-9]{1,6}[KMG]?$ ]] ;;
		PATH)
			if [ "$driver" = local ]; then
				[[ "$value" =~ ^/[A-Za-z0-9._/-]{1,254}$ && ! "$value" =~ (^|/)\.\.?(/|$) && ! "$value" =~ // ]] \
					&& [[ ! "$value" =~ ^/(home|proc|sys|dev|run|etc|usr|bin|sbin|lib|boot|root)(/|$) ]] \
					&& [ "$value" != / ]
			else
				[[ "$value" =~ ^[A-Za-z0-9._/@+~-]{1,255}$ && ! "$value" =~ (^|/)\.\.(/|$) ]]
			fi
			;;
		HOST) [[ "$value" =~ ^[A-Za-z0-9][A-Za-z0-9.:-]{0,252}$ ]] ;;
		PORT) [[ "$value" =~ ^[0-9]{1,5}$ ]] && [ "$value" -ge 1 ] && [ "$value" -le 65535 ] ;;
		USERNAME) [[ "$value" =~ ^[A-Za-z0-9._@+-]{1,128}$ ]] ;;
		TLS) [[ "$value" =~ ^(explicit|implicit|none)$ ]] ;;
		PROVIDER) [[ "$value" =~ ^(AWS|Cloudflare|Wasabi|Other)$ ]] ;;
		ENDPOINT) [[ "$value" =~ ^(https://[A-Za-z0-9.-]+|http://(127\.0\.0\.1|localhost))(:[0-9]{1,5})?/?$ ]] ;;
		REGION) [[ "$value" =~ ^[a-z0-9-]{1,32}$ ]] ;;
		BUCKET)
			if [ "$driver" = b2 ]; then
				[[ "$value" =~ ^[A-Za-z0-9-]{6,50}$ ]]
			else
				[[ "$value" =~ ^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$ ]]
			fi
			;;
		ACCESS_KEY_ID | KEY_ID) [[ "$value" =~ ^[A-Za-z0-9+/=._-]{3,128}$ ]] ;;
		AUTH) [[ "$value" =~ ^(token|service-account)$ ]] ;;
		ROOT_FOLDER_ID | SHARED_DRIVE_ID | DRIVE_ID) [[ "$value" =~ ^[A-Za-z0-9!_.-]{1,128}$ ]] ;;
		DRIVE_TYPE) [[ "$value" =~ ^(personal|business|documentLibrary)$ ]] ;;
		URL) [[ "$value" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/%-]*)?$ ]] ;;
		VENDOR) [[ "$value" =~ ^(nextcloud|owncloud|other)$ ]] ;;
		REMOTE) [[ "$value" =~ ^[A-Za-z0-9_.-]{1,64}$ ]] ;;
		*) return 1 ;;
	esac
}

# Fields a destination of DRIVER cannot do without
bd_required_fields() {
	case "$1" in
		local) echo 'PATH' ;;
		ftp | sftp) echo 'HOST USERNAME' ;;
		s3) echo 'BUCKET ACCESS_KEY_ID' ;;
		b2) echo 'BUCKET KEY_ID' ;;
		gdrive) echo 'AUTH' ;;
		webdav) echo 'URL' ;;
	esac
}

# Secrets a destination of DRIVER cannot do without: one of the words
# separated by | must be set
bd_required_secrets() {
	case "$1" in
		ftp | webdav) echo 'PASSWORD' ;;
		sftp) echo 'PASSWORD|PRIVATE_KEY' ;;
		s3) echo 'SECRET_ACCESS_KEY' ;;
		b2) echo 'APP_KEY' ;;
		gdrive) echo 'TOKEN|SERVICE_ACCOUNT' ;;
		dropbox | onedrive) echo 'TOKEN' ;;
		rclone) echo 'CONFIG' ;;
	esac
}

# Check the value of a secret field
bd_secret_valid() {
	local key="$1" file="$2" size
	size=$(stat -c %s -- "$file" 2> /dev/null) || return 1
	[ "$size" -ge 1 ] && [ "$size" -le 65536 ] || return 1
	if grep -q -P '\x00' -- "$file" 2> /dev/null; then
		return 1
	fi
	case "$key" in
		PASSWORD | PASSPHRASE | SECRET_ACCESS_KEY | APP_KEY) [ "$(wc -l < "$file")" -le 1 ] ;;
		PRIVATE_KEY) grep -q -- '-----BEGIN [A-Z ]*PRIVATE KEY-----' "$file" ;;
		TOKEN | SERVICE_ACCOUNT) jq -e 'type == "object"' "$file" > /dev/null 2>&1 ;;
		CONFIG) grep -qE '^[[:space:]]*type[[:space:]]*=' "$file" ;;
		*) return 1 ;;
	esac
}

# bd_read_options DRIVER FILE: check the KEY='value' lines of FILE and print
# them as KEY=value lines. Unknown keys are refused.
bd_read_options() {
	local driver="$1" file="$2" line key value allowed
	allowed=" DESCRIPTION ENCRYPT BWLIMIT $(bd_driver_fields "$driver") "
	[ -z "$file" ] && return 0
	if [ ! -f "$file" ]; then
		echo "options file $file doesn't exist"
		return "$E_NOTEXIST"
	fi
	while IFS= read -r line || [ -n "$line" ]; do
		line=${line%$'\r'}
		[[ -z "$line" || "$line" =~ ^# ]] && continue
		if [[ ! "$line" =~ ^([A-Z_]+)=\'([^\']*)\'$ ]]; then
			echo "invalid line in the options: ${line%%=*}"
			return "$E_INVALID"
		fi
		key=${BASH_REMATCH[1]}
		value=${BASH_REMATCH[2]}
		if [[ "$allowed" != *" $key "* ]]; then
			echo "$key is not an option of the $driver driver"
			return "$E_INVALID"
		fi
		if ! bd_field_valid "$driver" "$key" "$value"; then
			echo "invalid $key"
			return "$E_INVALID"
		fi
		echo "$key=$value"
	done < "$file"
}

#----------------------------------------------------------#
#                Keys and secrets                          #
#----------------------------------------------------------#

# The secret store's master key (E38); created when missing
bd_check_keys() {
	if ! command -v age > /dev/null 2>&1; then
		check_result "$E_NOTEXIST" "age is not installed"
	fi
	if ! secret_master_init; then
		check_result "$E_FORBIDEN" "the master key could not be created"
	fi
}

# Print the identity of NAME
bd_identity() {
	age -d -i "$SECRET_MASTER/identity" "$BD_DIR/$1.key.age"
}

# Create the key pair of NAME, or import the identity of a recovery kit
bd_init_key() {
	local name="$1" kit="$2" ident
	if [ -n "$kit" ]; then
		ident=$(grep -m1 '^AGE-SECRET-KEY-' "$kit")
		[ -n "$ident" ] || return 1
	else
		ident=$(age-keygen 2> /dev/null | grep '^AGE-SECRET-KEY-') || return 1
	fi
	(
		umask 077
		printf '%s\n' "$ident" | age -e -R "$SECRET_MASTER/recipient" -o "$BD_DIR/$name.key.age.tmp" \
			&& mv -f "$BD_DIR/$name.key.age.tmp" "$BD_DIR/$name.key.age" \
			&& printf '%s\n' "$ident" | age-keygen -y > "$BD_DIR/$name.recipient.tmp" \
			&& mv -f "$BD_DIR/$name.recipient.tmp" "$BD_DIR/$name.recipient"
	)
}

# Print the secret fields of NAME as KEY<TAB>base64 lines
bd_secret_lines() {
	local file="$BD_DIR/$1.secret.age"
	[ -f "$file" ] || return 0
	age -d -i "$SECRET_MASTER/identity" "$file"
}

# Print the value of secret KEY of NAME
bd_secret() {
	bd_secret_lines "$1" | awk -F'\t' -v k="$2" '$1 == k { print $2; exit }' | base64 -d 2> /dev/null
}

# Names of the secrets NAME holds, space separated
bd_secret_names() {
	bd_secret_lines "$1" | cut -f1 | tr '\n' ' ' | sed 's/ $//'
}

# bd_store_secrets NAME DIR [KEEP]: write the secret fields of DIR (one
# file per key); with KEEP=yes the stored keys DIR does not name stay
bd_store_secrets() {
	local IFS=$' \t\n'
	local name="$1" dir="$2" keep="$3" driver key line lines='' current=''
	driver=$(bd_value "$name" DRIVER)
	if [ "$keep" = yes ]; then
		current=$(bd_secret_lines "$name")
	fi
	for key in $(bd_driver_secrets "$driver"); do
		line=''
		if [ -n "$dir" ] && [ -f "$dir/$key" ]; then
			# An empty file removes the secret
			if [ -s "$dir/$key" ]; then
				line="$key"$'\t'"$(base64 -w0 < "$dir/$key")"
			fi
		elif [ "$keep" = yes ]; then
			line=$(printf '%s\n' "$current" | awk -F'\t' -v k="$key" '$1 == k')
		fi
		if [ -n "$line" ]; then
			lines="$lines$line"$'\n'
		fi
	done
	(
		umask 077
		printf '%s' "$lines" | age -e -R "$SECRET_MASTER/recipient" -o "$BD_DIR/$name.secret.age.tmp" \
			&& mv -f "$BD_DIR/$name.secret.age.tmp" "$BD_DIR/$name.secret.age"
	)
}

# Exit unless DIR holds valid secrets of DRIVER
bd_check_secret_dir() {
	local IFS=$' \t\n'
	local driver="$1" dir="$2" key
	[ -z "$dir" ] && return 0
	if [ ! -d "$dir" ]; then
		check_result "$E_NOTEXIST" "secrets folder $dir doesn't exist"
	fi
	for key in $(bd_driver_secrets "$driver"); do
		if [ -f "$dir/$key" ] && [ -s "$dir/$key" ] && ! bd_secret_valid "$key" "$dir/$key"; then
			check_result "$E_INVALID" "invalid $key"
		fi
	done
}

#----------------------------------------------------------#
#                   One-run sessions                       #
#----------------------------------------------------------#

# Add COMMAND to the EXIT trap, after what is there
bd_trap_add() {
	local current
	current=$(trap -p EXIT | sed -E "s/^trap -- '(.*)' EXIT$/\1/")
	# shellcheck disable=SC2064
	trap "${current:+$current; }$1" EXIT
}

# A private folder for this process, removed when it exits
bd_session() {
	if [ -n "${BD_RUN-}" ] && [ -d "$BD_RUN" ]; then
		return 0
	fi
	BD_RUN=$(mktemp -d -p /run arcpanel-backup.XXXXXXXX) || return 1
	chmod 700 "$BD_RUN"
	bd_trap_add 'rm -rf -- "$BD_RUN"'
}

# rclone's obscured form of stdin
bd_obscure() {
	rclone obscure - 2> /dev/null
}

# One-line JSON of a secret
bd_json_line() {
	jq -c . 2> /dev/null
}

# Write the rclone configuration of NAME into the session and print its
# path. The remote is called $BD_REMOTE.
bd_rclone_config() {
	local name="$1" driver conf value
	bd_session || return 1
	driver=$(bd_value "$name" DRIVER)
	conf="$BD_RUN/$name.rclone.conf"
	(
		umask 077
		echo "[$BD_REMOTE]"
		case "$driver" in
			ftp)
				echo "type = ftp"
				echo "host = $(bd_value "$name" HOST)"
				echo "port = $(bd_value_or "$name" PORT 21)"
				echo "user = $(bd_value "$name" USERNAME)"
				echo "pass = $(bd_secret "$name" PASSWORD | bd_obscure)"
				case "$(bd_value "$name" TLS)" in
					implicit) echo "tls = true" ;;
					none) ;;
					*) echo "explicit_tls = true" ;;
				esac
				if [ "$(bd_value "$name" TLS_VERIFY)" = no ]; then
					echo "no_check_certificate = true"
				fi
				;;
			sftp)
				echo "type = sftp"
				echo "host = $(bd_value "$name" HOST)"
				echo "port = $(bd_value_or "$name" PORT 22)"
				echo "user = $(bd_value "$name" USERNAME)"
				value=$(bd_secret "$name" PRIVATE_KEY)
				if [ -n "$value" ]; then
					printf '%s\n' "$value" > "$BD_RUN/$name.key"
					echo "key_file = $BD_RUN/$name.key"
					value=$(bd_secret "$name" PASSPHRASE)
					[ -n "$value" ] && echo "key_file_pass = $(printf '%s' "$value" | bd_obscure)"
				else
					echo "pass = $(bd_secret "$name" PASSWORD | bd_obscure)"
				fi
				echo "known_hosts_file = $BD_DIR/$name.known_hosts"
				echo "shell_type = none"
				echo "disable_hashcheck = true"
				;;
			s3)
				echo "type = s3"
				echo "provider = $(bd_value_or "$name" PROVIDER Other)"
				echo "access_key_id = $(bd_value "$name" ACCESS_KEY_ID)"
				echo "secret_access_key = $(bd_secret "$name" SECRET_ACCESS_KEY)"
				value=$(bd_value "$name" REGION)
				echo "region = ${value:-us-east-1}"
				value=$(bd_value "$name" ENDPOINT)
				[ -n "$value" ] && echo "endpoint = ${value%/}"
				[ "$(bd_value "$name" PATH_STYLE)" = yes ] && echo "force_path_style = true"
				echo "no_check_bucket = true"
				;;
			b2)
				echo "type = b2"
				echo "account = $(bd_value "$name" KEY_ID)"
				echo "key = $(bd_secret "$name" APP_KEY)"
				echo "hard_delete = true"
				;;
			gdrive)
				echo "type = drive"
				if [ "$(bd_value "$name" AUTH)" = service-account ]; then
					echo "scope = drive"
					echo "service_account_credentials = $(bd_secret "$name" SERVICE_ACCOUNT | bd_json_line)"
				else
					echo "scope = drive.file"
					echo "token = $(bd_secret "$name" TOKEN | bd_json_line)"
				fi
				value=$(bd_value "$name" ROOT_FOLDER_ID)
				[ -n "$value" ] && echo "root_folder_id = $value"
				value=$(bd_value "$name" SHARED_DRIVE_ID)
				[ -n "$value" ] && echo "team_drive = $value"
				;;
			dropbox)
				echo "type = dropbox"
				echo "token = $(bd_secret "$name" TOKEN | bd_json_line)"
				;;
			onedrive)
				echo "type = onedrive"
				echo "token = $(bd_secret "$name" TOKEN | bd_json_line)"
				echo "drive_id = $(bd_value "$name" DRIVE_ID)"
				echo "drive_type = $(bd_value_or "$name" DRIVE_TYPE personal)"
				;;
			webdav)
				echo "type = webdav"
				echo "url = $(bd_value "$name" URL)"
				echo "vendor = $(bd_value_or "$name" VENDOR other)"
				value=$(bd_value "$name" USERNAME)
				[ -n "$value" ] && echo "user = $value"
				value=$(bd_secret "$name" PASSWORD)
				[ -n "$value" ] && echo "pass = $(printf '%s' "$value" | bd_obscure)"
				;;
			rclone)
				# The section of a remote, without its header
				bd_secret "$name" CONFIG | grep -vE '^[[:space:]]*\['
				;;
		esac
		# An optional field left out last must not fail the configuration
		true
	) > "$conf" || return 1
	cp -p "$conf" "$conf.orig"
	echo "$conf"
}

# OAuth drivers: rclone writes refreshed tokens into the one-run
# configuration; keep them (spec §4.3)
bd_token_writeback() {
	local name="$1" conf="$BD_RUN/$1.rclone.conf" driver token dir
	driver=$(bd_value "$name" DRIVER)
	case "$driver" in
		gdrive | dropbox | onedrive) ;;
		*) return 0 ;;
	esac
	[ -f "$conf" ] && [ -f "$conf.orig" ] || return 0
	cmp -s "$conf" "$conf.orig" && return 0
	token=$(grep -m1 '^token = ' "$conf" | sed 's/^token = //')
	[ -n "$token" ] || return 0
	dir="$BD_RUN/$name.writeback"
	mkdir -p "$dir"
	printf '%s' "$token" > "$dir/TOKEN"
	if bd_secret_valid TOKEN "$dir/TOKEN"; then
		bd_store_secrets "$name" "$dir" yes
	fi
	rm -rf -- "$dir"
	cp -p "$conf" "$conf.orig"
}

# rclone with the configuration of NAME
bd_rclone() {
	local name="$1" conf bw rc
	shift
	# The session of this shell: one made inside $(…) would go with it
	bd_session || return 1
	conf=$(bd_rclone_config "$name") || return 1
	bw=$(bd_value "$name" BWLIMIT)
	# A destination that does not answer fails within a minute, not after many back-offs
	rclone --config "$conf" --retries 3 --low-level-retries 3 --contimeout 20s --timeout 5m ${bw:+--bwlimit "$bw"} "$@"
	rc=$?
	bd_token_writeback "$name"
	return $rc
}

#----------------------------------------------------------#
#                     Locations                            #
#----------------------------------------------------------#

# The folder of NAME on its storage: an rclone path or a local folder
bd_base() {
	local name="$1" driver path bucket
	driver=$(bd_value "$name" DRIVER)
	path=$(bd_value "$name" PATH)
	case "$driver" in
		local) echo "${path%/}" ;;
		s3 | b2)
			bucket=$(bd_value "$name" BUCKET)
			path=${path#/}
			echo "$BD_REMOTE:$bucket${path:+/${path%/}}"
			;;
		sftp | ftp | rclone)
			echo "$BD_REMOTE:${path%/}"
			;;
		*)
			path=${path#/}
			echo "$BD_REMOTE:${path%/}"
			;;
	esac
}

# bd_join BASE REL
bd_join() {
	local base="$1" rel="${2#/}"
	if [[ "$base" == *: ]] || [ -z "$rel" ]; then
		echo "$base$rel"
	else
		echo "$base/$rel"
	fi
}

#----------------------------------------------------------#
#                     Operations                           #
#----------------------------------------------------------#

# bd_put NAME SRC REL: store SRC (a file, or - for stdin) as REL
bd_put() {
	local name="$1" src="$2" rel="$3" driver target
	driver=$(bd_value "$name" DRIVER)
	target=$(bd_join "$(bd_base "$name")" "$rel")
	if [ "$driver" = local ]; then
		mkdir -p -- "$(dirname -- "$target")" || return 1
		if [ "$src" = - ]; then
			(umask 027 && cat > "$target.part") && mv -f -- "$target.part" "$target"
		else
			cp -f -- "$src" "$target.part" && mv -f -- "$target.part" "$target"
		fi
		return $?
	fi
	if [ "$src" = - ]; then
		bd_rclone "$name" rcat "$target"
	else
		bd_rclone "$name" copyto "$src" "$target"
	fi
}

# bd_get NAME REL DST: fetch REL into DST (a file, or - for stdout)
bd_get() {
	local name="$1" rel="$2" dst="$3" driver source
	driver=$(bd_value "$name" DRIVER)
	source=$(bd_join "$(bd_base "$name")" "$rel")
	if [ "$driver" = local ]; then
		if [ "$dst" = - ]; then
			cat -- "$source"
		else
			cp -f -- "$source" "$dst"
		fi
		return $?
	fi
	if [ "$dst" = - ]; then
		bd_rclone "$name" cat "$source"
	else
		bd_rclone "$name" copyto "$source" "$dst"
	fi
}

# bd_list NAME [DIR]: NAME<TAB>SIZE of the files in DIR
bd_list() {
	local name="$1" dir="$2" driver base
	driver=$(bd_value "$name" DRIVER)
	base=$(bd_join "$(bd_base "$name")" "$dir")
	if [ "$driver" = local ]; then
		[ -d "$base" ] || return 0
		find "$base" -mindepth 1 -maxdepth 1 -type f -printf '%f\t%s\n' | sort
		return 0
	fi
	bd_rclone "$name" lsf --files-only --format ps --separator $'\t' "$base" 2> /dev/null | sort
}

# bd_delete NAME REL
bd_delete() {
	local name="$1" rel="$2" driver target
	driver=$(bd_value "$name" DRIVER)
	target=$(bd_join "$(bd_base "$name")" "$rel")
	if [ "$driver" = local ]; then
		rm -f -- "$target"
		return $?
	fi
	bd_rclone "$name" deletefile "$target"
}

# Host keys of an SFTP destination: every key the server offers goes to
# FILE (rclone may agree on any of them); the fingerprint printed, the one
# the administrator accepts, is of the ed25519 key, else the first one
bd_scan_host_key() {
	local host="$1" port="$2" file="$3" lines line fp
	lines=$(ssh-keyscan -T 10 -p "$port" "$host" 2> /dev/null | grep -v '^#')
	[ -n "$lines" ] || return 1
	line=$(printf '%s\n' "$lines" | grep -m1 ' ssh-ed25519 ' || printf '%s\n' "$lines" | head -n1)
	fp=$(printf '%s\n' "$line" | ssh-keygen -lf - 2> /dev/null | awk '{ print $2 }')
	[ -n "$fp" ] || return 1
	printf '%s\n' "$lines" > "$file"
	echo "$fp"
}

# bd_check NAME: write, list, read back and delete a small file. Prints
# the error on failure.
bd_check() {
	local name="$1" rel probe got driver path
	bd_session || return 1
	driver=$(bd_value "$name" DRIVER)
	rel=".arcpanel-check-$(date +%s)-$RANDOM"
	probe="$BD_RUN/probe"
	echo "ArcPanel backup destination check" > "$probe"
	if [ "$driver" = local ]; then
		path=$(bd_value "$name" PATH)
		mkdir -p -- "$path" 2> /dev/null && chmod 711 -- "$path" 2> /dev/null
	fi
	if ! bd_put "$name" "$probe" "$rel" > "$BD_RUN/check.log" 2>&1; then
		bd_check_error "could not write"
		return 1
	fi
	if ! bd_list "$name" | cut -f1 | grep -qx -- "$rel"; then
		bd_delete "$name" "$rel" > /dev/null 2>&1
		echo "the test file was written but is not listed"
		return 1
	fi
	got=$(bd_get "$name" "$rel" - 2> "$BD_RUN/check.log")
	bd_delete "$name" "$rel" >> "$BD_RUN/check.log" 2>&1
	if [ "$got" != "ArcPanel backup destination check" ]; then
		bd_check_error "could not read back"
		return 1
	fi
	return 0
}

# The last line rclone logged as an error, without secrets
bd_check_error() {
	local reason
	reason=$(grep -iE 'error|failed|denied|refused|timeout|unauthori|forbidden|not found|no such' "$BD_RUN/check.log" 2> /dev/null \
		| tail -n1 | sed -E 's/^[0-9\/: ]+(ERROR|NOTICE|CRITICAL)? *:? *//' | cut -c1-300)
	echo "$1${reason:+: $reason}"
}

# Record the result of a transfer: ok resets the failures, failed counts
# them and marks the destination failing after three (spec §4.4)
bd_record_transfer() {
	local name="$1" result="$2" failures state
	bd_exists "$name" || return 0
	if [ "$result" = ok ]; then
		bd_set "$name" FAILURES=0 STATE=ok
		return 0
	fi
	failures=$(bd_value "$name" FAILURES)
	failures=$((${failures:-0} + 1))
	state=$(bd_value "$name" STATE)
	if [ "$failures" -ge 3 ]; then
		if [ "$state" != failing ] && declare -F bj_notify_destination > /dev/null; then
			bj_notify_destination "$name"
		fi
		state=failing
	fi
	bd_set "$name" "FAILURES=$failures" "STATE=$state"
}

#----------------------------------------------------------#
#                    Encryption                            #
#----------------------------------------------------------#

# Encrypt stdin to the key of NAME
bd_encrypt() {
	age -e -R "$BD_DIR/$1.recipient"
}

# Decrypt stdin with the key of NAME
bd_decrypt() {
	age -d -i <(bd_identity "$1")
}

# bd_restic_password USER NAME: print the restic password of USER on NAME,
# reading the copy on the destination or creating one when it is new.
bd_restic_password() {
	local owner="$1" name="$2" dir file rel password
	dir="$ARCPANEL/data/users/$owner/restic"
	file="$dir/$name.age"
	if [ -f "$file" ]; then
		age -d -i "$SECRET_MASTER/identity" "$file"
		return $?
	fi
	mkdir -p "$dir"
	chmod 700 "$dir"
	rel=$(bd_restic_rel "$name" "$owner").password.age
	bd_session || return 1
	if [ -s "$ARCPANEL/data/users/$owner/restic.conf" ]; then
		# The password of earlier versions, kept in plain text: moved here
		password=$(head -n1 "$ARCPANEL/data/users/$owner/restic.conf")
		printf '%s\n' "$password" | bd_encrypt "$name" > "$BD_RUN/password.age" || return 1
		bd_put "$name" "$BD_RUN/password.age" "$rel" > /dev/null 2>&1
	elif bd_get "$name" "$rel" "$BD_RUN/password.age" > /dev/null 2>&1 && [ -s "$BD_RUN/password.age" ]; then
		password=$(bd_decrypt "$name" < "$BD_RUN/password.age") || return 1
	else
		password=$(generate_password '' '32')
		printf '%s\n' "$password" | bd_encrypt "$name" > "$BD_RUN/password.age" || return 1
		bd_put "$name" "$BD_RUN/password.age" "$rel" > /dev/null 2>&1 || return 1
	fi
	(umask 077 && printf '%s\n' "$password" | age -e -R "$SECRET_MASTER/recipient" -o "$file.tmp") \
		&& mv -f "$file.tmp" "$file" || return 1
	printf '%s\n' "$password"
}

# The folder of USER's repository below the base of NAME
bd_restic_rel() {
	local prefix
	prefix=$(bd_value "$1" RESTIC_PREFIX)
	echo "${prefix:+$prefix/}$2"
}

# bd_restic_env USER NAME: export what restic needs for USER's repository
# on NAME (RESTIC_REPOSITORY, RESTIC_PASSWORD_FILE, RCLONE_CONFIG)
bd_restic_env() {
	local owner="$1" name="$2" driver base
	bd_session || return 1
	driver=$(bd_value "$name" DRIVER)
	base=$(bd_join "$(bd_base "$name")" "$(bd_restic_rel "$name" "$owner")")
	if [ "$driver" = local ]; then
		export RESTIC_REPOSITORY="$base"
		unset RCLONE_CONFIG
	else
		export RCLONE_CONFIG
		RCLONE_CONFIG=$(bd_rclone_config "$name") || return 1
		export RESTIC_REPOSITORY="rclone:$base"
	fi
	export RESTIC_PASSWORD_FILE="$BD_RUN/restic-$owner-$name"
	(umask 077 && bd_restic_password "$owner" "$name" > "$RESTIC_PASSWORD_FILE") || return 1
	[ -s "$RESTIC_PASSWORD_FILE" ]
}

#----------------------------------------------------------#
#                       Output                             #
#----------------------------------------------------------#

# JSON object of NAME without secrets: every field plus SECRETS (the names
# of the stored secrets) and JOBS (the jobs that target it)
bd_json() {
	local IFS=$' \t\n'
	local name="$1" key keys driver json='{}' value
	driver=$(bd_value "$name" DRIVER)
	keys="$BD_STATE_KEYS $(bd_driver_fields "$driver")"
	for key in $keys; do
		value=$(bd_value "$name" "$key")
		json=$(jq -c --arg k "$key" --arg v "$value" '. + {($k): $v}' <<< "$json")
	done
	json=$(jq -c --arg v "$(bd_secret_names "$name")" --arg j "$(bd_jobs_of "$name")" '. + {SECRETS: $v, JOBS: $j}' <<< "$json")
	printf '%s\n' "$json"
}

# IDs of the jobs that target NAME, comma separated
bd_jobs_of() {
	local IFS=$' \t\n'
	local file
	for file in "$ARCPANEL/data/backup/jobs"/*.conf; do
		[ -f "$file" ] || continue
		if grep -m1 "^TARGETS='" "$file" | cut -d"'" -f2 | tr ',' '\n' | cut -d: -f1 | grep -qx -- "$1"; then
			basename -- "$file" .conf
		fi
	done | tr '\n' ',' | sed 's/,$//'
}
