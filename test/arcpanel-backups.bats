#!/usr/bin/env bats
# ArcPanel backups (E7 batch 1,
# docs/features/specs/2026-10-06-001-e7-batch1-pages-design.md §10.4).
# Run as root on a test server with restic installed. Uses a local restic
# repository below /backup when the server has none, and removes it again.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PIPE=$ARCPANEL/data/queue/backup.pipe
BK_USER=arcbatsbk
BK_PLAIN=arcbatsbk2
BK_PACKAGE=arcbats-incremental
REPO_DIR=/backup/restic-arcbats
STATE="$BATS_FILE_TMPDIR/restic-state"

setup_file() {
    local tmp pass
    # Leftovers of an interrupted run
    v-delete-user "$BK_USER" > /dev/null 2>&1 || true
    v-delete-user "$BK_PLAIN" > /dev/null 2>&1 || true
    rm -rf "$REPO_DIR/$BK_USER"
    if [ -f "$ARCPANEL/conf/restic.conf" ]; then
        echo existing > "$STATE"
    else
        echo created > "$STATE"
        mkdir -p "$REPO_DIR"
        v-add-backup-host-restic "$REPO_DIR" 5 -1 -1 -1 -1 > /dev/null
    fi
    tmp=$(mktemp)
    sed "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$ARCPANEL/data/packages/default.pkg" > "$tmp"
    grep -q "^BACKUPS_INCREMENTAL=" "$tmp" || echo "BACKUPS_INCREMENTAL='yes'" >> "$tmp"
    v-add-user-package "$tmp" "$BK_PACKAGE" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$BK_PACKAGE" > /dev/null
    rm -f "$tmp"
    pass="Bk-$(date +%s)-Pass"
    v-add-user "$BK_USER" "$pass" "$BK_USER@example.invalid" "$BK_PACKAGE" > /dev/null
    v-add-user "$BK_PLAIN" "$pass" "$BK_PLAIN@example.invalid" default > /dev/null
    runuser -u "$BK_USER" -- sh -c "mkdir -p /home/$BK_USER/tmp/notes && echo original > /home/$BK_USER/tmp/notes/a.txt"
    v-backup-user-restic "$BK_USER" > /dev/null 2>&1
}

teardown_file() {
    sed -i "/'$BK_USER'/d" "$PIPE" 2> /dev/null || true
    v-delete-user "$BK_USER" > /dev/null 2>&1 || true
    v-delete-user "$BK_PLAIN" > /dev/null 2>&1 || true
    v-delete-user-package "$BK_PACKAGE" > /dev/null 2>&1 || true
    if [ "$(cat "$STATE" 2> /dev/null)" = created ]; then
        v-delete-backup-host-restic > /dev/null 2>&1 || rm -f "$ARCPANEL/conf/restic.conf"
        v-change-sys-config-value BACKUP_INCREMENTAL no > /dev/null 2>&1 || true
        rm -rf "$REPO_DIR"
    fi
}

snapshot_id() {
    v-list-user-backups-restic "$BK_USER" json | jq -r '.[0].short_id'
}

@test "v-schedule-user-restore-file-restic queues one self-removing line and the restore works" {
    local snapshot line file
    snapshot=$(snapshot_id)
    [ -n "$snapshot" ] && [ "$snapshot" != null ]
    runuser -u "$BK_USER" -- sh -c "echo changed > /home/$BK_USER/tmp/notes/a.txt"

    run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" "/home/$BK_USER/tmp/notes/a.txt"
    assert_success
    line=$(grep "v-restore-file-restic '$BK_USER' '$snapshot' '/home/$BK_USER/tmp/notes/a.txt' 'yes'" "$PIPE")
    [ "$(echo "$line" | wc -l)" -eq 1 ]
    [[ "$line" == *"# arcpanel-restore-file-"* ]]

    # A second restore of the user waits for the first one
    run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" "/home/$BK_USER/tmp/notes"
    assert_failure 4

    # Run only this line, as v-update-sys-queue would
    file=$(mktemp)
    printf '%s\n' "$line" > "$file"
    bash "$file"
    rm -f "$file"
    assert_equal "$(cat /home/$BK_USER/tmp/notes/a.txt)" original
    run grep -c "'$BK_USER'" "$PIPE"
    assert_output 0
}

@test "v-schedule-user-restore-file-restic refuses paths outside the home directory" {
    local snapshot
    snapshot=$(snapshot_id)
    run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" /etc/passwd
    assert_failure 10
    run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" "/home/$BK_PLAIN/tmp/notes"
    assert_failure 10
    run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" "/home/$BK_USER"
    assert_failure 10
    run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" "/home/${BK_USER}x/notes"
    assert_failure 10
    run grep -c "'$BK_USER'" "$PIPE"
    assert_output 0
}

@test "v-schedule-user-restore-file-restic refuses traversal and characters the queue would interpret" {
    local snapshot path
    snapshot=$(snapshot_id)
    for path in "/home/$BK_USER/../root/x" "/home/$BK_USER/./tmp" "/home/$BK_USER//tmp" "notes/a.txt" \
        "/home/$BK_USER/tmp/notes/a b.txt" "/home/$BK_USER/tmp/notes/a';id;'" "/home/$BK_USER/tmp/notes/*" '/home/'"$BK_USER"'/$(id)'; do
        run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" "$path"
        assert_failure 2
    done
    run grep -c "'$BK_USER'" "$PIPE"
    assert_output 0
}

@test "v-schedule-user-restore-file-restic checks the snapshot and the package" {
    run v-schedule-user-restore-file-restic "$BK_USER" 0123abcd "/home/$BK_USER/tmp/notes/a.txt"
    assert_failure 3
    run v-schedule-user-restore-file-restic "$BK_USER" 'bad id' "/home/$BK_USER/tmp/notes/a.txt"
    assert_failure 2
    run v-schedule-user-restore-file-restic "$BK_PLAIN" 0123abcd "/home/$BK_PLAIN/tmp/notes/a.txt"
    assert_failure 11
    run v-schedule-user-restore-file-restic "$BK_USER" "$(snapshot_id)"
    assert_failure 1
}

@test "v-schedule-user-restore-file-restic honours demo mode" {
    local snapshot
    snapshot=$(snapshot_id)
    # Demo mode also blocks v-change-sys-config-value, so it is switched in the file.
    v-change-sys-config-value DEMO_MODE yes
    run v-schedule-user-restore-file-restic "$BK_USER" "$snapshot" "/home/$BK_USER/tmp/notes/a.txt"
    sed -i "s/^DEMO_MODE='yes'/DEMO_MODE='no'/" "$ARCPANEL/conf/arcpanel.conf"
    assert_failure 1
    assert_output --partial 'security restrictions'
}
