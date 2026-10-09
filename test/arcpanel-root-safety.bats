#!/usr/bin/env bats
# ArcPanel root safety (E39 W1,
# docs/features/specs/2026-10-10-001-e39-w1-root-in-user-paths-design.md §13).
# Root commands must not work in paths the hosting user controls, must
# validate what they read from backups and must write into a home as the
# user. Run as root on a test server with restic installed. Uses a local
# restic repository below /backup when the server has none, and removes it
# again.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
RS_USER=arcbatsrs
RS_OTHER=arcbatsrs2
RS_PACKAGE=arcbats-rs-incremental
REPO_DIR=/backup/restic-arcbatsrs
STATE="$BATS_FILE_TMPDIR/restic-state"
TARGET=/root/arcbats-rs-target

setup_file() {
    local tmp pass
    v-delete-user "$RS_USER" > /dev/null 2>&1 || true
    v-delete-user "$RS_OTHER" > /dev/null 2>&1 || true
    rm -rf "$REPO_DIR/$RS_USER" "$TARGET"
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
    v-add-user-package "$tmp" "$RS_PACKAGE" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$RS_PACKAGE" > /dev/null
    rm -f "$tmp"
    pass="Rs-$(date +%s)-Pass"
    v-add-user "$RS_USER" "$pass" "$RS_USER@example.invalid" "$RS_PACKAGE" > /dev/null
    v-add-user "$RS_OTHER" "$pass" "$RS_OTHER@example.invalid" default > /dev/null
    runuser -u "$RS_USER" -- sh -c "mkdir -p '/home/$RS_USER/tmp/notes' '/home/$RS_USER/tmp/my notes' \
        && echo original > '/home/$RS_USER/tmp/notes/a.txt' && echo spaced > '/home/$RS_USER/tmp/my notes/b.txt'"
    v-backup-user-restic "$RS_USER" > /dev/null 2>&1
}

teardown_file() {
    v-delete-user "$RS_USER" > /dev/null 2>&1 || true
    v-delete-user "$RS_OTHER" > /dev/null 2>&1 || true
    v-delete-user-package "$RS_PACKAGE" > /dev/null 2>&1 || true
    rm -rf "$TARGET"
    if [ "$(cat "$STATE" 2> /dev/null)" = created ]; then
        v-delete-backup-host-restic > /dev/null 2>&1 || rm -f "$ARCPANEL/conf/restic.conf"
        v-change-sys-config-value BACKUP_INCREMENTAL no > /dev/null 2>&1 || true
        rm -rf "$REPO_DIR"
    fi
}

snapshot_id() {
    v-list-user-backups-restic "$RS_USER" json | jq -r '.[0].short_id'
}

# Runs a snippet with the libraries of the commands loaded
lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/safe-fs.sh; user=$RS_USER; $1"
}

#----------------------------------------------------------#
#                 M1: library and arguments                #
#----------------------------------------------------------#

@test "safe_workdir creates a root-only folder below .arcpanel-work and removes it on exit" {
    run lib 'safe_workdir test; stat -c "%u %a" "$SAFE_WORKDIR" "$(dirname "$SAFE_WORKDIR")"; echo "$SAFE_WORKDIR"'
    assert_success
    assert_line --index 0 '0 700'
    assert_line --index 1 '0 700'
    [[ "${lines[2]}" == /backup/.arcpanel-work/test.* ]]
    assert_not_exist "${lines[2]}"
}

@test "safe_workdir refuses a .arcpanel-work that is a link or not root's" {
    local saved=/backup/.arcpanel-work.bats-saved
    mv /backup/.arcpanel-work "$saved" 2> /dev/null || true
    mkdir -p "$TARGET"
    ln -s "$TARGET" /backup/.arcpanel-work
    run lib 'safe_workdir test'
    rm -f /backup/.arcpanel-work
    assert_failure 10
    mkdir -m 700 /backup/.arcpanel-work
    chown "$RS_USER" /backup/.arcpanel-work
    run lib 'safe_workdir test'
    rm -rf /backup/.arcpanel-work
    [ -d "$saved" ] && mv "$saved" /backup/.arcpanel-work
    assert_failure 10
    run find "$TARGET" -mindepth 1
    assert_output ''
}

@test "is_restore_name_valid refuses traversal, options and control characters" {
    run lib 'for n in example.com sub.example.com; do is_restore_name_valid domain "$n" || echo "bad $n"; done'
    assert_output ''
    run lib 'for n in ../x -x "a/b" "." "a..b" "ex ample.com" "x'"'"'y" $'"'"'a\nb'"'"'; do is_restore_name_valid domain "$n" && echo "passed $n"; done; true'
    assert_output ''
    run lib 'is_restore_name_valid database "arcbatsrs_db" && is_restore_name_valid segment "my notes" && echo ok'
    assert_output ok
}

@test "parse_backup_record sets allow-listed keys only and refuses quotes and new lines" {
    run lib "parse_backup_record 'DOMAIN IP' \"DOMAIN='a.example' IP='1.2.3.4' USER_DATA='/etc' PATH='/arcbats-path'\" && echo \"\$DOMAIN \$IP \$USER_DATA \$PATH\""
    refute_output --partial '/etc'
    refute_output --partial '/arcbats-path'
    assert_output --partial 'a.example 1.2.3.4 /usr/local/arcpanel/data/users/'
    # A key in lower case would name a variable of the script: the record is refused
    run lib "tmpdir=/keep; parse_backup_record 'DOMAIN' \"DOMAIN='a.example' tmpdir='/etc'\" || echo \"refused \$tmpdir\""
    assert_output 'refused /keep'
    run lib "parse_backup_record 'DOMAIN' \"DOMAIN='a\\\$(id)'\" || echo refused"
    assert_output refused
    run lib "parse_backup_record 'DOMAIN' \$'DOMAIN=\\'a\\'\\nIP=\\'1\\'' || echo refused"
    assert_output refused
    run lib "parse_backup_record 'CMD' \"CMD='echo \\\$HOME > /dev/null'\" && echo \"\$CMD\""
    assert_output 'echo $HOME > /dev/null'
}

@test "restic executors refuse a snapshot that is not an ID" {
    local snapshot
    for snapshot in --help '-x' 'abc|x' 'latest x' 0123ABCD; do
        run v-list-user-files-restic "$RS_USER" "$snapshot" "/home/$RS_USER"
        assert_failure 2
        run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
        assert_failure 2
        run v-delete-user-backup-restic "$RS_USER" "$snapshot"
        assert_failure 2
    done
}

@test "v-list-user-files-restic lists a folder with spaces and refuses folders outside the home" {
    local snapshot
    snapshot=$(snapshot_id)
    run v-list-user-files-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/my notes"
    assert_success
    assert_output --partial '"path":"/home/arcbatsrs/tmp/my notes/b.txt"'
    for folder in /etc "/home/$RS_OTHER" "/home/${RS_USER}x" "/home/$RS_USER/../$RS_OTHER" "/home/$RS_USER/./tmp" "/home/$RS_USER//tmp" tmp; do
        run v-list-user-files-restic "$RS_USER" "$snapshot" "$folder"
        assert_failure 10
    done
}

@test "v-restore-file-restic refuses paths outside the home" {
    local snapshot
    snapshot=$(snapshot_id)
    for path in /etc/passwd "/home/$RS_USER" "/home/$RS_USER/../$RS_OTHER/x" "/home/${RS_USER}x/a" "/home/$RS_USER/./tmp" notes/a.txt; do
        run v-restore-file-restic "$RS_USER" "$snapshot" "$path"
        assert_failure 10
    done
}

@test "v-restore-file-restic restores as the user, quoted, and never through a link" {
    local snapshot
    snapshot=$(snapshot_id)
    runuser -u "$RS_USER" -- sh -c "echo changed > '/home/$RS_USER/tmp/notes/a.txt'; rm -rf '/home/$RS_USER/tmp/my notes'"
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
    assert_success
    assert_equal "$(cat "/home/$RS_USER/tmp/notes/a.txt")" original
    assert_equal "$(stat -c %U "/home/$RS_USER/tmp/notes/a.txt")" "$RS_USER"
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/my notes"
    assert_success
    assert_equal "$(cat "/home/$RS_USER/tmp/my notes/b.txt")" spaced
    assert_equal "$(stat -c %U "/home/$RS_USER/tmp/my notes")" "$RS_USER"

    # The folder becomes a link to a root folder: nothing is written there
    mkdir -p "$TARGET"
    runuser -u "$RS_USER" -- sh -c "rm -rf '/home/$RS_USER/tmp/notes'"
    ln -s "$TARGET" "/home/$RS_USER/tmp/notes"
    chown -h "$RS_USER:$RS_USER" "/home/$RS_USER/tmp/notes"
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
    assert_failure
    assert_not_exist "$TARGET/a.txt"
    rm -f "/home/$RS_USER/tmp/notes"
    run find /backup/.arcpanel-work -mindepth 1
    assert_output ''
}

@test "v-restore-file-restic honours demo mode" {
    local snapshot
    snapshot=$(snapshot_id)
    v-change-sys-config-value DEMO_MODE yes
    run v-restore-file-restic "$RS_USER" "$snapshot" "/home/$RS_USER/tmp/notes/a.txt"
    sed -i "s/^DEMO_MODE='yes'/DEMO_MODE='no'/" "$ARCPANEL/conf/arcpanel.conf"
    assert_failure 1
    assert_output --partial 'security restrictions'
}
