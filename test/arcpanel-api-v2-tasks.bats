#!/usr/bin/env bats
# E34 batch 3 M1: queue task records
# docs/features/specs/2026-10-08-001-e34-api-v2-batch3-design.md §4, §8.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
BIN=$ARCPANEL/bin
PIPE=$ARCPANEL/data/queue/backup.pipe
LE_PIPE=$ARCPANEL/data/queue/letsencrypt.pipe
QT_USER=arcbatsqt
PASS="Qt-$(date +%s)-Pass"

qt_tasks_dir() {
    echo "$ARCPANEL/data/users/$QT_USER/tasks"
}

qt_clear_user_queue() {
    sed -i "/ $QT_USER /d" "$PIPE" 2> /dev/null || true
    sed -i "/v-run-queue-task $QT_USER /d" "$PIPE" 2> /dev/null || true
    sed -i "/ $QT_USER /d" "$LE_PIPE" 2> /dev/null || true
}

qt_write_queued() {
    local id=$1 kind=$2 args=$3 queued=${4:-$(date +'%F %T')}
    local dir
    dir=$(qt_tasks_dir)
    mkdir -p "$dir"
    printf "%s\n" \
        "ID='$id' KIND='$kind' ARGS='$args' STATUS='queued' EXIT='' ERROR='' QUEUED='$queued' START='' END='' PID=''" \
        > "$dir/$id.conf"
}

setup_file() {
    qt_clear_user_queue
    v-delete-user "$QT_USER" > /dev/null 2>&1 || true
    rm -rf "$(qt_tasks_dir)"
    v-add-user "$QT_USER" "$PASS" "$QT_USER@example.invalid" default > /dev/null
}

teardown_file() {
    qt_clear_user_queue
    v-delete-user "$QT_USER" > /dev/null 2>&1 || true
    rm -rf "$(qt_tasks_dir)"
}

setup() {
    qt_clear_user_queue
}

@test "v-run-queue-task: refuses a command outside the allowed list" {
    local id="19990101120001"
    qt_write_queued "$id" backup ''
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-list-user" "$QT_USER"
    assert_failure 10
    run grep -o "STATUS='[^']*'" "$(qt_tasks_dir)/$id.conf"
    assert_output "STATUS='queued'"
}

@test "v-run-queue-task: records failure with the exit code and runs a queued record once" {
    local id log_before log_after
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    [[ "$id" =~ ^[0-9]{14}$ ]]
    grep -q "v-run-queue-task $QT_USER $id" "$PIPE"
    grep -q "v-backup-user $QT_USER" "$PIPE"
    sed -i "/v-run-queue-task $QT_USER $id/d" "$PIPE"

    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    [ "$status" -gt 0 ]
    fail_rc=$status
    run v-list-user-tasks "$QT_USER" "$id" json
    assert_success
    run jq -r '.STATUS, .EXIT' <<< "$output"
    assert_output $'failed\n'"$fail_rc"

    log_before=$(wc -l < "$(qt_tasks_dir)/$id.log" 2> /dev/null || echo 0)
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_success
    log_after=$(wc -l < "$(qt_tasks_dir)/$id.log" 2> /dev/null || echo 0)
    [ "$log_before" -eq "$log_after" ]
}

@test "v-run-queue-task: records success with exit code zero" {
    local id
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    sed -i "/v-run-queue-task $QT_USER $id/d" "$PIPE"
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-backup-user" "$QT_USER" yes
    assert_success
    run v-list-user-tasks "$QT_USER" "$id" json
    assert_success
    run jq -r '.STATUS, .EXIT' <<< "$output"
    assert_output $'succeeded\n0'
}

@test "v-schedule-user-backup: duplicate checks still refuse a second backup" {
    run v-schedule-user-backup "$QT_USER" json
    assert_success
    run v-schedule-user-backup "$QT_USER" json
    assert_failure 4
}

@test "v-schedule-user-restore: prints an id and queues the wrapped line" {
    local backup id
    v-backup-user "$QT_USER" yes > /dev/null 2>&1
    backup=$(v-list-user-backups "$QT_USER" plain | awk 'NR==1 { print $1 }')
    [ -n "$backup" ]
    qt_clear_user_queue
    run v-schedule-user-restore "$QT_USER" "$backup" '' '' '' '' '' '' json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    run grep "v-run-queue-task $QT_USER $id" "$PIPE"
    assert_success
    run grep "v-restore-user" "$PIPE"
    assert_success
}

@test "v-schedule-user-backup-download: prints an id and queues the wrapped line" {
    local backup id
    backup=$(v-list-user-backups "$QT_USER" plain | awk 'NR==1 { print $1 }')
    [ -n "$backup" ]
    qt_clear_user_queue
    run v-schedule-user-backup-download "$QT_USER" "$backup" json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    run grep "v-run-queue-task $QT_USER $id" "$PIPE"
    assert_success
    run grep "v-download-backup $QT_USER" "$PIPE"
    assert_success
}

@test "v-list-user-tasks: lists records, shows one with its log, marks a removed line failed" {
    local id domain line
    domain="$QT_USER-le.example.test"
    v-add-web-domain "$QT_USER" "$domain" '' no "www.$domain" > /dev/null
    id=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' ecdsa-256 '' json | jq -r .ID)
    run v-list-user-tasks "$QT_USER" json
    assert_success
    run jq -r --arg id "$id" '.[$id].KIND' <<< "$output"
    assert_output letsencrypt

    echo 'task-log-line' > "$(qt_tasks_dir)/$id.log"
    run v-list-user-tasks "$QT_USER" "$id" 5 json
    assert_success
    run jq -r .LOG <<< "$output"
    assert_output task-log-line

    sed -i "/v-run-queue-task $QT_USER $id/d" "$LE_PIPE"
    run v-list-user-tasks "$QT_USER" "$id" json
    assert_success
    run jq -r '.STATUS, .ERROR' <<< "$output"
    assert_output $'failed\nremoved from the queue'

    sed -i "/ $domain /d" "$LE_PIPE"
    v-delete-web-domain "$QT_USER" "$domain" > /dev/null
}

@test "v-schedule-letsencrypt-domain: replaced request is failed with its reason" {
    local domain first second
    domain="$QT_USER-replace.example.test"
    v-add-web-domain "$QT_USER" "$domain" '' no "www.$domain" > /dev/null
    first=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' ecdsa-256 '' json | jq -r .ID)
    second=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' rsa '' json | jq -r .ID)
    [ "$first" != "$second" ]
    run v-list-user-tasks "$QT_USER" "$first" json
    assert_success
    run jq -r '.STATUS, .ERROR' <<< "$output"
    assert_output $'failed\nreplaced by a newer request'
    sed -i "/ $domain /d" "$LE_PIPE"
    v-delete-web-domain "$QT_USER" "$domain" > /dev/null
}

qt_enable_incremental_backups() {
    local pkg=arcbatsqt-inc
    if ! v-list-user-package "$pkg" > /dev/null 2>&1; then
        local tmp
        tmp=$(mktemp)
        sed "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$ARCPANEL/data/packages/default.pkg" > "$tmp"
        grep -q "^BACKUPS_INCREMENTAL=" "$tmp" || echo "BACKUPS_INCREMENTAL='yes'" >> "$tmp"
        v-add-user-package "$tmp" "$pkg" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$pkg" > /dev/null
        rm -f "$tmp"
    fi
    v-change-user-package "$QT_USER" "$pkg" yes > /dev/null
}

@test "v-schedule-user-backup-restic: prints an id and queues the wrapped line" {
    local id
    qt_enable_incremental_backups
    qt_clear_user_queue
    run v-schedule-user-backup-restic "$QT_USER" json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    grep -q "v-run-queue-task $QT_USER $id" "$PIPE"
    grep -q "v-backup-user-restic $QT_USER" "$PIPE"
    v-change-user-package "$QT_USER" default yes > /dev/null
}

@test "v-schedule-user-restore-restic: prints an id and queues the wrapped line" {
    local snapshot id
    if [ ! -f "$ARCPANEL/conf/restic.conf" ]; then
        skip "restic backup host is not configured"
    fi
    qt_enable_incremental_backups
    runuser -u "$QT_USER" -- sh -c "mkdir -p /home/$QT_USER/tmp/qt && echo x > /home/$QT_USER/tmp/qt/a.txt"
    v-backup-user-restic "$QT_USER" > /dev/null 2>&1
    snapshot=$(v-list-user-backups-restic "$QT_USER" json | jq -r '.[0].short_id')
    [ -n "$snapshot" ] && [ "$snapshot" != null ]

    qt_clear_user_queue
    run v-schedule-user-restore-restic "$QT_USER" "$snapshot" '' '' json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    grep -q "v-run-queue-task $QT_USER $id" "$PIPE"
    grep -q "v-restore-user-restic" "$PIPE"

    v-change-user-package "$QT_USER" default yes > /dev/null
}

@test "v-schedule-user-restore-file-restic: prints an id and queues the wrapped line" {
    local snapshot id path
    if [ ! -f "$ARCPANEL/conf/restic.conf" ]; then
        skip "restic backup host is not configured"
    fi
    qt_enable_incremental_backups
    runuser -u "$QT_USER" -- sh -c "mkdir -p /home/$QT_USER/tmp/qt && echo x > /home/$QT_USER/tmp/qt/a.txt"
    v-backup-user-restic "$QT_USER" > /dev/null 2>&1
    snapshot=$(v-list-user-backups-restic "$QT_USER" json | jq -r '.[0].short_id')
    [ -n "$snapshot" ] && [ "$snapshot" != null ]

    qt_clear_user_queue
    path="/home/$QT_USER/tmp/qt/a.txt"
    run v-schedule-user-restore-file-restic "$QT_USER" "$snapshot" "$path" json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    grep -q "v-run-queue-task $QT_USER $id" "$PIPE"
    grep -q "v-restore-file-restic" "$PIPE"

    v-change-user-package "$QT_USER" default yes > /dev/null
}

@test "queue task clean-up removes records older than seven days" {
    local old_id dir id
    old_id="19990101120001"
    dir=$(qt_tasks_dir)
    mkdir -p "$dir"
    qt_write_queued "$old_id" backup '' '1990-01-01 00:00:00'
    echo stale > "$dir/$old_id.log"
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    sed -i "/v-run-queue-task $QT_USER $id/d" "$PIPE"
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    [ ! -f "$dir/$old_id.conf" ]
    [ ! -f "$dir/$old_id.log" ]
}

@test "v-update-sys-queue daily: removes stale task records for idle users" {
    local clean_user=arcbatsqtdaily old_id clean_dir recent_id
    PASS="Qt-$(date +%s)-Daily"
    v-delete-user "$clean_user" > /dev/null 2>&1 || true
    v-add-user "$clean_user" "$PASS" "$clean_user@example.invalid" default > /dev/null
    clean_dir="$ARCPANEL/data/users/$clean_user/tasks"
    old_id="19990101120002"
    mkdir -p "$clean_dir"
    printf "%s\n" \
        "ID='$old_id' KIND='backup' ARGS='' STATUS='queued' EXIT='' ERROR='' QUEUED='1990-01-01 00:00:00' START='' END='' PID=''" \
        > "$clean_dir/$old_id.conf"
    echo stale-daily > "$clean_dir/$old_id.log"
    recent_id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    sed -i "/v-run-queue-task $QT_USER $recent_id/d" "$PIPE"

    run v-update-sys-queue daily
    assert_success
    [ ! -f "$clean_dir/$old_id.conf" ]
    [ ! -f "$clean_dir/$old_id.log" ]
    [ -f "$(qt_tasks_dir)/$recent_id.conf" ]

    v-delete-user "$clean_user" > /dev/null 2>&1 || true
    rm -rf "$clean_dir"
}
