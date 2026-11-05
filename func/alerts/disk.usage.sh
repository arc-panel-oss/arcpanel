#!/bin/bash
# E16 evaluator of disk.usage (spec §4): the used share of each mounted file
# system from `df -P`, per mount point (OPTIONS: the mount points to watch,
# all real file systems when empty). Prints mount:PATH PERCENT LEVEL.

# shellcheck disable=SC2154
alert_eval_disk_usage() {
	local out
	out=$(alerts_src df df -P -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs -x 9p -x drvfs) || return 1
	alerts_df_levels "$out"
}

# Lines of df -P (or df -Pi) output to OBJECT VALUE LEVEL, with the AE_*
# thresholds and options
alerts_df_levels() {
	awk -v w="$AE_WARNING" -v c="$AE_CRITICAL" -v opts=",$AE_OPTIONS," '
		NR == 1 || NF != 6 { next }
		{
			mnt = $6
			pct = $5
			sub("%", "", pct)
			if (pct !~ /^[0-9]+$/) next
			if (opts != ",," && index(opts, "," mnt ",") == 0) next
			if (seen[mnt]++) next
			level = "ok"
			if (c != "" && pct + 0 >= c + 0) level = "critical"
			else if (w != "" && pct + 0 >= w + 0) level = "warning"
			print "mount:" mnt, pct, level
		}' <<< "$1"
}
