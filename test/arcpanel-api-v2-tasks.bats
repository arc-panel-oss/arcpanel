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
    sed -i '/^QUEUE_MAX_ATTEMPTS=/d' "$ARCPANEL/conf/arcpanel.conf"
    # The snapshot job and destination of qt_enable_incremental_backups
    if [ -f "$BATS_FILE_TMPDIR/snapshot-job" ]; then
        v-delete-backup-job "$(cat "$BATS_FILE_TMPDIR/snapshot-job")" > /dev/null 2>&1 || true
        v-delete-backup-destination arcbatsqt-snap > /dev/null 2>&1 || true
        rm -rf /backup/arcbatsqt-snap
    fi
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

qt_record_value() {
    grep -oE "(^| )$2='[^']*'" "$(qt_tasks_dir)/$1.conf" | head -n1 | cut -d\' -f2
}

@test "v-run-queue-task: a failed worker that keeps its line is queued again with an attempt" {
    local id
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    # v-download-backup removes only its own lines: the backup line stays
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    grep -q "v-run-queue-task $QT_USER $id " "$PIPE"
    run v-list-user-tasks "$QT_USER" "$id" json
    assert_success
    run jq -r '.STATUS, .EXIT, .ATTEMPTS' <<< "$output"
    assert_output $'queued\n\n1'
    run qt_record_value "$id" ERROR
    assert_output --partial 'attempt 1 failed with exit code'

    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    run qt_record_value "$id" STATUS
    assert_output queued
    run qt_record_value "$id" ATTEMPTS
    assert_output 2
    # Both runs are in the log
    [ "$(grep -c 'no-such-backup.tar' "$(qt_tasks_dir)/$id.log")" -ge 2 ]
}

@test "v-run-queue-task: a failed worker that removes its line is failed" {
    local id fail_rc
    id="$(date +%s)0001"
    qt_write_queued "$id" backup-download 'no-such-backup.tar'
    echo "$BIN/v-run-queue-task $QT_USER $id -- $BIN/v-download-backup $QT_USER no-such-backup.tar" >> "$PIPE"
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    fail_rc=$status
    run grep -c "v-run-queue-task $QT_USER $id " "$PIPE"
    assert_output 0
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.STATUS, .EXIT, .ATTEMPTS' <<< "$output"
    assert_output "failed"$'\n'"$fail_rc"$'\n1'
}

@test "v-run-queue-task: removes a dead line without running it" {
    local id
    id="$(date +%s)0002"
    qt_write_queued "$id" backup ''
    sed -i "s/STATUS='queued'/STATUS='succeeded'/; s/EXIT=''/EXIT='0'/" "$(qt_tasks_dir)/$id.conf"
    echo "$BIN/v-run-queue-task $QT_USER $id -- $BIN/v-backup-user $QT_USER yes" >> "$PIPE"
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-backup-user" "$QT_USER" yes
    assert_success
    run grep -c "v-run-queue-task $QT_USER $id " "$PIPE"
    assert_output 0
    [ ! -f "$(qt_tasks_dir)/$id.log" ]
    run qt_record_value "$id" STATUS
    assert_output succeeded

    # A line without a record is dead too
    echo "$BIN/v-run-queue-task $QT_USER 19990101120009 -- $BIN/v-backup-user $QT_USER yes" >> "$PIPE"
    run v-run-queue-task "$QT_USER" 19990101120009 -- "$BIN/v-backup-user" "$QT_USER" yes
    assert_success
    run grep -c "v-run-queue-task $QT_USER 19990101120009 " "$PIPE"
    assert_output 0

    # The line of a record that another pass runs stays
    sed -i "s/STATUS='succeeded'/STATUS='running'/" "$(qt_tasks_dir)/$id.conf"
    echo "$BIN/v-run-queue-task $QT_USER $id -- $BIN/v-backup-user $QT_USER yes" >> "$PIPE"
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-backup-user" "$QT_USER" yes
    assert_success
    run grep -c "v-run-queue-task $QT_USER $id " "$PIPE"
    assert_output 1
    rm -f "$(qt_tasks_dir)/$id.conf"
}

@test "v-run-queue-task: a poll that reaps a line does not cancel the pass that holds it" {
    local id
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    # A worker of the same pass removed the line; bash still reads the old pipe
    sed -i "/v-run-queue-task $QT_USER $id /d" "$PIPE"
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.STATUS, .ERROR' <<< "$output"
    assert_output $'failed\nremoved from the queue'

    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-backup-user" "$QT_USER" yes
    assert_success
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.STATUS, .EXIT, .ERROR, .ATTEMPTS' <<< "$output"
    assert_output $'succeeded\n0\n\n1'
}

@test "v-schedule-letsencrypt-domain: a replaced request keeps the result it already has" {
    local domain first second conf
    domain="$QT_USER-keep.example.test"
    v-add-web-domain "$QT_USER" "$domain" '' no "www.$domain" > /dev/null
    first=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' ecdsa-256 '' json | jq -r .ID)
    conf="$(qt_tasks_dir)/$first.conf"
    sed -i "s/STATUS='queued'/STATUS='failed'/; s/EXIT=''/EXIT='15'/; s/ERROR=''/ERROR='DNS is not ready'/" "$conf"
    second=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' rsa '' json | jq -r .ID)
    [ "$first" != "$second" ]
    run qt_record_value "$first" ERROR
    assert_output 'DNS is not ready'
    run qt_record_value "$first" EXIT
    assert_output 15
    sed -i "/ $domain /d" "$LE_PIPE"
    v-delete-web-domain "$QT_USER" "$domain" > /dev/null
}

@test "queue task clean-up keeps an old record whose line is still retried" {
    local old_id id dir
    old_id="19990101120003"
    dir=$(qt_tasks_dir)
    qt_write_queued "$old_id" letsencrypt "$QT_USER-old.example.test" '1990-01-01 00:00:00'
    echo "$BIN/v-run-queue-task $QT_USER $old_id -- $BIN/v-add-letsencrypt-domain $QT_USER $QT_USER-old.example.test '' no" >> "$LE_PIPE"
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    sed -i "/v-run-queue-task $QT_USER $id /d" "$PIPE"
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    [ -f "$dir/$old_id.conf" ]
    sed -i "/v-run-queue-task $QT_USER $old_id /d" "$LE_PIPE"
    rm -f "$dir/$old_id.conf"
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
    local record=$output
    run jq -r '.ARGS | type' <<< "$record"
    assert_output string
    run jq -r .LOG <<< "$record"
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

# Incremental backups for QT_USER: a package that allows them and, since
# E48, a snapshot job that covers the user (BACKUP_INCREMENTAL follows it)
qt_enable_incremental_backups() {
    local pkg=arcbatsqt-inc options
    if [ ! -f "$BATS_FILE_TMPDIR/snapshot-job" ]; then
        options=$(mktemp)
        printf "PATH='/backup/arcbatsqt-snap'\nENCRYPT='no'\n" > "$options"
        v-add-backup-destination arcbatsqt-snap local "$options" > /dev/null
        rm -f "$options"
        v-add-backup-job arcbatsqt-snapshots snapshot "users:$QT_USER" daily:03:00 arcbatsqt-snap:3 > "$BATS_FILE_TMPDIR/snapshot-job"
    fi
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

# Fleet PD1: retry limit and the reason of a failure
# docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md §4.

qt_set_max_attempts() {
    sed -i '/^QUEUE_MAX_ATTEMPTS=/d' "$ARCPANEL/conf/arcpanel.conf"
    if [ -n "$1" ]; then
        echo "QUEUE_MAX_ATTEMPTS='$1'" >> "$ARCPANEL/conf/arcpanel.conf"
    fi
}

# The record drops quotes from values (qt_set)
qt_last_error_line() {
    grep -E '^[[:space:]]*Error:' "$(qt_tasks_dir)/$1.log" | tail -n1 | sed -E 's/^[[:space:]]*Error:[[:space:]]*//' | tr -d "'"
}

@test "v-run-queue-task: gives up after QUEUE_MAX_ATTEMPTS with the reason (default 3)" {
    local id fail_rc
    qt_set_max_attempts ''
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    run qt_record_value "$id" STATUS
    assert_output queued

    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    fail_rc=$status
    run grep -c "v-run-queue-task $QT_USER $id " "$PIPE"
    assert_output 0
    run v-list-user-tasks "$QT_USER" "$id" json
    assert_success
    run jq -r '.STATUS, .EXIT, .ATTEMPTS' <<< "$output"
    assert_output "failed"$'\n'"$fail_rc"$'\n3'
    run qt_record_value "$id" END
    [ -n "$output" ]
    run qt_record_value "$id" ERROR
    assert_output "gave up after 3 attempts: $(qt_last_error_line "$id")"
}

@test "v-run-queue-task: QUEUE_MAX_ATTEMPTS 1 gives up at the first failure" {
    local id
    qt_set_max_attempts 1
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    qt_set_max_attempts ''
    run qt_record_value "$id" STATUS
    assert_output failed
    run qt_record_value "$id" ERROR
    assert_output --partial 'gave up after 1 attempts: '
}

@test "v-run-queue-task: a garbage or zero QUEUE_MAX_ATTEMPTS means 3 or 1" {
    local id
    qt_set_max_attempts 'many'
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    run qt_record_value "$id" STATUS
    assert_output queued
    qt_clear_user_queue

    qt_set_max_attempts 0
    id=$(v-schedule-user-backup "$QT_USER" json | jq -r .ID)
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    qt_set_max_attempts ''
    run qt_record_value "$id" STATUS
    assert_output failed
}

@test "v-run-queue-task: a failed worker that removes its line records the reason" {
    local id
    id="$(date +%s)0002"
    qt_write_queued "$id" backup-download 'no-such-backup.tar'
    echo "$BIN/v-run-queue-task $QT_USER $id -- $BIN/v-download-backup $QT_USER no-such-backup.tar" >> "$PIPE"
    run v-run-queue-task "$QT_USER" "$id" -- "$BIN/v-download-backup" "$QT_USER" 'no-such-backup.tar'
    assert_failure
    run qt_record_value "$id" ERROR
    [ -n "$output" ]
    assert_output "$(qt_last_error_line "$id")"
}

@test "qt_error_line: last Error line, else last non-empty line, cut to 300 characters" {
    local id="19990101120003" dir long
    dir=$(qt_tasks_dir)
    mkdir -p "$dir"
    printf '%s\n' 'starting' 'Error: first' 'more output' '  Error: second one' 'tail line' '' > "$dir/$id.log"
    run bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/queuetask.sh; qt_error_line $QT_USER $id"
    assert_output 'second one'

    printf '%s\n' 'starting' 'last words' '' '' > "$dir/$id.log"
    run bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/queuetask.sh; qt_error_line $QT_USER $id"
    assert_output 'last words'

    long=$(printf 'x%.0s' $(seq 1 400))
    printf 'Error: %s\n' "$long" > "$dir/$id.log"
    run bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/queuetask.sh; qt_error_line $QT_USER $id"
    [ "${#output}" -eq 300 ]
    rm -f "$dir/$id.log"
}
