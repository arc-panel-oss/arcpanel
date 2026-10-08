#!/usr/bin/env bats
# Lengths of the action log and the login history (E34 batch 6,
# docs/features/specs/2026-10-08-004-e34-api-v2-batch6-design.md D60).
# Run as root on a test server.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
LOGUSER=tlogs
PASS="Tl-$(date +%s)-Pass"

setup_file() {
    v-delete-user "$LOGUSER" > /dev/null 2>&1 || true
    v-add-user "$LOGUSER" "$PASS" "$LOGUSER@example.invalid" default > /dev/null
    local data="$ARCPANEL/data/users/$LOGUSER" i
    for i in $(seq 1 1200); do
        echo "ID='$i' DATE='2026-10-08' TIME='10:00:00' LEVEL='Info' CATEGORY='System' MESSAGE='Entry $i.'"
    done > "$data/history.log"
    for i in $(seq 1 1200); do
        echo "DATE='2026-10-08' TIME='10:00:00' IP='203.0.113.$((i % 250))' ACTION='login' STATUS='success' USER_AGENT='bats' SESSION='s$i' ACTIVE='no'"
    done > "$data/auth.log"
}

teardown_file() {
    v-delete-user "$LOGUSER" > /dev/null 2>&1 || true
}

@test "v-list-user-log keeps its default of 300 lines" {
    run jq -r 'keys | length' <<< "$(v-list-user-log "$LOGUSER" json)"
    assert_output '300'
}

@test "v-list-user-log reads the last LINES lines" {
    run jq -r '[keys[] | tonumber] | sort | "\(length) \(first) \(last)"' <<< "$(v-list-user-log "$LOGUSER" json 1000)"
    assert_output '1000 201 1200'
    run jq -r '.["1200"].MESSAGE' <<< "$(v-list-user-log "$LOGUSER" json 5)"
    assert_output 'Entry 1200.'
    run jq -r 'keys | length' <<< "$(v-list-user-log "$LOGUSER" json 5)"
    assert_output '5'
}

@test "v-list-user-log refuses a LINES outside 1 to 10000" {
    for lines in 0 10001 abc -5; do
        run v-list-user-log "$LOGUSER" json "$lines"
        assert_failure 2
    done
}

@test "v-list-user-auth-log keeps its default of 10 lines" {
    run jq -r 'keys | length' <<< "$(v-list-user-auth-log "$LOGUSER" json)"
    assert_output '10'
}

@test "v-list-user-auth-log reads the last LINES lines" {
    run jq -r '[.[]] | "\(length) \(last.SESSION)"' <<< "$(v-list-user-auth-log "$LOGUSER" json 1000)"
    assert_output '1000 s1200'
    run jq -r '[.[]] | first.SESSION' <<< "$(v-list-user-auth-log "$LOGUSER" json 1000)"
    assert_output 's201'
}

@test "v-list-user-auth-log refuses a LINES outside 1 to 10000" {
    for lines in 0 10001 x; do
        run v-list-user-auth-log "$LOGUSER" json "$lines"
        assert_failure 2
    done
}

@test "v-log-user-login records IPv6 addresses (sign-ins over ::1 or IPv6 were lost)" {
    run v-log-user-login "$LOGUSER" '2001:db8::7' success 0123abcd 'bats ipv6'
    assert_success
    run jq -r '[.[]] | last | "\(.IP) \(.STATUS) \(.SESSION)"' <<< "$(v-list-user-auth-log "$LOGUSER" json)"
    assert_output '2001:db8::7 success 0123abcd'
    run v-log-user-login "$LOGUSER" '::1' success 0123abce 'bats ipv6'
    assert_success
    run v-log-user-login "$LOGUSER" 'not-an-ip' success 0123abcf 'bats'
    assert_failure 2
}
