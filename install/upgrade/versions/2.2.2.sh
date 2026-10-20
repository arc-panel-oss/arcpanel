#!/bin/bash
# ArcPanel 2.2.2 upgrade step (E33 Update Center phase 1, P1,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §6).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# Automatic updates stay on the installed release line: the cron line runs
# v-update-sys-arcpanel-all auto, and the fixed time 41 4 of older installs
# moves to a random night time. Servers with automatic updates off keep them off.
if grep -q 'v-update-sys-arcpanel-all' /var/spool/cron/crontabs/arcpanelweb 2> /dev/null; then
	"$ARCPANEL/bin/v-add-cron-arcpanel-autoupdate" apt
	echo "[ * ] Automatic updates now install patch releases of the installed line only"
fi
