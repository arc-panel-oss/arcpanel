#!/usr/bin/env bats
# E34 batch 3 M1: queue tasks, since E15 jobs of the backup and letsencrypt
# queues (docs/features/specs/2026-10-08-001-e34-api-v2-batch3-design.md
# §4, §8; docs/features/specs/2026-11-05-001-e15-job-center-design.md §3.4).
# The runner, the retries and the clean-up of jobs are covered by
# arcpanel-jobs.bats; this suite covers the v-schedule-* commands and
# v-list-user-tasks.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
BIN=$ARCPANEL/bin
JOB_DIR=$ARCPANEL/data/jobs
QT_USER=arcbatsqt
PASS="Qt-$(date +%s)-Pass"

# Value of KEY of job ID
jv() {
    v-list-job "$1" 0 json | jq -r ".$2"
}

# The command of job ID, one word per line
argv_of() {
    tr '\0' '\n' < "$JOB_DIR/$1.argv"
}

# Wait until job ID is finished; print its status
wait_job() {
    local status
    for _ in $(seq 1 "${2:-180}"); do
        status=$(jv "$1" STATUS)
        if [ "$status" != queued ] && [ "$status" != running ]; then
            echo "$status"
            return
        fi
        sleep 1
    done
    echo timeout
}

# Cancel every queued or running job of QT_USER
qt_clear_user_jobs() {
    local id
    for id in $(bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/job.sh; job_active_ids"); do
        if grep -qE "USER='$QT_USER'|OBJECT='letsencrypt-hold'" "$JOB_DIR/$id.conf"; then
            v-cancel-job "$id" bats > /dev/null 2>&1 || true
        fi
    done
}

# Hold the letsencrypt queue: a running letsencrypt job with a live runner,
# so that new requests stay queued. Prints the PID of the fake runner.
hold_letsencrypt_queue() {
    local now
    now=$(date +'%F %T')
    bash -c 'exec -a v-run-job sleep 120' > /dev/null 2>&1 &
    echo "ID='19990101120099' TYPE='letsencrypt.domain' USER='system' OWNER='system' ACTOR='bats' OBJECT='letsencrypt-hold' ARGS='v-add-letsencrypt-domain' STATUS='running' EXIT='' ERROR='' UNIT='' PID='$!' QUEUED='$now' START='$now' END='' HEARTBEAT='$now' ATTEMPTS='1' RETRY_OF='' RESOURCE='' UPDATED='$now'" > "$JOB_DIR/19990101120099.conf"
    echo "$!"
}

release_letsencrypt_queue() {
    kill "$1" 2> /dev/null || true
    rm -f "$JOB_DIR"/19990101120099.*
}

setup_file() {
    v-delete-user "$QT_USER" > /dev/null 2>&1 || true
    v-add-user "$QT_USER" "$PASS" "$QT_USER@example.invalid" default > /dev/null
}

teardown_file() {
    qt_clear_user_jobs
    # The snapshot job and destination of qt_enable_incremental_backups
    if [ -f "$BATS_FILE_TMPDIR/snapshot-job" ]; then
        v-delete-backup-job "$(cat "$BATS_FILE_TMPDIR/snapshot-job")" > /dev/null 2>&1 || true
        v-delete-backup-destination arcbatsqt-snap > /dev/null 2>&1 || true
        rm -rf /backup/arcbatsqt-snap
    fi
    v-delete-user "$QT_USER" > /dev/null 2>&1 || true
}

setup() {
    qt_clear_user_jobs
}

@test "v-schedule-user-backup: prints a job ID and runs v-backup-user in the backup queue" {
    local id
    run v-schedule-user-backup "$QT_USER" json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    run v-list-job "$id" 0 json
    run jq -r '[.TYPE, .USER, .OBJECT, .CLASS, .RETRYABLE] | join(" ")' <<< "$output"
    assert_output "backup.user $QT_USER user:$QT_USER backup yes"
    run argv_of "$id"
    assert_output "$BIN/v-backup-user"$'\n'"$QT_USER"$'\n'yes
    run wait_job "$id"
    assert_output succeeded
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.KIND, .STATUS, .EXIT, .ATTEMPTS' <<< "$output"
    assert_output $'backup\nsucceeded\n0\n1'
}

@test "v-schedule-user-backup: duplicate checks still refuse a second backup" {
    run v-schedule-user-backup "$QT_USER" json
    assert_success
    run v-schedule-user-backup "$QT_USER" json
    assert_failure 4
    assert_output --partial 'backup is already scheduled'
}

@test "v-schedule-user-restore: prints an id and runs v-restore-user without a shell" {
    local backup id
    v-backup-user "$QT_USER" yes > /dev/null 2>&1
    backup=$(v-list-user-backups "$QT_USER" plain | awk 'NR==1 { print $1 }')
    [ -n "$backup" ]
    run v-schedule-user-restore "$QT_USER" "$backup" '' '' '' '' '' '' json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    run jv "$id" TYPE
    assert_output restore.user
    run argv_of "$id"
    assert_line --index 0 "$BIN/v-restore-user"
    assert_line --index 2 "$backup"
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.KIND, .ARGS' <<< "$output"
    assert_output "restore"$'\n'"$backup"
    run wait_job "$id"
    assert_output succeeded
    # a backup and a restore of the user never run together; the restore
    # is left to finish (a cancelled restore would leave its work folder)
    id=$(v-schedule-user-restore "$QT_USER" "$backup" '' '' '' '' '' '' json | jq -r .ID)
    run v-schedule-user-backup "$QT_USER" json
    assert_failure 7
    run wait_job "$id"
    assert_output succeeded
}

@test "v-schedule-user-backup-download: a missing archive fails at once with its reason" {
    local id
    run v-schedule-user-backup-download "$QT_USER" no-such-backup.tar json
    assert_success
    id=$(jq -r .ID <<< "$output")
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.KIND, .ARGS' <<< "$output"
    assert_output $'backup-download\nno-such-backup.tar'
    run wait_job "$id"
    assert_output failed
    run v-list-job "$id" 0 json
    run jq -r '.ATTEMPTS, (.ERROR | length > 0), (.EXIT | tonumber > 0)' <<< "$output"
    assert_output $'1\ntrue\ntrue'
}

@test "v-schedule-user-backup: JOB names an archive job and is passed to v-backup-user" {
    local id job
    qt_enable_incremental_backups
    run v-schedule-user-backup "$QT_USER" json 1
    assert_failure 3
    run v-schedule-user-backup "$QT_USER" json '../x'
    assert_failure 3
    # The snapshot job of the setup makes no archives
    run v-schedule-user-backup "$QT_USER" json "$(cat "$BATS_FILE_TMPDIR/snapshot-job")"
    assert_failure 2
    job=$(v-add-backup-job arcbatsqt-archives archive "users:$QT_USER" daily:04:00 arcbatsqt-snap:2)
    id=$(v-schedule-user-backup "$QT_USER" json "$job" | jq -r .ID)
    [[ "$id" =~ ^[0-9]{14}$ ]]
    run argv_of "$id"
    assert_output "$BIN/v-backup-user"$'\n'"$QT_USER"$'\n'yes$'\n'"$job"
    wait_job "$id" > /dev/null
    v-delete-backup-job "$job"
}

@test "v-list-user-tasks: lists the jobs of the user, shows one with its log, hides the others" {
    local id domain hold
    domain="$QT_USER-le.example.test"
    v-add-web-domain "$QT_USER" "$domain" '' no "www.$domain" > /dev/null
    hold=$(hold_letsencrypt_queue)
    id=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' ecdsa-256 '' json | jq -r .ID)
    run v-list-user-tasks "$QT_USER" json
    assert_success
    run jq -r --arg id "$id" '.[$id] | .KIND, .ARGS, .STATUS' <<< "$output"
    assert_output "letsencrypt"$'\n'"$domain"$'\n'queued

    echo 'task-log-line' > "$JOB_DIR/$id.log"
    run v-list-user-tasks "$QT_USER" "$id" 5 json
    assert_success
    run jq -r .LOG <<< "$output"
    assert_output task-log-line

    # a job of another user, or not a queue task, is not a task of the user
    run v-list-user-tasks "$(bash -c "source $ARCPANEL/conf/arcpanel.conf; echo \$ROOT_USER")" "$id" json
    assert_failure 3
    run v-list-user-tasks "$QT_USER" 19990101120099 json
    assert_failure 3

    release_letsencrypt_queue "$hold"
    v-cancel-job "$id" bats > /dev/null
    v-delete-web-domain "$QT_USER" "$domain" > /dev/null
}

@test "v-schedule-letsencrypt-domain: a replaced request is failed with its reason" {
    local domain first second hold mail
    domain="$QT_USER-replace.example.test"
    v-add-web-domain "$QT_USER" "$domain" '' no "www.$domain" > /dev/null
    hold=$(hold_letsencrypt_queue)
    first=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' ecdsa-256 '' json | jq -r .ID)
    second=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' rsa '' json | jq -r .ID)
    [ "$first" != "$second" ]
    run v-list-user-tasks "$QT_USER" "$first" json
    assert_success
    run jq -r '.STATUS, .ERROR' <<< "$output"
    assert_output $'failed\nreplaced by a newer request'
    run jv "$first" STATUS
    assert_output cancelled
    run jv "$second" STATUS
    assert_output queued
    run argv_of "$second"
    assert_output "$BIN/v-add-letsencrypt-domain"$'\n'"$QT_USER"$'\n'"$domain"$'\n'"www.$domain"$'\n'no$'\n'rsa
    release_letsencrypt_queue "$hold"
    v-cancel-job "$second" bats > /dev/null
    v-delete-web-domain "$QT_USER" "$domain" > /dev/null
}

@test "v-schedule-letsencrypt-domain: a replaced request keeps the result it already has" {
    local domain first second hold
    domain="$QT_USER-keep.example.test"
    v-add-web-domain "$QT_USER" "$domain" '' no "www.$domain" > /dev/null
    hold=$(hold_letsencrypt_queue)
    first=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' ecdsa-256 '' json | jq -r .ID)
    sed -i "s/STATUS='queued'/STATUS='failed'/; s/EXIT=''/EXIT='15'/; s/ERROR=''/ERROR='DNS is not ready'/" "$JOB_DIR/$first.conf"
    second=$(v-schedule-letsencrypt-domain "$QT_USER" "$domain" '' rsa '' json | jq -r .ID)
    [ "$first" != "$second" ]
    run jv "$first" ERROR
    assert_output 'DNS is not ready'
    run jv "$first" EXIT
    assert_output 15
    release_letsencrypt_queue "$hold"
    v-cancel-job "$second" bats > /dev/null
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

@test "v-schedule-user-backup-restic: prints an id and runs v-backup-user-restic" {
    local id
    qt_enable_incremental_backups
    run v-schedule-user-backup-restic "$QT_USER" json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    run jv "$id" TYPE
    assert_output backup.restic
    run argv_of "$id"
    assert_output "$BIN/v-backup-user-restic"$'\n'"$QT_USER"$'\n'yes
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r .KIND <<< "$output"
    assert_output snapshot
    wait_job "$id" > /dev/null
    v-change-user-package "$QT_USER" default yes > /dev/null
}

@test "v-schedule-user-restore-restic and -file-restic: print an id and run the restore" {
    local snapshot id path
    qt_enable_incremental_backups
    runuser -u "$QT_USER" -- sh -c "mkdir -p /home/$QT_USER/tmp/qt && echo x > /home/$QT_USER/tmp/qt/a.txt"
    v-backup-user-restic "$QT_USER" > /dev/null 2>&1
    snapshot=$(v-list-user-backups-restic "$QT_USER" json | jq -r '.[0].short_id')
    [ -n "$snapshot" ] && [ "$snapshot" != null ]

    run v-schedule-user-restore-restic "$QT_USER" "$snapshot" '' '' json
    assert_success
    id=$(jq -r .ID <<< "$output")
    [[ "$id" =~ ^[0-9]{14}$ ]]
    run jv "$id" TYPE
    assert_output restore.restic
    run argv_of "$id"
    assert_line --index 0 "$BIN/v-restore-user-restic"
    assert_line --index 3 '*'
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.KIND, .ARGS' <<< "$output"
    assert_output "snapshot-restore"$'\n'"$snapshot"
    wait_job "$id" > /dev/null

    path="/home/$QT_USER/tmp/qt/a.txt"
    run v-schedule-user-restore-file-restic "$QT_USER" "$snapshot" "$path" json
    assert_success
    id=$(jq -r .ID <<< "$output")
    run jv "$id" TYPE
    assert_output restore.file
    run v-list-user-tasks "$QT_USER" "$id" json
    run jq -r '.KIND, .ARGS' <<< "$output"
    assert_output "file-restore"$'\n'"$snapshot $path"
    # one restore of the user at a time
    run v-schedule-user-restore-file-restic "$QT_USER" "$snapshot" "$path" json
    assert_failure 4
    wait_job "$id" > /dev/null

    v-change-user-package "$QT_USER" default yes > /dev/null
}

@test "v-update-sys-queue: the backup and letsencrypt modes run the job queue" {
    run v-update-sys-queue backup
    assert_success
    run v-update-sys-queue letsencrypt
    assert_success
    [ ! -e "$BIN/v-run-queue-task" ]
}
