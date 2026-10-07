#!/usr/bin/env bats
# REST API v2 (E34,
# docs/features/specs/2026-10-07-002-e34-api-v2-design.md): the access key
# check of the panel app and the v2-only permission sets.
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
USER_A=arcsmokeapi
PASS="Api-$(date +%s)-Pass"
ROOT_USER=$(grep "^ROOT_USER=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
STATE=/tmp/arcpanel-api-v2-bats.env

# New key of USER with PERMISSIONS: prints "ID SECRET"
new_key() {
    v-add-access-key "$1" "$2" bats json | jq -r '"\(.ACCESS_KEY_ID) \(.SECRET_ACCESS_KEY)"'
}

# The secret in a 0600 file under /tmp, as the panel passes it
secret_file() {
    local file
    file=$(mktemp /tmp/arcbats.XXXXXX)
    chmod 600 "$file"
    echo "$1" > "$file"
    echo "$file"
}

cleanup() {
    v-delete-user "$USER_A" > /dev/null 2>&1 || true
    if [ -f "$STATE" ]; then
        # shellcheck disable=SC1090
        source "$STATE"
        for key in "$ADMIN_KEY" "$ADMIN_READ_KEY" "$READ_KEY" "$MIXED_KEY"; do
            [ -n "$key" ] && v-delete-access-key "$key" > /dev/null 2>&1 || true
        done
        rm -f "$STATE"
    fi
    rm -f /tmp/arcbats.*
}

setup_file() {
    cleanup
    v-add-sys-arcpanel > /dev/null
    v-add-user "$USER_A" "$PASS" "$USER_A@example.invalid" default > /dev/null
    read -r admin_id admin_secret <<< "$(new_key "$ROOT_USER" '')"
    read -r admin_read_id admin_read_secret <<< "$(new_key "$ROOT_USER" 'v2-read')"
    read -r read_id read_secret <<< "$(new_key "$USER_A" 'v2-read')"
    read -r mixed_id mixed_secret <<< "$(new_key "$USER_A" 'v2-write,purge-nginx-cache')"
    cat > "$STATE" << EOF
ADMIN_KEY=$admin_id
ADMIN_SECRET=$admin_secret
ADMIN_READ_KEY=$admin_read_id
ADMIN_READ_SECRET=$admin_read_secret
READ_KEY=$read_id
READ_SECRET=$read_secret
MIXED_KEY=$mixed_id
MIXED_SECRET=$mixed_secret
EOF
    chmod 600 "$STATE"
}

teardown_file() {
    cleanup
}

setup() {
    # shellcheck disable=SC1090
    source "$STATE"
}

@test "v2 sets: installed by v-add-sys-arcpanel, marked for API v2" {
    for set in v2-read v2-write; do
        assert_file_exist "$ARCPANEL/data/api/$set"
        run grep -c "^API_VERSION='2'$" "$ARCPANEL/data/api/$set"
        assert_output "1"
        run grep -c "^ROLE='user'$" "$ARCPANEL/data/api/$set"
        assert_output "1"
    done
    run bash -c "v-list-apis json | jq -r '.\"v2-read\".API_VERSION, .billing.API_VERSION'"
    assert_success
    assert_output $'2\n1'
}

@test "v2 sets: every command of the sets exists" {
    for set in v2-read v2-write; do
        commands=$(grep "^COMMANDS=" "$ARCPANEL/data/api/$set" | cut -d"'" -f2 | tr ',' ' ')
        for command in $commands; do
            assert_file_exist "$ARCPANEL/bin/$command"
        done
    done
}

@test "v2 sets: v2-read is part of v2-write" {
    read_list=$(grep "^COMMANDS=" "$ARCPANEL/data/api/v2-read" | cut -d"'" -f2 | tr ',' '\n')
    write_list=$(grep "^COMMANDS=" "$ARCPANEL/data/api/v2-write" | cut -d"'" -f2 | tr ',' '\n')
    for command in $read_list; do
        run grep -qx "$command" <<< "$write_list"
        assert_success
    done
}

@test "get_apis_commands: skips API_VERSION 2 sets unless asked for v2" {
    run bash -c "source $ARCPANEL/func/main.sh; get_apis_commands 'v2-read'"
    assert_success
    assert_output ""
    run bash -c "source $ARCPANEL/func/main.sh; get_apis_commands 'v2-read' v2"
    assert_output --partial "v-list-sys-config"
    run bash -c "source $ARCPANEL/func/main.sh; get_apis_commands 'v2-write,purge-nginx-cache'"
    assert_output "v-purge-nginx-cache,v-list-web-domains,v-list-web-domain"
}

@test "check: the administrator's key without sets may run every command" {
    file=$(secret_file "$ADMIN_SECRET")
    run v-check-access-key-permissions "$ADMIN_KEY" "$file" 127.0.0.1 json
    assert_success
    run jq -r '.USER, .ROLE, (.PERMISSIONS | length), (.COMMANDS | join(","))' <<< "$output"
    assert_output "$ROOT_USER"$'\n'"admin"$'\n0\n*'
}

@test "check: a key with sets gets their commands, v2 sets included" {
    file=$(secret_file "$MIXED_SECRET")
    run v-check-access-key-permissions "$MIXED_KEY" "$file" 127.0.0.1 json
    assert_success
    run jq -r '.USER, .ROLE, (.PERMISSIONS | join(",")), (.COMMANDS | join(","))' <<< "$output"
    assert_line --index 0 "$USER_A"
    assert_line --index 1 "user"
    assert_line --index 2 "v2-write,purge-nginx-cache"
    assert_line --index 3 --partial "v-list-sys-config"
    assert_line --index 3 --partial "v-purge-nginx-cache"
}

@test "check: an administrator's key with sets is limited to them" {
    file=$(secret_file "$ADMIN_READ_SECRET")
    run v-check-access-key-permissions "$ADMIN_READ_KEY" "$file" 127.0.0.1 json
    assert_success
    run jq -r '.COMMANDS | index("*")' <<< "$output"
    assert_output "null"
}

@test "check: a wrong secret fails with E_PASSWORD and is logged" {
    file=$(secret_file "$(printf 'x%.0s' {1..40})")
    run v-check-access-key-permissions "$READ_KEY" "$file" 198.51.100.7 json
    assert_failure 9
    run tail -n1 "$ARCPANEL/log/auth.log"
    assert_output --partial "$READ_KEY 198.51.100.7 failed to login"
}

@test "check: an unknown key fails with E_PASSWORD" {
    file=$(secret_file "$READ_SECRET")
    run v-check-access-key-permissions "AAAAAAAAAAAAAAAAAAAA" "$file" 127.0.0.1 json
    assert_failure 9
}

@test "check: the secret is read from a file under /tmp only" {
    run v-check-access-key-permissions "$READ_KEY" "$READ_SECRET" 127.0.0.1 json
    assert_failure 9
    run v-check-access-key-permissions "$READ_KEY" "/tmp/../etc/passwd" 127.0.0.1 json
    assert_failure 9
}

@test "check: the key of a suspended owner fails with E_SUSPENDED" {
    v-suspend-user "$USER_A" no > /dev/null
    file=$(secret_file "$READ_SECRET")
    run v-check-access-key-permissions "$READ_KEY" "$file" 127.0.0.1 json
    v-unsuspend-user "$USER_A" no > /dev/null
    assert_failure 5
}

@test "API v1: refuses the commands of a v2 set" {
    run v-check-access-key "$READ_KEY" "$READ_SECRET" v-list-sys-config 127.0.0.1 json
    assert_failure 10
    assert_output --partial "don't have permission to run the command v-list-sys-config"
    run v-check-access-key "$MIXED_KEY" "$MIXED_SECRET" v-list-sys-config 127.0.0.1 json
    assert_failure 10
}

@test "API v1: still runs the commands of the other sets of the key" {
    run v-check-access-key "$MIXED_KEY" "$MIXED_SECRET" v-list-web-domains 127.0.0.1 json
    assert_success
    run jq -r '.USER' <<< "$output"
    assert_output "$USER_A"
}
