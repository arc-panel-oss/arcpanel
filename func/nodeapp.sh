#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel Node.js app functions: apps bound to web domains, run by a pm2   #
# daemon of each user under systemd --user (E14)                            #
#                                                                           #
#   $HESTIA/data/users/<user>/nodeapps.conf  the app records (one per line) #
#   $HESTIA/data/users/<user>/pm2.conf       runtime and version of pm2     #
#   ~/web/<domain>/nodeapp/                  app.conf (copy for backups),   #
#                                            ecosystem.config.cjs, .env,    #
#                                            logs/                          #
#   ~/.config/systemd/user/arcpanel-pm2.service, ~/.pm2/                    #
#   /etc/logrotate.d/arcpanel-node-<user>                                   #
# Everything in a home directory is read and written as the user.          #
# See docs/features/specs/2026-10-05-007-e14-node-apps-design.md.           #
#                                                                           #
#===========================================================================#

# shellcheck source=/usr/local/hestia/func/node.sh
source "$HESTIA/func/node.sh"
# shellcheck source=/usr/local/hestia/func/arc-template.sh
source "$HESTIA/func/arc-template.sh"

# pm2 release installed when a user has none
NODEAPP_PM2_VERSION="7.0.4"

NODEAPP_KEYS="DOMAIN NAME START CWD NODE_VERSION PORT INSTANCES MAX_MEMORY TEMPLATE PREV_TPL SUSPENDED TIME DATE"
NODEAPP_UNIT="arcpanel-pm2"
NODEAPP_UNIT_SOURCE="$HESTIA/install/common/node/arcpanel-pm2.service"
NODEAPP_DEFAULT_TEMPLATE="nodejs-proxy"
NODEAPP_DEFAULT_CWD="public_html"

# Default and highest memory limit of an app in MB when the package has none
NODEAPP_DEFAULT_MEMORY=512
NODEAPP_MAX_MEMORY=8192

#----------------------------------------------------------#
#                        Records                           #
#----------------------------------------------------------#

nodeapp_conf() {
	echo "$HESTIA/data/users/$1/nodeapps.conf"
}

# Serialise the writers of USER's records. Nested commands (hooks, internal
# calls) run inside the caller's lock.
nodeapp_lock() {
	if [ "$ARC_NODEAPPS_LOCKED" = "$1" ]; then
		return 0
	fi
	exec {NODEAPP_LOCK_FD}> "$HESTIA/data/users/$1/nodeapps.lock"
	if ! flock -w 120 "$NODEAPP_LOCK_FD"; then
		check_result "$E_LA" "the Node.js apps of $1 are locked by another command"
	fi
	export ARC_NODEAPPS_LOCKED="$1"
}

# The record line of USER's DOMAIN, empty when there is none
nodeapp_line() {
	local file
	file=$(nodeapp_conf "$1")
	if [ -f "$file" ]; then
		grep -m1 "^DOMAIN='$2' " "$file"
	fi
}

nodeapp_exists() {
	[ -n "$(nodeapp_line "$1" "$2")" ]
}

# Value of KEY in a record LINE
nodeapp_field() {
	grep -oE "(^| )$2='[^']*'" <<< "$1" | head -n1 | cut -d\' -f2
}

nodeapp_get() {
	nodeapp_field "$(nodeapp_line "$1" "$2")" "$3"
}

# Domains of USER's apps, in record order
nodeapp_domains() {
	local file
	file=$(nodeapp_conf "$1")
	if [ -f "$file" ]; then
		grep -oE "^DOMAIN='[^']*'" "$file" | cut -d\' -f2
	fi
}

nodeapp_count() {
	nodeapp_domains "$1" | grep -c .
}

# Build a record line from KEY=VALUE pairs on top of LINE (may be empty).
# Quotes and line breaks are dropped from values.
nodeapp_compose() {
	local line="$1" pair key out=''
	local -A values=()
	shift
	for key in $NODEAPP_KEYS; do
		values[$key]=$(nodeapp_field "$line" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $NODEAPP_KEYS; do
		out="$out$key='${values[$key]}' "
	done
	echo "${out% }"
}

# Create or update the record of USER's DOMAIN with KEY=VALUE pairs
nodeapp_set() {
	local user="$1" domain="$2" file line tmp
	shift 2
	file=$(nodeapp_conf "$user")
	line=$(nodeapp_compose "$(nodeapp_line "$user" "$domain")" "DOMAIN=$domain" "$@")
	tmp="$file.$$"
	{
		if [ -f "$file" ]; then
			grep -v "^DOMAIN='$domain' " "$file"
		fi
		echo "$line"
	} > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
}

nodeapp_remove() {
	local user="$1" domain="$2" file tmp
	file=$(nodeapp_conf "$user")
	if [ ! -f "$file" ]; then
		return 0
	fi
	tmp="$file.$$"
	grep -v "^DOMAIN='$domain' " "$file" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
	if [ ! -s "$file" ]; then
		rm -f "$file"
	fi
}

#----------------------------------------------------------#
#                    Paths and validation                  #
#----------------------------------------------------------#

nodeapp_domain_dir() {
	echo "$HOMEDIR/$1/web/$2"
}

nodeapp_dir() {
	echo "$HOMEDIR/$1/web/$2/nodeapp"
}

# pm2 process name
nodeapp_name_valid() {
	[[ "$1" =~ ^[a-z0-9][a-z0-9._-]{0,39}$ ]]
}

# Default process name of DOMAIN
nodeapp_default_name() {
	local name
	name=$(printf '%s' "$1" | tr 'A-Z' 'a-z' | tr -c 'a-z0-9._-' '-' | cut -c1-40)
	echo "${name%%-}"
}

# A relative path without "..", or "." for the folder itself
nodeapp_relpath_valid() {
	local path="$1"
	[ "${#path}" -le 200 ] || return 1
	[ "$path" = '.' ] && return 0
	[[ "$path" =~ ^[A-Za-z0-9_][A-Za-z0-9._/@+-]*$ ]] || return 1
	[[ "/$path/" != *"/../"* ]] && [[ "/$path/" != *"/./"* ]] && [[ "$path" != *"//"* ]]
}

# START: a file relative to CWD, or npm:<script> for a package.json script
nodeapp_start_valid() {
	if [[ "$1" == npm:* ]]; then
		[[ "${1#npm:}" =~ ^[A-Za-z0-9][A-Za-z0-9:._-]{0,63}$ ]]
	else
		nodeapp_relpath_valid "$1" && [ "$1" != '.' ]
	fi
}

# Absolute working directory of an app
nodeapp_cwd_path() {
	local dir
	dir=$(nodeapp_domain_dir "$1" "$2")
	if [ "$3" = '.' ]; then
		echo "$dir"
	else
		echo "$dir/$3"
	fi
}

# Exit with E_FORBIDEN unless PATH, with its symlinks resolved as the user,
# stays inside the domain directory of USER's DOMAIN
nodeapp_check_inside() {
	local user="$1" domain="$2" path="$3" real base
	base=$(nodeapp_domain_dir "$user" "$domain")
	real=$(node_as_user "$user" readlink -m "$path" 2> /dev/null)
	case "$real" in
		"$base" | "$base"/*) ;;
		*) check_result "$E_FORBIDEN" "path outside of the directory of $domain ($path)" ;;
	esac
}

# Exit unless START exists for an app of USER's DOMAIN in CWD
nodeapp_check_start() {
	local user="$1" domain="$2" cwd="$3" start="$4" dir script
	dir=$(nodeapp_cwd_path "$user" "$domain" "$cwd")
	nodeapp_check_inside "$user" "$domain" "$dir"
	if ! node_as_user "$user" test -d "$dir"; then
		check_result "$E_NOTEXIST" "working directory $cwd does not exist in $domain"
	fi
	if [[ "$start" == npm:* ]]; then
		script=${start#npm:}
		if ! node_as_user "$user" cat "$dir/package.json" 2> /dev/null | jq -e --arg s "$script" '.scripts[$s] | type == "string"' > /dev/null 2>&1; then
			check_result "$E_NOTEXIST" "package.json in $cwd has no script \"$script\""
		fi
		return 0
	fi
	nodeapp_check_inside "$user" "$domain" "$dir/$start"
	if ! node_as_user "$user" test -f "$dir/$start"; then
		check_result "$E_NOTEXIST" "start file $start does not exist in $cwd"
	fi
}

# Installed runtime (X.Y.Z) for VERSION (X.Y.Z or default) of USER; returns
# 1 when there is none
nodeapp_runtime() {
	local user="$1" version="${2#v}" resolved
	if [ -z "$version" ] || [ "$version" = 'default' ]; then
		resolved=$(node_default "$user")
	else
		resolved="$version"
	fi
	if [ -n "$resolved" ] && node_version_installed "$user" "$resolved"; then
		echo "$resolved"
		return 0
	fi
	return 1
}

#----------------------------------------------------------#
#                         Limits                           #
#----------------------------------------------------------#

# NODE_APPS of USER's package (integer or unlimited)
nodeapp_limit() {
	local value
	value=$(node_pkg_key "$1" NODE_APPS)
	echo "${value:-3}"
}

# Exit with E_LIMIT when USER cannot have another app
nodeapp_check_limit() {
	local limit
	limit=$(nodeapp_limit "$1")
	if [ "$limit" != 'unlimited' ] && [ "$(nodeapp_count "$1")" -ge "$limit" ]; then
		check_result "$E_LIMIT" "the package of $1 allows $limit Node.js apps (NODE_APPS)"
	fi
}

nodeapp_max_instances() {
	if [[ "$NODE_MAX_INSTANCES" =~ ^[0-9]+$ ]] && [ "$NODE_MAX_INSTANCES" -gt 0 ]; then
		echo "$NODE_MAX_INSTANCES"
	else
		nproc
	fi
}

# MEMORY_LIMIT of USER's package in MB, empty when unlimited
nodeapp_memory_limit() {
	local value number
	value=$(tenancy_pkg_limit "$(node_package_of "$1")" MEMORY_LIMIT)
	if [[ "$value" =~ ^([0-9]+)([KkMmGg]?)$ ]]; then
		number=${BASH_REMATCH[1]}
		case "${BASH_REMATCH[2]}" in
			K | k) echo $((number / 1024)) ;;
			G | g) echo $((number * 1024)) ;;
			*) echo "$number" ;;
		esac
	fi
}

# Default memory limit of a new app of USER in MB
nodeapp_default_memory() {
	local limit apps value="$NODEAPP_DEFAULT_MEMORY"
	limit=$(nodeapp_memory_limit "$1")
	apps=$(nodeapp_limit "$1")
	if [ -n "$limit" ]; then
		if [[ "$apps" =~ ^[0-9]+$ ]] && [ "$apps" -gt 0 ] && [ $((limit / apps)) -lt "$value" ]; then
			value=$((limit / apps))
		elif [ "$limit" -lt "$value" ]; then
			value="$limit"
		fi
	fi
	if [ "$value" -lt 64 ]; then
		value=64
	fi
	echo "$value"
}

# Exit unless INSTANCES and MAX_MEMORY (MB) fit the limits of USER
nodeapp_check_scale() {
	local user="$1" instances="$2" memory="$3" max limit
	max=$(nodeapp_max_instances)
	if ! [[ "$instances" =~ ^[0-9]{1,3}$ ]] || [ "$instances" -lt 1 ]; then
		check_result "$E_INVALID" "invalid instances :: $instances"
	fi
	if [ "$instances" -gt "$max" ]; then
		check_result "$E_LIMIT" "at most $max instances per app (NODE_MAX_INSTANCES)"
	fi
	if ! [[ "$memory" =~ ^[0-9]{1,6}$ ]] || [ "$memory" -lt 64 ]; then
		check_result "$E_INVALID" "invalid memory :: $memory (MB, at least 64)"
	fi
	limit=$(nodeapp_memory_limit "$user")
	if [ -z "$limit" ]; then
		limit="$NODEAPP_MAX_MEMORY"
	fi
	if [ "$memory" -gt "$limit" ]; then
		check_result "$E_LIMIT" "at most $limit MB per app (MEMORY_LIMIT)"
	fi
}

#----------------------------------------------------------#
#                        Templates                         #
#----------------------------------------------------------#

# Whether NAME is a Node.js template (metadata runtime "node") of the
# client-facing kind of this server
nodeapp_tpl_is_node() {
	local kind meta dir
	kind=$(arc_tpl_active_kind)
	[ -n "$kind" ] || return 1
	dir=$(arc_tpl_dir "$kind")
	[ -f "$dir/$1.tpl" ] && [ -f "$dir/$1.stpl" ] || return 1
	meta=$(arc_tpl_meta_file "$kind" "$1")
	[ -n "$meta" ] && [ "$(jq -r '.runtime // "none"' "$meta" 2> /dev/null)" = 'node' ]
}

nodeapp_tpl_exists() {
	local dir
	dir=$(arc_tpl_dir "$(arc_tpl_active_kind)")
	[ -n "$dir" ] && [ -f "$dir/$1.tpl" ]
}

# Client-facing template of USER's package, "default" when it does not exist
nodeapp_package_template() {
	local key=PROXY_TEMPLATE name
	if [ "$(arc_tpl_active_kind)" = 'web' ]; then
		key=WEB_TEMPLATE
	fi
	name=$(tenancy_pkg_limit "$(node_package_of "$1")" "$key")
	if [ -n "$name" ] && nodeapp_tpl_exists "$name"; then
		echo "$name"
	else
		echo default
	fi
}

# Switch the client-facing template of USER's DOMAIN to NAME; RESTART (default
# no) is passed to the upstream command
nodeapp_tpl_switch() {
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
			echo "Node.js apps need NGINX as web server or proxy"
			return "$E_INVALID"
			;;
	esac
}

#----------------------------------------------------------#
#                     The pm2 daemon                       #
#----------------------------------------------------------#

nodeapp_pm2_conf() {
	echo "$HESTIA/data/users/$1/pm2.conf"
}

nodeapp_pm2_runtime() {
	local file
	file=$(nodeapp_pm2_conf "$1")
	if [ -f "$file" ]; then
		grep -m1 "^PM2_RUNTIME=" "$file" | cut -d\' -f2
	fi
}

# pm2 executable of USER in runtime VERSION
nodeapp_pm2_bin() {
	echo "$(node_version_dir "$1" "$2")/lib/node_modules/pm2/bin/pm2"
}

nodeapp_pm2_present() {
	[ -n "$2" ] && node_as_user "$1" test -f "$(nodeapp_pm2_bin "$1" "$2")"
}

nodeapp_unit_file() {
	echo "$(node_home "$1")/.config/systemd/user/$NODEAPP_UNIT.service"
}

# systemctl --user for USER, run as the user against its manager
nodeapp_systemctl() {
	local u="$1" uid
	shift
	uid=$(id -u "$u")
	user="$u" user_exec env -i HOME="$(node_home "$u")" XDG_RUNTIME_DIR="/run/user/$uid" \
		PATH=/usr/bin:/bin systemctl --user "$@"
}

# Make sure pm2 is installed for USER and recorded in pm2.conf. The runtime
# of an existing pm2 is kept while it is installed; otherwise the default
# runtime is used and pm2 is installed into it when missing.
nodeapp_pm2_install() {
	local user="$1" runtime dir version
	runtime=$(nodeapp_pm2_runtime "$user")
	if ! nodeapp_pm2_present "$user" "$runtime"; then
		runtime=$(node_default "$user")
		if [ -z "$runtime" ]; then
			echo "No default Node.js runtime for $user"
			return "$E_NOTEXIST"
		fi
		if ! nodeapp_pm2_present "$user" "$runtime"; then
			dir=$(node_version_dir "$user" "$runtime")
			echo "Installing pm2@$NODEAPP_PM2_VERSION for Node.js v$runtime"
			NODE_RUN_VERSION="$runtime" node_as_user_long "$user" \
				npm install --global --no-fund --no-audit --prefix "$dir" "pm2@$NODEAPP_PM2_VERSION"
			case $? in
				0) ;;
				124 | 137) return "$E_CONNECT" ;;
				*) return "$E_CONNECT" ;;
			esac
			node_outdated_clear "$user" "$runtime"
		fi
	fi
	version=$(node_as_user "$user" jq -r '.version // ""' "$(node_version_dir "$user" "$runtime")/lib/node_modules/pm2/package.json" 2> /dev/null)
	printf "PM2_RUNTIME='%s'\nPM2_VERSION='%s'\n" "$runtime" "$version" > "$(nodeapp_pm2_conf "$user")"
	chmod 640 "$(nodeapp_pm2_conf "$user")"
}

# Render the unit of USER from the shipped template
nodeapp_unit_write() {
	local user="$1" runtime pm2 dir
	runtime=$(nodeapp_pm2_runtime "$user")
	dir=$(node_version_dir "$user" "$runtime")
	pm2=$(nodeapp_pm2_bin "$user" "$runtime")
	node_as_user "$user" mkdir -p "$(dirname "$(nodeapp_unit_file "$user")")" "$(node_home "$user")/.pm2"
	sed -e "s|@RUNTIME@|$dir|g" -e "s|@PM2@|$pm2|g" -e 's/\r$//' "$NODEAPP_UNIT_SOURCE" \
		| node_as_user "$user" bash -c 'umask 022; cat > "$1.tmp" && mv -f "$1.tmp" "$1"' unit "$(nodeapp_unit_file "$user")"
	# resurrect needs a dump; an empty one starts no app
	node_as_user "$user" bash -c 'umask 077; [ -s "$1" ] || echo "[]" > "$1"' dump "$(node_home "$user")/.pm2/dump.pm2"
}

# Start the systemd manager of USER (linger keeps it after logout and boot)
nodeapp_manager_start() {
	local user="$1" uid i
	uid=$(id -u "$user")
	loginctl enable-linger "$user" > /dev/null 2>&1 || return 1
	systemctl start "user@$uid.service" > /dev/null 2>&1 || return 1
	for i in $(seq 1 40); do
		if [ -S "/run/user/$uid/systemd/private" ]; then
			return 0
		fi
		sleep 0.25
	done
	return 1
}

nodeapp_daemon_active() {
	local uid
	uid=$(id -u "$1" 2> /dev/null) || return 1
	[ -S "/run/user/$uid/systemd/private" ] && nodeapp_systemctl "$1" is-active --quiet "$NODEAPP_UNIT" 2> /dev/null
}

# pm2 installed, unit current, linger on and the daemon running. Prints why
# it failed and returns an error code otherwise.
nodeapp_daemon_ensure() {
	local user="$1" rc i
	nodeapp_pm2_install "$user"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		echo "pm2 could not be installed for $user"
		return "$rc"
	fi
	nodeapp_unit_write "$user"
	if ! nodeapp_manager_start "$user"; then
		echo "the systemd user manager of $user did not start"
		return "$E_RESTART"
	fi
	nodeapp_systemctl "$user" daemon-reload > /dev/null 2>&1
	nodeapp_systemctl "$user" enable "$NODEAPP_UNIT" > /dev/null 2>&1
	nodeapp_systemctl "$user" start "$NODEAPP_UNIT" > /dev/null 2>&1
	for i in $(seq 1 40); do
		if nodeapp_daemon_active "$user"; then
			return 0
		fi
		sleep 0.25
	done
	echo "the pm2 daemon of $user did not start"
	return "$E_RESTART"
}

# Run pm2 as USER against its daemon. Never called while the daemon is down:
# the pm2 client would start a daemon outside of systemd.
nodeapp_pm2() {
	local user="$1" runtime
	shift
	runtime=$(nodeapp_pm2_runtime "$user")
	if [ -z "$runtime" ] || ! nodeapp_daemon_active "$user"; then
		echo "the pm2 daemon of $user is not running"
		return "$E_RESTART"
	fi
	NODE_RUN_VERSION="$runtime" node_as_user "$user" PM2_HOME="$(node_home "$user")/.pm2" \
		timeout 120 "$(nodeapp_pm2_bin "$user" "$runtime")" "$@"
}

# pm2 jlist of USER as a JSON array, [] when the daemon is down
nodeapp_jlist() {
	local out
	if ! nodeapp_daemon_active "$1"; then
		echo '[]'
		return 0
	fi
	out=$(nodeapp_pm2 "$1" jlist 2> /dev/null | sed -n '/^\[/,$p')
	if printf '%s' "$out" | jq -e 'type == "array"' > /dev/null 2>&1; then
		printf '%s\n' "$out"
	else
		echo '[]'
	fi
}

# Stop the daemon of USER (apps stay in the dump)
nodeapp_daemon_stop() {
	local uid
	uid=$(id -u "$1" 2> /dev/null) || return 0
	if [ -S "/run/user/$uid/systemd/private" ]; then
		nodeapp_systemctl "$1" stop "$NODEAPP_UNIT" > /dev/null 2>&1
	fi
	return 0
}

# No app left: stop and remove the daemon, the linger and the logrotate file
nodeapp_daemon_retire() {
	local user="$1" uid
	uid=$(id -u "$user" 2> /dev/null) || return 0
	if [ -S "/run/user/$uid/systemd/private" ]; then
		nodeapp_systemctl "$user" disable --now "$NODEAPP_UNIT" > /dev/null 2>&1
	fi
	node_as_user "$user" rm -f "$(nodeapp_unit_file "$user")" "$(node_home "$user")/.pm2/dump.pm2" 2> /dev/null
	loginctl disable-linger "$user" > /dev/null 2>&1
	rm -f "$(nodeapp_pm2_conf "$user")" "/etc/logrotate.d/arcpanel-node-$user"
	return 0
}

#----------------------------------------------------------#
#                       App files                          #
#----------------------------------------------------------#

# The pm2 app definition of USER's DOMAIN as JSON, from its record
nodeapp_app_json() {
	local user="$1" domain="$2" line runtime rdir cwd start script args='' instances mode logs
	line=$(nodeapp_line "$user" "$domain")
	runtime=$(nodeapp_runtime "$user" "$(nodeapp_field "$line" NODE_VERSION)") || return "$E_NOTEXIST"
	rdir=$(node_version_dir "$user" "$runtime")
	cwd=$(nodeapp_cwd_path "$user" "$domain" "$(nodeapp_field "$line" CWD)")
	start=$(nodeapp_field "$line" START)
	if [[ "$start" == npm:* ]]; then
		script="$rdir/lib/node_modules/npm/bin/npm-cli.js"
		args="run ${start#npm:}"
	else
		script="$cwd/$start"
	fi
	instances=$(nodeapp_field "$line" INSTANCES)
	mode=fork
	if [ "${instances:-1}" -gt 1 ]; then
		mode=cluster
	fi
	logs=$(nodeapp_dir "$user" "$domain")/logs
	jq -n --arg name "$(nodeapp_field "$line" NAME)" --arg script "$script" --arg args "$args" \
		--arg cwd "$cwd" --arg interpreter "$rdir/bin/node" --arg port "$(nodeapp_field "$line" PORT)" \
		--argjson instances "${instances:-1}" --arg mode "$mode" \
		--arg memory "$(nodeapp_field "$line" MAX_MEMORY)M" --arg logs "$logs" --arg path "$rdir/bin:/usr/local/bin:/usr/bin:/bin" '
		{ name: $name, script: $script, cwd: $cwd, interpreter: $interpreter,
			instances: $instances, exec_mode: $mode, max_memory_restart: $memory,
			autorestart: true, max_restarts: 10, min_uptime: "10s", restart_delay: 2000,
			exp_backoff_restart_delay: 100, out_file: ($logs + "/out.log"), error_file: ($logs + "/err.log"),
			merge_logs: true, time: true,
			env: ({ PATH: $path, NODE_ENV: "production", HOST: "127.0.0.1" } + (if $port != "" then { PORT: $port } else {} end)) }
		+ (if $args != "" then { args: $args } else {} end)'
}

# Write ecosystem.config.cjs, app.conf and the folders of USER's DOMAIN
nodeapp_files_write() {
	local user="$1" domain="$2" dir app
	dir=$(nodeapp_dir "$user" "$domain")
	app=$(nodeapp_app_json "$user" "$domain") || return $?
	node_as_user "$user" bash -c 'umask 027; mkdir -p "$1/logs" && [ ! -L "$1" ] && [ ! -L "$1/logs" ]' dir "$dir" || return "$E_FORBIDEN"
	node_as_user "$user" bash -c 'umask 077; [ -e "$1" ] || : > "$1"' env "$dir/.env"
	{
		echo "// Generated by ArcPanel from the Node.js app of $domain; changes are overwritten."
		echo "// Environment: .env next to this file (KEY=value lines), read at every start."
		echo "const fs = require('fs');"
		echo "const path = require('path');"
		echo ""
		echo "function readEnv(file) {"
		echo "	const env = {};"
		echo "	let text = '';"
		echo "	try {"
		echo "		text = fs.readFileSync(file, 'utf8');"
		echo "	} catch (e) {"
		echo "		return env;"
		echo "	}"
		echo "	for (const line of text.split(/\\r?\\n/)) {"
		echo "		const m = line.match(/^\\s*([A-Za-z_][A-Za-z0-9_]*)\\s*=(.*)\$/);"
		echo "		if (!m) continue;"
		echo "		let value = m[2].trim();"
		echo "		if (value.length > 1 && (value[0] === '\"' || value[0] === \"'\") && value.endsWith(value[0])) value = value.slice(1, -1);"
		echo "		env[m[1]] = value;"
		echo "	}"
		echo "	return env;"
		echo "}"
		echo ""
		printf 'const app = %s;\n' "$app"
		echo "const fixed = app.env;"
		echo "app.env = Object.assign(readEnv(path.join(__dirname, '.env')), fixed);"
		echo ""
		echo "module.exports = { apps: [app] };"
	} | node_as_user "$user" bash -c 'umask 027; cat > "$1.tmp" && mv -f "$1.tmp" "$1"' eco "$dir/ecosystem.config.cjs"
	nodeapp_line "$user" "$domain" \
		| node_as_user "$user" bash -c 'umask 027; cat > "$1.tmp" && mv -f "$1.tmp" "$1"' conf "$dir/app.conf"
}

# Per-user logrotate file for the logs of every app
nodeapp_logrotate_write() {
	local user="$1"
	cat > "/etc/logrotate.d/arcpanel-node-$user" <<- EOF
		# ArcPanel: logs of the Node.js apps of $user (E14)
		$HOMEDIR/$user/web/*/nodeapp/logs/*.log {
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
	chmod 644 "/etc/logrotate.d/arcpanel-node-$user"
}

#----------------------------------------------------------#
#                    Running the apps                      #
#----------------------------------------------------------#

# Start USER's DOMAIN from its ecosystem (replaces a running process)
nodeapp_start() {
	local user="$1" domain="$2" name
	name=$(nodeapp_get "$user" "$domain" NAME)
	nodeapp_pm2 "$user" delete "$name" > /dev/null 2>&1
	nodeapp_pm2 "$user" start "$(nodeapp_dir "$user" "$domain")/ecosystem.config.cjs" > /dev/null 2>&1 || return "$E_RESTART"
	nodeapp_pm2 "$user" save --force > /dev/null 2>&1
}

nodeapp_stop() {
	local user="$1" domain="$2" name
	name=$(nodeapp_get "$user" "$domain" NAME)
	if nodeapp_daemon_active "$user"; then
		nodeapp_pm2 "$user" stop "$name" > /dev/null 2>&1
		nodeapp_pm2 "$user" save --force > /dev/null 2>&1
	fi
	return 0
}

nodeapp_forget() {
	local user="$1" domain="$2" name
	name=$(nodeapp_get "$user" "$domain" NAME)
	if nodeapp_daemon_active "$user"; then
		nodeapp_pm2 "$user" delete "$name" > /dev/null 2>&1
		nodeapp_pm2 "$user" save --force > /dev/null 2>&1
	fi
	return 0
}

# pm2 status of NAME in a jlist JSON (empty when not listed)
nodeapp_status_of() {
	jq -r --arg n "$2" '[.[] | select(.name == $n)][0].pm2_env.status // ""' <<< "$1"
}

# Owner of a socket listening on PORT that is not USER's pm2 app: prints
# "pid/process", empty when the port is free or used by the app
nodeapp_port_holder() {
	local port="$1" line
	line=$(ss -Hltnp "sport = :$port" 2> /dev/null | head -n1)
	if [ -z "$line" ]; then
		return 0
	fi
	grep -oE 'users:\(\("[^"]*",pid=[0-9]+' <<< "$line" | head -n1 | sed -E 's/users:\(\("([^"]*)",pid=([0-9]+)/\2\/\1/'
}

#----------------------------------------------------------#
#                         Output                           #
#----------------------------------------------------------#

# The apps of USER as a JSON array merged with the pm2 list JLIST
nodeapp_rows_json() {
	local user="$1" jlist="$2" domain line runtime
	for domain in $(nodeapp_domains "$user"); do
		line=$(nodeapp_line "$user" "$domain")
		runtime=$(nodeapp_runtime "$user" "$(nodeapp_field "$line" NODE_VERSION)" 2> /dev/null)
		jq -nc --arg line "$line" --arg runtime "$runtime" --argjson jlist "$jlist" '
			($line | [scan("([A-Z_]+)='"'"'([^'"'"']*)'"'"'")] | map({ key: .[0], value: .[1] }) | from_entries) as $r
			| ([ $jlist[] | select(.name == $r.NAME) ]) as $procs
			| ($procs[0].pm2_env.status // "") as $st
			| $r + {
				RUNTIME: $runtime,
				STATUS: (if $r.SUSPENDED != "no" then "suspended"
					elif $runtime == "" then "errored"
					elif $st == "" then "missing"
					elif $st == "stopping" then "stopped"
					else $st end),
				REASON: (if $runtime == "" then "runtime missing" else "" end),
				PID: (($procs[0].pid // 0) | if . == 0 then "" else tostring end),
				CPU: ([ $procs[].monit.cpu // 0 ] | add // 0 | tostring),
				MEMORY: ((([ $procs[].monit.memory // 0 ] | add // 0) / 1048576 | floor) | tostring),
				RESTARTS: ([ $procs[].pm2_env.restart_time // 0 ] | add // 0 | tostring),
				UPTIME: (if $st == "online" then (((now * 1000) - ($procs[0].pm2_env.pm_uptime // (now * 1000))) / 1000 | floor | tostring) else "0" end)
			}'
	done
}

# Start command proposed for an app in CWD of USER's DOMAIN: npm:start when
# package.json has a start script, else its main file, else the first of
# server.js, app.js, index.js. Empty when nothing fits.
nodeapp_detect_start() {
	local user="$1" domain="$2" cwd="$3" dir pkg main file
	dir=$(nodeapp_cwd_path "$user" "$domain" "$cwd")
	pkg=$(node_as_user "$user" head -c 1048576 "$dir/package.json" 2> /dev/null)
	if [ -n "$pkg" ]; then
		if jq -e '.scripts.start | type == "string"' <<< "$pkg" > /dev/null 2>&1; then
			echo "npm:start"
			return 0
		fi
		main=$(jq -r '.main // "" | ltrimstr("./")' <<< "$pkg" 2> /dev/null)
		if [ -n "$main" ] && nodeapp_start_valid "$main" && node_as_user "$user" test -f "$dir/$main"; then
			echo "$main"
			return 0
		fi
	fi
	for file in server.js app.js index.js; do
		if node_as_user "$user" test -f "$dir/$file"; then
			echo "$file"
			return 0
		fi
	done
}
