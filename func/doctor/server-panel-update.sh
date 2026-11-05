#!/bin/bash
# E17 check server.panel-update (spec §4.1): a newer release of the installed
# line exists (v-list-sys-arcpanel-releases lists the releases of the
# signed manifest newer than the installed version; KIND patch is the
# installed line).
# shellcheck disable=SC2034
DOCTOR_ID=server.panel-update
DOCTOR_SCOPE=server
DOCTOR_TITLE='ArcPanel is up to date'
DOCTOR_DOCS='/docs/guides/doctor#server-panel-update'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local out patch newer installed=${VERSION-}
	DR_LINK=updates
	if ! out=$(doctor_src releases "$BIN/v-list-sys-arcpanel-releases" json 2> /dev/null) \
		|| ! jq -e 'type == "array"' <<< "$out" > /dev/null 2>&1; then
		dr_skip 'The release list could not be read.'
		return
	fi
	# The manifest lists its releases newest first
	patch=$(jq -r '[.[] | select(.KIND == "patch" and .WITHDRAWN != true) | .VERSION][0] // empty' <<< "$out")
	newer=$(jq -r '[.[] | select(.WITHDRAWN != true) | .VERSION][0] // empty' <<< "$out")
	if [ -n "$patch" ]; then
		dr_warn "ArcPanel $patch fixes the installed version $installed. Install it from the Updates page." "$installed → $patch"
		return
	fi
	if [ -n "$newer" ]; then
		dr_ok "The installed line is up to date; ArcPanel $newer is available as a new version." "$installed"
		return
	fi
	dr_ok "ArcPanel $installed is up to date." "$installed"
}
