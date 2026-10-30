#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel Git Deploy functions (E20)                                       #
#                                                                           #
# Every web domain can deploy atomic releases in two modes: pull (the       #
# server fetches and builds) and push (a CI uploads a built archive).       #
#   data/users/<user>/deploy/<domain>.conf        configuration (DEPLOY_KEYS)#
#   data/users/<user>/deploy/<domain>.publishers  trusted publishers (push) #
#   data/deploy/uploads/<task>.tar.gz             archives waiting to run   #
#   ~/web/<domain>/releases/<YYYYMMDD-HHMMSS>/    one release each          #
#   ~/web/<domain>/shared/                        linked into every release #
#   ~/web/<domain>/current -> releases/<id>       the live release          #
# Each deploy is a task record (func/queuetask.sh, KIND deploy) run at once #
# by a transient unit in the user's slice; one runs per domain at a time,   #
# later requests collapse into the newest (PENDING).                        #
# Callers source func/main.sh, func/domain.sh, func/tenancy.sh and          #
# func/queuetask.sh first.                                                  #
# See docs/features/specs/2026-10-29-001-e20-git-deploy-design.md.          #
#                                                                           #
#===========================================================================#

DEPLOY_KEYS="ID MODE URL BRANCH AUTH PRESET SHARED KEEP WEBROOT MIGRATE HEALTH_PATH PREV_DOCROOT PREV_PHPROOT STATE CURRENT RUNNING PENDING PUBLIC_KEY"
DEPLOY_CHANGE_KEYS="BRANCH PRESET SHARED KEEP WEBROOT MIGRATE HEALTH_PATH URL"
DEPLOY_PRESETS="none laravel static custom"
DEPLOY_ISSUERS_DEFAULT="https://token.actions.githubusercontent.com,https://gitlab.com"
DEPLOY_UPLOADS="$ARCPANEL/data/deploy/uploads"
DEPLOY_PORT_CONF="/etc/nginx/conf.d/arcpanel-deploy-port.conf"

#----------------------------------------------------------#
#                     Server settings                      #
#----------------------------------------------------------#

deploy_port() {
	echo "${DEPLOY_PORT:-8088}"
}

deploy_build_timeout() {
	local value="${DEPLOY_BUILD_TIMEOUT:-900}"
	[[ "$value" =~ ^[0-9]{1,5}$ ]] || value=900
	echo "$value"
}

deploy_issuers() {
	echo "${DEPLOY_OIDC_ISSUERS:-$DEPLOY_ISSUERS_DEFAULT}"
}

# Size like 512M or 1G in bytes (empty when invalid)
deploy_bytes() {
	if [[ "$1" =~ ^([0-9]{1,6})([KMG]?)$ ]]; then
		case "${BASH_REMATCH[2]}" in
			K) echo $((BASH_REMATCH[1] * 1024)) ;;
			M) echo $((BASH_REMATCH[1] * 1024 * 1024)) ;;
			G) echo $((BASH_REMATCH[1] * 1024 * 1024 * 1024)) ;;
			*) echo "${BASH_REMATCH[1]}" ;;
		esac
	fi
}

deploy_max_artifact() {
	local bytes
	bytes=$(deploy_bytes "${DEPLOY_MAX_ARTIFACT:-512M}")
	echo "${bytes:-536870912}"
}

# The panel's host name in the endpoint URLs (as App\Support\PanelHost::link)
deploy_host() {
	hostname
}

deploy_endpoint_url() {
	echo "https://$(deploy_host):$(deploy_port)/$1/$2"
}

#----------------------------------------------------------#
#                     Configuration                        #
#----------------------------------------------------------#

deploy_dir() {
	echo "$ARCPANEL/data/users/$1/deploy"
}

deploy_conf() {
	echo "$(deploy_dir "$1")/$2.conf"
}

deploy_publishers_file() {
	echo "$(deploy_dir "$1")/$2.publishers"
}

deploy_exists() {
	[ -f "$(deploy_conf "$1" "$2")" ]
}

# Value of KEY in the configuration of USER DOMAIN
deploy_get() {
	local file
	file=$(deploy_conf "$1" "$2")
	if [ -f "$file" ]; then
		grep -oE "(^| )$3='[^']*'" "$file" | head -n1 | cut -d\' -f2
	fi
}

# Set KEY=VALUE pairs; the other keys keep their values. Quotes and line
# breaks are dropped. Callers hold deploy_lock when they read and write.
deploy_set() {
	local owner="$1" name="$2" file pair key line='' tmp dir
	local -A values=()
	shift 2
	for key in $DEPLOY_KEYS; do
		values[$key]=$(deploy_get "$owner" "$name" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $DEPLOY_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	dir=$(deploy_dir "$owner")
	mkdir -p "$dir"
	chmod 750 "$dir"
	file=$(deploy_conf "$owner" "$name")
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
}

# The domain's state lock: held briefly around reads and writes of the
# configuration and when a deploy is handed on. Do not nest.
deploy_lock() {
	local dir
	dir=$(deploy_dir "$1")
	mkdir -p "$dir"
	chmod 750 "$dir"
	exec {DEPLOY_LOCK_FD}> "$dir/$2.lock"
	flock -x "$DEPLOY_LOCK_FD"
}

deploy_unlock() {
	if [ -n "${DEPLOY_LOCK_FD:-}" ]; then
		flock -u "$DEPLOY_LOCK_FD"
		exec {DEPLOY_LOCK_FD}>&-
		DEPLOY_LOCK_FD=
	fi
}

# 12 random characters [a-z0-9] not used by another domain
deploy_new_id() {
	local id
	while :; do
		id=$(tr -dc 'a-z0-9' < /dev/urandom | head -c 12)
		[ "${#id}" -eq 12 ] || continue
		[ -z "$(deploy_find_id "$id")" ] && break
	done
	echo "$id"
}

# "USER DOMAIN" of the deploy whose ID is ID (empty when none)
deploy_find_id() {
	local file user domain
	[[ "$1" =~ ^[a-z0-9]{12}$ ]] || return 0
	shopt -s nullglob
	for file in "$ARCPANEL"/data/users/*/deploy/*.conf; do
		if grep -q "^ID='$1' " "$file"; then
			user=${file#"$ARCPANEL/data/users/"}
			user=${user%%/*}
			domain=$(basename "$file" .conf)
			echo "$user $domain"
			break
		fi
	done
	shopt -u nullglob
}

#----------------------------------------------------------#
#                       Validation                         #
#----------------------------------------------------------#

deploy_valid_mode() {
	[ "$1" = 'pull' ] || [ "$1" = 'push' ]
}

deploy_valid_preset() {
	[[ " $DEPLOY_PRESETS " == *" $1 "* ]]
}

# git@host:path, ssh://…, https://…; never file:// or a local path
deploy_valid_url() {
	local url="$1"
	[ "${#url}" -le 500 ] || return 1
	[[ "$url" =~ ^[A-Za-z0-9._~:/@%+=-]+$ ]] || return 1
	[[ "$url" =~ ^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/%+-]+$ ]] && [[ "$url" != *..* ]] && return 0
	[[ "$url" =~ ^ssh://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._~/%+-]+$ ]] && return 0
	[[ "$url" =~ ^https://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._~/%+-]+$ ]] && return 0
	return 1
}

# A branch or ref name as git accepts it
deploy_valid_ref() {
	local ref="$1"
	[ -n "$ref" ] && [ "${#ref}" -le 200 ] || return 1
	[[ "$ref" =~ ^[A-Za-z0-9._/@+-]+$ ]] || return 1
	[[ "$ref" != -* ]] || return 1
	if command -v git > /dev/null 2>&1; then
		git check-ref-format --branch "$ref" > /dev/null 2>&1
	else
		[[ "$ref" != *..* && "$ref" != */ && "$ref" != *.lock && "$ref" != /* && "$ref" != *//* ]]
	fi
}

# A relative path inside the release: no "..", no leading "/"
deploy_valid_relpath() {
	local path="$1"
	[ -n "$path" ] && [ "${#path}" -le 200 ] || return 1
	[[ "$path" =~ ^[A-Za-z0-9._][A-Za-z0-9._/-]*$ ]] || return 1
	[[ "/$path/" != */../* && "/$path/" != */./* ]]
}

deploy_valid_shared() {
	local entry count=0
	for entry in $1; do
		count=$((count + 1))
		[ "$count" -le 20 ] || return 1
		[ "$entry" != '/' ] || return 1
		deploy_valid_relpath "${entry%/}" || return 1
	done
	return 0
}

deploy_valid_webroot() {
	[ "$1" = '.' ] || deploy_valid_relpath "${1%/}"
}

deploy_valid_keep() {
	[[ "$1" =~ ^[0-9]{1,2}$ ]] && [ "$((10#$1))" -ge 1 ] && [ "$((10#$1))" -le 20 ]
}

deploy_valid_health() {
	[ -z "$1" ] || { [[ "$1" =~ ^/[A-Za-z0-9._~/?=\&%+-]*$ ]] && [ "${#1}" -le 200 ]; }
}

deploy_valid_release() {
	[[ "$1" =~ ^[0-9]{8}-[0-9]{6}$ ]]
}

# Validate VALUE of KEY (DEPLOY_CHANGE_KEYS); exits with E_INVALID
deploy_check_value() {
	local key="$1" value="$2" mode="$3"
	case "$key" in
		BRANCH) [ "$mode" = 'push' ] && [ -z "$value" ] || deploy_valid_ref "$value" ;;
		URL) [ "$mode" = 'push' ] && [ -z "$value" ] || deploy_valid_url "$value" ;;
		PRESET) deploy_valid_preset "$value" ;;
		SHARED) deploy_valid_shared "$value" ;;
		KEEP) deploy_valid_keep "$value" ;;
		WEBROOT) deploy_valid_webroot "$value" ;;
		MIGRATE) [ "$value" = 'yes' ] || [ "$value" = 'no' ] ;;
		HEALTH_PATH) deploy_valid_health "$value" ;;
		*) false ;;
	esac || check_result "$E_INVALID" "invalid $key :: $value"
}

# Defaults of a preset: SHARED, WEBROOT and MIGRATE, one per line
deploy_preset_defaults() {
	case "$1" in
		laravel) printf '%s\n' 'storage/ .env' 'public' 'yes' ;;
		static) printf '%s\n' '' 'dist' 'no' ;;
		*) printf '%s\n' '' '.' 'no' ;;
	esac
}

#----------------------------------------------------------#
#                     Support checks                       #
#----------------------------------------------------------#

deploy_package_allows() {
	local package
	package=$(tenancy_user_value "$1" PACKAGE)
	[ "$(tenancy_pkg_get "$package" GIT_DEPLOY)" != 'no' ]
}

# Whether the pool template of the domain's backend lets PHP read the
# releases and shared folders (D17): no open_basedir line, or one with both
deploy_template_ok() {
	local backend="${1:-default}" tpl line
	tpl="$ARCPANEL/data/templates/web/php-fpm/$backend.tpl"
	[ -f "$tpl" ] || return 1
	line=$(grep -E '^[[:space:]]*php_admin_value\[open_basedir\]' "$tpl" | tail -n1)
	[ -z "$line" ] && return 0
	[[ "$line" == *"/web/%domain%/releases"* && "$line" == *"/web/%domain%/shared"* ]]
}

# Why USER DOMAIN cannot use deploy (package, backend, template), empty when
# it can. The caller has the domain's values (get_domain_values web).
deploy_unsupported_reason() {
	if ! deploy_package_allows "$1"; then
		echo package
	elif [ -z "$WEB_BACKEND" ] || [ "$WEB_BACKEND" = 'no' ]; then
		echo backend
	elif ! deploy_template_ok "$BACKEND"; then
		echo template
	fi
}

#----------------------------------------------------------#
#                     Home layout                          #
#----------------------------------------------------------#

deploy_web() {
	echo "$HOMEDIR/$1/web/$2"
}

deploy_docroot_for() {
	local webroot="${3:-.}"
	webroot=${webroot%/}
	if [ "$webroot" = '.' ]; then
		echo "$(deploy_web "$1" "$2")/current"
	else
		echo "$(deploy_web "$1" "$2")/current/$webroot"
	fi
}

# Run a command as USER with a clean environment, in the home directory
deploy_as_user() {
	local u="$1" home
	shift
	home="$HOMEDIR/$u"
	user="$u" user_exec env -i -C "$home" HOME="$home" USER="$u" LOGNAME="$u" \
		PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8 TERM=dumb "$@"
}

# Releases of USER DOMAIN, oldest first
deploy_releases() {
	local dir name
	dir="$(deploy_web "$1" "$2")/releases"
	[ -d "$dir" ] && [ ! -L "$dir" ] || return 0
	for name in "$dir"/*; do
		name=${name##*/}
		deploy_valid_release "$name" && [ -d "$dir/$name" ] && [ ! -L "$dir/$name" ] && echo "$name"
	done | sort
}

# Value of KEY in the .arcpanel-release file of a release (read, never sourced)
deploy_release_value() {
	local file
	file="$(deploy_web "$1" "$2")/releases/$3/.arcpanel-release"
	[ -f "$file" ] && [ ! -L "$file" ] || return 0
	head -c 4096 "$file" | grep -oE "^$4='[^']*'" | head -n1 | cut -d\' -f2
}

# Set CUSTOM_DOCROOT and CUSTOM_PHPROOT of the domain to PATH (empty for the
# default) and rebuild it (D16: the unresolved path, so the link is followed)
deploy_set_docroot() {
	local u="$1" d="$2" path="$3"
	(
		user=$u
		domain=$d
		USER_DATA="$ARCPANEL/data/users/$user"
		add_object_key 'web' 'DOMAIN' "$domain" 'CUSTOM_DOCROOT' 'IP6'
		add_object_key 'web' 'DOMAIN' "$domain" 'CUSTOM_PHPROOT' 'IP6'
		update_object_value 'web' 'DOMAIN' "$domain" '$CUSTOM_DOCROOT' "$path"
		update_object_value 'web' 'DOMAIN' "$domain" '$CUSTOM_PHPROOT' "${4-$path}"
	)
	"$BIN/v-rebuild-web-domain" "$u" "$d" yes > /dev/null 2>&1
}

#----------------------------------------------------------#
#                     Runner                               #
#----------------------------------------------------------#

deploy_unit() {
	echo "arcpanel-deploy-$1-$2"
}

deploy_has_systemd() {
	[ -d /run/systemd/system ] && command -v systemd-run > /dev/null 2>&1
}

# Whether the deploy task ID of USER is still alive (queued or running and
# its unit or process exists)
deploy_task_alive() {
	local owner="$1" task="$2" status pid
	[ -n "$task" ] || return 1
	status=$(qt_get "$owner" "$task" STATUS)
	[ "$status" = 'queued' ] || [ "$status" = 'running' ] || return 1
	if deploy_has_systemd; then
		systemctl is-active --quiet "$(deploy_unit "$owner" "$task").service" && return 0
	fi
	pid=$(qt_get "$owner" "$task" PID)
	[[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2> /dev/null
}

# Start the runner of task ID for USER DOMAIN now
deploy_start() {
	local owner="$1" name="$2" task="$3" uid limit
	uid=$(id -u "$owner")
	limit=$(($(deploy_build_timeout) + 600))
	if deploy_has_systemd; then
		systemd-run --quiet --collect --unit="$(deploy_unit "$owner" "$task")" \
			--slice="user-$uid.slice" -p Nice=10 -p IOSchedulingClass=idle \
			-p RuntimeMaxSec="$limit" -E LANG=C.UTF-8 \
			"$BIN/v-run-queue-task" "$owner" "$task" -- \
			"$BIN/v-run-web-domain-deploy" "$owner" "$name" "$task" > /dev/null 2>&1
	else
		setsid nohup nice -n 10 ionice -c 3 "$BIN/v-run-queue-task" "$owner" "$task" -- \
			"$BIN/v-run-web-domain-deploy" "$owner" "$name" "$task" > /dev/null 2>&1 < /dev/null &
	fi
}

# Hand the domain on to the request that waited, or mark it idle. Caller
# holds deploy_lock.
deploy_handover_unlocked() {
	local owner="$1" name="$2" pending
	pending=$(deploy_get "$owner" "$name" PENDING)
	if [ -n "$pending" ] && [ "$(qt_get "$owner" "$pending" STATUS)" = 'queued' ]; then
		deploy_set "$owner" "$name" "RUNNING=$pending" PENDING=
		deploy_start "$owner" "$name" "$pending"
	else
		deploy_set "$owner" "$name" RUNNING= PENDING=
	fi
}

# Create a deploy task (ARGS "DOMAIN ACTION [VALUE]") and run it now, or
# keep it as the pending request when a deploy runs (the newest wins).
# Prints the task ID.
deploy_schedule() {
	local owner="$1" name="$2" args="$3" artifact="$4" running pending
	user=$owner
	qt_lock
	qt_create_unlocked deploy "$args"
	qt_cleanup_unlocked
	qt_unlock
	if [ -n "$artifact" ]; then
		mkdir -p "$DEPLOY_UPLOADS"
		chmod 700 "$ARCPANEL/data/deploy" "$DEPLOY_UPLOADS"
		install -m 600 -o root -g root "$artifact" "$DEPLOY_UPLOADS/$QT_ID.tar.gz"
	fi
	deploy_lock "$owner" "$name"
	running=$(deploy_get "$owner" "$name" RUNNING)
	if [ -n "$running" ] && ! deploy_task_alive "$owner" "$running"; then
		if [ "$(qt_get "$owner" "$running" STATUS)" = 'queued' ] || [ "$(qt_get "$owner" "$running" STATUS)" = 'running' ]; then
			qt_lock
			qt_set "$owner" "$running" STATUS=failed "ERROR=the deploy runner stopped" "END=$(date +'%F %T')" PID=
			qt_unlock
		fi
		running=
	fi
	if [ -n "$running" ]; then
		pending=$(deploy_get "$owner" "$name" PENDING)
		if [ -n "$pending" ] && [ "$pending" != "$QT_ID" ]; then
			qt_lock
			if [ "$(qt_get "$owner" "$pending" STATUS)" = 'queued' ]; then
				qt_set "$owner" "$pending" STATUS=failed "ERROR=replaced by a newer request" "END=$(date +'%F %T')"
			fi
			qt_unlock
			rm -f "$DEPLOY_UPLOADS/$pending.tar.gz"
		fi
		deploy_set "$owner" "$name" "PENDING=$QT_ID"
	else
		deploy_set "$owner" "$name" "RUNNING=$QT_ID"
		deploy_start "$owner" "$name" "$QT_ID"
	fi
	deploy_unlock
	echo "$QT_ID"
}

# Stop the running and pending deploys of USER DOMAIN (turning deploy off)
deploy_stop() {
	local owner="$1" name="$2" task
	for task in $(deploy_get "$owner" "$name" RUNNING) $(deploy_get "$owner" "$name" PENDING); do
		if deploy_has_systemd; then
			systemctl stop "$(deploy_unit "$owner" "$task").service" > /dev/null 2>&1
		fi
		user=$owner
		qt_lock
		case "$(qt_get "$owner" "$task" STATUS)" in
			queued | running)
				qt_set "$owner" "$task" STATUS=failed "ERROR=deploy was turned off" "END=$(date +'%F %T')" PID=
				;;
		esac
		qt_unlock
		rm -f "$DEPLOY_UPLOADS/$task.tar.gz"
	done
}

#----------------------------------------------------------#
#                     Archives                             #
#----------------------------------------------------------#

# Check the listing of a tar.gz before it is unpacked: only files,
# directories and links that stay inside the release; no absolute names,
# no ".." segments, no hard links or devices; at most MAX bytes unpacked.
# Prints the reason and returns 1 when the archive is refused.
deploy_check_archive() {
	local file="$1" max="$2" listing line type size name target total=0 dir part depth
	local re='^(.)[^ ]* +[^ ]+ +([0-9,]+) +[0-9-]+ +[0-9:]+ +"((\\.|[^"\\])*)"( -> "((\\.|[^"\\])*)"| link to "((\\.|[^"\\])*)")?$'
	if ! listing=$(tar -tvzf "$file" --quoting-style=c --numeric-owner 2> /dev/null); then
		echo "the archive is not a readable tar.gz"
		return 1
	fi
	while IFS= read -r line; do
		[ -n "$line" ] || continue
		if ! [[ "$line" =~ $re ]]; then
			echo "refused archive entry: ${line:0:200}"
			return 1
		fi
		type=${BASH_REMATCH[1]}
		size=${BASH_REMATCH[2]}
		name=${BASH_REMATCH[3]}
		target=${BASH_REMATCH[6]}
		if [[ "$name" == /* ]] || [[ "/$name/" == */../* ]]; then
			echo "refused archive entry: $name"
			return 1
		fi
		case "$type" in
			- | d) ;;
			l)
				if [[ "$target" == /* ]]; then
					echo "refused archive entry: $name (link out of the release)"
					return 1
				fi
				# Resolve the target against the link's folder, segment by segment
				dir=${name#./}
				dir=${dir%/*}
				[ "$dir" = "${name#./}" ] && dir=''
				depth=0
				for part in ${dir//\// } ${target//\// }; do
					case "$part" in
						. | '') ;;
						..) depth=$((depth - 1)) ;;
						*) depth=$((depth + 1)) ;;
					esac
					if [ "$depth" -lt 0 ]; then
						echo "refused archive entry: ${name#./} (link out of the release)"
						return 1
					fi
				done
				;;
			*)
				echo "refused archive entry: ${name#./} (type $type)"
				return 1
				;;
		esac
		if [[ "$size" =~ ^[0-9]+$ ]]; then
			total=$((total + size))
			if [ "$total" -gt "$max" ]; then
				echo "refused archive: the unpacked size is above $max bytes"
				return 1
			fi
		fi
	done <<< "$listing"
	return 0
}

#----------------------------------------------------------#
#                     Pipeline steps                       #
#----------------------------------------------------------#

deploy_log() {
	printf '%s %s\n' "$(date +'%F %T')" "$*"
}

# New release folder for USER DOMAIN; sets DEPLOY_RELEASE
deploy_new_release() {
	local owner="$1" name="$2" web id
	web=$(deploy_web "$owner" "$name")
	id=$(date -u +%Y%m%d-%H%M%S)
	while [ -e "$web/releases/$id" ]; do
		sleep 1
		id=$(date -u +%Y%m%d-%H%M%S)
	done
	deploy_as_user "$owner" bash -c '
		set -e
		umask 022
		web=$1 id=$2
		for dir in "$web/releases" "$web/shared"; do
			[ ! -L "$dir" ] || { echo "Error: $dir is a symbolic link"; exit 1; }
			mkdir -p "$dir"
			chmod 750 "$dir"
		done
		mkdir "$web/releases/$id"' _ "$web" "$id" || return 1
	# The web server reads the releases through the group, as public_html (-h:
	# a link the user put there is changed itself, never its target)
	chgrp -h www-data "$web/releases" "$web/shared" 2> /dev/null
	DEPLOY_RELEASE=$id
}

deploy_remove_release() {
	local web
	web=$(deploy_web "$1" "$2")
	deploy_valid_release "$3" || return 0
	deploy_as_user "$1" rm -rf -- "$web/releases/$3" > /dev/null 2>&1
}

# Unpack ARCHIVE (root's) into the release as the user
deploy_unpack() {
	local owner="$1" name="$2" release="$3" archive="$4" reason dir
	if ! reason=$(deploy_check_archive "$archive" $(($(deploy_max_artifact) * 4))); then
		echo "Error: $reason"
		return 1
	fi
	dir="$(deploy_web "$owner" "$name")/releases/$release"
	if ! deploy_as_user "$owner" timeout "$(deploy_build_timeout)" tar -xzf - -C "$dir" \
		--no-same-owner --no-same-permissions --no-overwrite-dir < "$archive"; then
		echo "Error: the archive could not be unpacked"
		return 1
	fi
}

# Write the release's .arcpanel-release (SOURCE COMMIT REF CREATED TASK)
deploy_write_release_info() {
	local owner="$1" name="$2" release="$3" source="$4" commit="$5" ref="$6" task="$7" file
	file="$(deploy_web "$owner" "$name")/releases/$release/.arcpanel-release"
	printf "SOURCE='%s' COMMIT='%s' REF='%s' CREATED='%s' TASK='%s'\n" \
		"$source" "${commit//\'/}" "${ref//\'/}" "$(date -u +'%F %T')" "$task" \
		| deploy_as_user "$owner" bash -c 'rm -f -- "$1" && umask 027 && cat > "$1"' _ "$file"
}

# Link the SHARED entries of the release to shared/, creating each one from
# the release's copy the first time (a file or a folder), or empty
deploy_link_shared() {
	local owner="$1" name="$2" release="$3" shared="$4"
	[ -n "$shared" ] || return 0
	# shellcheck disable=SC2086
	deploy_as_user "$owner" bash -c '
		umask 022
		web=$1 release=$2
		shift 2
		for entry in "$@"; do
			dir=no
			[ "${entry%/}" != "$entry" ] && dir=yes
			entry=${entry%/}
			src="$web/releases/$release/$entry"
			dst="$web/shared/$entry"
			[ -d "$src" ] && [ ! -L "$src" ] && dir=yes
			if [ ! -e "$dst" ] && [ ! -L "$dst" ]; then
				mkdir -p "$(dirname "$dst")" || exit 1
				if [ -e "$src" ] && [ ! -L "$src" ]; then
					cp -a "$src" "$dst" || exit 1
				elif [ "$dir" = yes ]; then
					mkdir "$dst" || exit 1
				else
					: > "$dst" || exit 1
					chmod 640 "$dst"
				fi
			fi
			rm -rf -- "$src"
			mkdir -p "$(dirname "$src")" || exit 1
			ln -s "$dst" "$src" || exit 1
		done' _ "$(deploy_web "$owner" "$name")" "$release" $shared
}

# Point current at releases/RELEASE with one rename(2)
deploy_switch() {
	local owner="$1" name="$2" release="$3"
	deploy_as_user "$owner" bash -c '
		cd "$1" || exit 1
		ln -sfn "releases/$2" current.tmp && mv -T current.tmp current' _ "$(deploy_web "$owner" "$name")" "$release"
}

# Reload what serves the domain after a switch, gracefully: PHP-FPM, NGINX
# (its open file cache), the domain's app processes (E42) and its NGINX cache
deploy_reload() {
	local owner="$1" name="$2" version rc=0 row proc
	(
		user=$owner
		domain=$name
		USER_DATA="$ARCPANEL/data/users/$user"
		get_domain_values 'web'
		if [ -n "$WEB_BACKEND" ] && [ "$WEB_BACKEND" != 'no' ] && [ "$BACKEND" != 'no-php' ]; then
			version=$(php_backend_version "$BACKEND")
			if [ -n "$version" ] && systemctl is-active --quiet "php$version-fpm"; then
				systemctl reload "php$version-fpm" || exit 1
			fi
		fi
		if [ "$FASTCGI_CACHE" = 'yes' ]; then
			"$BIN/v-purge-nginx-cache" "$owner" "$name" > /dev/null 2>&1
		fi
	) || rc=1
	# NGINX keeps open files for open_file_cache_valid (60 s): a graceful
	# reload makes it serve the new release at once
	if [ "$PROXY_SYSTEM" = 'nginx' ] || [ "$WEB_SYSTEM" = 'nginx' ]; then
		if systemctl is-active --quiet nginx; then
			systemctl reload nginx > /dev/null 2>&1 || rc=1
		fi
	fi
	if [ -x "$BIN/v-list-app-processes" ]; then
		while IFS= read -r row; do
			proc=${row#*$'\t'}
			[ -n "$proc" ] && "$BIN/v-reload-app-process" "$owner" "$name" "$proc" > /dev/null 2>&1
		done < <("$BIN/v-list-app-processes" "$owner" json 2> /dev/null \
			| jq -r --arg d "$name" '.PROCESSES[]? | select(.DOMAIN == $d and .STATUS == "running") | "\(.DOMAIN)\t\(.NAME)"' 2> /dev/null)
	fi
	return "$rc"
}

# GET https://DOMAIN/PATH (or http without SSL) through the domain's own
# address, up to 5 times in 30 seconds; 0 on a 2xx or 3xx answer
deploy_health() {
	local owner="$1" name="$2" path="$3" ip scheme port code i
	ip=$(
		user=$owner
		domain=$name
		USER_DATA="$ARCPANEL/data/users/$user"
		get_domain_values 'web'
		get_real_ip "$IP"
	)
	[ -n "$ip" ] || ip=127.0.0.1
	scheme=http
	port=${PROXY_PORT:-${WEB_PORT:-80}}
	if grep -F "DOMAIN='$name' " "$ARCPANEL/data/users/$owner/web.conf" | grep -q "SSL='yes'"; then
		scheme=https
		port=${PROXY_SSL_PORT:-${WEB_SSL_PORT:-443}}
	fi
	for i in 1 2 3 4 5; do
		code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 --resolve "$name:$port:$ip" \
			"$scheme://$name:$port$path" 2> /dev/null)
		deploy_log "health check $i: $scheme://$name$path answered ${code:-nothing}"
		if [[ "$code" =~ ^[23][0-9][0-9]$ ]]; then
			return 0
		fi
		[ "$i" -lt 5 ] && sleep 6
	done
	return 1
}

# Remove the oldest releases beyond KEEP; never CURRENT
deploy_prune() {
	local owner="$1" name="$2" keep="$3" current="$4" release count total
	total=$(deploy_releases "$owner" "$name" | wc -l)
	count=0
	while IFS= read -r release; do
		[ -n "$release" ] || continue
		[ "$((total - count))" -gt "$keep" ] || break
		if [ "$release" != "$current" ]; then
			deploy_log "removing release $release"
			deploy_remove_release "$owner" "$name" "$release"
		fi
		count=$((count + 1))
	done < <(deploy_releases "$owner" "$name")
}

# Seconds left of the build budget that started at START (epoch)
deploy_budget() {
	local left
	left=$(($(deploy_build_timeout) - ($(date +%s) - $1)))
	[ "$left" -gt 0 ] || left=1
	echo "$left"
}

# Fetch WANT (a branch name or a 40-hex commit) of the repository of USER
# DOMAIN into the release as the user, with a copy of the deploy key or of
# the token in a 0600 file of the user's tmp/ that a trap removes. .git/
# leaves the release. Prints the commit, or Git's last line on failure.
deploy_fetch() {
	local owner="$1" name="$2" release="$3" want="$4" seconds="$5" url auth branch home cred rc out line
	url=$(deploy_get "$owner" "$name" URL)
	auth=$(deploy_get "$owner" "$name" AUTH)
	branch=$(deploy_get "$owner" "$name" BRANCH)
	home="$HOMEDIR/$owner"
	if [ ! -d "$home/tmp" ] || [ -L "$home/tmp" ]; then
		echo "the tmp folder of $owner is missing"
		return 1
	fi
	cred=$(deploy_as_user "$owner" mktemp "$home/tmp/.arcpanel-deploy.XXXXXXXX") || return 1
	if [ "$auth" = 'token' ]; then
		deploy_read_secret "$owner" "$name" token | deploy_as_user "$owner" bash -c 'umask 077; cat > "$1"' _ "$cred"
	else
		deploy_read_secret "$owner" "$name" key | deploy_as_user "$owner" bash -c 'umask 077; cat > "$1"' _ "$cred"
	fi
	if [ ! -s "$cred" ]; then
		deploy_as_user "$owner" rm -f -- "$cred"
		echo "the $auth of the repository is not set (v-change-web-domain-deploy-credential)"
		return 1
	fi
	out=$(deploy_as_user "$owner" timeout "$seconds" bash -c '
		cred=$1 dir=$2 url=$3 want=$4 branch=$5 auth=$6
		trap "rm -f -- \"$cred\"" EXIT
		export GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1
		if [ "$auth" = token ]; then
			helper="!f() { echo username=x-access-token; printf \"password=%s\\n\" \"\$(cat \"$cred\")\"; }; f"
			set -- -c credential.helper= -c "credential.helper=$helper"
		else
			mkdir -p "$HOME/.ssh" && chmod 700 "$HOME/.ssh"
			export GIT_SSH_COMMAND="ssh -i $cred -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=$HOME/.ssh/arcpanel-deploy-known-hosts"
			set --
		fi
		cd "$dir" || exit 1
		git init -q . || exit 1
		git remote add origin "$url" || exit 1
		if [[ "$want" =~ ^[0-9a-f]{40}$ ]]; then
			git "$@" fetch -q --depth 1 origin "$want" 2>&1 \
				|| git "$@" fetch -q origin "refs/heads/$branch" 2>&1 || exit 1
			git checkout -q "$want" 2>&1 || exit 1
		else
			git "$@" fetch -q --depth 1 origin "refs/heads/${want#refs/heads/}" 2>&1 || exit 1
			git checkout -q FETCH_HEAD 2>&1 || exit 1
		fi
		git rev-parse HEAD > .git/arcpanel-commit || exit 1' _ \
		"$cred" "$(deploy_web "$owner" "$name")/releases/$release" "$url" "$want" "$branch" "$auth" 2>&1)
	rc=$?
	deploy_as_user "$owner" rm -f -- "$cred"
	if [ "$rc" -ne 0 ]; then
		if [ "$rc" -eq 124 ]; then
			echo "the fetch timed out"
			return 1
		fi
		# The line that says why (Git ends with a generic hint), else the last one
		line=$(printf '%s\n' "$out" | grep -m1 -E 'Permission denied|Host key verification|Could not resolve|Connection (refused|timed out)|not found|Authentication failed|find remote ref|^(fatal|error):')
		[ -n "$line" ] || line=$(printf '%s\n' "$out" | grep -v '^[[:space:]]*$' | tail -n1)
		printf '%s\n' "$line"
		return 1
	fi
	head -c 40 "$(deploy_web "$owner" "$name")/releases/$release/.git/arcpanel-commit" 2> /dev/null
	deploy_as_user "$owner" rm -rf -- "$(deploy_web "$owner" "$name")/releases/$release/.git"
	return 0
}

# PHP binary of the domain's backend version
deploy_php() {
	local version
	version=$(
		user=$1
		domain=$2
		USER_DATA="$ARCPANEL/data/users/$user"
		get_domain_values 'web'
		php_backend_version "${BACKEND:-default}"
	)
	if [ -n "$version" ] && [ -x "/usr/bin/php$version" ]; then
		echo "/usr/bin/php$version"
	else
		echo /usr/bin/php
	fi
}

# bin folder of the user's default Node.js (E13), empty when none
deploy_node_bin() {
	local home="$HOMEDIR/$1" version
	version=$(cat "$home/.nvm/alias/default" 2> /dev/null | head -n1 | tr -d 'v[:space:]')
	if [ -n "$version" ] && [ -d "$home/.nvm/versions/node/v$version/bin" ]; then
		echo "$home/.nvm/versions/node/v$version/bin"
	else
		ls -d "$home"/.nvm/versions/node/v*/bin 2> /dev/null | sort -V | tail -n1
	fi
}

# Run PRESET in the release as the user (in the jail when the user's shell
# is jailbash), within SECONDS
deploy_run_preset() {
	local owner="$1" name="$2" release="$3" preset="$4" seconds="$5" migrate php node_bin dir script shell
	[ "$preset" = 'none' ] && return 0
	migrate=$(deploy_get "$owner" "$name" MIGRATE)
	php=$(deploy_php "$owner" "$name")
	node_bin=$(deploy_node_bin "$owner")
	dir="$(deploy_web "$owner" "$name")/releases/$release"
	if [ "$preset" = 'laravel' ] && [ ! -x "$HOMEDIR/$owner/.composer/composer" ]; then
		deploy_log "installing Composer for $owner"
		"$BIN/v-add-user-composer" "$owner" > /dev/null 2>&1
	fi
	# shellcheck disable=SC2016
	script='
		set -e
		umask 022
		preset=$1 dir=$2 php=$3 node_bin=$4 migrate=$5 shared=$6 domain=$7
		[ -n "$node_bin" ] && export PATH="$node_bin:$PATH"
		cd "$dir"
		has_build() {
			[ -f package.json ] && "$php" -r '"'"'$p = json_decode(file_get_contents("package.json"), true); exit(isset($p["scripts"]["build"]) ? 0 : 1);'"'"'
		}
		build() {
			if has_build; then
				command -v npm > /dev/null || { echo "Error: package.json has a build script but Node.js is not installed for this user"; exit 1; }
				echo "+ npm ci"; npm ci --no-audit --no-fund
				echo "+ npm run build"; npm run build
			fi
		}
		case "$preset" in
			laravel)
				echo "+ composer install"
				"$php" "$HOME/.composer/composer" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
				build
				if [ "$migrate" = yes ]; then
					echo "+ php artisan migrate --force"; "$php" artisan migrate --force --no-interaction
				fi
				echo "+ php artisan optimize"; "$php" artisan optimize
				;;
			static)
				build
				;;
			custom)
				[ -f .arcpanel/deploy.sh ] || { echo "Error: the repository has no .arcpanel/deploy.sh"; exit 1; }
				echo "+ .arcpanel/deploy.sh"
				RELEASE_DIR=$dir SHARED_DIR=$shared DOMAIN=$domain PHP=$php bash .arcpanel/deploy.sh
				;;
		esac'
	shell=$(getent passwd "$owner" | cut -d: -f7)
	if [ "$shell" = '/usr/sbin/jailbash' ] && [ -x /usr/sbin/jailbash ]; then
		deploy_as_user "$owner" timeout "$seconds" /usr/sbin/jailbash -c "$script" _ "$preset" "$dir" "$php" \
			"$node_bin" "$migrate" "$(deploy_web "$owner" "$name")/shared" "$name" 2>&1
	else
		deploy_as_user "$owner" timeout "$seconds" bash -c "$script" _ "$preset" "$dir" "$php" \
			"$node_bin" "$migrate" "$(deploy_web "$owner" "$name")/shared" "$name" 2>&1
	fi
}

#----------------------------------------------------------#
#                     Credentials and port                 #
#----------------------------------------------------------#

# Name of a credential (key, token, webhook) of DOMAIN in the E38 store
deploy_secret_name() {
	echo "/deploy/$1/$2"
}

# Store standard input as the credential KIND of USER DOMAIN (E38)
deploy_store_secret() {
	local owner="$1" name="$2" kind="$3"
	secret_store_ready || return "$E_NOTEXIST"
	secret_user_init "$owner" || return "$E_FORBIDEN"
	(
		secret_lock "$owner"
		secret_store_version "$owner" "$(deploy_secret_name "$name" "$kind")" "Git Deploy $kind of $name" > /dev/null
	)
}

# Print the credential KIND of USER DOMAIN (nothing when it does not exist)
deploy_read_secret() {
	local owner="$1" secret
	secret=$(deploy_secret_name "$2" "$3")
	secret_store_ready && secret_exists "$owner" "$secret" || return 1
	secret_current_value "$owner" "$secret"
}

# Delete the deploy key, token and webhook secret of USER DOMAIN
deploy_delete_credentials() {
	local owner="$1" name="$2" kind secret
	secret_store_ready || return 0
	[ -d "$(secret_dir "$owner")" ] || return 0
	(
		secret_lock "$owner"
		for kind in key token webhook; do
			secret=$(deploy_secret_name "$name" "$kind")
			if secret_exists "$owner" "$secret"; then
				secret_remove "$owner" "$secret"
			fi
		done
	)
}

# Create a new ed25519 deploy key for USER DOMAIN: the private key goes to
# the store, the public key is printed
deploy_new_key() {
	local owner="$1" name="$2" tmp rc=0
	tmp=$(mktemp -d /dev/shm/arcpanel-deploy.XXXXXX 2> /dev/null || mktemp -d)
	chmod 700 "$tmp"
	if ssh-keygen -q -t ed25519 -N '' -C "arcpanel-deploy@$name" -f "$tmp/key" > /dev/null 2>&1; then
		deploy_store_secret "$owner" "$name" key < "$tmp/key" || rc=$?
		[ "$rc" -eq 0 ] && cat "$tmp/key.pub"
	else
		rc=$E_FORBIDEN
	fi
	rm -rf "$tmp"
	return "$rc"
}

# Create a new webhook secret for USER DOMAIN and print it
deploy_new_webhook_secret() {
	local secret
	secret=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 40)
	printf '%s' "$secret" | deploy_store_secret "$1" "$2" webhook || return $?
	echo "$secret"
}

# Number of domains with deploy on
deploy_count() {
	local files=("$ARCPANEL"/data/users/*/deploy/*.conf)
	[ -e "${files[0]}" ] || {
		echo 0
		return
	}
	echo "${#files[@]}"
}

# DEPLOY_PORT as arcpanel.conf has it now (empty when the port is off)
deploy_port_current() {
	if grep -q '^DEPLOY_PORT=' "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null; then
		grep -m1 '^DEPLOY_PORT=' "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2
	else
		echo 8088
	fi
}

# Check PORT for the deploy port (exit E_INVALID or E_INUSE otherwise):
# 1024-65535, not a port of the panel, the web terminal, the tool ports,
# the web or proxy server, not held in the port registry by anyone else,
# and free unless the deploy port already listens on it
deploy_port_check() {
	local port="$1" taken holder
	if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || [ "$port" -lt 1024 ] || [ "$port" -gt 65535 ]; then
		check_result "$E_INVALID" "invalid port :: $port"
	fi
	for taken in "${BACKEND_PORT:-8083}" "${WEB_TERMINAL_PORT:-8085}" "$WEBMAIL_PORT" "$DB_ADMIN_PORT" \
		"$WEB_PORT" "$WEB_SSL_PORT" "$PROXY_PORT" "$PROXY_SSL_PORT" 80 443 8084; do
		if [ -n "$taken" ] && [ "$port" = "$taken" ]; then
			check_result "$E_INUSE" "port $port is used by the server"
		fi
	done
	holder=$(ports_holder "$port" "$port" '@reserved')
	if [ -n "$holder" ]; then
		check_result "$E_INUSE" "port $port is allocated to $holder"
	fi
	if grep -q "^PORT='$port' .*COMMENT='[^']*'" "$PORTS_RESERVED" 2> /dev/null \
		&& ! grep -q "^PORT='$port' .*COMMENT='arcpanel-deploy-port'" "$PORTS_RESERVED" 2> /dev/null; then
		check_result "$E_INUSE" "port $port is reserved"
	fi
	if [ ! -f "$DEPLOY_PORT_CONF" ] || ! grep -q "listen *$port ssl" "$DEPLOY_PORT_CONF"; then
		if [ -n "$(ss -ltnH "( sport = :$port )" 2> /dev/null)" ]; then
			check_result "$E_INUSE" "port $port is in use"
		fi
	fi
}

# Print the NGINX server of the deploy port on PORT
deploy_port_render() {
	local port="$1" line max listen_v6=''
	max=$(deploy_bytes "${DEPLOY_MAX_ARTIFACT:-512M}")
	max=$(((${max:-536870912} + 1048575) / 1048576))m
	if [ -e /proc/net/if_inet6 ]; then
		listen_v6="	listen      [::]:$port ssl;"
	fi
	while IFS= read -r line; do
		line=${line//%port%/$port}
		line=${line//%panel_port%/${BACKEND_PORT:-8083}}
		line=${line//%max_artifact%/$max}
		line=${line//%cert%/$ARCPANEL/ssl/certificate.crt}
		line=${line//%key%/$ARCPANEL/ssl/certificate.key}
		if [ "$line" = '%listen_v6%' ]; then
			[ -n "$listen_v6" ] && echo "$listen_v6"
			continue
		fi
		echo "$line"
	done < "$ARCPANEL/install/deb/nginx/deploy-port.tpl"
}

# Write or remove the deploy port's NGINX file, reservation and firewall
# rule: they exist only while DEPLOY_PORT is set and at least one domain has
# deploy on (§6.1). Restores the previous file when the NGINX test fails.
# Callers source func/ports.sh and func/tool-ports.sh.
deploy_port_update() {
	local port new backup=''
	port=$(deploy_port_current)
	TOOL_PORTS_CHANGED=no
	if [ "$(deploy_count)" -eq 0 ] || [ -z "$port" ] || [ ! -d "$(dirname "$DEPLOY_PORT_CONF")" ]; then
		if [ -e "$DEPLOY_PORT_CONF" ]; then
			rm -f "$DEPLOY_PORT_CONF"
			TOOL_PORTS_CHANGED=yes
		fi
		tool_ports_reserve deploy ''
		tool_ports_firewall deploy ''
		tool_ports_reload
		return 0
	fi
	tool_ports_reserve deploy "$port" || return "$E_UPDATE"
	tool_ports_firewall deploy "$port" || return "$E_UPDATE"
	new=$(deploy_port_render "$port")
	if [ -e "$DEPLOY_PORT_CONF" ] && [ "$new" = "$(cat "$DEPLOY_PORT_CONF")" ]; then
		return 0
	fi
	if [ -e "$DEPLOY_PORT_CONF" ]; then
		backup=$(mktemp)
		cp -f "$DEPLOY_PORT_CONF" "$backup"
	fi
	echo "$new" > "$DEPLOY_PORT_CONF"
	chmod 644 "$DEPLOY_PORT_CONF"
	if ! nginx -t > /dev/null 2>&1; then
		if [ -n "$backup" ]; then
			mv -f "$backup" "$DEPLOY_PORT_CONF"
		else
			rm -f "$DEPLOY_PORT_CONF"
			tool_ports_reserve deploy ''
			tool_ports_firewall deploy ''
		fi
		return "$E_UPDATE"
	fi
	[ -n "$backup" ] && rm -f "$backup"
	TOOL_PORTS_CHANGED=yes
	tool_ports_reload
	return 0
}

#----------------------------------------------------------#
#                     Output                               #
#----------------------------------------------------------#

# JSON array of the publishers of USER DOMAIN
deploy_publishers_json() {
	local file line
	file=$(deploy_publishers_file "$1" "$2")
	{
		if [ -f "$file" ]; then
			while IFS= read -r line; do
				[ -n "$line" ] || continue
				jq -nc --arg pub "$(deploy_kv "$line" PUB)" --arg issuer "$(deploy_kv "$line" ISSUER)" \
					--arg repo "$(deploy_kv "$line" SUBJECT_REPO)" --arg ref "$(deploy_kv "$line" REF)" \
					--arg env "$(deploy_kv "$line" ENVIRONMENT)" --arg wf "$(deploy_kv "$line" WORKFLOW)" \
					'{PUB: $pub, ISSUER: $issuer, SUBJECT_REPO: $repo, REF: $ref, ENVIRONMENT: $env, WORKFLOW: $wf}'
			done < "$file"
		fi
	} | jq -sc '.'
}

# Value of KEY in a KEY='value' line
deploy_kv() {
	local re="(^| )$2='([^']*)'"
	if [[ "$1" =~ $re ]]; then
		echo "${BASH_REMATCH[2]}"
	fi
}
