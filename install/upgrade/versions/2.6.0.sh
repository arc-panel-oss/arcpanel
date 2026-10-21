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

# Browser headers (§9): the map that keeps the panel's cookies from the tools
# comes before the phpMyAdmin and phpPgAdmin includes that use it (N32)
if [ -d /etc/nginx/conf.d ]; then
	cp -f "$ARCPANEL/install/deb/nginx/arcpanel-tool-cookie.conf" /etc/nginx/conf.d/
	for upd_step_inc in /etc/nginx/conf.d/phpmyadmin.inc /etc/nginx/conf.d/phppgadmin.inc; do
		if [ -f "$upd_step_inc" ] && ! grep -q 'arcpanel_tool_cookie' "$upd_step_inc"; then
			sed -i 's|^\(\s*\)fastcgi_param SCRIPT_FILENAME \$request_filename;|&\n\1fastcgi_param HTTP_COOKIE     $arcpanel_tool_cookie;|' "$upd_step_inc"
		fi
	done
	unset upd_step_inc
	"$ARCPANEL/bin/v-update-sys-tool-ports" yes > /dev/null 2>&1
fi
# Content-Security-Policy and an empty HSTS file of the panel
"$ARCPANEL/bin/v-update-sys-panel-headers" > /dev/null 2>&1

# The internal endpoint template closes the admin API of Silo (N29): the new
# template files, then the endpoint domain written again with them
for upd_step_dir in nginx nginx/php-fpm; do
	if [ -d "$ARCPANEL/data/templates/web/$upd_step_dir" ]; then
		cp -f "$ARCPANEL/install/deb/templates/web/$upd_step_dir"/silo-endpoint.*tpl "$ARCPANEL/data/templates/web/$upd_step_dir/"
	fi
done
unset upd_step_dir
upd_step_root=$(grep -m1 "^ROOT_USER=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
upd_step_host=$(grep -m1 "^SILO_HOSTNAME=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
if [ -n "$upd_step_host" ] && grep -q "^DOMAIN='$upd_step_host' " "$ARCPANEL/data/users/${upd_step_root:-admin}/web.conf" 2> /dev/null; then
	"$ARCPANEL/bin/v-rebuild-web-domain" "${upd_step_root:-admin}" "$upd_step_host" yes > /dev/null 2>&1
fi
unset upd_step_root upd_step_host

# Two-factor authentication (§11.4): the QR code that 1.x kept in user.conf
# holds the secret; it is no longer written or printed, so it goes
for upd_step_conf in "$ARCPANEL"/data/users/*/user.conf; do
	[ -f "$upd_step_conf" ] || continue
	sed -i "s/^QRCODE='[^']*'$/QRCODE=''/" "$upd_step_conf"
done
unset upd_step_conf
