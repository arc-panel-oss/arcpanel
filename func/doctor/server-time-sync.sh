#!/bin/bash
# E17 check server.time-sync (spec §4.1): the clock is synchronised with NTP.
# shellcheck disable=SC2034
DOCTOR_ID=server.time-sync
DOCTOR_SCOPE=server
DOCTOR_TITLE='The clock is synchronised'
DOCTOR_DOCS='/docs/guides/doctor#server-time-sync'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local synced
	if ! command -v timedatectl > /dev/null 2>&1 && ! doctor_has_fixture time-sync; then
		dr_skip 'timedatectl is not available.'
		return
	fi
	if ! synced=$(doctor_src time-sync timedatectl show -p NTPSynchronized --value 2> /dev/null) || [ -z "$synced" ]; then
		dr_skip 'The state of the clock could not be read.'
		return
	fi
	synced=$(head -n1 <<< "$synced")
	if [ "$synced" != 'yes' ]; then
		dr_fail 'The clock is not synchronised with NTP: certificates, two-factor codes and logs depend on the right time. Turn on systemd-timesyncd or chrony.' "$synced"
		return
	fi
	dr_ok 'The clock is synchronised with NTP.' yes
}
