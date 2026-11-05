#!/bin/bash
# shellcheck disable=SC2034
# ArcPanel: the system task (E7 batch 3,
# docs/features/specs/2026-10-06-003-e7-batch3-pages-design.md §6).
# One long server operation at a time (PHP versions, plugin installs, package
# updates), run as a job of type sys.KIND (E15, func/job.sh) in a transient
# systemd unit, so a restart of the panel (arcpanel.service) during a package
# update does not end it.

#----------------------------------------------------------#
#                          Task                            #
#----------------------------------------------------------#

# Since E15 a system task is a job of type sys.KIND (func/job.sh). These
# wrappers read the newest one for the callers of the old task record
# (func/updates.sh, v-run-sys-arcpanel-autoupdate); they go in 2.8.

# shellcheck source=/usr/local/arcpanel/func/job.sh
source "$ARCPANEL/func/job.sh"

ST_TYPES='sys\.[a-z-]+'

# ID of the newest system task
st_task_id() {
	job_newest "$ST_TYPES"
}

# Value of KEY of the newest system task, in the old record's terms: KIND,
# ARGS (the arguments of v-run-sys-task after KIND), STATUS (running, done
# or failed), ID, EXIT, START, END
st_task_get() {
	local id args
	id=$(st_task_id) || return 0
	[ -n "$id" ] || return 0
	case "$1" in
		KIND) job_get "$id" TYPE | sed 's/^sys\.//' ;;
		ARGS)
			args=$(job_get "$id" ARGS)
			read -r -a args <<< "$args"
			printf '%s' "${args[*]:2}"
			;;
		STATUS) job_legacy_status "$(job_get "$id" STATUS)" ;;
		*) job_get "$id" "$1" ;;
	esac
}

# Mark the newest system task failed when its runner is gone
st_task_reap() {
	local id
	id=$(st_task_id) || return 0
	[ -n "$id" ] && job_reap_one "$id" > /dev/null 2>&1
	return 0
}

st_task_running() {
	st_task_reap
	[ "$(st_task_get STATUS)" = 'running' ]
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
