#!/bin/bash
# ArcPanel 2.6.0 upgrade step (E39 W5 resources and hardening,
# docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md §14).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# Records that the stricter validators of 2.6.0 refuse (§6) keep working, but
# a change of them is refused until they are corrected. List them in the
# upgrade log so that the administrator can correct them: object names with
# "|", account names with quotes, $, backtick, < or >, and cron fields outside
# the crontab grammar. The checks run in a subshell with the new main.sh, so
# a shell that still holds the functions of the old package uses the new ones
# and a refusal (check_result exits) ends only the subshell.
(
	# shellcheck source=/usr/local/arcpanel/func/main.sh
	source "$ARCPANEL/func/main.sh" > /dev/null 2>&1 || exit 0
	declare -F is_cron_format_valid > /dev/null || exit 0
	found=0
	for dir in "$ARCPANEL"/data/users/*/; do
		user=$(basename "$dir")
		[ -f "$dir/user.conf" ] || continue
		name=$(grep -oE "^NAME='[^']*'" "$dir/user.conf" | cut -d"'" -f2)
		if [[ "$name" =~ [\"\$\`\<\>\\] ]] || [[ "$name" == *[[:cntrl:]]* ]]; then
			echo "[ ! ] User $user: the name holds a character that 2.6.0 refuses (quotes, \$, backtick, < or >)"
			found=$((found + 1))
		fi
		for conf in "$dir"*.conf; do
			[ -f "$conf" ] && [ "${conf##*/}" != 'user.conf' ] || continue
			# The first key of a record is the object's name
			if grep -qE "^[A-Z_]+='[^']*\|" "$conf"; then
				echo "[ ! ] User $user: $(basename "$conf") has an object name with '|', which 2.6.0 refuses"
				found=$((found + 1))
			fi
		done
		[ -f "$dir/cron.conf" ] || continue
		while IFS= read -r line; do
			job=$(grep -oE "^JOB='[^']*'" <<< "$line" | cut -d"'" -f2)
			for field in MIN HOUR DAY MONTH WDAY; do
				value=$(grep -oE "(^| )$field='[^']*'" <<< "$line" | cut -d"'" -f2)
				lower=$(tr '[:upper:]' '[:lower:]' <<< "$field")
				if ! (is_cron_format_valid "$value" "$lower") > /dev/null 2>&1; then
					echo "[ ! ] User $user: cron job $job has a $lower field outside the crontab grammar of 2.6.0"
					found=$((found + 1))
					break
				fi
			done
		done < "$dir/cron.conf"
	done
	if [ "$found" -gt 0 ]; then
		echo "[ ! ] $found records above keep working; correct them before they are changed again (release notes of 2.6.0)"
	fi
)

# File Manager staging limits per account (§7): the panel reads these keys
# from v-list-sys-config now; values the administrator set are kept
for upd_step_pair in FILE_MANAGER_UPLOAD_MAX=4G FILE_STAGING_SESSIONS=4 FILE_STAGING_LIMIT=8G FILE_STAGING_RESERVE=10; do
	if ! grep -q "^${upd_step_pair%%=*}=" "$ARCPANEL/conf/arcpanel.conf"; then
		"$ARCPANEL/bin/v-change-sys-config-value" "${upd_step_pair%%=*}" "${upd_step_pair#*=}" > /dev/null 2>&1
	fi
done
unset upd_step_pair
