#!/bin/bash
# E16 evaluator of quota.disk (spec §4): the disk used by each user of the
# scope (U_DISK of user.conf, MB) against the disk quota of its package
# (DISK_QUOTA; unlimited quotas are skipped). Prints user:NAME PERCENT LEVEL.

# Lines USER U_DISK DISK_QUOTA of the scope's users
alerts_quota_lines() {
	local user file
	if [ -n "${ALERTS_FIXTURES-}" ]; then
		cat "$ALERTS_FIXTURES/quota.$AE_SCOPE" 2> /dev/null
		return 0
	fi
	while IFS= read -r user; do
		file="$ARCPANEL/data/users/$user/user.conf"
		echo "$user $(grep -m1 "^U_DISK='" "$file" | cut -d"'" -f2) $(grep -m1 "^DISK_QUOTA='" "$file" | cut -d"'" -f2)"
	done < <(alerts_scope_users "$AE_SCOPE")
}

# shellcheck disable=SC2154
alert_eval_quota_disk() {
	alerts_quota_lines | awk -v w="$AE_WARNING" -v c="$AE_CRITICAL" '
		$2 ~ /^[0-9]+$/ && $3 ~ /^[0-9]+$/ && $3 > 0 {
			v = int($2 * 100 / $3)
			level = "ok"
			if (c != "" && v >= c + 0) level = "critical"
			else if (w != "" && v >= w + 0) level = "warning"
			print "user:" $1, v, level, $2 " of " $3 " MB"
		}'
}
