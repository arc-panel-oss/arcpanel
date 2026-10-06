#!/usr/bin/env bats
# Backup archive formats after the full rename (D8 of
# docs/features/specs/2026-10-06-005-e9-full-rename-design.md): ArcPanel
# writes its objects into ./arcpanel/ directories and restores archives made
# by ArcPanel, by HestiaCP and ArcPanel 1.x (./hestia/) and by VestaCP
# (./vesta/). The HestiaCP and VestaCP archives are made from an ArcPanel
# archive by renaming its directories, so the suite needs no download.
# Run as root on a server installed with the ArcPanel installer. This file
# names the old formats on purpose (scripts/rename/keep.txt).

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

BF_USER="arcbfmt"
BF_DOMAIN="arcbfmt.example"
BACKUP_DIR="/backup"

setup_file() {
	local pass
	pass="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
	v-delete-user "$BF_USER" > /dev/null 2>&1 || true
	v-add-user "$BF_USER" "$pass" "$BF_USER@example.invalid" default > /dev/null
	v-add-web-domain "$BF_USER" "$BF_DOMAIN" > /dev/null
	echo "arcbfmt marker" > "/home/$BF_USER/web/$BF_DOMAIN/public_html/arcbfmt.txt"
	v-add-cron-job "$BF_USER" '17' '3' '*' '*' '*' "echo arcbfmt" > /dev/null
	rm -f "$BACKUP_DIR/$BF_USER".*.tar
	v-backup-user "$BF_USER" > /dev/null
	ARCHIVE="$(ls -t "$BACKUP_DIR/$BF_USER".*.tar | head -n1)"
	export ARCHIVE
	cp "$ARCHIVE" "$BATS_FILE_TMPDIR/arcpanel.tar"
}

teardown_file() {
	v-delete-user "$BF_USER" > /dev/null 2>&1 || true
	rm -f "$BACKUP_DIR/$BF_USER".*.tar
}

# Copy of the ArcPanel archive with every ./…/arcpanel directory renamed to $1
bf_convert() {
	local system=$1 dir
	dir="$BATS_TEST_TMPDIR/$system"
	rm -rf "$dir" && mkdir -p "$dir"
	tar -xf "$BATS_FILE_TMPDIR/arcpanel.tar" -C "$dir"
	find "$dir" -depth -type d -name arcpanel -execdir mv arcpanel "$system" \;
	tar -cf "$BACKUP_DIR/$(basename "$ARCHIVE")" -C "$dir" .
}

bf_restore_and_check() {
	v-delete-user "$BF_USER" > /dev/null
	run v-restore-user "$BF_USER" "$(basename "$ARCHIVE")" '*' 'no' 'no' 'no' '*' '*' 'no'
	assert_success
	run v-list-web-domain "$BF_USER" "$BF_DOMAIN" json
	assert_success
	assert_file_exists "/home/$BF_USER/web/$BF_DOMAIN/public_html/arcbfmt.txt"
	run grep -c "echo arcbfmt" "/usr/local/arcpanel/data/users/$BF_USER/cron.conf"
	assert_output '1'
}

@test "Backups write ./arcpanel directories and no old name" {
	run tar -tf "$BATS_FILE_TMPDIR/arcpanel.tar"
	assert_line './arcpanel/'
	assert_line --regexp "^\./web/$BF_DOMAIN/arcpanel/web\.conf$"
	refute_output --regexp '(^|/)(hestia|vesta)/'
}

@test "An ArcPanel archive restores" {
	cp "$BATS_FILE_TMPDIR/arcpanel.tar" "$ARCHIVE"
	bf_restore_and_check
}

@test "A HestiaCP or ArcPanel 1.x archive (./hestia) restores" {
	bf_convert hestia
	run tar -tf "$ARCHIVE" ./hestia
	assert_success
	bf_restore_and_check
}

@test "A VestaCP archive (./vesta) restores" {
	bf_convert vesta
	run tar -tf "$ARCHIVE" ./vesta
	assert_success
	bf_restore_and_check
}

# Restoring the administrator is not done here; this checks the substitution
# that v-restore-user applies to the administrator's cron.conf.
@test "The admin's cron paths of a HestiaCP archive point at /usr/local/arcpanel" {
	local dir conf
	dir="$BATS_TEST_TMPDIR/cron"
	mkdir -p "$dir/cron"
	conf="$dir/cron/cron.conf"
	echo "JOB='1' MIN='10' HOUR='00' DAY='*' MONTH='*' WDAY='*' CMD='sudo /usr/local/hestia/bin/v-update-sys-hestia-all' SUSPENDED='no' TIME='00:00:00' DATE='2026-01-01'" > "$conf"
	# the same substitution v-restore-user applies to the admin's cron.conf
	backup_system=hestia
	sed -i "s/$backup_system/arcpanel/g" "$conf"
	run cat "$conf"
	assert_output --partial "CMD='sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all'"
	assert_file_exists /usr/local/arcpanel/bin/v-update-sys-arcpanel-all
	run grep -c 'sed -i "s/$backup_system/arcpanel/g" $tmpdir/cron/cron.conf' /usr/local/arcpanel/bin/v-restore-user
	assert_output '1'
}
