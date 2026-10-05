#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel Node.js functions: per-user runtimes and global packages (E13)   #
#                                                                           #
# Runtimes live in the user's nvm directory (~/.nvm/versions/node/vX.Y.Z),  #
# which is the only state: nothing about them is stored under data/users.   #
# Root downloads official release tarballs into a shared cache, checks the  #
# release signature and the checksum, and the user unpacks them.            #
#   $HESTIA/data/cache/node/                 nvm archive, vX.Y.Z/ tarballs, #
#                                            index.json, schedule.json,     #
#                                            release-keys.gpg               #
#   $HESTIA/data/users/<user>/node-task.*    background task of the panel   #
# See docs/features/specs/2026-10-05-006-e13-node-manager-design.md.        #
#                                                                           #
#===========================================================================#

NODE_CACHE="$HESTIA/data/cache/node"
NODE_KEYRING="$NODE_CACHE/release-keys.gpg"
NODE_KEYS_SOURCE="$HESTIA/install/common/node/release-keys.asc"

# nvm release installed for the shell (`nvm use`); the panel never runs nvm
NODE_NVM_VERSION="0.40.8"
NODE_NVM_SHA256="7c5b2c4c78e6447b518860ead1f365056e9a03b3562903439bb401607b05a2ef"

NODE_DEFAULT_RELEASES_URL="https://nodejs.org/dist"
NODE_DEFAULT_SCHEDULE_URL="https://raw.githubusercontent.com/nodejs/Release/main/schedule.json"
NODE_DEFAULT_NVM_URL="https://github.com/nvm-sh/nvm/archive/refs/tags"

# Oldest major listed by v-list-sys-node-releases
NODE_MIN_MAJOR=18

# Seconds a global package install or update may take
NODE_NPM_TIMEOUT=900

# Age in seconds of a usable `npm outdated` result
NODE_OUTDATED_TTL=21600

NODE_SHELL_BEGIN="# >>> arcpanel nvm >>>"
NODE_SHELL_END="# <<< arcpanel nvm <<<"

#----------------------------------------------------------#
#                     Server and cache                     #
#----------------------------------------------------------#

node_releases_url() {
	echo "${NODE_RELEASES_URL:-$NODE_DEFAULT_RELEASES_URL}"
}

node_schedule_url() {
	echo "${NODE_SCHEDULE_URL:-$NODE_DEFAULT_SCHEDULE_URL}"
}

node_nvm_url() {
	echo "${NODE_NVM_URL:-$NODE_DEFAULT_NVM_URL}"
}

# Architecture name of the Node.js release files
node_arch() {
	case "$(uname -m)" in
		x86_64 | amd64) echo "x64" ;;
		aarch64 | arm64) echo "arm64" ;;
		*) return 1 ;;
	esac
}

# Download URL to FILE (any scheme curl knows, file:// for mirrors and tests)
node_download() {
	local url="$1" file="$2"
	curl --silent --show-error --fail --location --retry 2 \
		--connect-timeout 15 --max-time 900 --output "$file" "$url"
}

# Host part of a URL, for error messages
node_url_host() {
	local rest="${1#*://}"
	echo "${rest%%/*}"
}

node_cache_prepare() {
	mkdir -p "$NODE_CACHE"
	chown root:root "$NODE_CACHE"
	chmod 755 "$NODE_CACHE"
}

# Run "$@" while holding the cache lock named NAME (one per version, so two
# users who install the same version download it once)
node_with_cache_lock() {
	local name="$1" fd rc
	shift
	node_cache_prepare
	exec {fd}> "$NODE_CACHE/.lock-$name"
	flock -x "$fd"
	"$@"
	rc=$?
	exec {fd}>&-
	return "$rc"
}

# Rebuild the keyring of the Node.js releasers from the shipped keys
node_keyring_build() {
	local home tmp
	if [ ! -s "$NODE_KEYS_SOURCE" ]; then
		return 1
	fi
	node_cache_prepare
	home=$(mktemp -d)
	tmp="$NODE_CACHE/.release-keys.$$"
	rm -f "$tmp"
	gpg --batch --quiet --homedir "$home" --no-default-keyring --keyring "$tmp" \
		--import "$NODE_KEYS_SOURCE" > /dev/null 2>&1
	local rc=$?
	rm -rf "$home" "$tmp~"
	if [ "$rc" -ne 0 ] || [ ! -s "$tmp" ]; then
		rm -f "$tmp"
		return 1
	fi
	chmod 644 "$tmp"
	mv -f "$tmp" "$NODE_KEYRING"
}

# Check a detached signature of the releasers: node_verify_signature SIG DATA
node_verify_signature() {
	if [ ! -s "$NODE_KEYRING" ]; then
		node_keyring_build || return 1
	fi
	gpgv --quiet --keyring "$NODE_KEYRING" "$1" "$2" > /dev/null 2>&1
}

# Path of the cached tarball of VERSION (X.Y.Z)
node_cache_tarball() {
	echo "$NODE_CACHE/v$1/node-v$1-linux-$(node_arch).tar.xz"
}

# Expected sha256 of FILE_NAME in a SHASUMS256.txt
node_expected_sum() {
	awk -v f="$2" '$2 == f { print $1; exit }' "$1"
}

# Download VERSION into the cache when missing and verify it. Prints
# progress. Returns E_CONNECT, E_INVALID or E_NOTEXIST on failure; nothing
# unverified is left in the cache.
node_cache_fetch_locked() {
	local version="$1" arch dir name url tmp expected actual
	arch=$(node_arch) || return "$E_NOTEXIST"
	dir="$NODE_CACHE/v$version"
	name="node-v$version-linux-$arch.tar.xz"
	url="$(node_releases_url)/v$version"

	if [ -s "$dir/$name" ] && [ -s "$dir/SHASUMS256.txt" ]; then
		expected=$(node_expected_sum "$dir/SHASUMS256.txt" "$name")
		actual=$(sha256sum "$dir/$name" | cut -d' ' -f1)
		if [ -n "$expected" ] && [ "$expected" = "$actual" ]; then
			echo "Using cached $name"
			return 0
		fi
		rm -rf "$dir"
	fi

	tmp=$(mktemp -d "$NODE_CACHE/.fetch.XXXXXX")
	echo "Downloading $url/$name"
	if ! node_download "$url/SHASUMS256.txt" "$tmp/SHASUMS256.txt" \
		|| ! node_download "$url/SHASUMS256.txt.sig" "$tmp/SHASUMS256.txt.sig" \
		|| ! node_download "$url/$name" "$tmp/$name"; then
		rm -rf "$tmp"
		NODE_ERROR="Could not download from $(node_url_host "$url")"
		return "$E_CONNECT"
	fi
	echo "Verifying the release signature"
	if ! node_verify_signature "$tmp/SHASUMS256.txt.sig" "$tmp/SHASUMS256.txt"; then
		rm -rf "$tmp"
		NODE_ERROR="The release signature of v$version is not valid"
		return "$E_INVALID"
	fi
	expected=$(node_expected_sum "$tmp/SHASUMS256.txt" "$name")
	actual=$(sha256sum "$tmp/$name" | cut -d' ' -f1)
	if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
		rm -rf "$tmp"
		NODE_ERROR="The checksum of $name does not match"
		return "$E_INVALID"
	fi
	chmod 755 "$tmp"
	chmod 644 "$tmp"/*
	rm -rf "$dir"
	mv "$tmp" "$dir"
}

node_cache_fetch() {
	node_with_cache_lock "v$1" node_cache_fetch_locked "$1"
}

# Download the pinned nvm archive into the cache when missing
node_nvm_fetch_locked() {
	local file="$NODE_CACHE/nvm-$NODE_NVM_VERSION.tar.gz" tmp
	if [ -s "$file" ] && [ "$(sha256sum "$file" | cut -d' ' -f1)" = "$NODE_NVM_SHA256" ]; then
		return 0
	fi
	tmp="$file.$$"
	if ! node_download "$(node_nvm_url)/v$NODE_NVM_VERSION.tar.gz" "$tmp"; then
		rm -f "$tmp"
		NODE_ERROR="Could not download from $(node_url_host "$(node_nvm_url)")"
		return "$E_CONNECT"
	fi
	if [ "$(sha256sum "$tmp" | cut -d' ' -f1)" != "$NODE_NVM_SHA256" ]; then
		rm -f "$tmp"
		NODE_ERROR="The checksum of nvm $NODE_NVM_VERSION does not match"
		return "$E_INVALID"
	fi
	chmod 644 "$tmp"
	mv -f "$tmp" "$file"
}

node_nvm_fetch() {
	node_with_cache_lock nvm node_nvm_fetch_locked
}

# Refresh index.json and schedule.json; keeps the old files on failure
node_releases_refresh() {
	local tmp rc=0
	node_cache_prepare
	tmp=$(mktemp "$NODE_CACHE/.index.XXXXXX")
	if node_download "$(node_releases_url)/index.json" "$tmp" && jq -e 'type == "array"' "$tmp" > /dev/null 2>&1; then
		chmod 644 "$tmp"
		mv -f "$tmp" "$NODE_CACHE/index.json"
	else
		rm -f "$tmp"
		rc="$E_CONNECT"
	fi
	tmp=$(mktemp "$NODE_CACHE/.schedule.XXXXXX")
	if node_download "$(node_schedule_url)" "$tmp" && jq -e 'type == "object"' "$tmp" > /dev/null 2>&1; then
		chmod 644 "$tmp"
		mv -f "$tmp" "$NODE_CACHE/schedule.json"
	else
		rm -f "$tmp"
	fi
	return "$rc"
}

# index.json, downloaded on first use
node_index_ensure() {
	if [ ! -s "$NODE_CACHE/index.json" ]; then
		node_releases_refresh > /dev/null 2>&1
	fi
	[ -s "$NODE_CACHE/index.json" ]
}

# Versions (X.Y.Z) present in the cache, one per line
node_cached_versions() {
	local dir arch
	arch=$(node_arch) || return 0
	for dir in "$NODE_CACHE"/v*/; do
		[ -d "$dir" ] || continue
		dir=${dir%/}
		dir=${dir##*/v}
		if [ -s "$(node_cache_tarball "$dir")" ]; then
			echo "$dir"
		fi
	done
}

# One JSON array of the majors >= NODE_MIN_MAJOR built for this server's
# architecture: MAJOR, LATEST, LTS (codename), STATUS, EOL, CACHED
node_releases_json() {
	local arch cached schedule="$NODE_CACHE/schedule.json"
	arch=$(node_arch) || {
		echo '[]'
		return
	}
	[ -s "$schedule" ] || schedule=/dev/null
	cached=$(node_cached_versions | jq -R . | jq -sc .)
	jq -c --arg arch "linux-$arch" --arg today "$(date +%F)" \
		--argjson min "$NODE_MIN_MAJOR" --argjson cached "$cached" \
		--slurpfile sched "$schedule" '
		($sched[0] // {}) as $s
		| [ .[]
			| select((.files // []) | index($arch))
			| . + { major: (.version | ltrimstr("v") | split(".")[0] | tonumber) }
			| select(.major >= $min) ]
		| group_by(.major)
		| map(max_by(.version | ltrimstr("v") | split(".") | map(tonumber)))
		| map(
			($s["v" + (.major | tostring)]) as $e
			| (.version | ltrimstr("v")) as $v
			| {
				MAJOR: (.major | tostring),
				LATEST: $v,
				LTS: (if .lts then .lts else ($e.codename // "") end),
				STATUS: (
					if $e == null then (if .lts then "active" else "current" end)
					elif $today >= $e.end then "eol"
					elif ($e.maintenance // "9999") <= $today then "maintenance"
					elif ($e.lts // "9999") <= $today then "active"
					else "current" end),
				EOL: ($e.end // ""),
				CACHED: (if ($cached | index($v)) then "yes" else "no" end)
			})
		| sort_by(.MAJOR | tonumber) | reverse
	' "$NODE_CACHE/index.json"
}

# Field FIELD of major MAJOR in node_releases_json
node_major_field() {
	node_releases_json | jq -r --arg m "$1" --arg f "$2" '.[] | select(.MAJOR == $m) | .[$f]'
}

# Resolve a version spec (lts, a major, X.Y.Z or vX.Y.Z) to X.Y.Z through
# index.json. Returns E_INVALID for a malformed spec, E_NOTEXIST when no
# release for this architecture matches.
node_resolve() {
	local spec="${1#v}" arch result
	arch=$(node_arch) || return "$E_NOTEXIST"
	if [[ ! "$spec" =~ ^(lts|[0-9]{1,3}|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})$ ]]; then
		return "$E_INVALID"
	fi
	node_index_ensure || return "$E_CONNECT"
	result=$(jq -r --arg spec "$spec" --arg arch "linux-$arch" '
		[ .[] | select((.files // []) | index($arch)) | . + { v: (.version | ltrimstr("v")) } ]
		| map(select(
			if $spec == "lts" then .lts != false
			elif ($spec | test("^[0-9]+$")) then (.v | split(".")[0]) == $spec
			else .v == $spec end))
		| max_by(.v | split(".") | map(tonumber)) // empty
		| .v
	' "$NODE_CACHE/index.json")
	if [ -z "$result" ]; then
		return "$E_NOTEXIST"
	fi
	echo "$result"
}

#----------------------------------------------------------#
#                      Package keys                        #
#----------------------------------------------------------#

node_package_of() {
	tenancy_user_value "$1" PACKAGE
}

node_pkg_key() {
	tenancy_pkg_get "$(node_package_of "$1")" "$2"
}

# Whether the package of USER allows major MAJOR
node_allowed() {
	local user="$1" major="$2" allowed status item
	allowed=$(node_pkg_key "$user" NODE_ALLOWED)
	case "$allowed" in
		'*') return 0 ;;
		lts)
			status=$(node_major_field "$major" STATUS)
			[ "$status" = 'active' ] || [ "$status" = 'maintenance' ]
			return
			;;
	esac
	for item in ${allowed//,/ }; do
		if [ "$item" = "$major" ]; then
			return 0
		fi
	done
	return 1
}

# Exit with E_DISABLED unless the package of USER has NODE='yes'
node_check_enabled_package() {
	if [ "$(node_pkg_key "$1" NODE)" != 'yes' ]; then
		check_result "$E_DISABLED" "Node.js is not included in the package of $1"
	fi
}

#----------------------------------------------------------#
#                    The user's runtimes                   #
#----------------------------------------------------------#

node_home() {
	echo "$HOMEDIR/$1"
}

node_nvm_dir() {
	echo "$HOMEDIR/$1/.nvm"
}

node_version_dir() {
	echo "$HOMEDIR/$1/.nvm/versions/node/v$2"
}

# Exit with E_FORBIDEN when PATH resolves outside the home directory of USER
node_check_path() {
	local user="$1" path="$2"
	if ! readlink -m "$path" | grep -q "^$HOMEDIR/$user/"; then
		check_result "$E_FORBIDEN" "Path outside of the home directory of $user ($path)"
	fi
}

# Run a command as USER with a clean environment in the home directory.
# NODE_RUN_VERSION (X.Y.Z) puts that runtime first in PATH.
node_as_user() {
	local u="$1" home path
	shift
	home=$(node_home "$u")
	path="/usr/local/bin:/usr/bin:/bin"
	if [ -n "$NODE_RUN_VERSION" ]; then
		path="$(node_version_dir "$u" "$NODE_RUN_VERSION")/bin:$path"
	fi
	user="$u" user_exec env -i -C "$home" HOME="$home" USER="$u" LOGNAME="$u" \
		PATH="$path" NVM_DIR="$home/.nvm" LANG=C.UTF-8 TERM=dumb \
		NO_UPDATE_NOTIFIER=1 NPM_CONFIG_UPDATE_NOTIFIER=false "$@"
}

# Same, niced and limited to NODE_NPM_TIMEOUT seconds, umask 022
node_as_user_long() {
	local u="$1"
	shift
	node_as_user "$u" timeout --kill-after=15 "$NODE_NPM_TIMEOUT" \
		nice -n 10 ionice -c3 bash -c 'umask 022; exec "$@"' node-task "$@"
}

node_nvm_enabled() {
	[ -s "$(node_nvm_dir "$1")/nvm.sh" ]
}

# Installed versions (X.Y.Z) of USER, oldest first
node_versions() {
	local dir
	dir="$(node_nvm_dir "$1")/versions/node"
	if [ ! -d "$dir" ]; then
		return 0
	fi
	node_as_user "$1" find "$dir" -mindepth 1 -maxdepth 1 -type d -name 'v[0-9]*' -printf '%f\n' 2> /dev/null \
		| sed 's/^v//' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -t. -k1,1n -k2,2n -k3,3n
}

node_version_installed() {
	node_versions "$1" | grep -qx "$2"
}

# Default version of USER (X.Y.Z), empty when none. An alias set in the shell
# with nvm ("22", "lts/*") resolves to the newest installed match.
node_default() {
	local user="$1" alias_file value
	alias_file="$(node_nvm_dir "$user")/alias/default"
	if [ ! -f "$alias_file" ]; then
		return 0
	fi
	value=$(node_as_user "$user" head -c 64 "$alias_file" 2> /dev/null | tr -d '[:space:]')
	value=${value#v}
	if [ -z "$value" ]; then
		return 0
	fi
	if [[ "$value" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
		node_version_installed "$user" "$value" && echo "$value"
		return 0
	fi
	if [[ "$value" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
		node_versions "$user" | grep -E "^${value//./\\.}\." | tail -n1
		return 0
	fi
	# lts/*, node, stable: the newest installed
	node_versions "$user" | tail -n1
}

# Point the default alias of USER at VERSION (empty: remove it)
node_set_default() {
	local user="$1" version="$2" dir
	dir="$(node_nvm_dir "$user")/alias"
	if [ -z "$version" ]; then
		node_as_user "$user" rm -f "$dir/default"
		return
	fi
	node_as_user "$user" mkdir -p "$dir"
	printf 'v%s\n' "$version" | node_as_user "$user" bash -c 'cat > "$1"' node "$dir/default"
}

# Add the guarded nvm block to ~/.bashrc of USER (replaces an older one)
node_shell_block_add() {
	local user="$1" rc
	rc="$(node_home "$user")/.bashrc"
	node_shell_block_remove "$user"
	printf '%s\n%s\n%s\n%s\n' "$NODE_SHELL_BEGIN" \
		'export NVM_DIR="$HOME/.nvm"' \
		'[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"' \
		"$NODE_SHELL_END" \
		| node_as_user "$user" bash -c 'cat >> "$1"' node "$rc"
}

node_shell_block_remove() {
	local user="$1" rc
	rc="$(node_home "$user")/.bashrc"
	if [ -f "$rc" ] && [ ! -L "$rc" ]; then
		node_as_user "$user" sed -i "/^$NODE_SHELL_BEGIN\$/,/^$NODE_SHELL_END\$/d" "$rc"
	fi
}

node_shell_block_present() {
	local rc
	rc="$(node_home "$1")/.bashrc"
	[ -f "$rc" ] && grep -qx "$NODE_SHELL_BEGIN" "$rc"
}

#----------------------------------------------------------#
#                     Global packages                      #
#----------------------------------------------------------#

# npm package name, optionally scoped
node_pkg_name_valid() {
	[ "${#1}" -le 214 ] && [[ "$1" =~ ^(@[a-z0-9][a-z0-9._~-]*/)?[a-z0-9][a-z0-9._~-]*$ ]]
}

# NAME or NAME@VERSION (version, range or dist-tag). URLs, git, file and
# path specs are refused.
node_pkg_spec_valid() {
	local spec="$1" name rest
	name=$(node_pkg_spec_name "$spec")
	node_pkg_name_valid "$name" || return 1
	rest=${spec#"$name"}
	if [ -z "$rest" ]; then
		return 0
	fi
	[[ "$rest" =~ ^@[A-Za-z0-9.^~\<\>=*+-]{1,64}$ ]]
}

# Package name of a spec: everything before the version's "@" (a scope's
# leading "@" belongs to the name)
node_pkg_spec_name() {
	local spec="$1"
	if [[ "$spec" == @* ]]; then
		spec=${spec#@}
		echo "@${spec%%@*}"
	else
		echo "${spec%%@*}"
	fi
}

# Packages bundled with Node.js that cannot be removed
node_pkg_bundled() {
	[ "$1" = 'npm' ] || [ "$1" = 'corepack' ]
}

# Global packages of USER under VERSION as JSON lines {name, version}
node_packages_lines() {
	local user="$1" version="$2" dir
	dir="$(node_version_dir "$user" "$version")/lib/node_modules"
	if [ ! -d "$dir" ]; then
		return 0
	fi
	node_as_user "$user" bash -c '
		cd "$1" || exit 0
		for f in */package.json @*/*/package.json; do
			[ -f "$f" ] || continue
			jq -c "{name: (.name // \"\"), version: (.version // \"\")}" "$f" 2> /dev/null
		done
	' node "$dir"
}

node_outdated_file() {
	echo "$HESTIA/data/users/$1/node-outdated-$2.json"
}

# The `npm outdated` result of VERSION when younger than NODE_OUTDATED_TTL
node_outdated_fresh() {
	local file age
	file=$(node_outdated_file "$1" "$2")
	if [ ! -s "$file" ]; then
		return 1
	fi
	age=$(($(date +%s) - $(stat -c %Y "$file")))
	[ "$age" -lt "$NODE_OUTDATED_TTL" ]
}

node_outdated_clear() {
	rm -f "$(node_outdated_file "$1" "$2")"
}

#----------------------------------------------------------#
#                      Panel tasks                         #
#----------------------------------------------------------#

node_task_conf() {
	echo "$HESTIA/data/users/$1/node-task.conf"
}

node_task_log() {
	echo "$HESTIA/data/users/$1/node-task.log"
}

# Value of KEY in the task record of USER
node_task_get() {
	local file
	file=$(node_task_conf "$1")
	if [ -f "$file" ]; then
		grep -oE "(^| )$2='[^']*'" "$file" | head -n1 | cut -d\' -f2
	fi
}

NODE_TASK_KEYS="ID ACTION ARGS STATUS PID EXIT START END"

# Set KEY=VALUE pairs in the task record of USER; the other keys keep their
# values. Quotes and line breaks are dropped from values.
node_task_set() {
	local user="$1" file pair key line='' tmp
	local -A values=()
	shift
	for key in $NODE_TASK_KEYS; do
		values[$key]=$(node_task_get "$user" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $NODE_TASK_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	file=$(node_task_conf "$user")
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
}

# Whether the task process PID is alive and still the task runner
node_task_alive() {
	local pid="$1"
	[[ "$pid" =~ ^[0-9]+$ ]] && [ -r "/proc/$pid/cmdline" ] \
		&& tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'v-run-user-node-task'
}

# Mark a running task whose process is gone as failed
node_task_reap() {
	local user="$1"
	if [ "$(node_task_get "$user" STATUS)" = 'running' ] && ! node_task_alive "$(node_task_get "$user" PID)"; then
		node_task_set "$user" STATUS=failed "END=$(date +'%F %T')"
	fi
}

node_task_running() {
	node_task_reap "$1"
	[ "$(node_task_get "$1" STATUS)" = 'running' ]
}

#----------------------------------------------------------#
#                         Output                           #
#----------------------------------------------------------#

# Print a JSON document in FORMAT. ROWS is a jq path to the array of rows
# (objects with string values) and COLUMNS the keys shown by shell/plain/csv.
node_print() {
	local format="$1" json="$2" rows="$3" columns="$4" filter
	filter="[$(printf '.%s,' $columns | sed 's/,$//')]"
	case "$format" in
		json) printf '%s\n' "$json" | jq . ;;
		plain) printf '%s\n' "$json" | jq -r "${rows}[] | $filter | @tsv" ;;
		csv)
			echo "${columns// /,}"
			printf '%s\n' "$json" | jq -r "${rows}[] | $filter | @csv"
			;;
		*)
			{
				echo "$columns"
				echo "$columns" | sed 's/[^ ]/-/g'
				printf '%s\n' "$json" | jq -r "${rows}[] | $filter | map(if . == \"\" then \"-\" else . end) | join(\" \")"
			} | column -t
			;;
	esac
}
