#!/bin/bash
# E16 evaluator of inode.usage (spec §4): the used share of the inodes of
# each mounted file system from `df -Pi` (file systems without inodes are
# skipped). Prints mount:PATH PERCENT LEVEL.

# shellcheck disable=SC2154
alert_eval_inode_usage() {
	local out
	out=$(alerts_src dfi df -Pi -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs -x 9p -x drvfs) || return 1
	# shellcheck source=/usr/local/arcpanel/func/alerts/disk.usage.sh
	source "$ARCPANEL/func/alerts/disk.usage.sh"
	alerts_df_levels "$out"
}
