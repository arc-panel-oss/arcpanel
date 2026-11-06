#!/usr/bin/env bats
# Jobs (E15 Job Center,
# docs/features/specs/2026-11-05-001-e15-job-center-design.md): create,
# start, run, heartbeat, reap, cancel, retry, cleanup, concurrency keys,
# queue classes, masking, the log cap, the systemd and the fallback runner.
# Run as root on a test server. The suite registers test job types in
# func/job.d/ and a test command in bin/, and removes both at the end.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
JOB_DIR=$ARCPANEL/data/jobs
TYPES=$ARCPANEL/func/job.d/zz-bats-jobs.sh
STUB=$ARCPANEL/bin/v-bats-job-test

# Run bash code with the job library loaded
lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/tenancy.sh; source $ARCPANEL/func/job.sh; source_conf $ARCPANEL/conf/arcpanel.conf; $1"
}

# Start a job: mkjob TYPE OBJECT ARG... prints the ID (env JOB_* passes)
mkjob() {
    local type="$1" object="$2"
    shift 2
    lib "job_start $type system $object bats -- v-bats-job-test $(printf '%q ' "$@") ; rc=\$?; [ \$rc -eq 0 ] || { echo \"\$JOB_REASON\" >&2; exit \$rc; }; echo \$JOB_ID"
}

field() {
    v-list-job "$1" 0 json | jq -r ".$2"
}

# Wait until job ID is finished; print its status
wait_job() {
    local status i
    for i in $(seq 1 "${2:-60}"); do
        status=$(field "$1" STATUS)
        if [ "$status" != running ] && [ "$status" != queued ]; then
            echo "$status"
            return
        fi
        sleep 1
    done
    echo timeout
}

# Wait until job ID has STATUS
wait_status() {
    local i
    for i in $(seq 1 "${3:-30}"); do
        [ "$(field "$1" STATUS)" = "$2" ] && return 0
        sleep 1
    done
    return 1
}

# A hand-written record
write_record() {
    local id="$1" status="$2" extra="$3"
    mkdir -p "$JOB_DIR"
    echo "ID='$id' TYPE='bats.immediate' USER='system' OWNER='system' ACTOR='bats' OBJECT='bats-hand' ARGS='v-bats-job-test echo' STATUS='$status' EXIT='' ERROR='' UNIT='' PID='' QUEUED='2026-11-05 10:00:00' START='2026-11-05 10:00:00' END='' HEARTBEAT='' ATTEMPTS='1' RETRY_OF='' RESOURCE='' UPDATED='2026-11-05 10:00:00' $extra" > "$JOB_DIR/$id.conf"
    : > "$JOB_DIR/$id.log"
}

remove_bats_jobs() {
    local file id
    [ -d "$JOB_DIR" ] || return 0
    for file in "$JOB_DIR"/*.conf; do
        [ -f "$file" ] || continue
        if grep -qE "OBJECT='bats-|TYPE='bats\." "$file"; then
            id=$(basename "$file" .conf)
            systemctl stop "arcpanel-job-$id.service" > /dev/null 2>&1 || true
            rm -f "$JOB_DIR/$id".*
        fi
    done
}

setup_file() {
    mkdir -p "$ARCPANEL/func/job.d"
    cat > "$TYPES" << 'EOF'
job_type_add bats.immediate v-bats-job-test immediate object yes 60
job_type_add bats.fixed v-bats-job-test immediate object no 60
job_type_add bats.backup v-bats-job-test backup object yes 60
job_type_add bats.le v-bats-job-test letsencrypt object yes 60
job_type_add bats.deploy v-bats-job-test deploy object yes 60
job_type_add bats.sys v-bats-job-test immediate sys no 60
job_type_add bats.sys2 v-bats-job-test immediate sys no 60
job_type_add bats.short v-bats-job-test immediate object yes 3
EOF
    cat > "$STUB" << 'EOF'
#!/bin/bash
# info: test command of arcpanel-jobs.bats
case "$1" in
    echo) shift; echo "$@"; echo "JOB_ID=$JOB_ID" ;;
    sleep) echo "sleeping $2"; sleep "$2"; echo "slept" ;;
    fail) echo "working"; echo "Error: the bats command failed"; echo "trailing"; exit "${2:-3}" ;;
    noretry) source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/job.sh; job_no_retry; echo "Error: final"; exit 4 ;;
    secret) printf '%s\n' "$2" >> "$JOB_MASK_FILE"; echo "token=$2 done"; echo "url https://bob:hunter22@example.com/x" ;;
    big) for i in $(seq 1 "$2"); do printf 'line %06d %s\n' "$i" 0123456789012345678901234567890123456789012345678901234567890123456789; done ;;
    *) echo "Error: unknown"; exit 2 ;;
esac
EOF
    chmod 755 "$STUB"
    remove_bats_jobs
}

teardown_file() {
    remove_bats_jobs
    rm -f "$TYPES" "$STUB"
    rmdir "$ARCPANEL/func/job.d" 2> /dev/null || true
}

teardown() {
    # nothing of a test may block the next one
    local id
    for id in $(lib job_active_ids); do
        if grep -qE "OBJECT='bats-|TYPE='bats\." "$JOB_DIR/$id.conf"; then
            v-cancel-job "$id" bats > /dev/null 2>&1 || true
        fi
    done
}

@test "Jobs: arguments are validated" {
    run v-list-job
    assert_failure 1
    run v-list-job 123
    assert_failure 2
    run v-list-job 99999999999999
    assert_failure 3
    run v-list-job 99999999999999 abc
    assert_failure 2
    run v-cancel-job 99999999999999 'bad actor'
    assert_failure 2
    run v-retry-job 99999999999999
    assert_failure 3
    run v-list-jobs yesterday
    assert_failure 2
    run lib 'job_create nope.type system bats-x bats -- v-bats-job-test echo'
    assert_failure 2
    run lib 'job_create bats.immediate system bats-x bats -- v-add-user x'
    assert_failure 10
    run lib 'job_create bats.immediate system bats-x bats -- /tmp/v-bats-job-test echo'
    assert_failure 10
    run lib 'job_create bats.immediate system "bats x" bats -- v-bats-job-test echo'
    assert_failure 2
    run lib 'job_create bats.immediate nosuchuser42 bats-x bats -- v-bats-job-test echo'
    assert_failure 3
}

@test "Jobs: new IDs grow in the order the jobs are created, also within one second" {
    # Many IDs in one go fall into the same second; each must sort after
    # the one before, so the newest job of an object is the last created
    run lib 'prev=0; for i in $(seq 1 200); do id=$(job_lock; job_new_id; job_unlock); [[ $id =~ ^[0-9]{14}$ ]] || { echo "bad $id"; exit 1; }; [ "$id" -gt "$prev" ] || { echo "$id after $prev"; exit 1; }; prev=$id; done; echo ok'
    assert_success
    assert_output ok
}

@test "Jobs: the job types of the spec are registered with their commands" {
    run lib 'for t in backup.user restore.restic letsencrypt.domain deploy.run sys.php docker.engine docker.install node.build app-process.custom quick-install malware.scan backup.job fs.fetch doctor.run doctor.fix; do echo "$t $(job_type_class $t) ${JOB_TYPE_KEY[$t]} ${JOB_TYPE_RETRY[$t]}"; done'
    assert_success
    assert_line "backup.user backup object yes"
    assert_line "letsencrypt.domain letsencrypt object yes"
    assert_line "deploy.run deploy object yes"
    assert_line "sys.php immediate sys no"
    assert_line "docker.engine immediate sys no"
    assert_line "docker.install immediate object yes"
    assert_line "node.build immediate object yes"
    assert_line "doctor.run immediate object yes"
    assert_line "doctor.fix immediate object no"
    run lib 'job_type_limit doctor.run doctor:all; job_type_limit doctor.run doctor:web:x.example; job_type_limit backup.user user:x'
    assert_output $'7200\n1800\n86400'
}

@test "Jobs: a job runs in a transient unit and records its result" {
    [ -d /run/systemd/system ] || skip "no systemd"
    run mkjob bats.immediate bats-unit echo hello world
    assert_success
    assert_output --regexp '^[0-9]{14}$'
    id=$output
    run wait_job "$id"
    assert_output succeeded
    run v-list-job "$id" 0 json
    assert_success
    run jq -r '[.ID, .TYPE, .USER, .OWNER, .ACTOR, .OBJECT, .EXIT, .UNIT, .CLASS, .RETRYABLE] | join(" ")' <<< "$output"
    assert_output "$id bats.immediate system system bats bats-unit 0 arcpanel-job-$id immediate yes"
    run field "$id" LOG
    assert_line "hello world"
    assert_line "JOB_ID=$id"
    run field "$id" ARGS
    assert_output "v-bats-job-test echo hello world"
    for key in QUEUED START END HEARTBEAT UPDATED; do
        run field "$id" "$key"
        assert_output --regexp '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'
    done
    run stat -c '%U %G %a' "$JOB_DIR/$id.conf" "$JOB_DIR/$id.log" "$JOB_DIR/$id.argv"
    assert_output $'root arcpanelweb 640\nroot arcpanelweb 640\nroot root 600'
    run stat -c '%U %G %a' "$JOB_DIR"
    assert_output "root arcpanelweb 750"
    assert_file_not_exist "$JOB_DIR/$id.mask"
}

@test "Jobs: the fallback runner without systemd runs the job and records its PID" {
    export JOB_NO_SYSTEMD=1
    run mkjob bats.immediate bats-fallback echo plain
    assert_success
    id=$output
    run wait_job "$id"
    assert_output succeeded
    run field "$id" UNIT
    assert_output ''
    run field "$id" PID
    assert_output --regexp '^[0-9]+$'
    run field "$id" LOG
    assert_line plain
}

@test "Jobs: a failed job keeps the exit code and the last Error: line" {
    run mkjob bats.immediate bats-fail fail 5
    id=$output
    run wait_job "$id"
    assert_output failed
    run field "$id" EXIT
    assert_output 5
    run field "$id" ERROR
    assert_output "the bats command failed"
}

@test "Jobs: the runner refreshes the heartbeat" {
    export JOB_NO_SYSTEMD=1 JOB_HEARTBEAT_EVERY=1
    run mkjob bats.immediate bats-beat sleep 4
    id=$output
    wait_status "$id" running 10
    first=$(field "$id" HEARTBEAT)
    sleep 2.5
    run field "$id" HEARTBEAT
    refute_output "$first"
    run wait_job "$id"
    assert_output succeeded
}

@test "Jobs: the runner refuses a job it did not start" {
    write_record 11111111111111 queued
    printf '%s\0' "$ARCPANEL/bin/v-bats-job-test" echo > "$JOB_DIR/11111111111111.argv"
    run v-run-job 11111111111111
    assert_failure 10
    run v-run-job 22222222222222
    assert_failure 3
    run field 11111111111111 STATUS
    assert_output queued
    rm -f "$JOB_DIR"/11111111111111.*
}

@test "Jobs: a running job whose runner is gone is reaped as failed" {
    write_record 33333333333333 running ""
    now=$(date +'%F %T')
    sed -i -e "s/UNIT=''/UNIT='arcpanel-job-gone-33'/" -e "s/START='[^']*'/START='$now'/" "$JOB_DIR/33333333333333.conf"
    run field 33333333333333 STATUS
    assert_output failed
    run field 33333333333333 ERROR
    assert_output "runner stopped"
    rm -f "$JOB_DIR"/33333333333333.*
}

@test "Jobs: a running job with an old heartbeat is reaped by v-update-sys-jobs" {
    bash -c 'exec -a v-run-job sleep 60' &
    pid=$!
    write_record 44444444444444 running
    old=$(date -d '10 minutes ago' +'%F %T')
    sed -i -e "s/PID=''/PID='$pid'/" -e "s/HEARTBEAT=''/HEARTBEAT='$old'/" "$JOB_DIR/44444444444444.conf"
    run v-update-sys-jobs
    assert_success
    run grep -o "STATUS='[a-z]*'" "$JOB_DIR/44444444444444.conf"
    assert_output "STATUS='failed'"
    kill "$pid" 2> /dev/null || true
    rm -f "$JOB_DIR"/44444444444444.*
}

@test "Jobs: the time limit of the type stops the job" {
    run mkjob bats.short bats-short sleep 30
    id=$output
    run wait_job "$id" 40
    assert_output failed
    run field "$id" ERROR
    assert_output "time limit of 1 min reached"
}

@test "Jobs: a second job with the same concurrency key is refused" {
    run mkjob bats.immediate bats-key sleep 20
    id=$output
    wait_status "$id" running 10
    run mkjob bats.fixed bats-key echo again
    assert_failure 7
    assert_output --partial "$id"
    # a sys-keyed type blocks every sys-keyed type
    run mkjob bats.sys bats-sys-a sleep 20
    sys=$output
    run mkjob bats.sys2 bats-sys-b echo
    assert_failure 7
    assert_output --partial "$sys"
    v-cancel-job "$id" bats
    v-cancel-job "$sys" bats
}

@test "Jobs: a queued class starts one job at a time in ID order" {
    run mkjob bats.backup bats-q1 sleep 4
    first=$output
    run mkjob bats.backup bats-q2 echo second
    second=$output
    wait_status "$first" running 10
    run field "$second" STATUS
    assert_output queued
    run wait_job "$first"
    assert_output succeeded
    # the runner of the first job starts the next one
    run wait_job "$second" 20
    assert_output succeeded
}

@test "Jobs: v-update-sys-jobs starts a queued job with a free slot" {
    write_record 55555555555555 queued
    sed -i "s/TYPE='bats.immediate'/TYPE='bats.le'/; s/OBJECT='bats-hand'/OBJECT='bats-le'/" "$JOB_DIR/55555555555555.conf"
    printf '%s\0' "$ARCPANEL/bin/v-bats-job-test" echo queued-le > "$JOB_DIR/55555555555555.argv"
    run v-update-sys-jobs
    assert_success
    run wait_job 55555555555555
    assert_output succeeded
    run field 55555555555555 LOG
    assert_line queued-le
}

@test "Jobs: a newer deploy request replaces the queued one" {
    run mkjob bats.deploy bats-deploy sleep 5
    running=$output
    wait_status "$running" running 10
    run mkjob bats.deploy bats-deploy echo older
    older=$output
    run mkjob bats.deploy bats-deploy echo newer
    newer=$output
    run field "$older" STATUS
    assert_output cancelled
    run field "$older" ERROR
    assert_output "replaced by $newer"
    run wait_job "$newer" 30
    assert_output succeeded
}

@test "Jobs: a failed queued job is queued again up to QUEUE_MAX_ATTEMPTS" {
    run mkjob bats.backup bats-attempts fail 6
    id=$output
    for i in $(seq 1 60); do
        status=$(field "$id" STATUS)
        [ "$status" = failed ] && break
        v-update-sys-jobs
        sleep 1
    done
    run field "$id" STATUS
    assert_output failed
    run field "$id" ATTEMPTS
    assert_output 3
    run field "$id" ERROR
    assert_output "gave up after 3 attempts: the bats command failed"
    run grep -c -- '--- attempt' "$JOB_DIR/$id.log"
    assert_output 2
}

@test "Jobs: QUEUE_MAX_ATTEMPTS sets the runs of a queued job (garbage 3, zero 1)" {
    run lib 'QUEUE_MAX_ATTEMPTS=many job_max_attempts; QUEUE_MAX_ATTEMPTS=0 job_max_attempts; QUEUE_MAX_ATTEMPTS=5 job_max_attempts; QUEUE_MAX_ATTEMPTS= job_max_attempts'
    assert_output $'3\n1\n5\n3'
    sed -i '/^QUEUE_MAX_ATTEMPTS=/d' "$ARCPANEL/conf/arcpanel.conf"
    echo "QUEUE_MAX_ATTEMPTS='1'" >> "$ARCPANEL/conf/arcpanel.conf"
    run mkjob bats.backup bats-max1 fail 6
    id=$output
    run wait_job "$id"
    sed -i '/^QUEUE_MAX_ATTEMPTS=/d' "$ARCPANEL/conf/arcpanel.conf"
    assert_output failed
    run field "$id" ERROR
    assert_output "gave up after 1 attempts: the bats command failed"
}

@test "Jobs: the error line is the last Error: line, else the last line, at most 300 characters" {
    local id=19990101120005 long
    mkdir -p "$JOB_DIR"
    printf '%s\n' 'starting' 'Error: first' 'more output' '  Error: second one' 'tail line' '' > "$JOB_DIR/$id.log"
    run lib "job_error_line $id"
    assert_output 'second one'
    printf '%s\n' 'starting' 'last words' '' '' > "$JOB_DIR/$id.log"
    run lib "job_error_line $id"
    assert_output 'last words'
    long=$(printf 'x%.0s' $(seq 1 400))
    printf 'Error: %s\n' "$long" > "$JOB_DIR/$id.log"
    run lib "job_error_line $id"
    [ "${#output}" -eq 300 ]
    rm -f "$JOB_DIR/$id.log"
}

@test "Jobs: a command can make its failure final" {
    run mkjob bats.backup bats-noretry noretry
    id=$output
    run wait_job "$id"
    assert_output failed
    run field "$id" ATTEMPTS
    assert_output 1
    run field "$id" ERROR
    assert_output final
}

@test "Jobs: a queued job is cancelled at once, a finished one is refused" {
    run mkjob bats.backup bats-c1 sleep 10
    blocker=$output
    run mkjob bats.backup bats-c2 echo never
    id=$output
    run field "$id" STATUS
    assert_output queued
    run v-cancel-job "$id" alice
    assert_success
    run field "$id" STATUS
    assert_output cancelled
    run field "$id" ERROR
    assert_output "cancelled by alice"
    run v-cancel-job "$id" alice
    assert_failure 2
    assert_output --partial cancelled
    v-cancel-job "$blocker" bats
}

@test "Jobs: a running job is cancelled by stopping its unit" {
    [ -d /run/systemd/system ] || skip "no systemd"
    run mkjob bats.immediate bats-cancel sleep 60
    id=$output
    wait_status "$id" running 10
    run v-cancel-job "$id"
    assert_success
    run field "$id" STATUS
    assert_output cancelled
    run field "$id" ERROR
    assert_output "cancelled by cli"
    run systemctl is-active "arcpanel-job-$id.service"
    refute_output active
    run pgrep -f "v-bats-job-test sleep 60"
    assert_failure
}

@test "Jobs: a running job of the fallback runner is cancelled" {
    export JOB_NO_SYSTEMD=1
    run mkjob bats.immediate bats-cancel-fb sleep 60
    id=$output
    wait_status "$id" running 10
    pid=$(field "$id" PID)
    run v-cancel-job "$id" bats
    assert_success
    sleep 1
    run field "$id" STATUS
    assert_output cancelled
    run kill -0 "$pid"
    assert_failure
    run pgrep -f "v-bats-job-test sleep 60"
    assert_failure
}

@test "Jobs: a failed retryable job is retried as a new job" {
    run mkjob bats.immediate bats-retry fail 3
    id=$output
    wait_job "$id"
    run v-retry-job "$id" alice
    assert_success
    assert_output --regexp '^[0-9]{14}$'
    new=$output
    [ "$new" != "$id" ]
    run v-list-job "$new" 0 json
    run jq -r '[.TYPE, .USER, .OBJECT, .ACTOR, .RETRY_OF, .ARGS] | join(" ")' <<< "$output"
    assert_output "bats.immediate system bats-retry alice $id v-bats-job-test fail 3"
    wait_job "$new"
    # finished jobs only, retryable types only
    run mkjob bats.immediate bats-retry2 echo ok
    ok=$output
    wait_job "$ok"
    run v-retry-job "$ok"
    assert_failure 2
    assert_output --partial succeeded
    run mkjob bats.fixed bats-retry3 fail 3
    fixed=$output
    wait_job "$fixed"
    run v-retry-job "$fixed"
    assert_failure 2
    assert_output --partial "cannot be retried"
}

@test "Jobs: secrets and URL credentials are masked in the log" {
    run mkjob bats.immediate bats-mask secret s3cr3t-value-42
    id=$output
    wait_job "$id"
    run cat "$JOB_DIR/$id.log"
    refute_output --partial s3cr3t-value-42
    refute_output --partial hunter22
    assert_line "token=*** done"
    assert_line "url https://***@example.com/x"
    assert_file_not_exist "$JOB_DIR/$id.mask"
}

@test "Jobs: the log is capped, keeping its start and its end" {
    export JOB_NO_SYSTEMD=1 JOB_LOG_MAX=100000 JOB_LOG_HEAD=20000
    run mkjob bats.immediate bats-cap big 5000
    id=$output
    run wait_job "$id"
    assert_output succeeded
    size=$(stat -c %s "$JOB_DIR/$id.log")
    [ "$size" -le 100200 ]
    run head -n1 "$JOB_DIR/$id.log"
    assert_output --partial "line 000001"
    run tail -n1 "$JOB_DIR/$id.log"
    assert_output --partial "line 005000"
    run grep -c "log trimmed" "$JOB_DIR/$id.log"
    assert_output 1
}

@test "Jobs: the log is read by byte offset" {
    run mkjob bats.immediate bats-offset echo first-line
    id=$output
    wait_job "$id"
    run v-list-job "$id" 0 json
    offset=$(jq -r .OFFSET <<< "$output")
    size=$(jq -r .SIZE <<< "$output")
    [ "$offset" = "$size" ]
    run v-list-job "$id" 6 json
    run jq -r .LOG <<< "$output"
    assert_line --index 0 "line"
    run v-list-job "$id" "$size" json
    run jq -r .LOG <<< "$output"
    assert_output ''
    run v-list-job "$id" 999999 json
    run jq -r .OFFSET <<< "$output"
    assert_output "$size"
}

@test "Jobs: v-list-jobs lists jobs updated since a time" {
    write_record 66666666666666 succeeded
    run v-list-jobs all json
    assert_success
    run jq -r '."66666666666666" | [.STATUS, .CLASS, .RETRYABLE] | join(" ")' <<< "$output"
    assert_output "succeeded immediate yes"
    run v-list-jobs '2026-11-05 10:00:00' json
    run jq -r 'has("66666666666666")' <<< "$output"
    assert_output true
    run v-list-jobs '2026-11-05 10:00:01' json
    run jq -r 'has("66666666666666")' <<< "$output"
    assert_output false
    run v-list-jobs 2026-11-05T09:00:00 json
    run jq -r 'has("66666666666666")' <<< "$output"
    assert_output true
    run v-list-jobs
    assert_success
    rm -f "$JOB_DIR"/66666666666666.*
}

@test "Jobs: cleanup deletes old and surplus finished jobs, never active ones" {
    old=$(date -d '40 days ago' +'%F %T')
    write_record 70000000000001 succeeded "END='$old'"
    sed -i "s/END=''//" "$JOB_DIR/70000000000001.conf"
    write_record 70000000000002 queued
    sed -i "s/QUEUED='[^']*'/QUEUED='$old'/" "$JOB_DIR/70000000000002.conf"
    for n in 3 4 5 6; do
        write_record "7000000000000$n" failed "END='$(date +'%F %T')'"
        sed -i "0,/END=''/s/END=''//; s/USER='system'/USER='bats-cleanup'/" "$JOB_DIR/7000000000000$n.conf"
    done
    run lib 'JOB_KEEP_MAX=2 job_cleanup'
    assert_success
    assert_file_not_exist "$JOB_DIR/70000000000001.conf"
    assert_file_not_exist "$JOB_DIR/70000000000001.log"
    assert_file_exist "$JOB_DIR/70000000000002.conf"
    assert_file_not_exist "$JOB_DIR/70000000000003.conf"
    assert_file_not_exist "$JOB_DIR/70000000000004.conf"
    assert_file_exist "$JOB_DIR/70000000000005.conf"
    assert_file_exist "$JOB_DIR/70000000000006.conf"
    rm -f "$JOB_DIR"/7000000000000*.*
}

@test "Jobs: a job of a hosting user runs in the user's slice" {
    [ -d /run/systemd/system ] || skip "no systemd"
    user=$(lib 'echo $ROOT_USER')
    [ -f "$ARCPANEL/data/users/$user/user.conf" ] || skip "no administrator account $user"
    uid=$(id -u "$user")
    id=$(lib "job_start bats.immediate $user bats-slice bats -- v-bats-job-test sleep 3 && echo \$JOB_ID")
    wait_status "$id" running 10
    run systemctl show -p Slice --value "arcpanel-job-$id.service"
    assert_output "user-$uid.slice"
    run field "$id" OWNER
    assert_output "$user"
    wait_job "$id"
}
