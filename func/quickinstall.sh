#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: the Quick Install task of a user (E7 batch 2,
# docs/features/specs/2026-10-06-002-e7-batch2-pages-design.md §10.1).
# One install per user at a time, run as a job (E15).

#----------------------------------------------------------#
#                          Task                            #
#----------------------------------------------------------#

# Since E15 a Quick Install is a job of type quick-install with OBJECT
# web:DOMAIN (func/job.sh); its options are the job's request (root 0600),
# removed when the install succeeds. One install per user runs at a time.

# shellcheck source=/usr/local/arcpanel/func/job.sh
source "$ARCPANEL/func/job.sh"

qi_task_lock() {
	echo "$ARCPANEL/data/users/$1/quick-install-task.lock"
}

# ID of the newest Quick Install of USER
qi_task_id() {
	job_newest 'quick-install' '' "$1"
}

# Value of KEY of the newest install of USER in the old record's terms: ID,
# DOMAIN, APP, STATUS (running, done or failed), PID, EXIT, START, END
qi_task_get() {
	local id
	id=$(qi_task_id "$1") || return 0
	[ -n "$id" ] || return 0
	case "$2" in
		DOMAIN) job_argv_word "$id" 2 ;;
		APP) job_argv_word "$id" 3 ;;
		STATUS) job_legacy_status "$(job_get "$id" STATUS)" ;;
		*) job_get "$id" "$2" ;;
	esac
}

# Mark a running install whose runner is gone as failed
qi_task_reap() {
	local id
	id=$(qi_task_id "$1") || return 0
	[ -n "$id" ] && job_reap_one "$id" > /dev/null 2>&1
	return 0
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
