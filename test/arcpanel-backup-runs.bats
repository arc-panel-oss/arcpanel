#!/usr/bin/env bats
# Fleet PD2: records of backup runs
# docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md §5.
# Run as root on a test server with restic installed. Uses a local restic
# repository below /backup when the server has none, and removes it again.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
BIN=$ARCPANEL/bin
PIPE=$ARCPANEL/data/queue/backup.pipe
BR_USER=arcbatsbr
BR_OTHER=arcbatsbr2
BR_RESELLER=arcbatsbrres
BR_PACKAGE=arcbats-br-incremental
REPO_DIR=/backup/restic-arcbats-br
STATE="$BATS_FILE_TMPDIR/restic-state"

br_file() {
    echo "$ARCPANEL/data/users/$1/backup-runs.conf"
}

br_value() {
    grep -oE "(^| )$2='[^']*'" <<< "$1" | head -n1 | cut -d\' -f2
}

br_last() {
    tail -n1 "$(br_file "$1")"
}

br_clear() {
    rm -f "$(br_file "$1")"
    sed -i "/ $1 /d" "$PIPE" 2> /dev/null || true
}

setup_file() {
    local tmp pass
    for u in "$BR_USER" "$BR_OTHER" "$BR_RESELLER"; do
        v-delete-user "$u" > /dev/null 2>&1 || true
    done
    rm -rf "${REPO_DIR:?}/$BR_USER"
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
    v-add-user-package "$tmp" "$BR_PACKAGE" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$BR_PACKAGE" > /dev/null
    rm -f "$tmp"
    pass="Br-$(date +%s)-Pass"
    v-add-user "$BR_USER" "$pass" "$BR_USER@example.invalid" "$BR_PACKAGE" > /dev/null
    v-add-user "$BR_OTHER" "$pass" "$BR_OTHER@example.invalid" default > /dev/null
    v-change-package-tenancy "$BR_PACKAGE" USERS 5 > /dev/null
    v-add-user "$BR_RESELLER" "$pass" "$BR_RESELLER@example.invalid" "$BR_PACKAGE" > /dev/null
    v-change-user-role "$BR_RESELLER" reseller
    v-change-user-owner "$BR_OTHER" "$BR_RESELLER"
}

teardown_file() {
    sed -i '/^BACKUP_TEMP=.*arcbats/d' "$ARCPANEL/conf/arcpanel.conf"
    for u in "$BR_USER" "$BR_OTHER" "$BR_RESELLER"; do
        sed -i "/ $u /d" "$PIPE" 2> /dev/null || true
        v-delete-user "$u" > /dev/null 2>&1 || true
    done
    v-delete-user-package "$BR_PACKAGE" > /dev/null 2>&1 || true
    rm -f "$ARCPANEL/data/packages/$BR_PACKAGE.tenancy"
    if [ "$(cat "$STATE" 2> /dev/null)" = created ]; then
        v-delete-backup-host-restic > /dev/null 2>&1 || rm -f "$ARCPANEL/conf/restic.conf"
        v-change-sys-config-value BACKUP_INCREMENTAL no > /dev/null 2>&1 || true
        rm -rf "$REPO_DIR"
    fi
}

@test "v-backup-user: a manual run is recorded as succeeded with its archive" {
    local line archive
    br_clear "$BR_USER"
    run v-backup-user "$BR_USER"
    assert_success
    [ "$(wc -l < "$(br_file "$BR_USER")")" -eq 1 ]
    line=$(br_last "$BR_USER")
    [[ "$(br_value "$line" ID)" =~ ^[0-9]{14}$ ]]
    assert_equal "$(br_value "$line" ENGINE)" tar
    assert_equal "$(br_value "$line" TRIGGER)" cli
    assert_equal "$(br_value "$line" TASK)" ''
    assert_equal "$(br_value "$line" STATUS)" succeeded
    assert_equal "$(br_value "$line" EXIT)" 0
    assert_equal "$(br_value "$line" ERROR)" ''
    assert_equal "$(br_value "$line" PID)" ''
    [ -n "$(br_value "$line" START)" ]
    [ -n "$(br_value "$line" END)" ]
    archive=$(grep -oE "BACKUP='[^']*'" "$ARCPANEL/data/users/$BR_USER/backup.conf" | tail -n1 | cut -d\' -f2)
    assert_equal "$(br_value "$line" ARCHIVE)" "$archive"
    [[ "$(br_value "$line" SIZE)" =~ ^[0-9]+$ ]]
}

@test "v-backup-user: the nightly trigger is recorded as schedule" {
    br_clear "$BR_USER"
    ARC_BACKUP_TRIGGER=schedule run v-backup-user "$BR_USER"
    assert_success
    assert_equal "$(br_value "$(br_last "$BR_USER")" TRIGGER)" schedule
    # The nightly loops set it for every user they back up
    grep -q '^export ARC_BACKUP_TRIGGER=schedule' "$BIN/v-backup-users"
    grep -q '^export ARC_BACKUP_TRIGGER=schedule' "$BIN/v-backup-users-restic"
}

@test "v-run-queue-task: a requested backup is recorded with its task" {
    local id line
    br_clear "$BR_USER"
    id=$(v-schedule-user-backup "$BR_USER" json | jq -r .ID)
    sed -i "/v-run-queue-task $BR_USER $id/d" "$PIPE"
    run v-run-queue-task "$BR_USER" "$id" -- "$BIN/v-backup-user" "$BR_USER" yes
    assert_success
    line=$(br_last "$BR_USER")
    assert_equal "$(br_value "$line" TRIGGER)" request
    assert_equal "$(br_value "$line" TASK)" "$id"
    assert_equal "$(br_value "$line" STATUS)" succeeded
}

@test "v-backup-user: a failed run is recorded with its exit code and reason" {
    local line
    br_clear "$BR_USER"
    echo "BACKUP_TEMP='/nonexistent-arcbats/tmp'" >> "$ARCPANEL/conf/arcpanel.conf"
    run v-backup-user "$BR_USER"
    sed -i '/^BACKUP_TEMP=.*arcbats/d' "$ARCPANEL/conf/arcpanel.conf"
    assert_failure
    line=$(br_last "$BR_USER")
    assert_equal "$(br_value "$line" STATUS)" failed
    assert_equal "$(br_value "$line" EXIT)" "$status"
    assert_equal "$(br_value "$line" ERROR)" 'cant create tmp dir'
    assert_equal "$(br_value "$line" ARCHIVE)" ''
    [ -n "$(br_value "$line" END)" ]
}

@test "v-backup-user: a run whose process is gone is marked interrupted" {
    local first
    br_clear "$BR_USER"
    first="$(date +%s)0001"
    echo "ID='$first' ENGINE='tar' TRIGGER='cli' TASK='' STATUS='running' START='2026-01-01 00:00:00' END='' EXIT='' ERROR='' ARCHIVE='' SIZE='' PID='4194301'" > "$(br_file "$BR_USER")"
    run v-backup-user "$BR_USER"
    assert_success
    run grep "^ID='$first' " "$(br_file "$BR_USER")"
    assert_output --partial "STATUS='failed'"
    assert_output --partial "ERROR='interrupted'"
    [ "$(wc -l < "$(br_file "$BR_USER")")" -eq 2 ]
}

@test "v-backup-user: keeps the newest 30 runs" {
    local i file
    br_clear "$BR_USER"
    file=$(br_file "$BR_USER")
    for i in $(seq 1 35); do
        printf "ID='1700000000%04d' ENGINE='tar' TRIGGER='cli' TASK='' STATUS='succeeded' START='2023-11-14 22:13:20' END='2023-11-14 22:13:21' EXIT='0' ERROR='' ARCHIVE='old.tar' SIZE='1' PID=''\n" "$i" >> "$file"
    done
    run v-backup-user "$BR_USER"
    assert_success
    [ "$(wc -l < "$file")" -eq 30 ]
    assert_equal "$(br_value "$(br_last "$BR_USER")" STATUS)" succeeded
    ! grep -q "^ID='17000000000006' " "$file"
    grep -q "^ID='17000000000007' " "$file"
}

@test "v-backup-user-restic: a run is recorded with the snapshot" {
    local line
    br_clear "$BR_USER"
    run v-backup-user-restic "$BR_USER"
    assert_success
    line=$(br_last "$BR_USER")
    assert_equal "$(br_value "$line" ENGINE)" restic
    assert_equal "$(br_value "$line" STATUS)" succeeded
    assert_equal "$(br_value "$line" ARCHIVE)" "$(v-list-user-backups-restic "$BR_USER" json | jq -r 'sort_by(.time) | last | .short_id')"
    assert_equal "$(br_value "$line" SIZE)" ''
}

@test "v-list-user-backup-runs: newest first in every format, json safe for any reason" {
    local file
    br_clear "$BR_USER"
    file=$(br_file "$BR_USER")
    echo "ID='17000000000001' ENGINE='tar' TRIGGER='schedule' TASK='' STATUS='failed' START='2023-11-14 22:13:20' END='2023-11-14 22:13:21' EXIT='3' ERROR='a \"quoted\" back\\slash	tab' ARCHIVE='' SIZE='' PID=''" > "$file"
    echo "ID='17000000000002' ENGINE='restic' TRIGGER='request' TASK='17000000001234' STATUS='succeeded' START='2023-11-15 22:13:20' END='2023-11-15 22:14:20' EXIT='0' ERROR='' ARCHIVE='abc123' SIZE='' PID=''" >> "$file"

    run v-list-user-backup-runs "$BR_USER" json
    assert_success
    run jq -r 'keys_unsorted | join(",")' <<< "$output"
    assert_output '17000000000002,17000000000001'
    run v-list-user-backup-runs "$BR_USER" json
    run jq -r '."17000000000001" | .ERROR, .EXIT, .TRIGGER' <<< "$output"
    assert_output 'a "quoted" back\slash	tab'$'\n3\nschedule'
    run v-list-user-backup-runs "$BR_USER" json
    run jq -r '."17000000000002" | .ENGINE, .TASK, .ARCHIVE, .STATUS, .START, .END' <<< "$output"
    assert_output $'restic\n17000000001234\nabc123\nsucceeded\n2023-11-15 22:13:20\n2023-11-15 22:14:20'

    run v-list-user-backup-runs "$BR_USER" plain
    assert_success
    assert_line --index 0 --partial '17000000000002'
    run v-list-user-backup-runs "$BR_USER" csv
    assert_line --index 0 'ID,ENGINE,TRIGGER,TASK,STATUS,START,END,EXIT,ERROR,ARCHIVE,SIZE'
    run v-list-user-backup-runs "$BR_USER" shell
    assert_success
    assert_output --partial 'succeeded'

    # A user without runs lists nothing
    run v-list-user-backup-runs "$BR_OTHER" json
    assert_success
    assert_output '{}'
}

@test "v-list-sys-backup-runs: every user, an owner's tree, and changes since a time" {
    local since out
    br_clear "$BR_USER"
    br_clear "$BR_OTHER"
    echo "ID='17000000000001' ENGINE='tar' TRIGGER='cli' TASK='' STATUS='succeeded' START='2023-11-14 22:13:20' END='2023-11-14 22:13:21' EXIT='0' ERROR='' ARCHIVE='a.tar' SIZE='1' PID=''" > "$(br_file "$BR_USER")"
    echo "ID='17000000000003' ENGINE='tar' TRIGGER='cli' TASK='' STATUS='failed' START='2023-11-16 22:13:20' END='2023-11-16 22:13:21' EXIT='3' ERROR='x' ARCHIVE='' SIZE='' PID=''" > "$(br_file "$BR_OTHER")"

    out=$(v-list-sys-backup-runs '' '' json)
    assert_equal "$(jq -S ".OBJECTS[\"$BR_USER\"]" <<< "$out")" "$(v-list-user-backup-runs "$BR_USER" json | jq -S .)"
    assert_equal "$(jq -S ".OBJECTS[\"$BR_OTHER\"]" <<< "$out")" "$(v-list-user-backup-runs "$BR_OTHER" json | jq -S .)"

    out=$(v-list-sys-backup-runs "$BR_RESELLER" '' json)
    assert_equal "$(jq -c '.USERS' <<< "$out")" "[\"$BR_OTHER\",\"$BR_RESELLER\"]"
    assert_equal "$(jq -r ".OBJECTS[\"$BR_OTHER\"][\"17000000000003\"].STATUS" <<< "$out")" failed

    since=$(($(date +%s) + 2))
    sleep 3
    touch "$(br_file "$BR_OTHER")"
    out=$(v-list-sys-backup-runs '' "$since" json)
    assert_equal "$(jq -c '.CHANGED_USERS' <<< "$out")" "[\"$BR_OTHER\"]"

    run v-list-sys-backup-runs '' '' plain
    assert_success
    assert_output --partial "$BR_OTHER"
    run v-list-sys-backup-runs '' '' xml
    assert_failure
}

@test "v-delete-user: removes the runs of the user" {
    local pass="Br-$(date +%s)-Del" gone=arcbatsbr3
    v-delete-user "$gone" > /dev/null 2>&1 || true
    v-add-user "$gone" "$pass" "$gone@example.invalid" default > /dev/null
    v-backup-user "$gone" > /dev/null
    [ -f "$(br_file "$gone")" ]
    v-delete-user "$gone" > /dev/null
    [ ! -f "$(br_file "$gone")" ]
}
