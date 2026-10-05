#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel hooks (E12)                                                      #
#                                                                           #
# Upstream user commands call run_arcpanel_hooks at fixed points, so        #
# ArcPanel features can follow account changes made from any path (panel,   #
# legacy UI, API, CLI):                                                     #
#   post_add_user USER · post_change_user_package USER PACKAGE ·            #
#   post_suspend_user USER · post_unsuspend_user USER ·                     #
#   pre_delete_user USER                                                    #
# Each hook is a script $HESTIA/func/hooks.d/<event>/NN-name.sh, run with   #
# bash in name order. Hooks must be idempotent. A failing hook is logged to #
# $HESTIA/log/system.log and never fails the command that runs it.          #
# See docs/features/specs/2026-10-05-004-e12-port-registry-design.md §6.    #
#                                                                           #
#===========================================================================#

run_arcpanel_hooks() {
	local event="$1" dir hook rc
	shift
	dir="$HESTIA/func/hooks.d/$event"
	if [ ! -d "$dir" ]; then
		return 0
	fi
	for hook in "$dir"/*.sh; do
		if [ ! -f "$hook" ]; then
			continue
		fi
		HESTIA="$HESTIA" bash "$hook" "$@" > /dev/null 2>&1
		rc=$?
		if [ "$rc" -ne 0 ]; then
			echo "$(date +'%F %T') hook $event/$(basename "$hook") failed with code $rc ($*)" >> "$HESTIA/log/system.log"
		fi
	done
	return 0
}
