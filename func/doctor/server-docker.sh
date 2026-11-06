#!/bin/bash
# E17 check server.docker (spec §4.1): v-check-sys-docker reports a problem
# with the Docker apps; skipped without Docker.
# shellcheck disable=SC2034
DOCTOR_ID=server.docker
DOCTOR_SCOPE=server
DOCTOR_TITLE='The Docker apps match their records'
DOCTOR_DOCS='/docs/guides/doctor#server-docker'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local out count types
	if [ "${DOCKER_SYSTEM-}" != 'yes' ] && ! doctor_has_fixture docker; then
		dr_skip 'Docker is not installed.'
		return
	fi
	if ! out=$(doctor_src docker "$BIN/v-check-sys-docker" check json 2> /dev/null) \
		|| ! jq -e '.FINDINGS | type == "array"' <<< "$out" > /dev/null 2>&1; then
		dr_skip 'The Docker apps could not be checked.'
		return
	fi
	count=$(jq '.FINDINGS | length' <<< "$out")
	if [ "$count" -eq 0 ]; then
		dr_ok 'Every Docker app matches its record.' 0
		return
	fi
	types=$(jq -r '[.FINDINGS[].TYPE] | unique | join(", ")' <<< "$out")
	if [ "$count" -eq 1 ]; then
		dr_fail "The Docker check reports 1 problem ($types). The repair that runs every 5 minutes did not clear it." "$count"
	else
		dr_fail "The Docker check reports $count problems ($types). The repair that runs every 5 minutes did not clear them." "$count"
	fi
}
