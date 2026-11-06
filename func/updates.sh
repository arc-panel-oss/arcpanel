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

# The ArcPanel APT source and its keyring, as the installers write them
UPD_APT_SOURCE='/etc/apt/sources.list.d/arcpanel.list'
UPD_APT_KEYRING='/usr/share/keyrings/arcpanel-keyring.gpg'

# Checks the ArcPanel APT source: it must be a file with an active deb line,
# and every keyring its deb lines name with signed-by must be a non-empty file
# that apt can read (apt reads keyrings as the user _apt). Prints the problem
# and returns 1 when it finds one.
upd_check_apt_source() {
	local source="$UPD_APT_SOURCE" lines keyring
	if [ ! -e "$source" ]; then
		echo "the ArcPanel APT source $source is missing"
		return 1
	fi
	if [ ! -f "$source" ] || [ ! -r "$source" ]; then
		echo "the ArcPanel APT source $source is not a readable file"
		return 1
	fi
	lines="$(grep -E '^[[:space:]]*deb[[:space:]]' "$source")"
	if [ -z "$lines" ]; then
		echo "the ArcPanel APT source $source has no deb line"
		return 1
	fi
	for keyring in $(grep -oE 'signed-by=/[^] ]+' <<< "$lines" | cut -d= -f2-); do
		if [ ! -e "$keyring" ]; then
			echo "the ArcPanel APT keyring $keyring is missing"
			return 1
		fi
		if [ ! -f "$keyring" ] || [ ! -s "$keyring" ] || [ -z "$(find "$keyring" -maxdepth 0 -perm -o=r)" ]; then
			echo "the ArcPanel APT keyring $keyring is empty or not readable by apt"
			return 1
		fi
	done
}

# How to put the ArcPanel APT source and keyring back as the installers write
# them: the stable channel, or beta for a pre-release VERSION
upd_apt_source_hint() {
	local arch codename path=''
	arch="$(dpkg --print-architecture 2> /dev/null)"
	codename="$(. /etc/os-release 2> /dev/null && echo "$VERSION_CODENAME")"
	[[ "${VERSION:-}" == *~* ]] && path='beta/'
	echo "Restore $UPD_APT_SOURCE as the installer writes it:"
	echo "  deb [arch=${arch:-amd64} signed-by=$UPD_APT_KEYRING] https://apt.arcpanel.org/$path ${codename:-CODENAME} main"
	echo "and the repository key https://apt.arcpanel.org/arcpanel.gpg as $UPD_APT_KEYRING (gpg --dearmor, mode 0644)."
}

# Refreshes the package lists of the ArcPanel APT source alone. apt-get update
# exits 0 when the source or its key is missing or the repository cannot be
# reached, and only prints warnings, so any W: or E: line is a failure. Prints
# the output of apt-get and, on a failure, an "Error:" line with the reason.
# Returns 0, E_NOTEXIST (no usable source or keyring) or E_UPDATE (apt-get).
upd_apt_update() {
	local problem parts out rc
	if ! problem="$(upd_check_apt_source)"; then
		echo "Error: $problem, so ArcPanel cannot see new releases."
		upd_apt_source_hint
		return "${E_NOTEXIST:-3}"
	fi
	# An empty folder as sourceparts: with "-" apt warns on every run
	parts="$(mktemp -d)" || return "${E_UPDATE:-19}"
	out="$(apt-get update -o Dir::Etc::sourcelist="$UPD_APT_SOURCE" \
		-o Dir::Etc::sourceparts="$parts" -o APT::Get::List-Cleanup="0" -qq 2>&1)"
	rc=$?
	rmdir "$parts"
	[ -z "$out" ] || echo "$out"
	if [ "$rc" -ne 0 ] || grep -qE '^(W|E): ' <<< "$out"; then
		echo "Error: apt-get update could not read the ArcPanel APT repository of $UPD_APT_SOURCE, so ArcPanel cannot see new releases."
		return "${E_UPDATE:-19}"
	fi
}
