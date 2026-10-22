#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel app processes: long-running commands of a web domain, run as the #
# hosting user by one systemd system unit each (E42)                        #
#                                                                           #
#   $ARCPANEL/data/users/<user>/appprocesses.conf  the records (one per line) #
#   $ARCPANEL/data/app-processes/<user>/<id>.json  what arc-app-run starts    #
#   /etc/systemd/system/arcpanel-app-<user>-<id>.service  the unit          #
#   /run/arcpanel/app/<user>-<id>.env      the resolved environment (tmpfs) #
#   ~/web/<domain>/app-process/            <name>.conf (copy for backups),  #
#                                          <name>.env, logs/                #
#   /etc/logrotate.d/arcpanel-app-<user>                                    #
# Root writes only root-owned files; everything in a home directory is read #
# and written as the user.                                                  #
# See docs/features/specs/2026-10-16-003-e42-app-processes-design.md.       #
#                                                                           #
#===========================================================================#

# shellcheck source=/usr/local/arcpanel/func/node.sh
source "$ARCPANEL/func/node.sh"
# shellcheck source=/usr/local/arcpanel/func/arc-template.sh
source "$ARCPANEL/func/arc-template.sh"

APPPROC_KEYS="ID DOMAIN NAME KIND PRESET START CWD PORT MEMORY CPUS TEMPLATE PREV_TPL STATE SUSPENDED TIME DATE"
APPPROC_PRESETS="${ARC_APPPROC_PRESETS:-$ARCPANEL/install/common/app-processes/presets.conf}"
APPPROC_RUN_DIR="$ARCPANEL/data/app-processes"
APPPROC_ENV_DIR="/run/arcpanel/app"
APPPROC_UNIT_DIR="${ARC_APPPROC_UNIT_DIR:-/etc/systemd/system}"
APPPROC_RUNNER="$ARCPANEL/bin/arc-app-run"
# PHP settings of the processes; ".php" is no user name, and the folder is
# readable by everyone (the run directory itself is 711)
APPPROC_PHP_INI_DIR="$APPPROC_RUN_DIR/.php"
APPPROC_DEFAULT_CWD="public_html"

# Default and highest memory of a process in MB when the package has none
APPPROC_DEFAULT_MEMORY=512
APPPROC_MAX_MEMORY=8192
APPPROC_MIN_MEMORY=32
APPPROC_TASKS_MAX=256

# Largest command line and environment file the panel accepts
APPPROC_MAX_START=2048
APPPROC_MAX_ENV=65536

#----------------------------------------------------------#
#                        Records                           #
#----------------------------------------------------------#

appproc_conf() {
	echo "$ARCPANEL/data/users/$1/appprocesses.conf"
}

# Serialise the writers of USER's records. Nested commands (hooks, internal
# calls) run inside the caller's lock.
appproc_lock() {
	if [ "$ARC_APPPROC_LOCKED" = "$1" ]; then
		return 0
	fi
	exec {APPPROC_LOCK_FD}> "$ARCPANEL/data/users/$1/appprocesses.lock"
	if ! flock -w 120 "$APPPROC_LOCK_FD"; then
		check_result "$E_LA" "the app processes of $1 are locked by another command"
	fi
	export ARC_APPPROC_LOCKED="$1"
}

# Value of KEY in a record LINE
appproc_field() {
	if [[ "$1" =~ (^| )$2=\'([^\']*)\' ]]; then
		echo "${BASH_REMATCH[2]}"
	fi
}

# Record line of USER's process ID
appproc_line() {
	local file
	file=$(appproc_conf "$1")
	if [ -f "$file" ]; then
		grep -m1 "^ID='$2' " "$file"
	fi
}

# ID of USER's process NAME of DOMAIN, empty when there is none
appproc_id() {
	local file
	file=$(appproc_conf "$1")
	if [ -f "$file" ]; then
		grep -m1 -F " DOMAIN='$2' NAME='$3' " "$file" | grep -oE "^ID='[0-9]+'" | cut -d\' -f2
	fi
}

appproc_get() {
	appproc_field "$(appproc_line "$1" "$2")" "$3"
}

# IDs of USER's processes, in record order; with DOMAIN, only that domain's
appproc_ids() {
	local file
	file=$(appproc_conf "$1")
	if [ ! -f "$file" ]; then
		return 0
	fi
	if [ -n "$2" ]; then
		grep -F " DOMAIN='$2' " "$file" | grep -oE "^ID='[0-9]+'" | cut -d\' -f2
	else
		grep -oE "^ID='[0-9]+'" "$file" | cut -d\' -f2
	fi
}

appproc_count() {
	appproc_ids "$1" | grep -c .
}

# ID of the web process of USER's DOMAIN, empty when there is none
appproc_web_id() {
	local file
	file=$(appproc_conf "$1")
	if [ -f "$file" ]; then
		grep -F " DOMAIN='$2' " "$file" | grep -m1 -F " KIND='web' " | grep -oE "^ID='[0-9]+'" | cut -d\' -f2
	fi
}

appproc_next_id() {
	local max=0 id
	for id in $(appproc_ids "$1"); do
		if [ "$id" -gt "$max" ]; then
			max=$id
		fi
	done
	echo $((max + 1))
}

# Build a record line from KEY=VALUE pairs on top of LINE (may be empty).
# Quotes and line breaks are dropped from values.
appproc_compose() {
	local line="$1" pair key out=''
	local -A values=()
	shift
	for key in $APPPROC_KEYS; do
		values[$key]=$(appproc_field "$line" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $APPPROC_KEYS; do
		out="$out$key='${values[$key]}' "
	done
	echo "${out% }"
}

# Create or update USER's record ID with KEY=VALUE pairs
appproc_set() {
	local user="$1" id="$2" file line tmp
	shift 2
	file=$(appproc_conf "$user")
	line=$(appproc_compose "$(appproc_line "$user" "$id")" "ID=$id" "$@")
	tmp="$file.$$"
	{
		if [ -f "$file" ]; then
			grep -v "^ID='$id' " "$file"
		fi
		echo "$line"
	} > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
	appproc_copy_write "$user" "$id"
}

appproc_remove() {
	local user="$1" id="$2" file tmp
	file=$(appproc_conf "$user")
	if [ ! -f "$file" ]; then
		return 0
	fi
	tmp="$file.$$"
	grep -v "^ID='$id' " "$file" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
	if [ ! -s "$file" ]; then
		rm -f "$file"
	fi
}

# The command line of a record, decoded
appproc_start_of() {
	printf '%s' "$(appproc_field "$1" START)" | base64 -d 2> /dev/null
}

appproc_encode() {
	printf '%s' "$1" | base64 -w0
}

#----------------------------------------------------------#
#                    Paths and validation                  #
#----------------------------------------------------------#

appproc_domain_dir() {
	echo "$HOMEDIR/$1/web/$2"
}

# Folder of the processes of USER's DOMAIN (copies, environments, logs)
appproc_dir() {
	echo "$HOMEDIR/$1/web/$2/app-process"
}

appproc_unit() {
	echo "arcpanel-app-$1-$2.service"
}

appproc_unit_file() {
	echo "$APPPROC_UNIT_DIR/$(appproc_unit "$1" "$2")"
}

appproc_run_file() {
	echo "$APPPROC_RUN_DIR/$1/$2.json"
}

appproc_env_file() {
	echo "$APPPROC_ENV_DIR/$1-$2.env"
}

appproc_name_valid() {
	[[ "$1" =~ ^[a-z][a-z0-9-]{0,31}$ ]]
}

# A relative path without "..", or "." for the folder itself
appproc_relpath_valid() {
	local path="$1"
	[ "${#path}" -le 200 ] || return 1
	[ "$path" = '.' ] && return 0
	[[ "$path" =~ ^[A-Za-z0-9_][A-Za-z0-9._/@+-]*$ ]] || return 1
	[[ "/$path/" != *"/../"* ]] && [[ "/$path/" != *"/./"* ]] && [[ "$path" != *"//"* ]]
}

# Absolute working directory of CWD in USER's DOMAIN
appproc_cwd_path() {
	local dir
	dir=$(appproc_domain_dir "$1" "$2")
	if [ "$3" = '.' ]; then
		echo "$dir"
	else
		echo "$dir/$3"
	fi
}

# Return 1 unless PATH, with its symlinks resolved as the user, stays inside
# the domain directory of USER's DOMAIN
appproc_inside() {
	local user="$1" domain="$2" path="$3" real base
	base=$(appproc_domain_dir "$user" "$domain")
	real=$(node_as_user "$user" readlink -m "$path" 2> /dev/null)
	case "$real" in
		"$base" | "$base"/*) return 0 ;;
	esac
	return 1
}

# Exit unless CWD of USER's DOMAIN is a folder inside the domain directory
appproc_check_cwd() {
	local user="$1" domain="$2" cwd="$3" dir
	if ! appproc_relpath_valid "$cwd"; then
		check_result "$E_INVALID" "invalid working directory :: $cwd"
	fi
	dir=$(appproc_cwd_path "$user" "$domain" "$cwd")
	if ! appproc_inside "$user" "$domain" "$dir"; then
		check_result "$E_FORBIDEN" "path outside of the directory of $domain ($cwd)"
	fi
	if ! node_as_user "$user" test -d "$dir"; then
		check_result "$E_NOTEXIST" "working directory $cwd does not exist in $domain"
	fi
}

# Whether TEXT has a control character (line breaks, NUL, escapes)
appproc_has_control() {
	[[ "$1" =~ [[:cntrl:]] ]]
}

#----------------------------------------------------------#
#                     Command lines                        #
#----------------------------------------------------------#

# Split a command line into APPPROC_ARGS without a shell: whitespace
# separates words, single quotes keep everything, double quotes keep
# everything but \" and \\, a backslash outside quotes escapes the next
# character. No expansion, no pipes, no redirections. Returns 1 on an open
# quote or a trailing backslash.
appproc_split() {
	local text="$1" i c word='' have=no quote=''
	APPPROC_ARGS=()
	for ((i = 0; i < ${#text}; i++)); do
		c=${text:i:1}
		if [ "$quote" = "'" ]; then
			if [ "$c" = "'" ]; then
				quote=''
			else
				word+=$c
			fi
			continue
		fi
		if [ "$quote" = '"' ]; then
			if [ "$c" = '"' ]; then
				quote=''
			elif [ "$c" = '\' ] && [[ "${text:i+1:1}" == ['"\\'] ]]; then
				i=$((i + 1))
				word+=${text:i:1}
			else
				word+=$c
			fi
			continue
		fi
		case "$c" in
			' ' | $'\t')
				if [ "$have" = 'yes' ]; then
					APPPROC_ARGS+=("$word")
					word=''
					have=no
				fi
				;;
			"'" | '"')
				quote=$c
				have=yes
				;;
			'\')
				if [ $((i + 1)) -ge ${#text} ]; then
					return 1
				fi
				i=$((i + 1))
				word+=${text:i:1}
				have=yes
				;;
			*)
				word+=$c
				have=yes
				;;
		esac
	done
	if [ -n "$quote" ]; then
		return 1
	fi
	if [ "$have" = 'yes' ]; then
		APPPROC_ARGS+=("$word")
	fi
	[ "${#APPPROC_ARGS[@]}" -gt 0 ]
}

# Exit unless START is a command line the engine accepts
appproc_check_start() {
	local start="$1"
	if [ -z "$start" ]; then
		check_result "$E_INVALID" "the command is empty"
	fi
	if [ "${#start}" -gt "$APPPROC_MAX_START" ]; then
		check_result "$E_INVALID" "the command is longer than $APPPROC_MAX_START characters"
	fi
	if appproc_has_control "$start"; then
		check_result "$E_INVALID" "the command has a line break or a control character"
	fi
	if ! appproc_split "$start"; then
		check_result "$E_INVALID" "the command has an unclosed quote or a trailing backslash"
	fi
}

#----------------------------------------------------------#
#                         Presets                          #
#----------------------------------------------------------#

appproc_preset_names() {
	grep -oE "^NAME='[a-z0-9-]+'" "$APPPROC_PRESETS" 2> /dev/null | cut -d\' -f2
}

appproc_preset_line() {
	grep -m1 "^NAME='$1' " "$APPPROC_PRESETS" 2> /dev/null
}

appproc_preset_get() {
	appproc_field "$(appproc_preset_line "$1")" "$2"
}

appproc_preset_exists() {
	[[ "$1" =~ ^[a-z0-9-]{1,40}$ ]] && [ -n "$(appproc_preset_line "$1")" ]
}

# PHP version (X.Y) of the backend of USER's DOMAIN
appproc_php_version() {
	local backend
	backend=$(arc_tpl_domain_value "$1" "$2" BACKEND)
	if [[ "$backend" =~ PHP-([0-9]{1,2})_([0-9]{1,2})$ ]]; then
		echo "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
	elif [ -x /usr/bin/php ]; then
		/usr/bin/php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2> /dev/null
	fi
}

# Folder of the Python commands of a working directory DIR of USER
appproc_python_dir() {
	if node_as_user "$1" test -x "$2/.venv/bin/python"; then
		echo "$2/.venv/bin"
	else
		echo /usr/bin
	fi
}

# Python module of an app in DIR of USER: the folder that holds wsgi.py
# (gunicorn) or celery.py (celery), "app" when there is none
appproc_python_module() {
	local user="$1" dir="$2" file="$3" found
	found=$(node_as_user "$user" find "$dir" -mindepth 2 -maxdepth 2 -name "$file" -not -path '*/.venv/*' -printf '%h\n' 2> /dev/null | sort | head -n1)
	if [ -n "$found" ] && [[ "${found##*/}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
		echo "${found##*/}"
	else
		echo app
	fi
}

# Fill the placeholders of a preset TEXT for USER's DOMAIN with CWD, PORT
# and CPUS (%workers%: the CPUs rounded up, at least 1; a non-number keeps
# the placeholder)
appproc_fill() {
	local user="$1" domain="$2" cwd="$3" port="$4" text="$5" cpus="${6:-1}" dir version module=app workers hundredths
	dir=$(appproc_cwd_path "$user" "$domain" "$cwd")
	if [[ "$text" == *%php%* ]]; then
		version=$(appproc_php_version "$user" "$domain")
		text=${text//%php%//usr/bin/php$version}
	fi
	if [[ "$text" == *%python%* ]]; then
		text=${text//%python%/$(appproc_python_dir "$user" "$dir")}
	fi
	if [[ "$text" == *%module%* ]]; then
		if [[ "$text" == *celery* ]]; then
			module=$(appproc_python_module "$user" "$dir" celery.py)
		else
			module=$(appproc_python_module "$user" "$dir" wsgi.py)
		fi
		text=${text//%module%/$module}
	fi
	text=${text//%port%/$port}
	if hundredths=$(appproc_cpus_hundredths "$cpus"); then
		workers=$(((hundredths + 99) / 100))
		text=${text//%workers%/$workers}
	fi
	printf '%s' "$text"
}

# Why the runtime of PRESET is missing for USER's DOMAIN in CWD; empty when
# it is there
appproc_runtime_missing() {
	local user="$1" domain="$2" preset="$3" cwd="$4" runtime needs version dir
	runtime=$(appproc_preset_get "$preset" RUNTIME)
	needs=$(appproc_preset_get "$preset" NEEDS)
	dir=$(appproc_cwd_path "$user" "$domain" "$cwd")
	case "$runtime" in
		php)
			version=$(appproc_php_version "$user" "$domain")
			if [ -z "$version" ] || [ ! -x "/usr/bin/php$version" ]; then
				echo "PHP ${version:-of the domain} is not installed"
				return 0
			fi
			if [ -n "$needs" ] && ! /usr/bin/php"$version" -m 2> /dev/null | grep -qixF "$needs"; then
				echo "turn on $needs for PHP $version (CLI) in the PHP Manager"
				return 0
			fi
			;;
		python)
			if ! node_as_user "$user" test -x "$dir/.venv/bin/python" && [ ! -x /usr/bin/python3 ]; then
				echo "Python 3 is not installed (add it on the System packages page or create .venv in the app)"
			fi
			;;
		ruby)
			if [ ! -x /usr/bin/bundle ] && [ ! -x /usr/local/bin/bundle ]; then
				echo "Ruby with bundler is not installed (add it on the System packages page)"
			fi
			;;
	esac
}

# Presets whose DETECT files exist in DIR of USER, one per line
appproc_detected() {
	local user="$1" dir="$2" name detect file
	for name in $(appproc_preset_names); do
		detect=$(appproc_preset_get "$name" DETECT)
		[ -n "$detect" ] || continue
		for file in ${detect//,/ }; do
			if node_as_user "$user" test -e "$dir/$file"; then
				echo "$name"
				break
			fi
		done
	done
}

#----------------------------------------------------------#
#                         Limits                           #
#----------------------------------------------------------#

appproc_pkg() {
	node_pkg_key "$1" "$2"
}

# Exit unless the package of USER allows app processes
appproc_check_enabled_package() {
	if [ "$(appproc_pkg "$1" APP_PROCESSES)" != 'yes' ]; then
		check_result "$E_DISABLED" "app processes are not allowed by the package of $1"
	fi
}

appproc_limit() {
	local value
	value=$(appproc_pkg "$1" APP_PROCESSES_MAX)
	echo "${value:-3}"
}

# Exit with E_LIMIT when USER cannot have another process
appproc_check_limit() {
	local limit
	limit=$(appproc_limit "$1")
	if [ "$limit" != 'unlimited' ] && [ "$(appproc_count "$1")" -ge "$limit" ]; then
		check_result "$E_LIMIT" "the package of $1 allows $limit app processes (APP_PROCESSES_MAX)"
	fi
}

# Highest memory (MB) of one process of USER: APP_PROCESSES_MEMORY, then
# MEMORY_LIMIT of the package, then the server's highest
appproc_max_memory() {
	local value limit max="$APPPROC_MAX_MEMORY"
	value=$(tenancy_mb "$(appproc_pkg "$1" APP_PROCESSES_MEMORY)")
	limit=$(tenancy_mb "$(tenancy_pkg_limit "$(node_package_of "$1")" MEMORY_LIMIT)")
	if [ -n "$value" ] && [ "$value" -lt "$max" ]; then
		max=$value
	fi
	if [ -n "$limit" ] && [ "$limit" -lt "$max" ]; then
		max=$limit
	fi
	echo "$max"
}

# Default memory of a new process of USER in MB (spec D6)
appproc_default_memory() {
	local limit max count value="$APPPROC_DEFAULT_MEMORY"
	limit=$(tenancy_mb "$(tenancy_pkg_limit "$(node_package_of "$1")" MEMORY_LIMIT)")
	count=$(appproc_limit "$1")
	if [ -n "$limit" ] && [[ "$count" =~ ^[0-9]+$ ]] && [ "$count" -gt 0 ] && [ $((limit / count)) -lt "$value" ]; then
		value=$((limit / count))
	fi
	max=$(appproc_max_memory "$1")
	if [ "$value" -gt "$max" ]; then
		value=$max
	fi
	if [ "$value" -lt "$APPPROC_MIN_MEMORY" ]; then
		value=$APPPROC_MIN_MEMORY
	fi
	echo "$value"
}

# Highest CPUs of one process of USER, as given in the package (default 1)
appproc_max_cpus() {
	local value
	value=$(appproc_pkg "$1" APP_PROCESSES_CPUS)
	echo "${value:-1}"
}

# CPUs in hundredths (1.5 → 150); returns 1 when VALUE does not parse
appproc_cpus_hundredths() {
	tenancy_limit_value DOCKER_CPUS "$1" || return 1
	[ "$REPLY" != 'unlimited' ] && [ "$REPLY" -gt 0 ] || return 1
	echo "$REPLY"
}

# Exit unless MEMORY (MB) and CPUS fit the limits of USER
appproc_check_resources() {
	local user="$1" memory="$2" cpus="$3" max hundredths limit
	if ! [[ "$memory" =~ ^[0-9]{1,6}$ ]] || [ "$memory" -lt "$APPPROC_MIN_MEMORY" ]; then
		check_result "$E_INVALID" "invalid memory :: $memory (MB, at least $APPPROC_MIN_MEMORY)"
	fi
	max=$(appproc_max_memory "$user")
	if [ "$memory" -gt "$max" ]; then
		check_result "$E_LIMIT" "at most $max MB per app process (APP_PROCESSES_MEMORY, MEMORY_LIMIT)"
	fi
	if ! hundredths=$(appproc_cpus_hundredths "$cpus") || [ "$hundredths" -gt 6400 ]; then
		check_result "$E_INVALID" "invalid CPUs :: $cpus (for example 1 or 0.5)"
	fi
	limit=$(appproc_max_cpus "$user")
	if [ "$limit" != 'unlimited' ] && tenancy_limit_above DOCKER_CPUS "$cpus" "$limit"; then
		check_result "$E_LIMIT" "at most $limit CPUs per app process (APP_PROCESSES_CPUS)"
	fi
}

#----------------------------------------------------------#
#                        Templates                         #
#----------------------------------------------------------#

# Whether NAME is a template of this server whose metadata takes a
# BACKEND_PORT port variable
appproc_tpl_has_port() {
	local kind
	kind=$(arc_tpl_active_kind)
	[ -n "$kind" ] && [[ "$1" =~ ^[A-Za-z0-9_.-]{1,64}$ ]] || return 1
	[ -f "$(arc_tpl_dir "$kind")/$1.tpl" ] || return 1
	arc_tpl_variables "$kind" "$1" 2> /dev/null | grep -q '^BACKEND_PORT|port|'
}

appproc_tpl_exists() {
	local dir
	dir=$(arc_tpl_dir "$(arc_tpl_active_kind)")
	[[ "$1" =~ ^[A-Za-z0-9_.-]{1,64}$ ]] && [ -n "$dir" ] && [ -f "$dir/$1.tpl" ]
}

# Client-facing template of USER's package, "default" when it does not exist
appproc_package_template() {
	local key=PROXY_TEMPLATE name
	if [ "$(arc_tpl_active_kind)" = 'web' ]; then
		key=WEB_TEMPLATE
	fi
	name=$(tenancy_pkg_limit "$(node_package_of "$1")" "$key")
	if appproc_tpl_exists "$name"; then
		echo "$name"
	else
		echo default
	fi
}

# Switch the client-facing template of USER's DOMAIN to NAME; RESTART
# (default no) is passed to the upstream command
appproc_tpl_switch() {
	local user="$1" domain="$2" name="$3" restart="${4:-no}" ext
	case "$(arc_tpl_active_kind)" in
		web)
			"$BIN/v-change-web-domain-tpl" "$user" "$domain" "$name" "$restart"
			;;
		proxy)
			ext=$(arc_tpl_domain_value "$user" "$domain" PROXY_EXT)
			"$BIN/v-change-web-domain-proxy-tpl" "$user" "$domain" "$name" "$ext" "$restart"
			;;
		*)
			echo "web app processes need NGINX as web server or proxy"
			return "$E_INVALID"
			;;
	esac
}

#----------------------------------------------------------#
#                     Files and the unit                   #
#----------------------------------------------------------#

# Refresh the copy of USER's record ID in the domain folder when the folder
# exists (written as the user)
appproc_copy_write() {
	local user="$1" id="$2" line dir name
	line=$(appproc_line "$user" "$id")
	[ -n "$line" ] || return 0
	dir=$(appproc_dir "$user" "$(appproc_field "$line" DOMAIN)")
	name=$(appproc_field "$line" NAME)
	if node_as_user "$user" test -d "$dir" && ! node_as_user "$user" test -L "$dir"; then
		printf '%s\n' "$line" \
			| node_as_user "$user" bash -c 'umask 027; [ ! -L "$1" ] && cat > "$1.tmp" && mv -f "$1.tmp" "$1"' conf "$dir/$name.conf"
	fi
	return 0
}

# Create the folders of USER's DOMAIN (as the user) and the environment file
# of NAME when it is missing. The domain folder is 551 unless
# DOMAINDIR_WRITABLE is yes: the user, its owner, opens it for the mkdir and
# restores its mode (as func/rebuild.sh does for logs/).
appproc_dirs_write() {
	local user="$1" domain="$2" name="$3" dir
	dir=$(appproc_dir "$user" "$domain")
	node_as_user "$user" bash -c '
		umask 027
		if [ ! -e "$1" ]; then
			mode=$(stat -c %a "$3") || exit 1
			chmod u+w "$3" && mkdir "$1"
			rc=$?
			chmod "$mode" "$3"
			[ "$rc" -eq 0 ] || exit 1
		fi
		[ -d "$1" ] && [ ! -L "$1" ] || exit 1
		mkdir -p "$1/logs" && [ ! -L "$1/logs" ] || exit 1
		umask 077
		[ -e "$1/$2.env" ] || : > "$1/$2.env"' \
		dirs "$dir" "$name" "$(appproc_domain_dir "$user" "$domain")" || return "$E_FORBIDEN"
}

# The port blocks of USER's pool as SocketBindAllow values, one per line;
# nothing when the registry is off (no restriction then)
appproc_bind_ranges() {
	local user="$1"
	if [ "$PORT_REGISTRY" = 'no' ]; then
		return 0
	fi
	ports_read "$user" | awk -F'|' '{ if ($1 == $2) print $1; else print $1 "-" $2 }' | sort -un
}

# The JSON that arc-app-run starts: working directory, port, log, the
# argument list and the reload action
appproc_run_json() {
	local user="$1" id="$2" line domain cwd port name start reload kind
	line=$(appproc_line "$user" "$id")
	domain=$(appproc_field "$line" DOMAIN)
	cwd=$(appproc_cwd_path "$user" "$domain" "$(appproc_field "$line" CWD)")
	port=$(appproc_field "$line" PORT)
	name=$(appproc_field "$line" NAME)
	kind=$(appproc_field "$line" KIND)
	start=$(appproc_start_of "$line")
	appproc_split "$start" || return "$E_INVALID"
	local -a args=("${APPPROC_ARGS[@]}") rargs=()
	reload=$(appproc_preset_get "$(appproc_field "$line" PRESET)" RELOAD)
	case "$reload" in
		command:*)
			appproc_split "$(appproc_fill "$user" "$domain" "$(appproc_field "$line" CWD)" "$port" "${reload#command:}")" || return "$E_INVALID"
			rargs=("${APPPROC_ARGS[@]}")
			reload=command
			;;
		signal:*) ;;
		*) reload=restart ;;
	esac
	# The arguments go through NUL-separated input: a word such as
	# --server=swoole must never reach jq as an option
	jq -n --arg user "$user" --arg domain "$domain" --arg name "$name" --arg kind "$kind" \
		--arg cwd "$cwd" --arg port "$port" --arg home "$HOMEDIR/$user" \
		--arg log "$(appproc_dir "$user" "$domain")/logs/$name.log" \
		--arg envfile "$(appproc_dir "$user" "$domain")/$name.env" \
		--arg reload "$reload" \
		--argjson start "$(printf '%s\0' "${args[@]}" | jq -Rs 'split("\u0000")[:-1]')" \
		--argjson rargs "$( (("${#rargs[@]}" > 0)) && printf '%s\0' "${rargs[@]}" | jq -Rs 'split("\u0000")[:-1]' || echo '[]')" '
		{ user: $user, domain: $domain, name: $name, kind: $kind, cwd: $cwd, port: $port,
			home: $home, log: $log, envfile: $envfile, reload: $reload,
			start: $start, reload_args: $rargs }'
}

# The systemd unit of USER's record ID
appproc_unit_text() {
	local user="$1" id="$2" line domain name memory cpus hundredths range reload ranges uid
	line=$(appproc_line "$user" "$id")
	domain=$(appproc_field "$line" DOMAIN)
	name=$(appproc_field "$line" NAME)
	memory=$(appproc_field "$line" MEMORY)
	cpus=$(appproc_field "$line" CPUS)
	hundredths=$(appproc_cpus_hundredths "$cpus") || hundredths=100
	uid=$(id -u "$user")
	reload=$(appproc_preset_get "$(appproc_field "$line" PRESET)" RELOAD)
	ranges=$(appproc_bind_ranges "$user")
	echo "# Written by ArcPanel from the app process $domain/$name of $user (E42)."
	echo "# Changes are overwritten; use v-change-app-process-* instead."
	echo "[Unit]"
	echo "Description=ArcPanel app process $domain/$name of $user"
	echo "After=network.target"
	echo "StartLimitIntervalSec=60"
	echo "StartLimitBurst=5"
	echo ""
	echo "[Service]"
	echo "Type=simple"
	echo "User=$user"
	echo "Group=$user"
	echo "Slice=user-$uid.slice"
	echo "WorkingDirectory=$HOMEDIR/$user"
	echo "ExecStartPre=+$APPPROC_RUNNER --prepare $user $id"
	echo "ExecStart=$APPPROC_RUNNER $user $id"
	case "$reload" in
		signal:*) echo "ExecReload=/bin/kill -${reload#signal:} \$MAINPID" ;;
		command:*) echo "ExecReload=$APPPROC_RUNNER --reload $user $id" ;;
	esac
	echo "ExecStopPost=+$APPPROC_RUNNER --cleanup $user $id"
	echo "Restart=on-failure"
	echo "RestartSec=5"
	echo "TimeoutStopSec=30"
	echo "KillMode=mixed"
	echo "MemoryMax=${memory}M"
	echo "MemorySwapMax=0"
	echo "CPUQuota=${hundredths}%"
	echo "TasksMax=$APPPROC_TASKS_MAX"
	echo "NoNewPrivileges=yes"
	echo "PrivateTmp=yes"
	echo "ProtectSystem=full"
	echo "ProtectKernelTunables=yes"
	echo "ProtectKernelModules=yes"
	echo "ProtectControlGroups=yes"
	echo "RestrictSUIDSGID=yes"
	echo "LimitNOFILE=65536"
	if [ "$PORT_REGISTRY" != 'no' ]; then
		for range in $ranges; do
			echo "SocketBindAllow=$range"
		done
		echo "SocketBindDeny=any"
	fi
	echo ""
	echo "[Install]"
	echo "WantedBy=multi-user.target"
}

# Write the run file and the unit of USER's record ID; reload systemd when
# the unit changed. Returns E_INVALID when the command does not split.
appproc_files_write() {
	local user="$1" id="$2" run unit json text dir
	dir="$APPPROC_RUN_DIR/$user"
	json=$(appproc_run_json "$user" "$id") || return "$E_INVALID"
	text=$(appproc_unit_text "$user" "$id")
	mkdir -p "$APPPROC_RUN_DIR"
	chmod 711 "$APPPROC_RUN_DIR"
	if [ -L "$dir" ]; then
		rm -f "$dir"
	fi
	mkdir -p "$dir"
	chown "root:$user" "$dir"
	chmod 750 "$dir"
	run=$(appproc_run_file "$user" "$id")
	printf '%s\n' "$json" > "$run.tmp"
	chown "root:$user" "$run.tmp"
	chmod 640 "$run.tmp"
	mv -f "$run.tmp" "$run"
	unit=$(appproc_unit_file "$user" "$id")
	if [ ! -f "$unit" ] || [ "$(cat "$unit")" != "$text" ]; then
		printf '%s\n' "$text" > "$unit.tmp"
		chmod 644 "$unit.tmp"
		mv -f "$unit.tmp" "$unit"
		systemctl daemon-reload > /dev/null 2>&1
	fi
	appproc_copy_write "$user" "$id"
}

# IDs of the unit files of USER, whether or not a record exists
appproc_unit_ids() {
	local user="$1" unit rest
	for unit in "$APPPROC_UNIT_DIR"/arcpanel-app-"$user"-*.service; do
		[ -e "$unit" ] || continue
		rest=${unit##*/arcpanel-app-"$user"-}
		if [[ "$rest" =~ ^([0-9]{1,6})\.service$ ]]; then
			echo "${BASH_REMATCH[1]}"
		fi
	done
}

# Remove the unit, the run file and the prepared environment of USER's ID
appproc_files_remove() {
	local user="$1" id="$2" unit
	unit=$(appproc_unit "$user" "$id")
	systemctl disable --now "$unit" > /dev/null 2>&1
	systemctl reset-failed "$unit" > /dev/null 2>&1
	rm -f "$(appproc_unit_file "$user" "$id")" "$(appproc_run_file "$user" "$id")" "$(appproc_env_file "$user" "$id")"
	rmdir "$APPPROC_RUN_DIR/$user" 2> /dev/null
	systemctl daemon-reload > /dev/null 2>&1
	return 0
}

# Per-user logrotate file for the logs of every process
appproc_logrotate_write() {
	local user="$1"
	if [ "$(appproc_count "$user")" -eq 0 ]; then
		rm -f "/etc/logrotate.d/arcpanel-app-$user"
		return 0
	fi
	cat > "/etc/logrotate.d/arcpanel-app-$user" <<- EOF
		# ArcPanel: logs of the app processes of $user (E42)
		$HOMEDIR/$user/web/*/app-process/logs/*.log {
			su $user $user
			size 10M
			rotate 5
			copytruncate
			compress
			delaycompress
			missingok
			notifempty
		}
	EOF
	chmod 644 "/etc/logrotate.d/arcpanel-app-$user"
}

#----------------------------------------------------------#
#                       Environment                        #
#----------------------------------------------------------#

# Parse an environment file CONTENT (KEY=value lines; blank lines and #
# comments skipped; matching outer quotes removed) into KEY<TAB>VALUE lines.
# Returns 1 with APPPROC_ENV_REASON on a line that is not KEY=value.
appproc_env_parse() {
	local content="$1" line key value n=0
	APPPROC_ENV_REASON=''
	while IFS= read -r line || [ -n "$line" ]; do
		n=$((n + 1))
		line=${line%$'\r'}
		[[ "$line" =~ ^[[:space:]]*(#.*)?$ ]] && continue
		if ! [[ "$line" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]]; then
			APPPROC_ENV_REASON="line $n is not KEY=value"
			return 1
		fi
		key=${BASH_REMATCH[2]}
		value=${BASH_REMATCH[3]}
		value="${value#"${value%%[![:space:]]*}"}"
		value="${value%"${value##*[![:space:]]}"}"
		if [ "${#value}" -gt 1 ] && { [ "${value:0:1}" = '"' ] || [ "${value:0:1}" = "'" ]; } && [ "${value: -1}" = "${value:0:1}" ]; then
			value=${value:1:-1}
		fi
		if appproc_has_control "$value"; then
			APPPROC_ENV_REASON="$key has a control character"
			return 1
		fi
		printf '%s\t%s\n' "$key" "$value"
	done <<< "$content"
}

# The environment CONTENT of a process of USER as a JSON object, with
# {{secret:NAME}} references resolved (E38). PORT and HOST are left out:
# the runner sets them. Returns an error code with APPPROC_ENV_REASON.
appproc_env_to_json() {
	local user="$1" content="$2" parsed key value resolved rc lines=''
	parsed=$(appproc_env_parse "$content") || return "$E_INVALID"
	while IFS=$'\t' read -r key value; do
		[ -n "$key" ] || continue
		if [ "$key" = 'PORT' ] || [ "$key" = 'HOST' ]; then
			continue
		fi
		if [[ "$value" == *'{{secret:'* ]]; then
			if ! secret_store_ready; then
				APPPROC_ENV_REASON="the secret store is not set up"
				return "$E_NOTEXIST"
			fi
			secret_resolve_into "$user" "$value" resolved
			rc=$?
			if [ "$rc" -ne 0 ]; then
				APPPROC_ENV_REASON="$key: $SECRET_REASON"
				return "$rc"
			fi
			value=$resolved
		fi
		lines+="$key"$'\t'"$value"$'\n'
	done <<< "$parsed"
	printf '%s' "$lines" | jq -Rn '[inputs | capture("^(?<k>[^\t]*)\t(?<v>.*)$") | { (.k): .v }] | add // {}'
}

#----------------------------------------------------------#
#                    Running the processes                 #
#----------------------------------------------------------#

# Start (or restart) USER's process ID; prints the reason on failure
appproc_start() {
	local user="$1" id="$2" unit
	unit=$(appproc_unit "$user" "$id")
	systemctl reset-failed "$unit" > /dev/null 2>&1
	systemctl enable "$unit" > /dev/null 2>&1
	if ! systemctl restart "$unit" > /dev/null 2>&1; then
		echo "the app process could not be started; see its log"
		return "$E_RESTART"
	fi
	return 0
}

appproc_stop() {
	local unit
	unit=$(appproc_unit "$1" "$2")
	systemctl disable --now "$unit" > /dev/null 2>&1
	systemctl reset-failed "$unit" > /dev/null 2>&1
	return 0
}

# systemd state of USER's process ID as KEY=VALUE lines
appproc_show() {
	systemctl show "$(appproc_unit "$1" "$2")" \
		-p LoadState,ActiveState,SubState,MainPID,MemoryCurrent,CPUUsageNSec,NRestarts,ActiveEnterTimestampMonotonic 2> /dev/null
}

# Status of a record from the record and the systemd state SHOW
appproc_status_of() {
	local line="$1" show="$2" active load
	if [ "$(appproc_field "$line" SUSPENDED)" = 'yes' ]; then
		echo suspended
		return 0
	fi
	load=$(sed -n 's/^LoadState=//p' <<< "$show")
	active=$(sed -n 's/^ActiveState=//p' <<< "$show")
	if [ "$load" != 'loaded' ]; then
		echo missing
		return 0
	fi
	case "$active" in
		active | reloading) echo running ;;
		activating) echo starting ;;
		failed) echo failed ;;
		deactivating) echo stopping ;;
		*) echo stopped ;;
	esac
}

appproc_status() {
	appproc_status_of "$(appproc_line "$1" "$2")" "$(appproc_show "$1" "$2")"
}

# Exit unless USER's DOMAIN has a process NAME; with MODE active (default)
# also unless it and the user's package allow running it. Sets APPPROC_ID.
appproc_check_process() {
	local user="$1" domain="$2" name="$3" mode="${4:-active}"
	if ! appproc_name_valid "$name"; then
		check_result "$E_INVALID" "invalid name :: $name"
	fi
	APPPROC_ID=$(appproc_id "$user" "$domain" "$name")
	if [ -z "$APPPROC_ID" ]; then
		check_result "$E_NOTEXIST" "$domain has no app process named $name"
	fi
	if [ "$mode" = 'active' ]; then
		appproc_check_enabled_package "$user"
		if [ "$(appproc_get "$user" "$APPPROC_ID" SUSPENDED)" = 'yes' ]; then
			check_result "$E_SUSPENDED" "the app process $name of $domain is suspended"
		fi
	fi
}

# Whether the unit of USER's process ID is running or starting
appproc_running() {
	case "$(appproc_status "$1" "$2")" in
		running | starting) return 0 ;;
	esac
	return 1
}

# Rewrite the files of USER's process ID and start it, with the port check
# of a web process. Prints the reason on failure.
appproc_launch() {
	local user="$1" id="$2" port holder
	if ! appproc_files_write "$user" "$id"; then
		echo "the command of the app process could not be written"
		return "$E_INVALID"
	fi
	port=$(appproc_get "$user" "$id" PORT)
	if [ -n "$port" ] && ! appproc_running "$user" "$id"; then
		holder=$(appproc_port_holder "$port")
		if [ -n "$holder" ]; then
			echo "port $port is used by another process ($holder)"
			return "$E_INUSE"
		fi
	fi
	appproc_start "$user" "$id" || return $?
	appproc_set "$user" "$id" STATE=running
}

# Apply a changed setting of USER's process ID: restart it when it runs (or
# failed), else only rewrite its files
appproc_apply() {
	local out status
	status=$(appproc_status "$1" "$2")
	if [ "$status" = 'running' ] || [ "$status" = 'starting' ] || [ "$status" = 'failed' ]; then
		out=$(appproc_launch "$1" "$2")
		check_result $? "$out"
	else
		appproc_files_write "$1" "$2"
		check_result $? "the command of the app process could not be written" "$E_INVALID"
	fi
}

# Owner of a socket listening on PORT: prints "pid/process", empty when free
appproc_port_holder() {
	local line
	line=$(ss -Hltnp "sport = :$1" 2> /dev/null | head -n1)
	if [ -n "$line" ]; then
		grep -oE 'users:\(\("[^"]*",pid=[0-9]+' <<< "$line" | head -n1 | sed -E 's/users:\(\("([^"]*)",pid=([0-9]+)/\2\/\1/'
	fi
}

#----------------------------------------------------------#
#                Persistence and convergence               #
#----------------------------------------------------------#

# Whether the web domain of USER is suspended
appproc_domain_suspended() {
	[ "$(arc_tpl_domain_value "$1" "$2" SUSPENDED)" = 'yes' ]
}

# Whether USER's process ID should run: started by the user, and neither
# the record, the user nor the domain is suspended
appproc_should_run() {
	local user="$1" id="$2" line
	line=$(appproc_line "$user" "$id")
	[ "$(appproc_field "$line" STATE)" = 'running' ] \
		&& [ "$(appproc_field "$line" SUSPENDED)" != 'yes' ] \
		&& [ "$(tenancy_user_value "$user" SUSPENDED)" != 'yes' ] \
		&& ! appproc_domain_suspended "$user" "$(appproc_field "$line" DOMAIN)"
}

# Names of the copies (NAME.conf) in the folder of USER's DOMAIN that have
# no record, read as the user
appproc_copy_names() {
	local user="$1" domain="$2" dir name
	dir=$(appproc_dir "$user" "$domain")
	node_as_user "$user" bash -c '[ -d "$1" ] && [ ! -L "$1" ] && find "$1" -mindepth 1 -maxdepth 1 -type f -name "*.conf" -printf "%f\n"' copies "$dir" 2> /dev/null \
		| sed -n 's/\.conf$//p' | while read -r name; do
		if appproc_name_valid "$name" && [ -z "$(appproc_id "$user" "$domain" "$name")" ]; then
			echo "$name"
		fi
	done
}

# The copy line of NAME of USER's DOMAIN, read as the user
appproc_copy_line() {
	node_as_user "$1" bash -c '[ -f "$1" ] && [ ! -L "$1" ] && head -c 8192 "$1"' conf "$(appproc_dir "$1" "$2")/$3.conf" 2> /dev/null \
		| grep -m1 -F " DOMAIN='$2' NAME='$3' "
}

# Validate a copy LINE of NAME of USER's DOMAIN for an import (spec §6.3).
# Prints the reason and returns 1 when it cannot be imported; otherwise
# prints the KEY=VALUE pairs of the record (without ID and PORT).
appproc_copy_check() {
	local user="$1" domain="$2" name="$3" line="$4" preset kind start cwd memory cpus template prev max limit
	preset=$(appproc_field "$line" PRESET)
	kind=$(appproc_field "$line" KIND)
	cwd=$(appproc_field "$line" CWD)
	memory=$(appproc_field "$line" MEMORY)
	cpus=$(appproc_field "$line" CPUS)
	template=$(appproc_field "$line" TEMPLATE)
	prev=$(appproc_field "$line" PREV_TPL)
	start=$(appproc_start_of "$line")
	if [ "$(appproc_pkg "$user" APP_PROCESSES)" != 'yes' ]; then
		echo "the package does not allow app processes"
		return 1
	fi
	limit=$(appproc_limit "$user")
	if [ "$limit" != 'unlimited' ] && [ "$(appproc_count "$user")" -ge "$limit" ]; then
		echo "the package allows $limit app processes"
		return 1
	fi
	if ! appproc_preset_exists "$preset"; then
		echo "unknown preset $preset"
		return 1
	fi
	if [ "$(appproc_preset_get "$preset" KIND)" != 'either' ]; then
		kind=$(appproc_preset_get "$preset" KIND)
		template=$(appproc_preset_get "$preset" TEMPLATE)
	fi
	case "$kind" in
		web)
			if [ -n "$(appproc_web_id "$user" "$domain")" ]; then
				echo "the domain has a web process already"
				return 1
			fi
			if ! appproc_tpl_has_port "$template"; then
				echo "template $template has no port"
				return 1
			fi
			;;
		worker) template='' ;;
		*)
			echo "invalid kind"
			return 1
			;;
	esac
	if [ -z "$start" ] || [ "${#start}" -gt "$APPPROC_MAX_START" ] || appproc_has_control "$start" || ! appproc_split "$start"; then
		echo "invalid command"
		return 1
	fi
	if ! appproc_relpath_valid "$cwd" || ! appproc_inside "$user" "$domain" "$(appproc_cwd_path "$user" "$domain" "$cwd")" \
		|| ! node_as_user "$user" test -d "$(appproc_cwd_path "$user" "$domain" "$cwd")"; then
		echo "invalid working directory"
		return 1
	fi
	max=$(appproc_max_memory "$user")
	if ! [[ "$memory" =~ ^[0-9]{1,6}$ ]] || [ "$memory" -lt "$APPPROC_MIN_MEMORY" ]; then
		memory=$(appproc_default_memory "$user")
	elif [ "$memory" -gt "$max" ]; then
		memory=$max
	fi
	limit=$(appproc_max_cpus "$user")
	if ! appproc_cpus_hundredths "$cpus" > /dev/null || { [ "$limit" != 'unlimited' ] && tenancy_limit_above DOCKER_CPUS "$cpus" "$limit"; }; then
		cpus=$limit
		if [ "$cpus" = 'unlimited' ]; then
			cpus=1
		fi
	fi
	if [ "$kind" = 'web' ] && ! appproc_tpl_exists "$prev"; then
		prev=$(appproc_package_template "$user")
	fi
	printf '%s\n' "DOMAIN=$domain" "NAME=$name" "KIND=$kind" "PRESET=$preset" "START=$(appproc_encode "$start")" \
		"CWD=$cwd" "MEMORY=$memory" "CPUS=$cpus" "TEMPLATE=$template" "PREV_TPL=$prev" \
		"STATE=$(appproc_field "$line" STATE | grep -xE 'running|stopped' || echo running)" SUSPENDED=no \
		"TIME=$(appproc_field "$line" TIME)" "DATE=$(appproc_field "$line" DATE)"
}

# Make the port of USER's web process ID an app:DOMAIN port of the user's
# pool and the BACKEND_PORT of the domain, and the domain's template the
# process's: the recorded port when it can be kept (a restored user gets it
# back from the server range), else a new one. Returns an error code with
# the reason on failure.
appproc_port_ensure() {
	local user="$1" id="$2" domain port app out template
	domain=$(appproc_get "$user" "$id" DOMAIN)
	port=$(appproc_get "$user" "$id" PORT)
	if [ -n "$port" ]; then
		app=$(ports_record "$user" "$port" | cut -d'|' -f4)
		if [ "$app" != "app:$domain" ] && ! "$BIN/v-allocate-user-port" "$user" "app:$domain" tcp "$port" > /dev/null 2>&1; then
			if ! "$BIN/v-add-user-port" "$user" "$port" no restored > /dev/null 2>&1 \
				|| ! "$BIN/v-allocate-user-port" "$user" "app:$domain" tcp "$port" > /dev/null 2>&1; then
				port=''
			fi
		fi
	fi
	if [ -z "$port" ]; then
		port=$(ports_read "$user" | awk -F'|' -v app="app:$domain" '$4 == app { print $1; exit }')
	fi
	if [ -z "$port" ]; then
		out=$("$BIN/v-allocate-user-port" "$user" "app:$domain" 2>&1) || {
			echo "no free port in the pool of $user"
			return "$E_LIMIT"
		}
		port=$(printf '%s\n' "$out" | tail -n1)
	fi
	appproc_set "$user" "$id" "PORT=$port"
	template=$(appproc_get "$user" "$id" TEMPLATE)
	if [ "$(arc_tpl_active "$user" "$domain")" != "$template" ]; then
		appproc_tpl_switch "$user" "$domain" "$template" > /dev/null 2>&1 || {
			echo "template $template could not be applied to $domain"
			return "$E_RESTART"
		}
	fi
	if [ "$(arc_tplvars_get "$user" "$domain" BACKEND_PORT)" != "$port" ]; then
		"$BIN/v-change-web-domain-tpl-vars" "$user" "$domain" "BACKEND_PORT=$port" > /dev/null 2>&1 || {
			echo "the port of $domain could not be set"
			return "$E_RESTART"
		}
	elif ! grep -q "127\.0\.0\.1:$port;" "$HOMEDIR/$user/conf/web/$domain/nginx.conf" 2> /dev/null; then
		# Rendered before the port was registered again (a restore)
		"$BIN/v-rebuild-web-domain" "$user" "$domain" > /dev/null 2>&1 || return "$E_RESTART"
	fi
	return 0
}

# Remove USER's process ID: unit, record, copy, and for a web process its
# port and the domain's template (when the domain still exists)
appproc_delete() {
	local user="$1" id="$2" line domain name prev
	line=$(appproc_line "$user" "$id")
	[ -n "$line" ] || return 0
	domain=$(appproc_field "$line" DOMAIN)
	name=$(appproc_field "$line" NAME)
	appproc_files_remove "$user" "$id"
	appproc_remove "$user" "$id"
	if node_as_user "$user" test -f "$(appproc_dir "$user" "$domain")/$name.conf" 2> /dev/null; then
		node_as_user "$user" rm -f -- "$(appproc_dir "$user" "$domain")/$name.conf"
	fi
	if [ "$(appproc_field "$line" KIND)" = 'web' ]; then
		if ports_read "$user" | awk -F'|' -v app="app:$domain" '$4 == app { found = 1 } END { exit found ? 0 : 1 }'; then
			"$BIN/v-release-user-port" "$user" "app:$domain" > /dev/null 2>&1
		fi
		prev=$(appproc_field "$line" PREV_TPL)
		# The domain may be gone already (v-delete-user-app-processes runs
		# before the user is deleted, the check after a domain is deleted)
		if arc_tpl_domain_exists "$user" "$domain"; then
			if ! appproc_tpl_exists "$prev"; then
				prev=$(appproc_package_template "$user")
			fi
			if [ "$(arc_tpl_active "$user" "$domain")" = "$(appproc_field "$line" TEMPLATE)" ]; then
				appproc_tpl_switch "$user" "$domain" "$prev" yes > /dev/null 2>&1
			fi
			"$BIN/v-delete-web-domain-tpl-vars" "$user" "$domain" > /dev/null 2>&1
		fi
	fi
	return 0
}

#----------------------------------------------------------#
#                     One-off tasks                        #
#----------------------------------------------------------#

APPPROC_TASKS="composer-install migrate pip-install bundle-install custom"
APPPROC_TASK_KEYS="ID DOMAIN NAME TASK STATUS PID EXIT START END"
APPPROC_TASK_TIMEOUT=1800

appproc_task_conf() {
	echo "$ARCPANEL/data/users/$1/app-process-task.conf"
}

# The transient unit that runs USER's one-off task (E39 W5 N24)
appproc_task_unit() {
	echo "arcpanel-app-task-$1.service"
}

# The unit properties of a task of USER's record ID, one -p argument pair per
# line: the memory, CPU and task limits of the process, its sandbox and port
# rules, and the task timeout
appproc_task_properties() {
	local user="$1" id="$2" line hundredths range
	line=$(appproc_line "$user" "$id")
	hundredths=$(appproc_cpus_hundredths "$(appproc_field "$line" CPUS)") || hundredths=100
	printf -- '-p\n%s\n' "MemoryMax=$(appproc_field "$line" MEMORY)M" MemorySwapMax=0 "CPUQuota=${hundredths}%" \
		"TasksMax=$APPPROC_TASKS_MAX" "RuntimeMaxSec=$APPPROC_TASK_TIMEOUT" Nice=10 KillMode=control-group \
		NoNewPrivileges=yes PrivateTmp=yes ProtectSystem=full ProtectKernelTunables=yes ProtectKernelModules=yes \
		ProtectControlGroups=yes RestrictSUIDSGID=yes
	if [ "$PORT_REGISTRY" != 'no' ]; then
		for range in $(appproc_bind_ranges "$user"); do
			printf -- '-p\n%s\n' "SocketBindAllow=$range"
		done
		printf -- '-p\n%s\n' SocketBindDeny=any
	fi
}

# Stop the one-off task of USER with every process it started
appproc_task_stop() {
	systemctl stop "$(appproc_task_unit "$1")" > /dev/null 2>&1 || true
}

appproc_task_get() {
	local file
	file=$(appproc_task_conf "$1")
	if [ -f "$file" ]; then
		appproc_field "$(head -n1 "$file")" "$2"
	fi
}

# Set KEY=VALUE pairs in the task record of USER; the other keys keep their
# values
appproc_task_set() {
	local user="$1" file pair key line='' tmp
	local -A values=()
	shift
	for key in $APPPROC_TASK_KEYS; do
		values[$key]=$(appproc_task_get "$user" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $APPPROC_TASK_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	file=$(appproc_task_conf "$user")
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
}

# Whether the task process PID is alive and still the task runner
appproc_task_alive() {
	local pid="$1"
	[[ "$pid" =~ ^[0-9]+$ ]] && [ -r "/proc/$pid/cmdline" ] \
		&& tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'v-run-app-process-task'
}

# Whether a task of USER runs; a running record whose process is gone is
# marked failed first
appproc_task_running() {
	if [ "$(appproc_task_get "$1" STATUS)" = 'running' ]; then
		if appproc_task_alive "$(appproc_task_get "$1" PID)"; then
			return 0
		fi
		appproc_task_set "$1" STATUS=failed "END=$(date +'%F %T')"
	fi
	return 1
}

# The composer of USER: the user's own (v-add-user-composer), else the
# server's; empty when there is none
appproc_composer() {
	local file
	for file in "$HOMEDIR/$1/.composer/composer" /usr/local/bin/composer /usr/bin/composer; do
		if node_as_user "$1" test -x "$file" 2> /dev/null; then
			echo "$file"
			return 0
		fi
	done
}

# The command of TASK for USER's process ID (CUSTOM for custom) into
# APPPROC_ARGS; prints the reason and returns 1 when it cannot run
appproc_task_args() {
	local user="$1" id="$2" task="$3" custom="$4" line domain cwd dir version composer python
	line=$(appproc_line "$user" "$id")
	domain=$(appproc_field "$line" DOMAIN)
	cwd=$(appproc_field "$line" CWD)
	dir=$(appproc_cwd_path "$user" "$domain" "$cwd")
	version=$(appproc_php_version "$user" "$domain")
	APPPROC_ARGS=()
	case "$task" in
		composer-install)
			composer=$(appproc_composer "$user")
			if [ -z "$composer" ]; then
				echo "composer is not installed for $user (v-add-user-composer)"
				return 1
			fi
			if ! node_as_user "$user" test -f "$dir/composer.json"; then
				echo "composer.json does not exist in $cwd"
				return 1
			fi
			APPPROC_ARGS=("/usr/bin/php$version" "$composer" install --no-dev --optimize-autoloader --no-interaction --no-progress)
			;;
		migrate)
			if ! node_as_user "$user" test -f "$dir/artisan"; then
				echo "artisan does not exist in $cwd"
				return 1
			fi
			APPPROC_ARGS=("/usr/bin/php$version" artisan migrate --force --no-interaction)
			;;
		pip-install)
			python=$(appproc_python_dir "$user" "$dir")
			if [ "$python" = '/usr/bin' ]; then
				echo "create a virtual environment first (python3 -m venv .venv in $cwd)"
				return 1
			fi
			if ! node_as_user "$user" test -f "$dir/requirements.txt"; then
				echo "requirements.txt does not exist in $cwd"
				return 1
			fi
			APPPROC_ARGS=("$python/pip" install --disable-pip-version-check -r requirements.txt)
			;;
		bundle-install)
			if ! node_as_user "$user" test -f "$dir/Gemfile"; then
				echo "Gemfile does not exist in $cwd"
				return 1
			fi
			APPPROC_ARGS=(env BUNDLE_PATH=vendor/bundle BUNDLE_DEPLOYMENT=true bundle install)
			;;
		custom)
			if [ -z "$custom" ] || appproc_has_control "$custom" || [ "${#custom}" -gt "$APPPROC_MAX_START" ] || ! appproc_split "$custom"; then
				echo "invalid command"
				return 1
			fi
			;;
		*)
			echo "invalid task :: $task ($APPPROC_TASKS)"
			return 1
			;;
	esac
	return 0
}

#----------------------------------------------------------#
#                         Output                           #
#----------------------------------------------------------#

# USER's processes as JSON objects, one per line, with their live state;
# with DOMAIN only that domain's
appproc_rows_json() {
	local user="$1" domain="$2" id line show uptime_us now_us
	now_us=$(awk '{ printf "%d", $1 * 1000000 }' /proc/uptime)
	for id in $(appproc_ids "$user" "$domain"); do
		line=$(appproc_line "$user" "$id")
		show=$(appproc_show "$user" "$id")
		jq -nc --arg line "$line" --arg show "$show" --arg status "$(appproc_status_of "$line" "$show")" \
			--arg start "$(appproc_start_of "$line")" --arg now "$now_us" \
			--arg unit "$(appproc_unit "$user" "$id")" '
			($line | [scan("([A-Z_]+)='"'"'([^'"'"']*)'"'"'")] | map({ key: .[0], value: .[1] }) | from_entries) as $r
			| ($show | split("\n") | map(select(contains("=")) | { key: (split("=")[0]), value: (split("=")[1:] | join("=")) }) | from_entries) as $s
			| def num: if . == null or . == "" or . == "[not set]" or (. | test("^[0-9]+$") | not) then 0 else tonumber end;
			$r + {
				START: $start,
				UNIT: $unit,
				STATUS: $status,
				PID: (($s.MainPID | num) | if . == 0 then "" else tostring end),
				MEMORY_USED: ((($s.MemoryCurrent | num) / 1048576 | floor) | tostring),
				CPU_SECONDS: ((($s.CPUUsageNSec | num) / 1000000000 | floor) | tostring),
				RESTARTS: (($s.NRestarts | num) | tostring),
				UPTIME: (if $status == "running" and ($s.ActiveEnterTimestampMonotonic | num) > 0
					then ((($now | tonumber) - ($s.ActiveEnterTimestampMonotonic | num)) / 1000000 | floor | if . < 0 then 0 else . end | tostring)
					else "0" end)
			}'
	done
}
