#!/bin/bash
# ArcPanel 2.7.0 upgrade step (E20 Git Deploy,
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
