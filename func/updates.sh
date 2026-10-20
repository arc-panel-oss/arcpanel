#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel updates of the panel packages (E33 Update Center phase 1).      #
# See                                                                      #
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md   #
# §6.                                                                      #
#                                                                           #
#===========================================================================#

# Upstream version of a package version: 2.2.2-1+ubuntu24.04 -> 2.2.2
upd_upstream_version() {
	if [[ "$1" == *-* ]]; then
		echo "${1%-*}"
	else
		echo "$1"
	fi
}

# Release line of a version: 2.2.2 -> 2.2, 2.3.0~beta.1 -> 2.3
upd_version_line() {
	local version="${1%%~*}"
	version="$(upd_upstream_version "$version")"
	echo "${version%.*}"
}

# Reads the package versions APT offers (one per line) and prints
# "<target> <newer>": the newest package version of the line of INSTALLED that
# is newer than it, and the newest upstream version of a newer line, each "-"
# when there is none. INSTALLED is an upstream version (VERSION).
upd_pick_versions() {
	local installed="$1" line candidate upstream target='' newer=''
	line="$(upd_version_line "$installed")"
	while read -r candidate; do
		[ -n "$candidate" ] || continue
		upstream="$(upd_upstream_version "$candidate")"
		dpkg --compare-versions "$upstream" gt "$installed" || continue
		if [ "$(upd_version_line "$upstream")" = "$line" ]; then
			if [ -z "$target" ] || dpkg --compare-versions "$candidate" gt "$target"; then
				target="$candidate"
			fi
		elif [ -z "$newer" ] || dpkg --compare-versions "$upstream" gt "$newer"; then
			newer="$upstream"
		fi
	done
	echo "${target:--} ${newer:--}"
}

# Whether VERSION is in the comma list LIST
upd_in_list() {
	[[ ",$2," == *",$1,"* ]]
}
