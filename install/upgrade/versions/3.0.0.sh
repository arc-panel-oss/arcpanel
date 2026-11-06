#!/bin/bash
# ArcPanel 3.0.0 upgrade step (E20 Git Deploy,
# docs/features/specs/2026-10-29-001-e20-git-deploy-design.md).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# D17: the shipped PHP-FPM pool templates let PHP read the releases and
# shared folders of a deploy. Only the shipped names are changed; a custom
# template keeps working but cannot use deploy until the paths are added.
# The pools themselves are written again when a domain turns deploy on.
for tpl in "$ARCPANEL"/data/templates/web/php-fpm/default.tpl \
	"$ARCPANEL"/data/templates/web/php-fpm/socket.tpl \
	"$ARCPANEL"/data/templates/web/php-fpm/no-php.tpl \
	"$ARCPANEL"/data/templates/web/php-fpm/PHP-*.tpl; do
	[ -f "$tpl" ] || continue
	if grep -q '/web/%domain%/public_shtml:' "$tpl" && ! grep -q '/web/%domain%/releases' "$tpl"; then
		sed -i 's#/home/%user%/web/%domain%/public_shtml:#/home/%user%/web/%domain%/public_shtml:/home/%user%/web/%domain%/releases:/home/%user%/web/%domain%/shared:#' "$tpl"
		echo "[ * ] E20: $(basename "$tpl") allows the releases and shared folders of Git Deploy"
	fi
done

# The server settings of Git Deploy (§3.4); the port itself opens only when
# a domain turns deploy on
for upd_step_pair in DEPLOY_PORT=8088 DEPLOY_MAX_ARTIFACT=512M DEPLOY_BUILD_TIMEOUT=900 \
	DEPLOY_OIDC_ISSUERS=https://token.actions.githubusercontent.com,https://gitlab.com; do
	if ! grep -q "^${upd_step_pair%%=*}=" "$ARCPANEL/conf/arcpanel.conf"; then
		"$ARCPANEL/bin/v-change-sys-config-value" "${upd_step_pair%%=*}" "${upd_step_pair#*=}" > /dev/null 2>&1
	fi
done
unset upd_step_pair

# E15 Job Center (docs/features/specs/2026-11-05-001-e15-job-center-design.md
# §9): the finished task records of 2.6 become jobs with the same IDs, the
# lines of the backup and Let's Encrypt pipes become queued jobs, and records
# that still run are imported by v-update-sys-jobs once they finish. The
# panel's database (data/arcpanel/) and its migrations come with
# v-add-sys-arcpanel, which every upgrade runs.
# shellcheck source=/usr/local/arcpanel/func/job.sh
source "$ARCPANEL/func/job.sh"
# shellcheck source=/usr/local/arcpanel/func/job-import.sh
source "$ARCPANEL/func/job-import.sh"
job_import_start

# The job queue runs every minute instead of the two queue pipes
upd_step_cron=/var/spool/cron/crontabs/arcpanelweb
if [ -f "$upd_step_cron" ]; then
	sed -i -e '/bin\/v-update-sys-queue backup/d' -e '/bin\/v-update-sys-queue letsencrypt/d' "$upd_step_cron"
	if ! grep -q 'bin/v-update-sys-jobs' "$upd_step_cron"; then
		# sed, not >>: the crontab folder is sticky and group-writable, so
		# fs.protected_regular refuses even root an O_CREAT open of the file
		sed -i -e '$a* * * * * sudo /usr/local/arcpanel/bin/v-update-sys-jobs > /dev/null 2>&1' "$upd_step_cron"
		echo "[ * ] E15: the job queue runs every minute from cron"
	fi
fi
unset upd_step_cron
rm -f "$ARCPANEL/bin/v-run-queue-task"

# E16 Alerts (docs/features/specs/2026-11-05-002-e16-alerts-design.md §9):
# data/alerts/, the default rules and their channels, the engine every
# minute; the port check loses its notify mode
"$ARCPANEL/bin/v-add-sys-alerts"

# E17 Doctor (docs/features/specs/2026-11-05-003-e17-doctor-design.md §3.4):
# the nightly run of every check at 04:40, after the backups
upd_step_cron=/var/spool/cron/crontabs/arcpanelweb
if [ -f "$upd_step_cron" ] && ! grep -q 'bin/v-run-sys-doctor' "$upd_step_cron"; then
	# sed, not >> (fs.protected_regular, see the E15 step above)
	sed -i -e "\$a40 04 * * * sudo /usr/local/arcpanel/bin/v-run-sys-doctor all '' cron > /dev/null 2>&1" "$upd_step_cron"
	echo "[ * ] E17: the Doctor runs every night from cron"
fi
unset upd_step_cron
