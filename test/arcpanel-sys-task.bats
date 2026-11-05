#!/usr/bin/env bats
# System task commands (E7 batch 3,
# docs/features/specs/2026-10-06-003-e7-batch3-pages-design.md §6); since
# E15 a system task is a job of type sys.KIND.
# Run as root on a test server with internet access (the PHP test installs
# and removes PHP 8.3 from the PHP package repository).

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
JOB_DIR=$ARCPANEL/data/jobs
PHP_TEST=8.3

# Wait until the system task is no longer running; print its status
wait_task() {
    local status i
    for i in $(seq 1 "${1:-300}"); do
        status=$(v-list-sys-task 10 json | jq -r .STATUS)
        if [ "$status" != running ]; then
            echo "$status"
            return
        fi
        sleep 2
    done
    echo timeout
}

# A hand-written job record of a system task (ID KIND ARGS STATUS UNIT)
write_record() {
    local now
    now=$(date +'%F %T')
    mkdir -p "$JOB_DIR"
    echo "ID='$1' TYPE='sys.$2' USER='system' OWNER='system' ACTOR='cli' OBJECT='bats' ARGS='v-run-sys-task $2 $3' STATUS='$4' EXIT='' ERROR='' UNIT='$5' PID='' QUEUED='$now' START='$now' END='' HEARTBEAT='' ATTEMPTS='1' RETRY_OF='' RESOURCE='' UPDATED='$now'" > "$JOB_DIR/$1.conf"
    : > "$JOB_DIR/$1.log"
}

remove_record() {
    rm -f "$JOB_DIR/$1".*
}

setup_file() {
    # no task left from an earlier run
    [ "$(wait_task 600)" != timeout ]
    if [ -f "/etc/init.d/php$PHP_TEST-fpm" ]; then
        echo "PHP $PHP_TEST is installed on this server; the PHP test needs it absent" >&3
    fi
}

@test "System task: kinds and arguments are whitelisted" {
    run v-start-sys-task
    assert_failure 1
    run v-start-sys-task reboot
    assert_failure 2
    run v-start-sys-task php - -
    assert_failure 1
    run v-start-sys-task php 9.9 -
    assert_failure 2
    run v-start-sys-task php '8.3;id' -
    assert_failure 2
    run v-start-sys-task php - 5.6
    assert_failure 3
    run v-start-sys-task plugin nope on
    assert_failure 2
    run v-start-sys-task plugin quota maybe
    assert_failure 2
    run v-start-sys-task arcpanel-update bash
    assert_failure 2
}

@test "System task: an installed version is not installed again, the default version is not removed" {
    default=$(php -r 'echo substr(phpversion(), 0, 3);')
    run v-start-sys-task php "$default" -
    assert_failure 4
    run v-start-sys-task php - "$default"
    assert_failure 10
    assert_output --partial "default version"
}

@test "System task: PHP versions list their state" {
    default=$(php -r 'echo substr(phpversion(), 0, 3);')
    run v-list-sys-php-versions json
    assert_success
    run jq -r --arg v "$default" '.[$v].INSTALLED + " " + .[$v].DEFAULT' <<< "$output"
    assert_output "yes yes"
    run bash -c "v-list-sys-php-versions json | jq -r 'keys | length'"
    assert_output --regexp '^[0-9]+$'
}

@test "System task: a plugin task runs in a transient unit and records the result" {
    # FIREWALL_SYSTEM is nftables on the test server: v-add-sys-firewall is a no-op
    grep -q "^FIREWALL_SYSTEM='nftables'" "$ARCPANEL/conf/arcpanel.conf" || skip "firewall not enabled"
    run v-start-sys-task plugin firewall on
    assert_success
    assert_output --regexp '^[0-9]{14}$'
    id=$output
    run grep -o "UNIT='arcpanel-job-$id'" "$JOB_DIR/$id.conf"
    assert_success
    run wait_task
    assert_output done
    run v-list-sys-task 50 json
    assert_success
    run jq -r '.ID + " " + .KIND + " " + .ARGS + " " + .EXIT' <<< "$output"
    assert_output "$id plugin firewall on 0"
    run bash -c "v-list-sys-task 50 json | jq -r .LOG"
    assert_output --partial '$ v-add-sys-firewall'
    run stat -c '%U %a' "$JOB_DIR/$id.conf"
    assert_output "root 640"
}

@test "System task: the runner refuses tasks it did not start and fails an unknown kind" {
    write_record 11111111111111 bogus '' running ''
    run v-run-sys-task plugin firewall on
    assert_failure 10
    JOB_ID=22222222222222 run v-run-sys-task bogus
    assert_failure 10
    JOB_ID=11111111111111 run v-run-sys-task plugin firewall on
    assert_failure 10
    JOB_ID=11111111111111 run v-run-sys-task bogus
    assert_failure 2
    assert_output --partial "Unknown task kind"
    remove_record 11111111111111
}

@test "System task: a running task whose runner is gone is reaped as failed" {
    write_record 33333333333333 plugin 'firewall on' running arcpanel-job-gone
    run bash -c "v-list-sys-task 10 json | jq -r .STATUS"
    assert_output failed
    run grep -c "STATUS='failed'" "$JOB_DIR/33333333333333.conf"
    assert_output 1
    remove_record 33333333333333
}

@test "System task: a second task is refused while one runs" {
    write_record 44444444444444 plugin 'firewall on' running ''
    # a live runner: this shell, renamed by its command line
    bash -c 'exec -a v-run-job sleep 30' &
    pid=$!
    sed -i "s/PID=''/PID='$pid'/" "$JOB_DIR/44444444444444.conf"
    run v-start-sys-task plugin firewall on
    assert_failure 7
    assert_output --partial "Another system task is running"
    kill "$pid"
    wait "$pid" 2> /dev/null || true
    run wait_task 5
    assert_output failed
    remove_record 44444444444444
}

@test "System task: PHP is installed through a panel restart and removed again" {
    [ ! -f "/etc/init.d/php$PHP_TEST-fpm" ] || skip "PHP $PHP_TEST is installed"
    run v-start-sys-task php "$PHP_TEST" -
    assert_success
    id=$output
    # the panel restarts (as a arcpanel package update does) while the task runs
    sleep 5
    systemctl restart arcpanel
    run systemctl is-active "arcpanel-job-$id.service"
    [ "$output" = active ] || [ "$(v-list-sys-task 10 json | jq -r .STATUS)" = done ]
    run wait_task 600
    assert_output done
    run v-list-sys-php-versions json
    run jq -r --arg v "$PHP_TEST" '.[$v].INSTALLED' <<< "$output"
    assert_output yes

    run v-start-sys-task php - "$PHP_TEST"
    assert_success
    run wait_task 600
    assert_output done
    assert_file_not_exists "/etc/init.d/php$PHP_TEST-fpm"
}
