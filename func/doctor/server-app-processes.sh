#!/bin/bash
# E17 check server.app-processes (spec §4.1): v-check-sys-app-processes
# reports a process down; skipped without app processes.
# shellcheck disable=SC2034
DOCTOR_ID=server.app-processes
DOCTOR_SCOPE=server
DOCTOR_TITLE='The app processes run'
DOCTOR_DOCS='/docs/guides/doctor#server-app-processes'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local out processes count names
	if ! out=$(doctor_src app-processes "$BIN/v-check-sys-app-processes" check json 2> /dev/null) \
		|| ! jq -e '.FINDINGS | type == "array"' <<< "$out" > /dev/null 2>&1; then
		dr_skip 'The app processes could not be checked.'
		return
	fi
	processes=$(jq -r '.PROCESSES // "0"' <<< "$out")
	if [ "${processes:-0}" = '0' ] && [ "$(jq '.FINDINGS | length' <<< "$out")" -eq 0 ]; then
		dr_skip 'No app process is set up.'
		return
	fi
	count=$(jq '[.FINDINGS[] | select(.TYPE != "over-limit")] | length' <<< "$out")
	if [ "$count" -eq 0 ]; then
		dr_ok "Every app process runs as recorded ($processes processes)." "$processes"
		return
	fi
	names=$(jq -r '[.FINDINGS[] | select(.TYPE != "over-limit") | (.USER + "/" + (if .NAME != "" then .NAME else .TYPE end))] | unique | .[0:5] | join(", ")' <<< "$out")
	dr_warn "$count app processes are not as recorded ($names)." "$count"
}
