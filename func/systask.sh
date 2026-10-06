#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: the system task (E7 batch 3,
# docs/features/specs/2026-10-06-003-e7-batch3-pages-design.md §6).
# One long server operation at a time (PHP versions, plugin installs, package
# updates), run in the background like the Quick Install task of E7 batch 2.
# The runner lives in a transient systemd unit, so a restart of the panel
# (hestia.service) during a package update does not end it.

#----------------------------------------------------------#
#                          Task                            #
#----------------------------------------------------------#

ST_DIR="$HESTIA/data/sys-task"

st_task_conf() {
	echo "$ST_DIR/task.conf"
}

st_task_log() {
	echo "$ST_DIR/task.log"
}

st_task_lock() {
	echo "$ST_DIR/task.lock"
}

# Create the task folder (root only)
st_task_dir() {
	if [ ! -d "$ST_DIR" ]; then
		mkdir -p "$ST_DIR"
	fi
	chown root:root "$ST_DIR"
	chmod 750 "$ST_DIR"
}

# Value of KEY in the task record
st_task_get() {
	local file
	file=$(st_task_conf)
	if [ -f "$file" ]; then
		grep -oE "(^| )$1='[^']*'" "$file" | head -n1 | cut -d\' -f2
	fi
}

ST_TASK_KEYS="ID KIND ARGS STATUS PID UNIT EXIT START END"

# Set KEY=VALUE pairs in the task record; the other keys keep their values.
# Quotes and line breaks are dropped from values.
st_task_set() {
	local pair key line='' tmp file
	local -A values=()
	for key in $ST_TASK_KEYS; do
		values[$key]=$(st_task_get "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $ST_TASK_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	file=$(st_task_conf)
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
}

# Whether the runner of the task is alive: its systemd unit, or (without
# systemd) its process
st_task_alive() {
	local unit pid
	unit=$(st_task_get UNIT)
	pid=$(st_task_get PID)
	if [ -n "$unit" ]; then
		systemctl is-active --quiet "$unit.service"
		return
	fi
	[[ "$pid" =~ ^[0-9]+$ ]] && [ -r "/proc/$pid/cmdline" ] \
		&& tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'v-run-sys-task'
}

# Mark a running task whose runner is gone as failed
st_task_reap() {
	if [ "$(st_task_get STATUS)" = 'running' ] && ! st_task_alive; then
		st_task_set STATUS=failed "END=$(date +'%F %T')"
	fi
}

st_task_running() {
	st_task_reap
	[ "$(st_task_get STATUS)" = 'running' ]
}

# Whether systemd runs the machine (transient units are available)
st_has_systemd() {
	[ -d /run/systemd/system ] && command -v systemd-run > /dev/null 2>&1
}

#----------------------------------------------------------#
#                     PHP versions                         #
#----------------------------------------------------------#

# Versions v-add-web-php accepts (install/upgrade/upgrade.conf)
st_php_supported() {
	local multiphp_v=()
	# shellcheck source=/usr/local/hestia/install/upgrade/upgrade.conf
	source "$HESTIA/install/upgrade/upgrade.conf"
	printf '%s\n' "${multiphp_v[@]}"
}

# Whether a PHP version is installed, as v-add-web-php checks it
st_php_installed() {
	[ -f "/etc/init.d/php$1-fpm" ] && [ -f "$HESTIA/data/templates/web/php-fpm/PHP-${1/\./_}.tpl" ]
}

# The PHP version of the system php binary (web/inc/main.php DEFAULT_PHP_VERSION)
st_php_default() {
	php -r 'echo substr(phpversion(), 0, 3);' 2> /dev/null
}

# Web domains that use a PHP version, one USER/DOMAIN per line: the backend
# template PHP-X_Y, a custom template ending in PHP-X_Y, or "default" for
# the default version
st_php_used_by() {
	local version="$1" tpl conf user default
	tpl="PHP-${version/\./_}"
	default=$(st_php_default)
	for conf in "$HESTIA"/data/users/*/web.conf; do
		[ -f "$conf" ] || continue
		user=$(basename "$(dirname "$conf")")
		while IFS= read -r line; do
			local domain backend
			domain=$(echo "$line" | grep -oE "^DOMAIN='[^']*'" | cut -d\' -f2)
			backend=$(echo "$line" | grep -oE " BACKEND='[^']*'" | cut -d\' -f2)
			if [ "$backend" = "$tpl" ] || [[ "$backend" == *"-$tpl" ]] \
				|| { [ "$backend" = 'default' ] && [ "$version" = "$default" ]; }; then
				echo "$user/$domain"
			fi
		done < "$conf"
	done
}
