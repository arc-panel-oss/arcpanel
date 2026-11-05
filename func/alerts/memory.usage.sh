#!/bin/bash
# E16 evaluator of memory.usage (spec §4): the used share of RAM and swap
# together from /proc/meminfo (MemTotal - MemAvailable plus the swap in use,
# of MemTotal plus SwapTotal). Prints system PERCENT LEVEL.

# shellcheck disable=SC2154
alert_eval_memory_usage() {
	local meminfo
	meminfo=$(alerts_src meminfo cat /proc/meminfo) || return 1
	awk -v w="$AE_WARNING" -v c="$AE_CRITICAL" '
		$1 == "MemTotal:" { mt = $2 }
		$1 == "MemAvailable:" { ma = $2 }
		$1 == "SwapTotal:" { st = $2 }
		$1 == "SwapFree:" { sf = $2 }
		END {
			if (mt == "" || ma == "" || mt + st <= 0) exit 1
			v = int(((mt - ma) + (st - sf)) * 100 / (mt + st) + 0.5)
			level = "ok"
			if (c != "" && v >= c + 0) level = "critical"
			else if (w != "" && v >= w + 0) level = "warning"
			printf "system %d %s RAM %d%% used, swap %d MB used\n", v, level, (mt - ma) * 100 / mt, (st - sf) / 1024
		}' <<< "$meminfo"
}
