#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel secret store (E38)                                               #
#                                                                           #
# Path-named secrets per user (/shop/db_password), encrypted with age: each #
# value with the user's key, each user key with the server's master key.   #
# Plaintext only travels through pipes; no value and no private key is     #
# ever a command argument or written to disk by these functions.           #
# See docs/features/specs/2026-10-09-001-e38-secret-store-design.md.        #
#                                                                           #
#===========================================================================#

SECRET_STORE="$ARCPANEL/data/secrets"
SECRET_MASTER="$SECRET_STORE/.master"
SECRET_KEEP=5
SECRET_MAX_BYTES=32768
SECRET_DEFAULT_LIMIT=200

#----------------------------------------------------------#
#                       Locations                          #
#----------------------------------------------------------#

secret_dir() {
	echo "$SECRET_STORE/$1"
}

secret_index() {
	echo "$SECRET_STORE/$1/params.conf"
}

# Directory id of NAME: the first 16 hex characters of its SHA-256
secret_id() {
	printf '%s' "$1" | sha256sum | cut -c1-16
}

secret_vdir() {
	echo "$SECRET_STORE/$1/params/$(secret_id "$2")"
}

#----------------------------------------------------------#
#                        Checks                            #
#----------------------------------------------------------#

# Whether age is installed and the master key exists
secret_store_ready() {
	command -v age > /dev/null 2>&1 && [ -f "$SECRET_MASTER/identity" ] && [ -f "$SECRET_MASTER/recipient" ]
}

# Exit unless the store is ready
secret_check_system() {
	if ! secret_store_ready; then
		check_result "$E_NOTEXIST" "the secret store is not set up (run v-add-sys-secret-key)"
	fi
}

# One to five segments of [A-Za-z0-9_.-], each 1-64 characters and not
# . or .., at most 128 characters (D3)
secret_name_valid() {
	local name="$1" seg
	local -a segs
	[ "${#name}" -le 128 ] || return 1
	[[ "$name" =~ ^(/[A-Za-z0-9_.-]{1,64}){1,5}$ ]] || return 1
	IFS=/ read -ra segs <<< "${name#/}"
	for seg in "${segs[@]}"; do
		if [ "$seg" = '.' ] || [ "$seg" = '..' ]; then
			return 1
		fi
	done
	return 0
}

secret_check_name() {
	if ! secret_name_valid "$1"; then
		check_result "$E_INVALID" "invalid secret name :: $1 (/path/name, letters, digits, _ . -)"
	fi
}

# At most 255 characters, no quotes, $, `, \ or | (the separator of the
# list rows) and no control characters
secret_check_description() {
	local text="$1"
	if [ "${#text}" -gt 255 ] || [[ "$text" == *[\'\"\$\`\\\|]* ]] || [[ "$text" == *[[:cntrl:]]* ]]; then
		check_result "$E_INVALID" "invalid description (at most 255 characters, without quotes, \$, \`, \\ or |)"
	fi
}

# Whether FILE ends with a newline, which is dropped from the value
secret_file_trailing_newline() {
	[ -s "$1" ] && [ "$(tail -c1 "$1" | wc -l)" -eq 1 ]
}

# Exit unless FILE holds a usable value: a regular file, 1 byte to 32 KiB
# after the dropped trailing newline, no NUL byte (D4)
secret_check_value_file() {
	local file="$1" size
	if [ ! -f "$file" ] || [ -L "$file" ]; then
		check_result "$E_NOTEXIST" "file $file does not exist"
	fi
	size=$(stat -c %s "$file")
	if secret_file_trailing_newline "$file"; then
		size=$((size - 1))
	fi
	if [ "$size" -lt 1 ]; then
		check_result "$E_INVALID" "the value is empty"
	fi
	if [ "$size" -gt "$SECRET_MAX_BYTES" ]; then
		check_result "$E_INVALID" "the value is larger than 32 KiB"
	fi
	if [ "$(tr -d '\000' < "$file" | wc -c)" -ne "$(stat -c %s "$file")" ]; then
		check_result "$E_INVALID" "the value contains a NUL byte"
	fi
}

# Print the value held in FILE, without its trailing newline
secret_file_value() {
	if secret_file_trailing_newline "$1"; then
		head -c -1 "$1"
	else
		cat "$1"
	fi
}

#----------------------------------------------------------#
#                         Keys                             #
#----------------------------------------------------------#

# Create the master key once
secret_master_init() {
	local ident
	mkdir -p "$SECRET_MASTER"
	chown root:root "$SECRET_STORE" "$SECRET_MASTER"
	chmod 700 "$SECRET_STORE" "$SECRET_MASTER"
	if [ ! -f "$SECRET_MASTER/identity" ]; then
		ident=$(age-keygen 2> /dev/null) || return 1
		(
			umask 077
			printf '%s\n' "$ident" > "$SECRET_MASTER/identity.tmp" \
				&& mv -f "$SECRET_MASTER/identity.tmp" "$SECRET_MASTER/identity"
		) || return 1
	fi
	chmod 400 "$SECRET_MASTER/identity"
	if [ ! -s "$SECRET_MASTER/recipient" ]; then
		(umask 077 && age-keygen -y "$SECRET_MASTER/identity" > "$SECRET_MASTER/recipient") || return 1
	fi
	chmod 600 "$SECRET_MASTER/recipient"
}

# Print the identity of USER, decrypted with the master key
secret_user_identity() {
	age -d -i "$SECRET_MASTER/identity" "$(secret_dir "$1")/key.age"
}

# Create the directory and the keys of USER when they are missing
secret_user_init() {
	local user="$1" dir ident
	dir=$(secret_dir "$user")
	if [ -s "$dir/key.age" ] && [ -s "$dir/recipient" ] && [ -s "$dir/fingerprint.key" ]; then
		return 0
	fi
	mkdir -p "$dir/params"
	chown root:root "$dir" "$dir/params"
	chmod 700 "$dir" "$dir/params"
	(
		umask 077
		if [ ! -s "$dir/key.age" ]; then
			ident=$(age-keygen 2> /dev/null) || exit 1
			printf '%s\n' "$ident" | age -e -R "$SECRET_MASTER/recipient" -o "$dir/key.age.tmp" || exit 1
			mv -f "$dir/key.age.tmp" "$dir/key.age"
		fi
		if [ ! -s "$dir/recipient" ]; then
			secret_user_identity "$user" | age-keygen -y > "$dir/recipient.tmp" || exit 1
			mv -f "$dir/recipient.tmp" "$dir/recipient"
		fi
		if [ ! -s "$dir/fingerprint.key" ]; then
			head -c 32 /dev/urandom > "$dir/fingerprint.key.tmp" || exit 1
			mv -f "$dir/fingerprint.key.tmp" "$dir/fingerprint.key"
		fi
		touch "$dir/params.conf"
	)
}

# Exit unless the keys of USER exist or could be created
secret_check_user() {
	if ! secret_user_init "$1"; then
		check_result "$E_FORBIDEN" "the secret keys of $1 could not be created"
	fi
}

#----------------------------------------------------------#
#                   Encryption and hashes                  #
#----------------------------------------------------------#

# Encrypt stdin to the key of USER into OUT
secret_encrypt() {
	(umask 077 && age -e -R "$(secret_dir "$1")/recipient" -o "$2.tmp") && mv -f "$2.tmp" "$2"
}

# Print the plaintext of the encrypted file IN of USER
secret_decrypt() {
	age -d -i <(secret_user_identity "$1") "$2"
}

# Fingerprint of stdin: the first 8 hex characters of SHA-256 over the
# user's fingerprint key and the value (a keyed hash; no HMAC tool reads its
# key from a file, and length extension does not matter for a fingerprint)
secret_fingerprint() {
	cat "$(secret_dir "$1")/fingerprint.key" - | sha256sum | cut -c1-8
}

#----------------------------------------------------------#
#                         Index                            #
#----------------------------------------------------------#

# Value of KEY in the record LINE
secret_field() {
	printf '%s\n' "$1" | grep -oE "(^| )$2='[^']*'" | head -n1 | cut -d"'" -f2
}

# Record line of USER's NAME, empty when there is none
secret_line() {
	grep "^ID='$(secret_id "$2")' " "$(secret_index "$1")" 2> /dev/null | head -n1
}

secret_exists() {
	[ -n "$(secret_line "$1" "$2")" ]
}

secret_count() {
	grep -c "^ID=" "$(secret_index "$1")" 2> /dev/null || true
}

secret_limit() {
	echo "${SECRETS_LIMIT:-$SECRET_DEFAULT_LIMIT}"
}

secret_names() {
	grep "^ID=" "$(secret_index "$1")" 2> /dev/null | grep -oE " NAME='[^']*'" | cut -d"'" -f2
}

# Exit unless USER has NAME
secret_check_exists() {
	if ! secret_exists "$1" "$2"; then
		check_result "$E_NOTEXIST" "secret $2 not found"
	fi
}

# Write the record of USER's NAME (replaces the old one)
secret_write_line() {
	local user="$1" name="$2" description="$3" version="$4" versions="$5" fingerprint="$6"
	local index id
	index=$(secret_index "$user")
	id=$(secret_id "$name")
	(
		umask 077
		grep -v "^ID='$id' " "$index" > "$index.tmp" 2> /dev/null
		echo "ID='$id' NAME='$name' DESCRIPTION='$description' VERSION='$version' VERSIONS='$versions' FINGERPRINT='$fingerprint' TIME='$(date +%T)' DATE='$(date +%F)'" >> "$index.tmp"
		mv -f "$index.tmp" "$index"
	)
}

# Lines of the versions of USER's NAME
secret_versions() {
	cat "$(secret_vdir "$1" "$2")/versions.conf" 2> /dev/null
}

# Hold the lock of USER's store for the rest of the command
secret_lock() {
	exec {SECRET_LOCK_FD}> "$(secret_dir "$1")/.lock"
	if ! flock -w 60 "$SECRET_LOCK_FD"; then
		check_result "$E_LA" "the secret store of $1 is busy"
	fi
}

#----------------------------------------------------------#
#                        Changes                           #
#----------------------------------------------------------#

# Store stdin as the next version of USER's NAME (a new secret when it does
# not exist) with DESCRIPTION, or the current description when DESCRIPTION
# is not given; keeps the last SECRET_KEEP versions. Prints the new version.
secret_store_version() {
	local user="$1" name="$2" description="${3-}" line version vdir fingerprint versions v keep
	line=$(secret_line "$user" "$name")
	vdir=$(secret_vdir "$user" "$name")
	version=1
	if [ -n "$line" ]; then
		version=$(($(secret_field "$line" VERSION) + 1))
		if [ "$#" -lt 3 ]; then
			description=$(secret_field "$line" DESCRIPTION)
		fi
	fi
	mkdir -p "$vdir"
	chmod 700 "$vdir"
	secret_encrypt "$user" "$vdir/v$version.age" || return "$E_FORBIDEN"
	fingerprint=$(secret_decrypt "$user" "$vdir/v$version.age" | secret_fingerprint "$user") || return "$E_FORBIDEN"
	(
		umask 077
		echo "VERSION='$version' FINGERPRINT='$fingerprint' TIME='$(date +%T)' DATE='$(date +%F)'" >> "$vdir/versions.conf"
	)
	# Keep the last SECRET_KEEP versions
	keep=$(grep -oE "^VERSION='[0-9]+'" "$vdir/versions.conf" | cut -d"'" -f2 | sort -n | tail -n "$SECRET_KEEP")
	for v in $(grep -oE "^VERSION='[0-9]+'" "$vdir/versions.conf" | cut -d"'" -f2); do
		if ! grep -qx "$v" <<< "$keep"; then
			rm -f "$vdir/v$v.age"
			sed -i "/^VERSION='$v' /d" "$vdir/versions.conf"
		fi
	done
	versions=$(echo "$keep" | tr '\n' ' ' | sed 's/ $//')
	secret_write_line "$user" "$name" "$description" "$version" "$versions" "$fingerprint"
	echo "$version"
}

# Remove USER's NAME with its versions
secret_remove() {
	local user="$1" name="$2" index
	index=$(secret_index "$user")
	rm -rf "$(secret_vdir "$user" "$name")"
	(
		umask 077
		grep -v "^ID='$(secret_id "$name")' " "$index" > "$index.tmp"
		mv -f "$index.tmp" "$index"
	)
}

#----------------------------------------------------------#
#                       References                         #
#----------------------------------------------------------#

# Values decrypted by this command, per USER:NAME
declare -A SECRET_CACHE=()
SECRET_REASON=''

# Print the current value of USER's NAME
secret_current_value() {
	local version
	version=$(secret_field "$(secret_line "$1" "$2")" VERSION)
	secret_decrypt "$1" "$(secret_vdir "$1" "$2")/v$version.age"
}

# Replace every {{secret:NAME}} in TEXT with the current value of USER's
# NAME and store the result in the variable OUT. Values are single-line
# (both .env formats are line-based). On failure SECRET_REASON says why
# and the return code is E_NOTEXIST or E_INVALID. Call it without a
# subshell, so OUT is set.
secret_resolve_into() {
	local __user="$1" __rest="$2" __result='' __ref __name __value __key
	local -n __secret_out="$3"
	SECRET_REASON=''
	while [[ "$__rest" =~ \{\{secret:([^}]*)\}\} ]]; do
		__ref=${BASH_REMATCH[0]}
		__name=${BASH_REMATCH[1]}
		__key="$__user:$__name"
		if [ -z "${SECRET_CACHE[$__key]+x}" ]; then
			if ! secret_name_valid "$__name" || ! secret_exists "$__user" "$__name"; then
				SECRET_REASON="secret $__name not found"
				return "$E_NOTEXIST"
			fi
			__value=$(secret_current_value "$__user" "$__name" && printf x)
			if [ "${__value: -1}" != 'x' ]; then
				SECRET_REASON="secret $__name could not be decrypted"
				return "$E_FORBIDEN"
			fi
			SECRET_CACHE[$__key]=${__value%x}
		fi
		__value=${SECRET_CACHE[$__key]}
		if [[ "$__value" == *$'\n'* ]] || [[ "$__value" == *$'\r'* ]]; then
			SECRET_REASON="secret $__name has several lines and cannot go into an environment value"
			return "$E_INVALID"
		fi
		__result+="${__rest%%"$__ref"*}$__value"
		__rest=${__rest#*"$__ref"}
	done
	__secret_out="$__result$__rest"
}

# Check that every reference in TEXT resolves for USER; SECRET_REASON says
# why when it does not
secret_check_text() {
	local resolved
	if [[ "$2" != *'{{secret:'* ]]; then
		return 0
	fi
	if ! secret_store_ready; then
		SECRET_REASON="the secret store is not set up"
		return "$E_NOTEXIST"
	fi
	secret_resolve_into "$1" "$2" resolved
}

# Whether VALUE is exactly one reference, which may be shown where a
# password is not (a value that mixes text and a reference is a secret)
secret_is_reference() {
	[[ "$1" =~ ^\{\{secret:[^}]+\}\}$ ]]
}

# Print the names referenced in TEXT, one per line
secret_refs() {
	printf '%s\n' "$1" | grep -oE '\{\{secret:[^}]*\}\}' | sed -e 's/^{{secret://' -e 's/}}$//' | sort -u
}

# Print "APP<TAB>NAME" for every secret NAME that an app of USER references:
# node:DOMAIN for the .env of a Node.js app (read as the user), docker:NAME
# for the .env of a Docker app. The paths are those of nodeapp_dir and
# dockerapp_rdir, written out so the secret commands need not load the app
# libraries.
secret_refs_of_user() {
	local user="$1" domain app name file
	for domain in $(grep -oE "^DOMAIN='[^']*'" "$ARCPANEL/data/users/$user/nodeapps.conf" 2> /dev/null | cut -d"'" -f2); do
		file="$HOMEDIR/$user/web/$domain/nodeapp/.env"
		for name in $(secret_refs "$(user="$user" user_exec head -c 65536 -- "$file" 2> /dev/null | grep -v '^[[:space:]]*#')"); do
			printf 'node:%s\t%s\n' "$domain" "$name"
		done
	done
	for app in $(grep -oE "^NAME='[^']*'" "$ARCPANEL/data/users/$user/dockerapps.conf" 2> /dev/null | cut -d"'" -f2); do
		file="$ARCPANEL/data/users/$user/docker/$app/.env"
		for name in $(secret_refs "$(head -c 65536 -- "$file" 2> /dev/null)"); do
			printf 'docker:%s\t%s\n' "$app" "$name"
		done
	done
}

# Print the apps of USER that reference NAME, one per line
secret_used_by() {
	secret_refs_of_user "$1" | awk -F'\t' -v n="$2" '$2 == n { print $1 }' | sort -u
}

# Number of apps in the output REFS of secret_refs_of_user that use NAME
secret_used_count() {
	printf '%s\n' "$1" | awk -F'\t' -v n="$2" '$2 == n { print $1 }' | sort -u | grep -c . || true
}

# Exit unless the value on stdin can go into the apps of USER that use NAME:
# one line for every app, and no quotes, $, ` or \ and at most 1024
# characters for a Docker app (its .env is written with single quotes)
secret_check_value_usable() {
	local user="$1" name="$2" used value
	used=$(secret_used_by "$user" "$name")
	value=$(
		cat
		printf x
	)
	value=${value%x}
	if [ -z "$used" ]; then
		return 0
	fi
	if [[ "$value" == *$'\n'* ]] || [[ "$value" == *$'\r'* ]]; then
		echo "secret $name is used by $(echo "$used" | tr '\n' ' ' | sed 's/ $//') and cannot have several lines"
		return "$E_INVALID"
	fi
	if grep -q '^docker:' <<< "$used"; then
		if [ "${#value}" -gt 1024 ] || [[ "$value" == *[\'\"\$\`\\]* ]]; then
			echo "secret $name is used by a Docker app and cannot hold quotes, \$, \` or \\ or more than 1024 characters"
			return "$E_INVALID"
		fi
	fi
	return 0
}

# After a change of USER's NAME: print the apps that use it and, when
# RESTART is yes, restart the ones that run. An app that does not restart
# is printed again as failed:APP; the change itself stands, so the command
# still succeeds and a caller does not store the value again.
secret_after_change() {
	local user="$1" name="$2" restart="$3" used app
	used=$(secret_used_by "$user" "$name")
	if [ -z "$used" ]; then
		return 0
	fi
	echo "$used"
	if [ "$restart" != 'yes' ]; then
		return 0
	fi
	while IFS= read -r app; do
		case "$app" in
			node:*)
				if [ "$($BIN/v-list-node-app "$user" "${app#node:}" json 2> /dev/null | jq -r '.[].STATUS' 2> /dev/null)" = 'online' ]; then
					$BIN/v-restart-node-app "$user" "${app#node:}" > /dev/null 2>&1 || echo "failed:$app"
				fi
				;;
			docker:*)
				if [ "$(grep "^NAME='${app#docker:}' " "$ARCPANEL/data/users/$user/dockerapps.conf" 2> /dev/null | grep -oE " STATE='[^']*'" | cut -d"'" -f2)" = 'running' ]; then
					$BIN/v-restart-docker-app "$user" "${app#docker:}" > /dev/null 2>&1 || echo "failed:$app"
				fi
				;;
		esac
	done <<< "$used"
	return 0
}

#----------------------------------------------------------#
#                        Backups                           #
#----------------------------------------------------------#

# Copy the store of USER into DEST of a backup: the encrypted files as they
# are, the fingerprint key encrypted with the master key (D10)
secret_backup_to() {
	local user="$1" dest="$2" dir
	dir=$(secret_dir "$user")
	mkdir -p "$dest"
	chmod 700 "$dest"
	cp -a "$dir/." "$dest/"
	rm -f "$dest/.lock" "$dest/fingerprint.key" "$dest"/*.tmp
	if [ -s "$dir/fingerprint.key" ] && [ -s "$SECRET_MASTER/recipient" ]; then
		age -e -R "$SECRET_MASTER/recipient" -o "$dest/fingerprint.key.age" "$dir/fingerprint.key"
	fi
}

# Restore the store of USER from SRC of a backup when this server's master
# key decrypts it; prints what happened
secret_restore_from() {
	local user="$1" src="$2" dir
	if ! command -v age > /dev/null 2>&1 || [ ! -f "$SECRET_MASTER/identity" ]; then
		echo "secrets skipped: the secret store is not set up"
		return 0
	fi
	if [ ! -s "$src/key.age" ] || ! age -d -i "$SECRET_MASTER/identity" "$src/key.age" > /dev/null 2>&1; then
		echo "secrets skipped: the backup was encrypted with another master key"
		return 0
	fi
	dir=$(secret_dir "$user")
	rm -rf "$dir"
	mkdir -p "$dir"
	cp -a "$src/." "$dir/"
	if [ -s "$dir/fingerprint.key.age" ]; then
		(umask 077 && age -d -i "$SECRET_MASTER/identity" -o "$dir/fingerprint.key" "$dir/fingerprint.key.age")
		rm -f "$dir/fingerprint.key.age"
	fi
	chown -R root:root "$dir"
	chmod -R go-rwx "$dir"
	chmod 700 "$dir"
	echo "secrets restored ($(secret_count "$user"))"
}

# Remove the store of USER (its key goes with it, so copies in backups can
# no longer be decrypted)
secret_user_remove() {
	if [ -n "$1" ] && [ -d "$(secret_dir "$1")" ]; then
		rm -rf "$(secret_dir "$1")"
	fi
}

#----------------------------------------------------------#
#                         Output                           #
#----------------------------------------------------------#

# JSON string of TEXT (names and descriptions hold no quotes or controls)
secret_json_string() {
	local text="$1"
	text=${text//\\/\\\\}
	text=${text//\"/\\\"}
	printf '"%s"' "$text"
}
