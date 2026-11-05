#!/usr/bin/env bats
# Cancel of a running backup or restore job (E15 Job Center,
# docs/features/specs/2026-11-05-001-e15-job-center-design.md §5, §8): the
# command gets TERM, runs its EXIT trap and leaves no work folder, private
# folder or running run record behind. Run as root on a test server; the
# suite makes a user with a large incompressible file so that the commands
# run long enough to be cancelled mid-run.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
JC_USER=arcbatsjc
PASS="Jc-$(date +%s)-Pass"

jv() {
    v-list-job "$1" 0 json | jq -r ".$2"
}

# Wait until job ID is finished; print its status
wait_job() {
    local status
    for _ in $(seq 1 "${2:-300}"); do
        status=$(jv "$1" STATUS)
        if [ "$status" != queued ] && [ "$status" != running ]; then
            echo "$status"
            return
        fi
        sleep 1
    done
    echo timeout
}

# Wait (at most 120 s) until the glob PATTERN matches a path not in BEFORE
# (a list of paths, one per line); prints it
wait_new_path() {
    local pattern="$1" before="$2" path
    for _ in $(seq 1 1200); do
        for path in $pattern; do
            [ -e "$path" ] || continue
            grep -qxF "$path" <<< "$before" || {
                echo "$path"
                return 0
            }
        done
        sleep 0.1
    done
    return 1
}

backup_root() {
    local dir
    dir=$(grep -m1 "^BACKUP_TEMP=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
    [ -n "$dir" ] || dir=$(grep -m1 "^BACKUP=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
    echo "${dir:-/backup}"
}

setup_file() {
    v-delete-user "$JC_USER" > /dev/null 2>&1 || true
    v-add-user "$JC_USER" "$PASS" "$JC_USER@example.invalid" default > /dev/null
    # Large and incompressible: the backup and the restore take seconds
    runuser -u "$JC_USER" -- sh -c "mkdir -p /home/$JC_USER/tmp && head -c 400M /dev/urandom > /home/$JC_USER/tmp/big.bin"
}

teardown_file() {
    local id
    for id in $(grep -l "USER='$JC_USER'" "$ARCPANEL"/data/jobs/*.conf 2> /dev/null); do
        v-cancel-job "$(basename "$id" .conf)" bats > /dev/null 2>&1 || true
    done
    v-delete-user "$JC_USER" > /dev/null 2>&1 || true
}

@test "cancel: a running backup removes its temporary folders and ends its run as failed" {
    local root before work id
    root=$(backup_root)
    before=$(ls -d "$root"/tmp.* /run/arcpanel-backup.* 2> /dev/null || true)
    id=$(v-schedule-user-backup "$JC_USER" json | jq -r .ID)
    [[ "$id" =~ ^[0-9]{14}$ ]]
    work=$(wait_new_path "$root/tmp.*" "$before")
    [ -n "$work" ]
    run v-cancel-job "$id" bats
    assert_success
    run wait_job "$id"
    assert_output cancelled
    assert_dir_not_exists "$work"
    run bash -c "ls -d '$root'/tmp.* /run/arcpanel-backup.* 2> /dev/null"
    for path in $output; do
        grep -qxF "$path" <<< "$before"
    done
    # the run record of the backup is not left running
    run bash -c "grep -c \"STATUS='running'\" '$ARCPANEL/data/users/$JC_USER/backup-runs.conf' 2> /dev/null || true"
    assert_output '0'
}

@test "cancel: a running restore removes its work folder" {
    local root before work id backup
    v-backup-user "$JC_USER" yes > /dev/null 2>&1
    backup=$(v-list-user-backups "$JC_USER" plain | awk 'NR==1 { print $1 }')
    [ -n "$backup" ]
    root=$(backup_root)/.arcpanel-work
    before=$(ls -d "$root"/restore-user.* 2> /dev/null || true)
    id=$(v-schedule-user-restore "$JC_USER" "$backup" '' '' '' '' '' '' json | jq -r .ID)
    [[ "$id" =~ ^[0-9]{14}$ ]]
    work=$(wait_new_path "$root/restore-user.*" "$before")
    [ -n "$work" ]
    run v-cancel-job "$id" bats
    assert_success
    run wait_job "$id"
    assert_output cancelled
    assert_dir_not_exists "$work"
}
