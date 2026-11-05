#!/bin/bash
# E17 check server.fail2ban (spec §4.1): the service runs and the SSH and
# panel jails are active.
# shellcheck disable=SC2034
DOCTOR_ID=server.fail2ban
DOCTOR_SCOPE=server
DOCTOR_TITLE='Fail2ban protects SSH and the panel'
DOCTOR_DOCS='/docs/guides/doctor#server-fail2ban'
DOCTOR_FIX_ALLOWED='v-restart-service'

doctor_check() {
	local state jails missing=''
	if ! command -v fail2ban-client > /dev/null 2>&1 && ! doctor_has_fixture fail2ban-state; then
		dr_skip 'Fail2ban is not installed.'
		return
	fi
	state=$(doctor_src fail2ban-state systemctl is-active fail2ban 2> /dev/null | head -n1)
	if [ "$state" != 'active' ]; then
		dr_fail 'The Fail2ban service is not running, so repeated failed logins are not blocked.' "${state:-unknown}"
		dr_fix 'Restart Fail2ban' v-restart-service fail2ban
		return
	fi
	jails=$(doctor_src fail2ban-jails fail2ban-client status 2> /dev/null | sed -n 's/.*Jail list:[[:space:]]*//p' | tr -d ' ' | tr ',' ' ')
	if ! grep -qE '(^| )(ssh|sshd)[^ ]*' <<< "$jails"; then
		missing='SSH'
	fi
	if ! grep -qE '(^| )arcpanel[^ ]*' <<< "$jails"; then
		missing="${missing:+$missing and }panel"
	fi
	if [ -n "$missing" ]; then
		dr_warn "Fail2ban runs, but the $missing jail is not active." "${jails:-no jails}"
		return
	fi
	dr_ok 'Fail2ban runs with the SSH and panel jails.' "$jails"
}
