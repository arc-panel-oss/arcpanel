#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: the Quick Install task of a user (E7 batch 2,
# docs/features/specs/2026-10-06-002-e7-batch2-pages-design.md §10.1).
# One install per user at a time, run in the background like the Node.js
# tasks of E13: the record and the output live in the user's data folder,
# the options in a root-only file that the runner removes.

#----------------------------------------------------------#
#                          Task                            #
#----------------------------------------------------------#

qi_task_conf() {
	echo "$ARCPANEL/data/users/$1/quick-install-task.conf"
}

qi_task_log() {
	echo "$ARCPANEL/data/users/$1/quick-install-task.log"
}

qi_task_lock() {
	echo "$ARCPANEL/data/users/$1/quick-install-task.lock"
}

qi_task_options() {
	echo "$ARCPANEL/data/users/$1/quick-install.options"
}

# Value of KEY in the task record of USER
qi_task_get() {
	local file
	file=$(qi_task_conf "$1")
	if [ -f "$file" ]; then
		grep -oE "(^| )$2='[^']*'" "$file" | head -n1 | cut -d\' -f2
	fi
}

QI_TASK_KEYS="ID DOMAIN APP STATUS PID EXIT START END"

# Set KEY=VALUE pairs in the task record of USER; the other keys keep their
# values. Quotes and line breaks are dropped from values.
qi_task_set() {
	local user="$1" file pair key line='' tmp
	local -A values=()
	shift
	for key in $QI_TASK_KEYS; do
		values[$key]=$(qi_task_get "$user" "$key")
	done
	for pair in "$@"; do
		key=${pair%%=*}
		values[$key]=$(printf '%s' "${pair#*=}" | tr -d "'\n\r")
	done
	for key in $QI_TASK_KEYS; do
		line="$line$key='${values[$key]}' "
	done
	file=$(qi_task_conf "$user")
	tmp="$file.$$"
	echo "${line% }" > "$tmp"
	chmod 640 "$tmp"
	mv -f "$tmp" "$file"
}

# Whether the task process PID is alive and still the task runner
qi_task_alive() {
	local pid="$1"
	[[ "$pid" =~ ^[0-9]+$ ]] && [ -r "/proc/$pid/cmdline" ] \
		&& tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'v-run-user-quick-install'
}

# Mark a running task whose process is gone as failed
qi_task_reap() {
	local user="$1"
	if [ "$(qi_task_get "$user" STATUS)" = 'running' ] && ! qi_task_alive "$(qi_task_get "$user" PID)"; then
		qi_task_set "$user" STATUS=failed "END=$(date +'%F %T')"
		rm -f "$(qi_task_options "$user")"
	fi
}

qi_task_running() {
	qi_task_reap "$1"
	[ "$(qi_task_get "$1" STATUS)" = 'running' ]
}

# Installer names: lib/quickinstall/app/WebApp/Installers/<APP>/<APP>Setup.php
qi_app_exists() {
	[[ "$1" =~ ^[A-Za-z][A-Za-z0-9]{0,63}$ ]] \
		&& [ -f "$ARCPANEL/lib/quickinstall/app/WebApp/Installers/$1/$1Setup.php" ]
}
