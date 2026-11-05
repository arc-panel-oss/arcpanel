#!/bin/bash
# E16 evaluator of service.down (spec §4): the state of every service the
# server runs from `v-list-sys-services json` (OPTIONS: the services to
# watch, all when empty). Prints service:NAME STATE LEVEL, critical when a
# service is not running.

# shellcheck disable=SC2154
alert_eval_service_down() {
	local out
	out=$(alerts_src services "$BIN/v-list-sys-services" json) || return 1
	jq -r --arg opts ",$AE_OPTIONS," '
		to_entries[]
		| select(.key | test("^[A-Za-z0-9_.@-]+$"))
		| select($opts == ",," or ($opts | contains("," + .key + ",")))
		| "service:\(.key) \(.value.STATE // "unknown") \(if (.value.STATE // "") == "running" then "ok" else "critical" end)"
	' <<< "$out" || return 1
}
