#!/usr/bin/env bats
# Statistics of a reseller's tree (E7 batch 2,
# docs/features/specs/2026-10-06-002-e7-batch2-pages-design.md §9.4).
# Run as root on a test server.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
RESELLER=tsres
PASS="Ts-$(date +%s)-Pass"

cleanup() {
    for user in tsc1 tsc2 tsother "$RESELLER"; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    v-delete-user-package ts-plan > /dev/null 2>&1 || true
    rm -f "$ARCPANEL/data/packages/ts-plan.tenancy"
}

# stats.log line of one month
stats_line() {
    local date=$1 time=$2 quota=$3 disk=$4 web=$5 bandwidth=$6
    echo "DATE='$date' TIME='$time' PACKAGE='default' IP_OWNED='0' DISK_QUOTA='$quota' U_DISK='$disk' U_DISK_DIRS='$disk' U_DISK_WEB='0' U_DISK_MAIL='0' U_DISK_DB='0' BANDWIDTH='1000' U_BANDWIDTH='$bandwidth' U_WEB_DOMAINS='$web' U_WEB_SSL='0' U_WEB_ALIASES='0' U_DNS_DOMAINS='0' U_DNS_RECORDS='0' U_MAIL_DOMAINS='0' U_MAIL_DKIM='0' U_MAIL_ACCOUNTS='0' U_DATABASES='0' U_CRON_JOBS='0' U_BACKUPS='1'"
}

setup_file() {
    cleanup
    # a reseller plan that may hold customers
    v-copy-user-package default ts-plan > /dev/null
    v-change-package-tenancy ts-plan USERS 5
    v-add-user "$RESELLER" "$PASS" "$RESELLER@example.invalid" ts-plan > /dev/null
    for user in tsc1 tsc2 tsother; do
        v-add-user "$user" "$PASS" "$user@example.invalid" default > /dev/null
    done
    v-change-user-role "$RESELLER" reseller > /dev/null
    v-change-user-owner tsc1 "$RESELLER" > /dev/null
    v-change-user-owner tsc2 "$RESELLER" > /dev/null
    {
        stats_line 2026-09-01 01:00:00 2000 100 1 10
        stats_line 2026-10-01 01:00:00 2000 150 2 20
    } > "$ARCPANEL/data/users/$RESELLER/stats.log"
    {
        stats_line 2026-09-01 01:05:00 500 7 1 3
        stats_line 2026-10-01 01:05:00 unlimited 8 1 4
    } > "$ARCPANEL/data/users/tsc1/stats.log"
    stats_line 2026-10-01 01:10:00 300 20 3 5 > "$ARCPANEL/data/users/tsc2/stats.log"
    # not in the tree
    stats_line 2026-10-01 01:15:00 999 999 99 99 > "$ARCPANEL/data/users/tsother/stats.log"
}

teardown_file() {
    cleanup
}

@test "v-list-reseller-users-stats sums the months of the tree" {
    run v-list-reseller-users-stats "$RESELLER" json
    assert_success
    run jq -c '."2026-10-01" | {U_DISK, U_WEB_DOMAINS, U_BANDWIDTH, DISK_QUOTA, BANDWIDTH, U_USERS, TIME, U_BACKUPS}' <<< "$(v-list-reseller-users-stats "$RESELLER" json)"
    assert_output '{"U_DISK":"178","U_WEB_DOMAINS":"6","U_BANDWIDTH":"29","DISK_QUOTA":"unlimited","BANDWIDTH":"3000","U_USERS":"3","TIME":"01:10:00","U_BACKUPS":"3"}'
    run jq -c '."2026-09-01" | {U_DISK, U_USERS, DISK_QUOTA}' <<< "$(v-list-reseller-users-stats "$RESELLER" json)"
    assert_output '{"U_DISK":"107","U_USERS":"2","DISK_QUOTA":"2500"}'
    run jq -r 'keys | join(",")' <<< "$(v-list-reseller-users-stats "$RESELLER" json)"
    assert_output '2026-09-01,2026-10-01'
}

@test "v-list-reseller-users-stats prints shell, plain and csv" {
    run v-list-reseller-users-stats "$RESELLER"
    assert_success
    assert_output --partial 'DATE'
    assert_output --regexp '2026-10-01 +3 +6'
    run v-list-reseller-users-stats "$RESELLER" csv
    assert_line --index 0 --partial 'DATE,TIME,PACKAGE,IP_OWNED,DISK_QUOTA,U_DISK'
}

@test "v-list-reseller-users-stats refuses users that are no reseller" {
    run v-list-reseller-users-stats tsc1 json
    assert_failure
    run v-list-reseller-users-stats ghostuser json
    assert_failure
}

@test "v-list-reseller-users-stats answers an empty object without statistics" {
    rm -f "$ARCPANEL"/data/users/{"$RESELLER",tsc1,tsc2}/stats.log
    run v-list-reseller-users-stats "$RESELLER" json
    assert_success
    assert_output '{}'
}
