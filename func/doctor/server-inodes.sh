#!/bin/bash
# E17 check server.inodes (spec §4.1): free inodes per mount point; warn
# below 10%, fail below 5%.
# shellcheck disable=SC2034
DOCTOR_ID=server.inodes
DOCTOR_SCOPE=server
DOCTOR_TITLE='Every file system has free inodes'
DOCTOR_DOCS='/docs/guides/doctor#server-inodes'
DOCTOR_FIX_ALLOWED=''

doctor_check() {
	local out worst readonly
	if ! out=$(doctor_src dfi df -Pi -x tmpfs -x devtmpfs -x squashfs -x overlay 2> /dev/null); then
		dr_skip 'The inodes could not be read.'
		return
	fi
	# Read-only mounts never get new files
	readonly=$(awk '$4 ~ /(^|,)ro(,|$)/ { print $2 }' /proc/mounts 2> /dev/null | tr '\n' ' ')
	# MOUNT FREE_PERCENT of each writable file system with inodes, lowest
	# first
	worst=$(awk -v ro=" $readonly" 'NR > 1 && $2 ~ /^[0-9]+$/ && $2 > 0 && index(ro, " " $6 " ") == 0 { printf "%s %d\n", $6, ($4 * 100) / $2 }' <<< "$out" | sort -k2,2n | head -n1)
	if [ -z "$worst" ]; then
		dr_skip 'No file system reports inodes.'
		return
	fi
	local mount=${worst% *} free=${worst##* }
	if [ "$free" -lt 5 ]; then
		dr_fail "Only $free% of the inodes of $mount are free: new files cannot be created soon. Delete small files (caches, sessions, mail queues)." "$mount $free% free"
	elif [ "$free" -lt 10 ]; then
		dr_warn "Only $free% of the inodes of $mount are free." "$mount $free% free"
	else
		dr_ok "Every file system has at least $free% free inodes." "$mount $free% free"
	fi
}
