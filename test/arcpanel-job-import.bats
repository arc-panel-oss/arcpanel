#!/usr/bin/env bats
# The upgrade import of the task records of 2.6 into jobs (E15 Job Center,
# docs/features/specs/2026-11-05-001-e15-job-center-design.md §9): finished
# records become jobs with the same IDs, pipe lines become queued jobs, a
# record that still runs is imported by v-update-sys-jobs once it finishes.
# Run as root on a test server; it writes records in the old formats for a
# test user and removes them at the end.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
JOB_DIR=$ARCPANEL/data/jobs
JI_USER=arcbats-jimp
QUEUE=$ARCPANEL/data/queue

# Run bash code with the job library and the import loaded
lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/tenancy.sh; source $ARCPANEL/func/job.sh; source $ARCPANEL/func/job-import.sh; source_conf $ARCPANEL/conf/arcpanel.conf; $1"
}

field() {
    v-list-job "$1" 0 json | jq -r ".$2"
}

setup_file() {
    v-add-user "$JI_USER" 'arcbats-Imp0rt!' arcbats-jimp@example.com > /dev/null 2>&1 || true
    mkdir -p "$QUEUE"
    for f in backup.pipe letsencrypt.pipe; do
        [ -f "$QUEUE/$f" ] && mv "$QUEUE/$f" "$QUEUE/$f.bats-saved"
    done
    return 0
}

teardown_file() {
    pkill -f 'arcbats-jimp-sleeper' 2> /dev/null || true
    for id in 17000000000001 17000000000002 17000000000003 17000000000004 17000000000005; do
        rm -f "$JOB_DIR/$id".*
    done
    for f in $(grep -l "USER='$JI_USER'" "$JOB_DIR"/*.conf 2> /dev/null); do
        rm -f "${f%.conf}".*
    done
    rm -f "$JOB_DIR/.import-pending"
    for f in backup.pipe letsencrypt.pipe; do
        rm -f "$QUEUE/$f"
        [ -f "$QUEUE/$f.bats-saved" ] && mv "$QUEUE/$f.bats-saved" "$QUEUE/$f"
    done
    v-delete-user "$JI_USER" > /dev/null 2>&1 || true
}

@test "import: finished records become jobs with their IDs, logs and errors; the old files go" {
    udir=$ARCPANEL/data/users/$JI_USER
    mkdir -p "$udir/tasks" "$udir/malware"
    echo "ID='17000000000001' KIND='backup' ARGS='$JI_USER' STATUS='failed' EXIT='1' ERROR='disk full' QUEUED='2026-11-01 01:00:00' START='2026-11-01 01:00:01' END='2026-11-01 01:02:00' PID='' ATTEMPTS='3'" > "$udir/tasks/17000000000001.conf"
    printf 'packing\nError: disk full\n' > "$udir/tasks/17000000000001.log"
    echo "ID='17000000000002' DOMAIN='' STATUS='done' PID='' EXIT='0' START='2026-11-02 10:00:00' END='2026-11-02 10:01:00'" > "$udir/malware/task.conf"
    echo "scanned 3 files" > "$udir/malware/task.log"

    run lib job_import_start
    assert_success
    assert_output --partial 'imported backup.user 17000000000001'
    assert_output --partial 'imported malware.scan 17000000000002'

    assert_equal "$(field 17000000000001 TYPE) $(field 17000000000001 STATUS) $(field 17000000000001 USER)" "backup.user failed $JI_USER"
    assert_equal "$(field 17000000000001 ERROR)" 'disk full'
    assert_equal "$(field 17000000000001 ATTEMPTS) $(field 17000000000001 ACTOR) $(field 17000000000001 OBJECT)" "3 upgrade user:$JI_USER"
    v-list-job 17000000000001 0 json | jq -e '.LOG | contains("packing")'
    assert_equal "$(field 17000000000002 STATUS) $(field 17000000000002 OBJECT)" "succeeded malware:$JI_USER"
    assert_file_not_exists "$udir/tasks/17000000000001.conf"
    assert_file_not_exists "$udir/tasks/17000000000001.log"
    assert_file_not_exists "$udir/malware/task.conf"
    # nothing runs: the marker is gone
    assert_file_not_exists "$JOB_DIR/.import-pending"
    # the files are root's and the panel's group may read them
    run stat -c '%U:%G %a' "$JOB_DIR/17000000000001.conf"
    assert_output 'root:arcpanelweb 640'
}

@test "import: a running record waits; v-update-sys-jobs imports it once it has finished" {
    udir=$ARCPANEL/data/users/$JI_USER
    bash -c 'exec -a arcbats-jimp-sleeper sleep 120' &
    pid=$!
    echo "ID='17000000000003' ACTION='add-version' ARGS='lts' STATUS='running' PID='$pid' EXIT='' START='2026-11-03 10:00:00' END=''" > "$udir/node-task.conf"

    run lib job_import_start
    assert_success
    assert_file_exists "$JOB_DIR/.import-pending"
    assert_file_exists "$udir/node-task.conf"
    run v-list-job 17000000000003 0 json
    assert_failure

    kill "$pid"
    wait "$pid" 2> /dev/null || true
    sed -i "s/STATUS='running'/STATUS='done'/; s/EXIT=''/EXIT='0'/; s/END=''/END='2026-11-03 10:05:00'/" "$udir/node-task.conf"
    run v-update-sys-jobs
    assert_success
    assert_equal "$(field 17000000000003 TYPE) $(field 17000000000003 STATUS) $(field 17000000000003 OBJECT)" "node.install succeeded node:$JI_USER"
    assert_file_not_exists "$udir/node-task.conf"
    assert_file_not_exists "$JOB_DIR/.import-pending"
}

@test "import: a marker older than 24 hours goes even when records still run" {
    udir=$ARCPANEL/data/users/$JI_USER
    echo "ID='17000000000004' DOMAIN='example.com' APP='DokuWiki' STATUS='running' PID='1' EXIT='' START='' END=''" > "$udir/quick-install-task.conf"
    echo $(($(date +%s) - 25 * 3600)) > "$JOB_DIR/.import-pending"
    run v-update-sys-jobs
    assert_success
    assert_file_not_exists "$JOB_DIR/.import-pending"
    rm -f "$udir/quick-install-task.conf"
}

@test "import: the lines of the queue pipes become queued jobs and their records go" {
    udir=$ARCPANEL/data/users/$JI_USER
    mkdir -p "$udir/tasks"
    echo "ID='17000000000005' KIND='backup' ARGS='$JI_USER' STATUS='queued' EXIT='' ERROR='' QUEUED='2026-11-05 01:00:00' START='' END='' PID='' ATTEMPTS='0'" > "$udir/tasks/17000000000005.conf"
    echo "$ARCPANEL/bin/v-run-queue-task $JI_USER 17000000000005 -- $ARCPANEL/bin/v-backup-user $JI_USER yes > /dev/null 2>&1" > "$QUEUE/backup.pipe"
    echo "$ARCPANEL/bin/v-run-queue-task $JI_USER 17000000000006 -- $ARCPANEL/bin/v-add-letsencrypt-domain $JI_USER mail.example.com '' yes" > "$QUEUE/letsencrypt.pipe"
    echo "$ARCPANEL/bin/v-run-queue-task $JI_USER 17000000000007 -- $ARCPANEL/bin/rm -rf /" >> "$QUEUE/letsencrypt.pipe"

    # the queue must not start them during the test
    run lib 'JOB_SKIP_DISPATCH=all job_import_pipes'
    assert_success
    assert_output --partial 'queued backup.user'
    assert_output --partial 'queued letsencrypt.domain'
    assert_output --partial 'skipped a queue line of an unknown command: rm'
    assert_file_not_exists "$QUEUE/backup.pipe"
    assert_file_not_exists "$QUEUE/letsencrypt.pipe"
    assert_file_not_exists "$udir/tasks/17000000000005.conf"

    backup=$(grep -l "USER='$JI_USER'" "$JOB_DIR"/*.conf | xargs grep -l "TYPE='backup.user'" | xargs grep -l "ACTOR='upgrade'" | xargs grep -L "ID='170000000000" | head -n1)
    [ -n "$backup" ]
    run grep -c "ARGS='v-backup-user $JI_USER yes'" "$backup"
    assert_output '1'
    le=$(grep -l "USER='$JI_USER'" "$JOB_DIR"/*.conf | xargs grep -l "TYPE='letsencrypt.domain'" | head -n1)
    run grep -c "OBJECT='mail:mail.example.com'" "$le"
    assert_output '1'
    # Cancel them so that the queue never runs them
    for f in "$backup" "$le"; do
        v-cancel-job "$(basename "$f" .conf)" bats > /dev/null 2>&1 || true
    done
}

@test "the 2.7.0 upgrade step replaces the queue cron lines with the job queue, once" {
    local cron=/var/spool/cron/crontabs/arcpanelweb saved
    saved=$(mktemp)
    cp -p "$cron" "$saved"
    sed -i '/bin\/v-update-sys-jobs/d' "$cron"
    # sed, not >>: fs.protected_regular refuses root an O_CREAT open here
    sed -i -e '$a*/5 * * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue backup' \
        -e '$a*/5 * * * * sudo /usr/local/arcpanel/bin/v-update-sys-queue letsencrypt' "$cron"
    for i in 1 2; do
        run lib "source $ARCPANEL/install/upgrade/versions/2.7.0.sh"
        assert_success
    done
    run grep -c 'v-update-sys-queue backup\|v-update-sys-queue letsencrypt' "$cron"
    assert_output '0'
    run grep -c 'bin/v-update-sys-jobs' "$cron"
    assert_output '1'
    run grep -c 'v-update-sys-queue daily' "$cron"
    assert_output '1'
    cp -p "$saved" "$cron"
    rm -f "$saved"
}
