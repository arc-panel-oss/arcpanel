#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel upgrade functions (ADR-0006)                                     #
#                                                                           #
# Two version numbers are tracked in $HESTIA/conf/hestia.conf:              #
#   VERSION           upstream HestiaCP base version, drives the upstream   #
#                     scripts in install/upgrade/versions/                  #
#   ARCPANEL_VERSION  ArcPanel version shown to users, drives the scripts   #
#                     in install/upgrade/arcpanel/                          #
#                                                                           #
#===========================================================================#

ARCPANEL_UPGRADE_DIR="$HESTIA/install/upgrade/arcpanel"

# Strip the Debian revision and distro suffix from a package version:
# 1.0.0-1+debian12 -> 1.0.0, 1.0.1-1+ubuntu24.04~beta -> 1.0.1~beta
arcpanel_strip_deb_version() {
	echo "$1" | sed -E 's/-[0-9]{1,}\+[a-z0-9\.]{1,}//'
}

# Sets:
#   new_version           upstream version shipped in install/upstream.conf
#   arcpanel_new_version  version of the installed arcpanel package
arcpanel_get_versions() {
	UPSTREAM_VERSION=''
	if [ -f "$HESTIA/install/upstream.conf" ]; then
		source_conf "$HESTIA/install/upstream.conf"
	fi
	new_version="$UPSTREAM_VERSION"
	if [ -z "$new_version" ]; then
		# Fallback for a package built without upstream.conf
		new_version="$VERSION"
	fi
	arcpanel_new_version="$(arcpanel_strip_deb_version "$(dpkg-query -W -f='${Version}' arcpanel 2> /dev/null)")"
}

# Comparable number for a dotted version: 1.2.3 -> 1002003000
arcpanel_version_number() {
	echo "$1" | awk -F. '{ printf("%d%03d%03d%03d\n", $1, $2, $3, $4); }'
}

# Version shown to users in messages, notifications and emails.
arcpanel_display_version() {
	if [ -n "$arcpanel_new_version" ]; then
		echo "$arcpanel_new_version"
	else
		echo "$new_version"
	fi
}

# Run every install/upgrade/arcpanel/<version>.sh that is newer than the
# installed ARCPANEL_VERSION and not newer than the new package version.
arcpanel_upgrade_start_routine() {
	local current target step step_version
	current=$(echo "${ARCPANEL_VERSION:-0.0.0}" | sed "s/~\([a-zA-Z0-9].*\)//g")
	target=$(echo "$arcpanel_new_version" | sed "s/~\([a-zA-Z0-9].*\)//g")

	if [ -z "$target" ]; then
		echo "[ ! ] ArcPanel package version not found, skipping ArcPanel upgrade steps."
		return 0
	fi

	if [ -d "$ARCPANEL_UPGRADE_DIR" ]; then
		for step in $(ls -v "$ARCPANEL_UPGRADE_DIR"/*.sh 2> /dev/null); do
			step_version=$(basename "$step" .sh)
			if [ "$(arcpanel_version_number "$step_version")" -gt "$(arcpanel_version_number "$current")" ] \
				&& [ "$(arcpanel_version_number "$step_version")" -le "$(arcpanel_version_number "$target")" ]; then
				echo "[ * ] Running ArcPanel upgrade step $step_version..."
				# shellcheck source=/dev/null
				source "$step"
			fi
		done
	fi

	arcpanel_set_version "$arcpanel_new_version"
}

arcpanel_set_version() {
	$BIN/v-change-sys-config-value "ARCPANEL_VERSION" "$1"
	ARCPANEL_VERSION="$1"
}
