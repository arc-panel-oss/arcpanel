#!/bin/bash
# E17 check server.firewall (spec §4.1): the firewall is on and its table is
# loaded (v-check-firewall).
# shellcheck disable=SC2034
DOCTOR_ID=server.firewall
DOCTOR_SCOPE=server
DOCTOR_TITLE='The firewall is on'
DOCTOR_DOCS='/docs/guides/doctor#server-firewall'
DOCTOR_FIX_ALLOWED='v-update-firewall'

doctor_check() {
	local out status
	if [ -z "${FIREWALL_SYSTEM-}" ] && ! doctor_has_fixture firewall; then
		dr_fail 'The firewall is off: every port of the server is open to the internet.' 'off'
		return
	fi
	if ! out=$(doctor_src firewall "$BIN/v-check-firewall" json 2> /dev/null); then
		dr_skip 'The state of the firewall could not be read.'
		return
	fi
	status=$(jq -r '.STATUS // empty' <<< "$out" 2> /dev/null)
	case "$status" in
		ok) dr_ok 'The firewall is on and its rules are loaded.' "${FIREWALL_SYSTEM:-nftables}" ;;
		drift)
			dr_warn 'The loaded firewall rules were changed outside the panel.' drift
			dr_fix 'Load the firewall rules again' v-update-firewall
			;;
		missing)
			dr_fail 'The firewall is on, but its rules are not loaded.' missing
			dr_fix 'Load the firewall rules again' v-update-firewall
			;;
		off) dr_fail 'The firewall is off: every port of the server is open to the internet.' off ;;
		*) dr_skip 'The state of the firewall could not be read.' ;;
	esac
}
