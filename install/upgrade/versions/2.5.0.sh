#!/bin/bash
# ArcPanel 2.5.0 upgrade step (E33 Update Center phase 1, P2,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §10).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# Automatic updates follow the update policy. A server with the nightly line
# of v-update-sys-arcpanel-all gets the policy patch, with a 2-hour window at
# the hour of that line and a delay of 2 days; a server without it gets
# notify. The hourly line of v-run-sys-arcpanel-autoupdate replaces the
# nightly one. A policy that is set (a second run) is kept, and only its cron
# line is brought in place.
# shellcheck source=/usr/local/arcpanel/func/updates.sh
source "$ARCPANEL/func/updates.sh"
if upd_policy_set UPDATE_POLICY; then
	upd_step_policy="$(upd_policy_get UPDATE_POLICY)"
elif upd_cron_old; then
	upd_step_policy='patch'
else
	upd_step_policy='notify'
fi
if ! upd_policy_set UPDATE_POLICY || upd_cron_old \
	|| { [ "$upd_step_policy" = 'off' ] && upd_cron_installed; } \
	|| { [ "$upd_step_policy" != 'off' ] && ! upd_cron_installed; }; then
	# shellcheck disable=SC2046
	"$ARCPANEL/bin/v-change-sys-update-policy" $(upd_policy_args "$upd_step_policy")
	case "$upd_step_policy" in
		patch) echo "[ * ] Automatic updates install patch releases of the installed line in a 2-hour window, 2 days after their release" ;;
		notify) echo "[ * ] Automatic updates announce new versions and install none: turn on the policy patch on the Updates page" ;;
	esac
fi
unset upd_step_policy
