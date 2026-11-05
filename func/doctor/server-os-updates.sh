#!/bin/bash
# E17 check server.os-updates (spec §4.1): security updates of the
# distribution are pending (apt-get -s upgrade, packages from a -security
# suite); fail when the packages were last changed more than 30 days ago.
# shellcheck disable=SC2034
DOCTOR_ID=server.os-updates
DOCTOR_SCOPE=server
DOCTOR_TITLE='Security updates of the system are installed'
DOCTOR_DOCS='/docs/guides/doctor#server-os-updates'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local out count last age days
	if ! command -v apt-get > /dev/null 2>&1 && ! doctor_has_fixture apt-upgrade; then
		dr_skip 'APT is not available.'
		return
	fi
	if ! out=$(doctor_src apt-upgrade env LANG=C apt-get -s -o Debug::NoLocking=1 upgrade 2> /dev/null); then
		dr_skip 'The pending updates could not be listed.'
		return
	fi
	count=$(grep -E '^Inst ' <<< "$out" | grep -cE '[-/][a-z]+-security[ ,/)]' || true)
	if [ "$count" -eq 0 ]; then
		dr_ok 'No security update is pending.' 0
		return
	fi
	# When the packages were last changed: the status file of dpkg
	last=$(doctor_src dpkg-changed stat -c %Y /var/lib/dpkg/status 2> /dev/null | head -n1)
	[[ "$last" =~ ^[0-9]+$ ]] || last=$(doctor_now)
	age=$(($(doctor_now) - last))
	days=$((age / 86400))
	if [ "$days" -gt 30 ]; then
		dr_fail "$count security updates are pending and no package was updated for $days days. Install the updates (apt-get upgrade) or turn on unattended upgrades." "$count"
		return
	fi
	dr_warn "$count security updates are pending. Install them with apt-get upgrade." "$count"
}
