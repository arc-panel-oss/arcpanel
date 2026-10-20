#!/bin/bash
# ArcPanel 2.2.1 upgrade step (E46 webmail and database tools ports,
# docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# Webmail on 8086 and phpMyAdmin/phpPgAdmin on 8087 when the tools are
# installed and the ports are free; a busy port is left off with a warning
"$ARCPANEL/bin/v-add-sys-tool-ports"

# Webmail sign-on: the Dovecot passdb of session passwords, its cron job and
# the Roundcube plugin
"$ARCPANEL/bin/v-add-sys-mail-sso"
