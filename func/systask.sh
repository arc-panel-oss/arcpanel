#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: the system task (E7 batch 3,
# docs/features/specs/2026-10-06-003-e7-batch3-pages-design.md §6).
# One long server operation at a time (PHP versions, plugin installs, package
# updates), run in the background like the Quick Install task of E7 batch 2.
# The runner lives in a transient systemd unit, so a restart of the panel
# (arcpanel.service) during a package update does not end it.

#----------------------------------------------------------#
#                          Task                            #
#----------------------------------------------------------#

ST_DIR="$ARCPANEL/data/sys-task"

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

# The PHP functions live in func/php.sh (E41)
# shellcheck source=/usr/local/arcpanel/func/php.sh
source "$ARCPANEL/func/php.sh"

# Versions v-add-web-php accepts: installable in the catalog
st_php_supported() {
	local version
	for version in $(php_catalog_versions); do
		if php_version_installable "$version"; then
			echo "$version"
		fi
	done
}

st_php_installed() {
	php_version_installed "$1"
}

st_php_default() {
	php_default_version
}

st_php_used_by() {
	php_used_by "$1"
}
