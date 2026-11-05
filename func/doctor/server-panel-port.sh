#!/bin/bash
# E17 check server.panel-port (spec §4.1): the panel listens on the default
# port 8083.
# shellcheck disable=SC2034
DOCTOR_ID=server.panel-port
DOCTOR_SCOPE=server
DOCTOR_TITLE='The panel does not use the default port'
DOCTOR_DOCS='/docs/guides/doctor#server-panel-port'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local port="${BACKEND_PORT:-8083}"
	if [ "$port" = '8083' ]; then
		dr_warn 'The panel listens on the default port 8083, which scanners try first. Change it with v-change-sys-port.' "$port"
		return
	fi
	dr_ok "The panel listens on port $port." "$port"
}
