#!/bin/bash
# E17 check server.ports (spec §4.1): findings of the port registry report
# (the comparison of v-check-sys-ports, read without writing its report).
# shellcheck disable=SC2034
DOCTOR_ID=server.ports
DOCTOR_SCOPE=server
DOCTOR_TITLE='The port registry matches the server'
DOCTOR_DOCS='/docs/guides/doctor#server-ports'
DOCTOR_FIX_ALLOWED='v-check-sys-ports'

doctor_ports_findings() {
	# shellcheck source=/usr/local/arcpanel/func/tenancy.sh
	source "$ARCPANEL/func/tenancy.sh" || return 1
	# shellcheck source=/usr/local/arcpanel/func/ports.sh
	source "$ARCPANEL/func/ports.sh" || return 1
	ports_findings
}

doctor_check() {
	local out count types
	DR_LINK=ports
	if ! out=$(doctor_src ports doctor_ports_findings 2> /dev/null); then
		dr_skip 'The port registry could not be compared with the server.'
		return
	fi
	out=$(grep -v '^[[:space:]]*$' <<< "$out")
	if [ -z "$out" ]; then
		dr_ok 'The port registry matches the listening ports and the firewall.' 0
		return
	fi
	count=$(wc -l <<< "$out")
	types=$(cut -d'|' -f1 <<< "$out" | sort -u | tr '\n' ' ')
	dr_warn "The port registry report has $count findings (${types% })." "$count"
	dr_fix 'Repair the port registry' v-check-sys-ports repair
}
