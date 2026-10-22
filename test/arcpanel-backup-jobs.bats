#!/usr/bin/env bats
# Backup jobs (E48 M2 and M7,
# docs/features/specs/2026-10-22-002-e48-backup-destinations-and-schedules-design.md §5 and §7).
# Run as root on a test server with rclone and restic. Archives go to local
# folders, snapshots to rclone serve webdav; a webdav destination on a
# closed port stands for a destination that is down.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
JOBS=$ARCPANEL/data/backup/jobs
U1=arcbatsbj1
U2=arcbatsbj2
PKG=arcbats-bj
DIR=/srv/arcbats-bj
DAV_DIR=/var/tmp/arcbatsbjdav
DAV_PORT=18192
STATE="$BATS_FILE_TMPDIR/state"

cleanup() {
    local id name
    for id in $(v-list-backup-jobs json 2> /dev/null | jq -r 'to_entries[] | select(.value.NAME | startswith("arcbats")) | .key'); do
        rm -f "$JOBS/$id.lock"
        v-delete-backup-job "$id" > /dev/null 2>&1
    done
    for name in $(v-list-backup-destinations json 2> /dev/null | jq -r 'keys[] | select(startswith("arcbats"))'); do
        v-delete-backup-destination "$name" > /dev/null 2>&1
    done
}

options() {
    local file="$BATS_TEST_TMPDIR/$1.options" pair
    shift
    : > "$file"
    for pair in "$@"; do
        printf "%s='%s'\n" "${pair%%=*}" "${pair#*=}" >> "$file"
    done
    echo "$file"
}

secrets() {
    local dir="$BATS_TEST_TMPDIR/$1.secrets" pair
    shift
    mkdir -p "$dir"
    for pair in "$@"; do
        printf '%s' "${pair#*=}" > "$dir/${pair%%=*}"
    done
    echo "$dir"
}

job_value() {
    v-list-backup-job "$1" json | jq -r --arg i "$1" --arg k "$2" '.[$i][$k]'
}

last_run() {
    v-list-backup-job-runs "$1" json | jq -r --arg k "$2" '.[0][$k]'
}

# Wait for job ID to stop running (at most 120 s)
wait_job() {
    local i
    for i in $(seq 1 60); do
        [ "$(job_value "$1" RUNNING)" = no ] && return 0
        sleep 2
    done
    return 1
}

user_run() {
    grep "JOB='$2'" "$ARCPANEL/data/users/$1/backup-runs.conf" | tail -n1 | grep -oE " $3='[^']*'" | cut -d"'" -f2
}

# Notices of USER with TOPIC
notices() {
    v-list-user-notifications "$1" json | jq --arg t "$2" '[.[] | select(.TOPIC == $t)] | length'
}

# Mails to the administrator noted in log/backup.log after line FROM
mails_since() {
    tail -n +"$(($1 + 1))" "$ARCPANEL/log/backup.log" | grep 'Mail to the administrator: ' || true
}

log_lines() {
    grep -c '' "$ARCPANEL/log/backup.log" 2> /dev/null || echo 0
}

setup_file() {
    local tmp pass
    cleanup
    for u in $U1 $U2; do v-delete-user "$u" > /dev/null 2>&1 || true; done
    v-delete-user-package "$PKG" > /dev/null 2>&1 || true
    rm -rf "$DIR" "$DAV_DIR"
    pkill -f "rclone serve webdav $DAV_DIR" > /dev/null 2>&1 || true
    grep -E '^BACKUP_PARALLEL=' "$ARCPANEL/conf/arcpanel.conf" > "$STATE" || true
    grep -E '^BACKUP_REPORT=' "$ARCPANEL/conf/arcpanel.conf" > "$STATE.report" || true
    cp -p "$ARCPANEL/data/backup/report.last" "$STATE.report-last" 2> /dev/null || true

    tmp=$(mktemp)
    sed -e "s/^BACKUPS=.*/BACKUPS='3'/" -e "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" \
        "$ARCPANEL/data/packages/default.pkg" > "$tmp"
    v-add-user-package "$tmp" "$PKG" > /dev/null
    rm -f "$tmp"
    pass="Bj-$(date +%s)-Pass"
    v-add-user "$U1" "$pass" "$U1@example.invalid" "$PKG" > /dev/null
    v-add-user "$U2" "$pass" "$U2@example.invalid" "$PKG" > /dev/null

    mkdir -p "$DAV_DIR"
    setsid nohup rclone serve webdav "$DAV_DIR" --addr "127.0.0.1:$DAV_PORT" --user davuser --pass davpass \
        > /dev/null 2>&1 < /dev/null &
    sleep 2

    v-add-backup-destination arcbats-l1 local "$(BATS_TEST_TMPDIR=$BATS_FILE_TMPDIR options l1 PATH=$DIR/l1)" > /dev/null
    v-add-backup-destination arcbats-l2 local "$(BATS_TEST_TMPDIR=$BATS_FILE_TMPDIR options l2 PATH=$DIR/l2)" > /dev/null
    v-add-backup-destination arcbats-down webdav "$(BATS_TEST_TMPDIR=$BATS_FILE_TMPDIR options down URL=http://127.0.0.1:1 PATH=x)" \
        "$(BATS_TEST_TMPDIR=$BATS_FILE_TMPDIR secrets down PASSWORD=x)" > /dev/null
    v-add-backup-destination arcbats-dav webdav "$(BATS_TEST_TMPDIR=$BATS_FILE_TMPDIR options dav URL=http://127.0.0.1:$DAV_PORT USERNAME=davuser PATH=arc)" \
        "$(BATS_TEST_TMPDIR=$BATS_FILE_TMPDIR secrets dav PASSWORD=davpass)" > /dev/null
}

teardown_file() {
    cleanup
    pkill -f "rclone serve webdav $DAV_DIR" > /dev/null 2>&1 || true
    for u in $U1 $U2; do v-delete-user "$u" > /dev/null 2>&1 || true; done
    v-delete-user-package "$PKG" > /dev/null 2>&1 || true
    rm -rf "$DIR" "$DAV_DIR"
    if [ -s "$STATE" ]; then
        v-change-sys-config-value BACKUP_PARALLEL "$(cut -d"'" -f2 "$STATE")" > /dev/null
    else
        sed -i '/^BACKUP_PARALLEL=/d' "$ARCPANEL/conf/arcpanel.conf"
    fi
    if [ -s "$STATE.report" ]; then
        v-change-sys-config-value BACKUP_REPORT "$(cut -d"'" -f2 "$STATE.report")" > /dev/null
    else
        sed -i '/^BACKUP_REPORT=/d' "$ARCPANEL/conf/arcpanel.conf"
    fi
    if [ -f "$STATE.report-last" ]; then
        cp -p "$STATE.report-last" "$ARCPANEL/data/backup/report.last"
    else
        rm -f "$ARCPANEL/data/backup/report.last"
    fi
}

@test "jobs: engine, scope, schedule and targets are checked; the next runs are listed" {
    run v-add-backup-job arcbats-x archive all 'daily:25:00' arcbats-l1
    assert_failure 2
    run v-add-backup-job arcbats-x archive all 'cron:0 0 31 2 *' arcbats-l1
    assert_failure 2
    run v-add-backup-job arcbats-x tarball all daily:01:00 arcbats-l1
    assert_failure 2
    run v-add-backup-job arcbats-x archive users:nobody-here daily:01:00 arcbats-l1
    assert_failure 3
    run v-add-backup-job arcbats-x archive all daily:01:00 arcbats-nowhere
    assert_failure 3
    run v-add-backup-job arcbats-x archive all daily:01:00 arcbats-l1,arcbats-l1:3
    assert_failure 2
    run v-add-backup-job arcbats-x archive all daily:01:00 arcbats-l1:abc
    assert_failure 2
    run v-list-backup-schedule 'weekly:1:02:30' 3 json
    assert_success
    assert_equal "$(jq 'length' <<< "$output")" 3
    for day in $(jq -r '.[].TIME' <<< "$output" | cut -d' ' -f1); do
        assert_equal "$(date -d "$day" +%u)" 1
    done
}

@test "jobs: one destination down makes the run partial; the others keep the archive" {
    local id archive
    id=$(v-add-backup-job arcbats-partial archive "users:$U1,$U2" daily:01:00 arcbats-l1:2,arcbats-down)
    assert_equal "$(job_value "$id" TARGETS)" 'arcbats-l1:2:-1:-1:-1:-1,arcbats-down:-1:-1:-1:-1:-1'
    run v-run-backup-job "$id"
    assert_success
    assert_equal "$(last_run "$id" STATUS)" partial
    assert_equal "$(last_run "$id" USERS)" 2
    assert_equal "$(user_run "$U1" "$id" STATUS)" succeeded
    assert_equal "$(user_run "$U1" "$id" DESTS)" 'arcbats-l1:ok,arcbats-down:failed'
    archive=$(user_run "$U1" "$id" ARCHIVE)
    assert_file_exists "$DIR/l1/$archive"
    run grep "^BACKUP='$archive' " "$ARCPANEL/data/users/$U1/backup.conf"
    assert_output --partial "JOB='$id' DESTINATIONS='arcbats-l1' ENCRYPTED=''"
    # The user and the administrators hear of it
    run v-list-user-notifications "$U1" json
    assert_output --partial 'Backup partly failed'
    # A third failure in a row marks the destination failing
    v-run-backup-job "$id" > /dev/null
    assert_equal "$(v-list-backup-destination arcbats-down json | jq -r '."arcbats-down".STATE')" failing
    v-delete-backup-job "$id" > /dev/null
}

@test "jobs: every target keeps its own number of archives, at most the package's" {
    local id n
    id=$(v-add-backup-job arcbats-keep archive "users:$U1" daily:01:00 arcbats-l1:1,arcbats-l2:-1)
    rm -f "$DIR"/l1/* "$DIR"/l2/*
    for n in 1 2 3 4; do
        v-run-backup-job "$id" "$U1" > /dev/null
        sleep 1
    done
    run sh -c "ls '$DIR/l1' | grep -c '^$U1\.'"
    assert_output 1
    run sh -c "ls '$DIR/l2' | grep -c '^$U1\.'"
    assert_output 3
    run sh -c "grep \"JOB='$id'\" '$ARCPANEL/data/users/$U1/backup.conf' | wc -l"
    assert_output 3
    # Deleting from one destination keeps the record while another holds it
    archive=$(ls "$DIR/l1" | head -n1)
    run v-delete-user-backup "$U1" "$archive" arcbats-l1
    assert_success
    run grep -c "^BACKUP='$archive' .*DESTINATIONS='arcbats-l2'" "$ARCPANEL/data/users/$U1/backup.conf"
    assert_output 1
    v-delete-backup-job "$id" > /dev/null
}

@test "jobs: the dispatcher starts a due job once, skips paused ones and computes the next run" {
    local id paused next
    id=$(v-add-backup-job arcbats-due archive "users:$U2" 'cron:*/30 * * * *' arcbats-l2)
    paused=$(v-add-backup-job arcbats-paused archive "users:$U2" 'cron:*/30 * * * *' arcbats-l2 '' paused)
    # Due a week ago: one run, not one per missed slot
    sed -i "s/^NEXT_RUN_TS=.*/NEXT_RUN_TS='$(($(date +%s) - 604800))'/" "$JOBS/$id.conf" "$JOBS/$paused.conf"
    run v-update-backup-jobs
    assert_success
    sleep 3
    wait_job "$id"
    run v-list-backup-job-runs "$id" json
    assert_equal "$(jq 'length' <<< "$output")" 1
    assert_equal "$(jq -r '.[0].TRIGGER' <<< "$output")" schedule
    next=$(job_value "$id" NEXT_RUN_TS)
    [ "$next" -gt "$(date +%s)" ]
    run v-list-backup-job-runs "$paused" json
    assert_output '[]'
    run v-update-backup-jobs
    sleep 2
    run v-list-backup-job-runs "$id" json
    assert_equal "$(jq 'length' <<< "$output")" 1
    # Enabled again, it starts from now
    run v-unsuspend-backup-job "$paused"
    assert_success
    [ "$(job_value "$paused" NEXT_RUN_TS)" -gt "$(date +%s)" ]
    v-delete-backup-job "$id" > /dev/null
    v-delete-backup-job "$paused" > /dev/null
}

@test "jobs: a running job is not started again and cannot be deleted" {
    local id
    id=$(v-add-backup-job arcbats-busy archive "users:$U2" daily:01:00 arcbats-l2)
    flock "$JOBS/$id.lock" sleep 15 &
    sleep 1
    run v-start-backup-job "$id"
    assert_failure 7
    run v-delete-backup-job "$id"
    assert_failure 7
    sed -i "s/^NEXT_RUN_TS=.*/NEXT_RUN_TS='$(($(date +%s) - 60))'/" "$JOBS/$id.conf"
    run v-update-backup-jobs
    run grep -c "Backup job $id is still running" "$ARCPANEL/log/backup.log"
    [ "$output" -ge 1 ]
    wait
    run v-delete-backup-job "$id"
    assert_success
}

@test "jobs: scheduled runs follow the package's backup frequency" {
    local id
    id=$(v-add-backup-job arcbats-often archive "users:$U1" hourly:00 arcbats-l2)
    v-change-package-tenancy "$PKG" BACKUP_FREQUENCY daily > /dev/null
    run v-run-backup-job "$id" '' schedule
    assert_equal "$(last_run "$id" DONE)" 1
    # Daily: the next scheduled run skips the user
    run v-run-backup-job "$id" '' schedule
    assert_equal "$(last_run "$id" SKIPPED)" 1
    assert_equal "$(last_run "$id" DONE)" 0
    # Hourly: it does not (the last run started less than an hour ago, so
    # make it older)
    v-change-package-tenancy "$PKG" BACKUP_FREQUENCY hourly > /dev/null
    sed -i "s/START='[^']*'/START='$(date -d '-2 hours' +'%F %T')'/" "$ARCPANEL/data/users/$U1/backup-runs.conf"
    run v-run-backup-job "$id" '' schedule
    assert_equal "$(last_run "$id" DONE)" 1
    # A request is never capped
    run v-run-backup-job "$id" '' cli
    assert_equal "$(last_run "$id" DONE)" 1
    run v-change-package-tenancy "$PKG" BACKUP_FREQUENCY often
    assert_failure 2
    v-delete-backup-job "$id" > /dev/null
}

@test "jobs: BACKUP_PARALLEL slots hold users back across jobs" {
    local id
    v-change-sys-config-value BACKUP_PARALLEL 1 > /dev/null
    id=$(v-add-backup-job arcbats-slots archive "users:$U2" daily:01:00 arcbats-l2)
    mkdir -p "$ARCPANEL/data/backup/slots"
    flock "$ARCPANEL/data/backup/slots/1.lock" sleep 12 &
    sleep 1
    v-start-backup-job "$id"
    sleep 6
    assert_equal "$(last_run "$id" STATUS)" running
    assert_equal "$(last_run "$id" DONE)" 0
    wait
    wait_job "$id"
    assert_equal "$(last_run "$id" STATUS)" success
    assert_equal "$(last_run "$id" DONE)" 1
    v-delete-backup-job "$id" > /dev/null
}

@test "jobs: a snapshot job keeps its retention and restores a file" {
    local id snapshot
    id=$(v-add-backup-job arcbats-snap snapshot "users:$U1" daily:01:00 arcbats-dav:1)
    runuser -u "$U1" -- sh -c "mkdir -p /home/$U1/tmp && echo one > /home/$U1/tmp/f.txt"
    run v-run-backup-job "$id" "$U1"
    assert_success
    runuser -u "$U1" -- sh -c "echo two > /home/$U1/tmp/f.txt"
    run v-run-backup-job "$id" "$U1"
    assert_success
    assert_equal "$(user_run "$U1" "$id" DESTS)" 'arcbats-dav:ok'
    assert_file_exists "$DAV_DIR/arc/restic/$U1.password.age"
    assert_file_exists "$ARCPANEL/data/users/$U1/restic/arcbats-dav.age"
    run v-list-user-backups-restic "$U1" json
    assert_equal "$(jq 'length' <<< "$output")" 1
    assert_equal "$(jq -r '.[0].destination' <<< "$output")" arcbats-dav
    snapshot=$(jq -r '.[0].short_id' <<< "$output")
    runuser -u "$U1" -- sh -c "echo three > /home/$U1/tmp/f.txt"
    run v-restore-file-restic "$U1" "$snapshot" "/home/$U1/tmp/f.txt"
    assert_success
    run cat "/home/$U1/tmp/f.txt"
    assert_output two
    v-delete-backup-job "$id" > /dev/null
}

@test "jobs: the backup keys of the panel are listed by v-list-sys-config" {
    run sh -c "v-list-sys-config json | jq -r '.config | [.BACKUP_PARALLEL, .BACKUP_REPORT, .BACKUP_LA_LIMIT, .BACKUP_TEMP] | map(length > 0) | all'"
    assert_output true
    run v-list-sys-config shell
    assert_output --partial 'Backups at once:'
}

@test "notices: a failed backup tells the user once and mails follow BACKUP_REPORT" {
    local id from before
    id=$(v-add-backup-job arcbats-notice archive "users:$U1" daily:01:00 arcbats-down)
    v-change-sys-config-value BACKUP_REPORT failures > /dev/null

    # A backup with the job outside a job run (a request, the command line)
    before=$(notices "$U1" 'Backup failed')
    from=$(log_lines)
    run v-backup-user "$U1" no "$id"
    assert_failure
    assert_equal "$(notices "$U1" 'Backup failed')" "$((before + 1))"
    run v-list-user-notifications "$U1" json
    assert_output --partial 'arcbats-notice'
    run mails_since "$from"
    assert_output --partial "$U1 → backup failed"
    assert_equal "$(mails_since "$from" | wc -l)" 1

    # A job run: one notice for the user, one mail for the job, none per user
    before=$(notices "$U1" 'Backup failed')
    from=$(log_lines)
    v-run-backup-job "$id" > /dev/null
    assert_equal "$(last_run "$id" STATUS)" failed
    assert_equal "$(notices "$U1" 'Backup failed')" "$((before + 1))"
    run mails_since "$from"
    assert_output --partial 'Backup job arcbats-notice: failed'
    assert_equal "$(mails_since "$from" | wc -l)" 1

    # BACKUP_REPORT off: no mail, the notices stay
    v-change-sys-config-value BACKUP_REPORT off > /dev/null
    before=$(notices "$U1" 'Backup failed')
    from=$(log_lines)
    v-backup-user "$U1" no "$id" > /dev/null 2>&1 || true
    v-run-backup-job "$id" > /dev/null
    assert_equal "$(notices "$U1" 'Backup failed')" "$((before + 2))"
    assert_equal "$(mails_since "$from" | wc -l)" 0

    v-change-sys-config-value BACKUP_REPORT failures > /dev/null
    v-delete-backup-job "$id" > /dev/null
}

# bj_daily_report with the hour of the day set to $1
daily_report_at() {
    HOUR="$1" bash <<'SCRIPT'
source /etc/arcpanel/arcpanel.conf
source "$ARCPANEL/func/main.sh"
source "$ARCPANEL/func/secret.sh"
source "$ARCPANEL/func/backup-driver.sh"
source "$ARCPANEL/func/backup-job.sh"
source_conf "$ARCPANEL/conf/arcpanel.conf"
date() {
    if [ "$1" = +%H ]; then
        echo "$HOUR"
    else
        command date "$@"
    fi
}
bj_daily_report
SCRIPT
}

@test "notices: the daily report is mailed once a day after 07:00, only when BACKUP_REPORT is daily" {
    local id from
    id=$(v-add-backup-job arcbats-report archive "users:$U1" daily:01:00 arcbats-l1)
    v-run-backup-job "$id" > /dev/null
    rm -f "$ARCPANEL/data/backup/report.last"

    v-change-sys-config-value BACKUP_REPORT failures > /dev/null
    from=$(log_lines)
    daily_report_at 08
    assert_equal "$(mails_since "$from" | wc -l)" 0

    v-change-sys-config-value BACKUP_REPORT daily > /dev/null
    daily_report_at 06
    assert_equal "$(mails_since "$from" | wc -l)" 0
    daily_report_at 08
    daily_report_at 09
    run mails_since "$from"
    assert_output --partial "Backup report $(hostname) $(date +%F)"
    assert_equal "$(mails_since "$from" | wc -l)" 1
    assert_equal "$(cat "$ARCPANEL/data/backup/report.last")" "$(date +%F)"

    v-change-sys-config-value BACKUP_REPORT failures > /dev/null
    v-delete-backup-job "$id" > /dev/null
}
