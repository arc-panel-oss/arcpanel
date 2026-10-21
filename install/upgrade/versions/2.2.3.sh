#!/bin/bash
# ArcPanel 2.2.3 upgrade step (E39 W5 M1,
# docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md §4).
# Sourced by upgrade_start_routine (func/upgrade.sh); safe to run again.

# Access-key files that name a key more than once were not written by
# v-add-access-key as it is now: every reader refuses them. Move them out of
# the key directory, say which ones in the upgrade log and tell the
# administrator, so that the keys can be reviewed and made again.
upgrade_223_quarantine="$ARCPANEL/data/access-keys/.quarantine"
upgrade_223_moved=''
# Without the check of the new func/main.sh every file would look damaged
if ! declare -F access_key_file_ok > /dev/null; then
	echo "[ ! ] Access keys were not checked: func/main.sh has no access_key_file_ok"
	return 0 2> /dev/null || exit 0
fi
for upgrade_223_file in "$ARCPANEL"/data/access-keys/*; do
	[ -f "$upgrade_223_file" ] || continue
	if access_key_file_ok "$upgrade_223_file"; then
		continue
	fi
	if [ ! -d "$upgrade_223_quarantine" ]; then
		mkdir -p "$upgrade_223_quarantine"
	fi
	chown root:root "$upgrade_223_quarantine"
	chmod 700 "$upgrade_223_quarantine"
	upgrade_223_id="$(basename -- "$upgrade_223_file")"
	mv -f -- "$upgrade_223_file" "$upgrade_223_quarantine/$upgrade_223_id"
	echo "[ ! ] Access key $upgrade_223_id was damaged and has been moved to $upgrade_223_quarantine"
	upgrade_223_moved="$upgrade_223_moved $upgrade_223_id"
done

if [ -n "$upgrade_223_moved" ]; then
	"$BIN/v-add-user-notification" "$ROOT_USER" 'Access keys moved to quarantine' "<p>The upgrade to 2.2.3 found access keys whose files were changed by hand or written by an older version, and turned them off:${upgrade_223_moved}.</p><p>They are kept in data/access-keys/.quarantine. Review where they were used and create new keys.</p>" > /dev/null 2>&1 || true
fi
unset upgrade_223_quarantine upgrade_223_moved upgrade_223_file upgrade_223_id
