#!/usr/bin/env bats
# Remote backup hosts and the nightly runs: the fixes of E48 M0
# (docs/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md §13).
# Run as root on a test server with expect, rclone, restic and sshd on port 22.
# Skips when the server already has an SFTP or rclone backup host.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
BH_USER=arcbatsbh
BH_PACKAGE=arcbats-bh-incremental
SFTP_USER=arcbatssftp
SFTP_HOME=/var/tmp/arcbatssftp
RCLONE_DIR=/var/tmp/arcbatsrclone
RCLONE_REMOTE=arcbatsrclone
REPO_DIR=/backup/restic-arcbatsbh
STATE="$BATS_FILE_TMPDIR/state"

conf_value() {
    grep "^$1=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2
}

setup_file() {
    local tmp pass
    if [ -e "$ARCPANEL/conf/sftp.backup.conf" ] || [ -e "$ARCPANEL/conf/rclone.backup.conf" ] \
        || [ -e "$ARCPANEL/conf/restic.conf" ]; then
        echo skip > "$STATE"
        return 0
    fi
    {
        echo "BACKUP_SYSTEM=$(conf_value BACKUP_SYSTEM)"
        echo "BACKUP_INCREMENTAL=$(conf_value BACKUP_INCREMENTAL)"
    } > "$STATE"

    # Leftovers of an interrupted run
    v-delete-user "$BH_USER" > /dev/null 2>&1 || true
    userdel -r "$SFTP_USER" > /dev/null 2>&1 || true
    rm -rf "$SFTP_HOME" "$RCLONE_DIR" "$REPO_DIR"

    # An SFTP account on this server that accepts one key
    useradd -m -d "$SFTP_HOME" -s /bin/bash "$SFTP_USER"
    install -d -m 700 -o "$SFTP_USER" -g "$SFTP_USER" "$SFTP_HOME/.ssh" "$SFTP_HOME/backup"
    ssh-keygen -q -t ed25519 -N '' -f "$BATS_FILE_TMPDIR/sftp-key" > /dev/null
    install -m 600 -o "$SFTP_USER" -g "$SFTP_USER" "$BATS_FILE_TMPDIR/sftp-key.pub" "$SFTP_HOME/.ssh/authorized_keys"

    # An rclone remote that points at a local folder
    mkdir -p "$RCLONE_DIR" /root/.config/rclone
    touch /root/.config/rclone/rclone.conf
    printf '\n[%s]\ntype = alias\nremote = %s\n' "$RCLONE_REMOTE" "$RCLONE_DIR" >> /root/.config/rclone/rclone.conf

    tmp=$(mktemp)
    sed "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$ARCPANEL/data/packages/default.pkg" > "$tmp"
    grep -q "^BACKUPS_INCREMENTAL=" "$tmp" || echo "BACKUPS_INCREMENTAL='yes'" >> "$tmp"
    v-add-user-package "$tmp" "$BH_PACKAGE" > /dev/null 2>&1 || true
    rm -f "$tmp"
    pass="Bh-$(date +%s)-Pass"
    v-add-user "$BH_USER" "$pass" "$BH_USER@example.invalid" "$BH_PACKAGE" > /dev/null
}

teardown_file() {
    local system incremental
    [ "$(cat "$STATE" 2> /dev/null)" = skip ] && return 0
    v-delete-backup-host sftp > /dev/null 2>&1 || true
    v-delete-backup-host rclone > /dev/null 2>&1 || true
    v-delete-backup-host-restic > /dev/null 2>&1 || rm -f "$ARCPANEL/conf/restic.conf"
    system=$(grep '^BACKUP_SYSTEM=' "$STATE" | cut -d= -f2)
    incremental=$(grep '^BACKUP_INCREMENTAL=' "$STATE" | cut -d= -f2)
    sed -i "s/^BACKUP_SYSTEM=.*/BACKUP_SYSTEM='$system'/" "$ARCPANEL/conf/arcpanel.conf"
    v-change-sys-config-value BACKUP_INCREMENTAL "${incremental:-no}" > /dev/null 2>&1 || true
    v-delete-user "$BH_USER" > /dev/null 2>&1 || true
    v-delete-user-package "$BH_PACKAGE" > /dev/null 2>&1 || true
    userdel -r "$SFTP_USER" > /dev/null 2>&1 || true
    sed -i "/^\[$RCLONE_REMOTE\]$/,/^remote = /d" /root/.config/rclone/rclone.conf
    rm -rf "$SFTP_HOME" "$RCLONE_DIR" "$REPO_DIR"
}

setup() {
    if [ "$(cat "$STATE" 2> /dev/null)" = skip ]; then
        skip "the server already has an SFTP, rclone or restic backup host"
    fi
}

@test "sftp: a key given in a temporary file keeps working after the file is gone" {
    local key="$BATS_TEST_TMPDIR/panel-secret"
    cp "$BATS_FILE_TMPDIR/sftp-key" "$key"
    run v-add-backup-host sftp 127.0.0.1 "$SFTP_USER" "$key" "$SFTP_HOME/backup" 22
    assert_success
    rm -f "$key"
    assert_file_exists "$ARCPANEL/conf/sftp.backup.key"
    run stat -c '%a %U' "$ARCPANEL/conf/sftp.backup.key"
    assert_output '600 root'
    run grep "^PASSWORD='$ARCPANEL/conf/sftp.backup.key'" "$ARCPANEL/conf/sftp.backup.conf"
    assert_success

    run v-backup-user "$BH_USER"
    assert_success
    run sh -c "ls '$SFTP_HOME/backup' | grep -c '^$BH_USER\.'"
    assert_output '1'
}

@test "sftp: deleting a backup prints nothing" {
    local archive
    archive=$(ls "$SFTP_HOME/backup" | grep "^$BH_USER\." | head -n1)
    [ -n "$archive" ]
    run v-delete-user-backup "$BH_USER" "$archive"
    assert_success
    assert_output ''
    assert_file_not_exists "$SFTP_HOME/backup/$archive"
}

@test "sftp: deleting the host removes the key" {
    run v-delete-backup-host sftp
    assert_success
    assert_file_not_exists "$ARCPANEL/conf/sftp.backup.key"
}

@test "rclone: without a folder the archive goes to the root of the remote" {
    run v-add-backup-host rclone "$RCLONE_REMOTE" '' '' ''
    assert_success
    run v-backup-user "$BH_USER"
    assert_success
    run sh -c "ls '$RCLONE_DIR' | grep -c '^$BH_USER\..*\.tar$'"
    assert_output '1'
}

@test "rclone: a remote-only archive downloads back to the server" {
    local archive
    archive=$(ls "$RCLONE_DIR" | grep "^$BH_USER\..*\.tar$" | head -n1)
    rm -f "/backup/$archive"
    run v-download-backup "$BH_USER" "$archive"
    assert_success
    assert_file_exists "/backup/$archive"
    rm -f "/backup/$archive"
    v-delete-backup-host rclone > /dev/null
}

@test "restic: a server without archive backups still makes snapshots" {
    sed -i "s/^BACKUP_SYSTEM=.*/BACKUP_SYSTEM=''/" "$ARCPANEL/conf/arcpanel.conf"
    mkdir -p "$REPO_DIR"
    run v-add-backup-host-restic "$REPO_DIR" 5 -1 -1 -1 -1
    assert_success
    run v-backup-user-restic "$BH_USER"
    assert_success
    run v-list-user-backups-restic "$BH_USER" json
    assert_success
    run sh -c "v-list-user-backups-restic '$BH_USER' json | jq 'length'"
    assert_output '1'
}

@test "restic: the nightly run makes snapshots after the archives" {
    run v-backup-users
    assert_success
    run sh -c "v-list-user-backups-restic '$BH_USER' json | jq 'length'"
    assert_output '2'
}
