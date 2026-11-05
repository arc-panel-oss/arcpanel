#!/bin/bash
# E16 evaluator of ports.orphan (spec §4): the findings of the port registry
# check. Runs `v-check-sys-ports` (it writes data/ports/.report.json) and
# prints system COUNT LEVEL DETAIL, a warning when there are findings.
# Nothing is printed on a server without the port registry.

# shellcheck disable=SC2154
alert_eval_ports_orphan() {
	local report
	if [ -n "${ALERTS_FIXTURES-}" ] && [ -f "$ALERTS_FIXTURES/ports" ]; then
		report=$(cat "$ALERTS_FIXTURES/ports")
	else
		[ -d "$ARCPANEL/data/ports" ] || return 0
		timeout 120 "$BIN/v-check-sys-ports" > /dev/null 2>&1 || return 1
		report=$(cat "$ARCPANEL/data/ports/.report.json" 2> /dev/null) || return 1
	fi
	jq -r '
		(.FINDINGS // []) as $f
		| ($f | length) as $n
		| ($f | group_by(.TYPE) | map("\(length) \(.[0].TYPE)") | join(", ")) as $d
		| "system \($n) \(if $n > 0 then "warning" else "ok" end) \($d)"
	' <<< "$report" || return 1
}
