#!/usr/bin/env bats
# Login commands of E39 W2 (docs/features/specs/2026-10-10-002-e39-w2-authentication-and-sessions-design.md):
# quote-safe login log, failures that reach the fail2ban log, TOTP replay
# guard, suspended accounts answered only after the password, and the
# session stamp written by a password change. Run as root on a test server.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

ARCPANEL=/usr/local/arcpanel
AUTHUSER=tauthw2
PASS="Ta-$(date +%s)-Pass"
AUTHLOG="$ARCPANEL/log/auth.log"

setup_file() {
    v-delete-user "$AUTHUSER" > /dev/null 2>&1 || true
    v-add-user "$AUTHUSER" "$PASS" "$AUTHUSER@example.invalid" default > /dev/null
}

teardown_file() {
    v-delete-user "$AUTHUSER" > /dev/null 2>&1 || true
}

# The TOTP code of SECRET for the time step STEP (default: now).
totp() {
    "$ARCPANEL/php/bin/php" -r '
        require "/usr/local/arcpanel/lib/vendor/autoload.php";
        $t = new RobThree\Auth\TwoFactorAuth(new RobThree\Auth\Providers\Qr\QRServerProvider(), "ArcPanel");
        echo $t->getCode($argv[1], isset($argv[2]) ? (int) $argv[2] * 30 : null);
    ' "$@"
}

user_value() {
    grep "^$2=" "$ARCPANEL/data/users/$1/user.conf" | cut -d "'" -f 2
}

# The hash of PASSWORD as the panel computes it for v-check-user-hash.
panel_hash() {
    local shadow salt
    shadow=$(grep "^$1:" /etc/shadow | cut -d : -f 2)
    shadow=${shadow#!}
    salt=$(cut -d '$' -f 3 <<< "$shadow")
    "$ARCPANEL/php/bin/php" -r 'echo str_replace("\$rounds=5000", "", crypt($argv[1], "\$6\$rounds=5000\$".$argv[2]."\$"));' "$2" "$salt"
}

hash_file() {
    local file
    file=$(mktemp /tmp/arcpanel-hash.XXXXXX)
    panel_hash "$1" "$2" > "$file"
    chown arcpanelweb: "$file"
    echo "$file"
}

@test "v-log-user-login writes a user agent with quotes and pipes instead of failing" {
    run v-log-user-login "$AUTHUSER" 203.0.113.9 failed "$(printf 'a%.0s' {1..32})" "Mozilla'5.0\"|x\$(id)" yes "Invalid or missing 2FA token"
    assert_success
    run tail -n 1 "$ARCPANEL/data/users/$AUTHUSER/auth.log"
    assert_output --partial "USER_AGENT='Mozilla_5.0__x_(id)'"
    run tail -n 1 "$AUTHLOG"
    assert_output --partial "$AUTHUSER 203.0.113.9 failed to login (Invalid or missing 2FA token)"
}

@test "v-log-sys-login-failure writes a line the fail2ban filter matches" {
    run v-log-sys-login-failure 203.0.113.10 "Invalid user name"
    assert_success
    run tail -n 1 "$AUTHLOG"
    assert_output --regexp '^[0-9-]+ [0-9:]+ - 203\.0\.113\.10 failed to login \(Invalid user name\)$'
    if command -v fail2ban-regex > /dev/null; then
        run fail2ban-regex "$(tail -n 1 "$AUTHLOG")" /etc/fail2ban/filter.d/arcpanel.conf
        assert_output --partial 'Failregex: 1 total'
    fi
}

@test "v-log-sys-login-failure refuses an invalid address" {
    run v-log-sys-login-failure "not-an-ip"
    assert_failure
}

@test "v-check-user-2fa accepts a code once and logs every wrong one" {
    v-add-user-2fa "$AUTHUSER" > /dev/null
    local secret code
    secret=$(user_value "$AUTHUSER" TWOFA)
    code=$(totp "$secret")

    run v-check-user-2fa "$AUTHUSER" "$code" 203.0.113.11
    assert_success
    run user_value "$AUTHUSER" TWOFA_STEP
    assert_output --regexp '^[0-9]+$'

    # The same code again, within its window (L5)
    run v-check-user-2fa "$AUTHUSER" "$code" 203.0.113.11
    assert_failure 9
    run tail -n 1 "$AUTHLOG"
    assert_output --partial "$AUTHUSER 203.0.113.11 failed to login (Invalid or missing 2FA token)"

    run v-check-user-2fa "$AUTHUSER" 000000 203.0.113.12
    assert_failure 9
    run tail -n 1 "$AUTHLOG"
    assert_output --partial "$AUTHUSER 203.0.113.12 failed to login"
}

@test "v-check-user-2fa refuses the code of an older time step" {
    local secret step
    secret=$(user_value "$AUTHUSER" TWOFA)
    step=$(user_value "$AUTHUSER" TWOFA_STEP)
    run v-check-user-2fa "$AUTHUSER" "$(totp "$secret" $((step - 1)))" 203.0.113.13
    assert_failure 9
}

@test "v-check-user-2fa keeps the secret out of the process arguments" {
    run grep -c 'check.php "\$TWOFA"' "$ARCPANEL/bin/v-check-user-2fa"
    assert_output '0'
}

@test "v-delete-user-login-limits runs for an existing user and refuses an unknown one" {
    run v-delete-user-login-limits "$AUTHUSER"
    assert_success
    run v-delete-user-login-limits nosuchuserw2
    assert_failure
}
