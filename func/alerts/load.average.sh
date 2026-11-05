#!/bin/bash
# E16 evaluator of load.average (spec §4): the five-minute load average of
# /proc/loadavg per core (nproc). Prints system LOAD LEVEL.

# shellcheck disable=SC2154
alert_eval_load_average() {
	local loadavg cores
	loadavg=$(alerts_src loadavg cat /proc/loadavg) || return 1
	cores=$(alerts_src nproc nproc) || return 1
	[[ "$cores" =~ ^[0-9]+$ ]] && [ "$cores" -ge 1 ] || return 1
	awk -v cores="$cores" -v w="$AE_WARNING" -v c="$AE_CRITICAL" '
		NR == 1 {
			if ($2 !~ /^[0-9.]+$/) exit 1
			v = sprintf("%.2f", $2 / cores)
			level = "ok"
			if (c != "" && v + 0 >= c + 0) level = "critical"
			else if (w != "" && v + 0 >= w + 0) level = "warning"
			print "system", v, level, "load " $2 " on " cores " cores"
		}' <<< "$loadavg"
}
