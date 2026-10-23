#!/usr/bin/env bats
# E23: the system-wide audit log
# docs/features/specs/2026-10-23-002-e23-audit-log-design.md §4, §6 and §10.
# Run as root on a test server. The suite moves data/audit/ aside, works on
# an empty log and puts the saved log back at the end.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
AUDIT=$ARCPANEL/data/audit
SAVED=$ARCPANEL/data/audit.arcbats-saved
CONF=$ARCPANEL/conf/arcpanel.conf
RES_A=arcbatsauda
RES_B=arcbatsaudb
CUS_A=arcbatsauda1
CUS_B=arcbatsaudb1
AUD_PACKAGE=arcbats-audit

today() {
    date -u +%F
}

days_ago() {
    date -u -d "$1 days ago" +%F
}

ulid() {
    tr -dc '0-9A-HJKMNP-TV-Z' < /dev/urandom | head -c 26
}

# event ACTION [ACTOR] [EFFECTIVE] [OBJECT_USER] [RESULT] [CHANNEL]: a
# temporary event file
event() {
    local file
    file=$(mktemp)
    cat > "$file" << EOF
{"v":1,"id":"$(ulid)","request_id":"req-$RANDOM","channel":"${6:-web}","actor":{"user":"${2:-admin}","role":"admin","ip":"203.0.113.7","key":null,"ua":"0123456789abcdef"},"effective_user":"${3:-${2:-admin}}","action":"$1","object":{"type":"web_domain","user":"${4:-${3:-${2:-admin}}}","name":"shop.example.com"},"result":"${5:-ok}","status":302,"fields":["version","profile"]}
EOF
    echo "$file"
}

add() {
    v-add-sys-audit-event "$(event "$@")"
}

lines() {
    wc -l < "$AUDIT/$(today).jsonl"
}

anchor_value() {
    grep -oE "$2='[^']*'" "$AUDIT/$1.anchor" | cut -d\' -f2
}

line_value() {
    sed -n "${2}p" "$AUDIT/$1.jsonl" | jq -r "$3"
}

# Move today's file and anchor to DAY, as if they had been written then
move_today_to() {
    mv "$AUDIT/$(today).jsonl" "$AUDIT/$1.jsonl"
    mv "$AUDIT/$(today).anchor" "$AUDIT/$1.anchor"
}

setup_file() {
    local pass tmp
    if [ -d "$AUDIT" ]; then
        rm -rf "$SAVED"
        mv "$AUDIT" "$SAVED"
    fi
    cp "$CONF" "$BATS_FILE_TMPDIR/arcpanel.conf"
    for u in "$CUS_A" "$CUS_B" "$RES_A" "$RES_B"; do
        v-delete-user "$u" > /dev/null 2>&1 || true
    done
    tmp=$(mktemp)
    cp "$ARCPANEL/data/packages/default.pkg" "$tmp"
    v-add-user-package "$tmp" "$AUD_PACKAGE" > /dev/null 2>&1 || true
    rm -f "$tmp"
    v-change-package-tenancy "$AUD_PACKAGE" USERS 5 > /dev/null
    pass="Aud-$(date +%s)-Pass"
    for u in "$RES_A" "$RES_B" "$CUS_A" "$CUS_B"; do
        v-add-user "$u" "$pass" "$u@example.invalid" "$AUD_PACKAGE" > /dev/null
    done
    v-change-user-role "$RES_A" reseller
    v-change-user-role "$RES_B" reseller
    v-change-user-owner "$CUS_A" "$RES_A"
    v-change-user-owner "$CUS_B" "$RES_B"
}

teardown_file() {
    for u in "$CUS_A" "$CUS_B" "$RES_A" "$RES_B"; do
        v-delete-user "$u" > /dev/null 2>&1 || true
    done
    v-delete-user-package "$AUD_PACKAGE" > /dev/null 2>&1 || true
    rm -f "$ARCPANEL/data/packages/$AUD_PACKAGE.tenancy"
    cp "$BATS_FILE_TMPDIR/arcpanel.conf" "$CONF"
    rm -rf "$AUDIT"
    if [ -d "$SAVED" ]; then
        mv "$SAVED" "$AUDIT"
    fi
}

setup() {
    rm -rf "$AUDIT"
    sed -i '/^AUDIT_RETENTION_DAYS=/d; /^AUDIT_SYSLOG=/d' "$CONF"
}

@test "add: appends one chained line and writes the anchor" {
    run add web.domains.update.php admin alice
    assert_success
    assert_dir_exists "$AUDIT"
    [ "$(stat -c '%U %a' "$AUDIT")" = "root 700" ]
    [ "$(stat -c '%a' "$AUDIT/$(today).jsonl")" = "600" ]
    [ "$(lines)" -eq 1 ]
    [ "$(line_value "$(today)" 1 .prev)" = "$(printf '0%.0s' {1..64})" ]
    [ "$(line_value "$(today)" 1 .action)" = "web.domains.update.php" ]
    [ "$(line_value "$(today)" 1 .effective_user)" = "alice" ]
    [[ "$(line_value "$(today)" 1 .ts)" =~ ^$(today)T[0-9:]{8}\.[0-9]{3}Z$ ]]
    # Keys in the order of §3
    [ "$(sed -n 1p "$AUDIT/$(today).jsonl" | jq -c 'keys_unsorted')" = '["v","id","ts","request_id","channel","actor","effective_user","action","object","result","status","fields","prev","hash"]' ]
    run add users.store admin
    assert_success
    [ "$(lines)" -eq 2 ]
    [ "$(line_value "$(today)" 2 .prev)" = "$(line_value "$(today)" 1 .hash)" ]
    [ "$(anchor_value "$(today)" COUNT)" = "2" ]
    [ "$(anchor_value "$(today)" LAST_HASH)" = "$(line_value "$(today)" 2 .hash)" ]
    [ "$(anchor_value "$(today)" SEALED)" = "no" ]
}

@test "add: the hash covers the event without prev and hash" {
    add users.store admin
    local line body prev hash
    line=$(sed -n 1p "$AUDIT/$(today).jsonl")
    prev=$(jq -r .prev <<< "$line")
    hash=$(jq -r .hash <<< "$line")
    body="${line%,\"prev\":*}}"
    [ "$(printf '%s\n%s' "$prev" "$body" | sha256sum | cut -d' ' -f1)" = "$hash" ]
}

@test "add: removes the event file, refuses invalid events" {
    local file
    file=$(event users.store admin)
    run v-add-sys-audit-event "$file"
    assert_success
    assert_file_not_exists "$file"

    file=$(mktemp)
    echo 'not json' > "$file"
    run v-add-sys-audit-event "$file"
    assert_failure 2
    assert_file_not_exists "$file"

    file=$(event users.store admin admin admin ok ftp)
    run v-add-sys-audit-event "$file"
    assert_failure 2
    assert_output --partial 'invalid channel'

    file=$(event 'Bad Action' admin)
    run v-add-sys-audit-event "$file"
    assert_failure 2

    file=$(event users.store admin admin admin maybe)
    run v-add-sys-audit-event "$file"
    assert_failure 2

    run v-add-sys-audit-event /nonexistent/file
    assert_failure 3
    [ "$(lines)" -eq 1 ]
}

@test "add: never reads or removes a link or a file of another account" {
    local target link
    target=$(mktemp)
    echo keep > "$target"
    link="$BATS_TEST_TMPDIR/link"
    ln -s "$target" "$link"
    run v-add-sys-audit-event "$link"
    assert_failure 3
    assert_file_exists "$target"
    chown nobody "$target"
    run env SUDO_USER=arcpanelweb v-add-sys-audit-event "$target"
    assert_failure 10
    assert_file_exists "$target"
    rm -f "$target"
}

@test "add: a long field list is cut to 8 KB and ends with an ellipsis" {
    local file fields
    fields=$(for i in $(seq 1 600); do printf '"field_number_%03d",' "$i"; done)
    file=$(mktemp)
    printf '{"v":1,"id":"%s","request_id":null,"channel":"api_v2","actor":{"user":"admin","role":"admin","ip":null,"key":"AAAAAAAAAAAAAAAAAAAA","ua":null},"effective_user":"admin","action":"api.v2.users.update","object":null,"result":"ok","status":200,"fields":[%s]}\n' "$(ulid)" "${fields%,}" > "$file"
    run v-add-sys-audit-event "$file"
    assert_success
    [ "$(sed -n 1p "$AUDIT/$(today).jsonl" | wc -c)" -le 8193 ]
    [ "$(line_value "$(today)" 1 '.fields[-1]')" = "…" ]
    run v-check-sys-audit
    assert_success
}

@test "anchors: the first event of a day continues the chain of the day before" {
    add users.store admin
    add users.store admin
    move_today_to "$(days_ago 1)"
    add users.destroy admin
    [ "$(line_value "$(today)" 1 .prev)" = "$(anchor_value "$(days_ago 1)" LAST_HASH)" ]
    [ "$(anchor_value "$(today)" COUNT)" = "1" ]
    run v-check-sys-audit
    assert_success
    assert_output --partial 'OK 3 events'
}

@test "check: finds a changed line" {
    for i in 1 2 3; do add users.store admin; done
    sed -i '2s/users.store/users.destroy/' "$AUDIT/$(today).jsonl"
    run v-check-sys-audit
    assert_failure 2
    assert_output --partial "BROKEN $(today) line 2"
    assert_output --partial 'changed'
    run v-check-sys-audit '' '' json
    assert_failure 2
    [ "$(jq -r .OK <<< "$output")" = "false" ]
    [ "$(jq -r .LINE <<< "$output")" = "2" ]
}

@test "check: finds a removed line" {
    for i in 1 2 3; do add users.store admin; done
    sed -i '2d' "$AUDIT/$(today).jsonl"
    run v-check-sys-audit
    assert_failure 2
    assert_output --partial "BROKEN $(today) line 2"
}

@test "check: finds a removed last line through the anchor" {
    for i in 1 2 3; do add users.store admin; done
    sed -i '3d' "$AUDIT/$(today).jsonl"
    run v-check-sys-audit
    assert_failure 2
    assert_output --partial 'anchor'
}

@test "check: finds an inserted line" {
    for i in 1 2 3; do add users.store admin; done
    sed -i '2p' "$AUDIT/$(today).jsonl"
    run v-check-sys-audit
    assert_failure 2
    assert_output --partial "BROKEN $(today) line 3"
}

@test "check: finds a removed day within the retention" {
    add users.store admin
    move_today_to "$(days_ago 2)"
    add users.store admin
    move_today_to "$(days_ago 1)"
    add users.store admin
    rm "$AUDIT/$(days_ago 2).jsonl"
    run v-check-sys-audit "$(days_ago 3)"
    assert_failure 2
    assert_output --partial "BROKEN $(days_ago 2)"
}

@test "update: seals, gzips after 7 days, purges after the retention and keeps anchors" {
    v-change-sys-config-value AUDIT_RETENTION_DAYS 30
    add users.store admin
    move_today_to "$(days_ago 40)"
    add users.store "$RES_A"
    move_today_to "$(days_ago 10)"
    add users.store admin
    move_today_to "$(days_ago 1)"
    add users.store admin
    run v-update-sys-audit
    assert_success
    assert_file_not_exists "$AUDIT/$(days_ago 40).jsonl"
    assert_file_exists "$AUDIT/$(days_ago 40).anchor"
    assert_file_not_exists "$AUDIT/$(days_ago 10).jsonl"
    assert_file_exists "$AUDIT/$(days_ago 10).jsonl.gz"
    assert_file_exists "$AUDIT/$(days_ago 1).jsonl"
    [ "$(anchor_value "$(days_ago 1)" SEALED)" = "yes" ]
    [ "$(anchor_value "$(days_ago 10)" SEALED)" = "yes" ]
    [ "$(anchor_value "$(today)" SEALED)" = "no" ]
    # The purge is recorded with the number of files removed
    [ "$(tail -n1 "$AUDIT/$(today).jsonl" | jq -r '.action + " " + .object.name + " " + .channel')" = "audit.purge 1 system" ]
    run v-check-sys-audit
    assert_success
    assert_output --partial 'OK 4 events'
    run v-check-sys-audit "$(days_ago 60)"
    assert_success
    # gzipped days are listed
    run v-list-sys-audit "$(days_ago 12)" "$(days_ago 8)" "ACTOR=$RES_A" json
    assert_success
    [ "$(jq -r .TOTAL <<< "$output")" = "1" ]
}

@test "update: start records audit.start with the version" {
    run v-update-sys-audit start 2.6.0
    assert_success
    [ "$(tail -n1 "$AUDIT/$(today).jsonl" | jq -r '.action + " " + .object.name')" = "audit.start 2.6.0" ]
    run v-update-sys-audit weekly
    assert_failure 2
}

@test "list: newest first, filters, limit, offset and formats" {
    add users.store admin
    add web.domains.update.php admin alice alice denied
    add api.v2.users.update bob bob bob ok api_v2
    run v-list-sys-audit "$(today)" "$(today)" '' json
    assert_success
    [ "$(jq -r .TOTAL <<< "$output")" = "3" ]
    [ "$(jq -r '.EVENTS[0].action' <<< "$output")" = "api.v2.users.update" ]
    run v-list-sys-audit "$(today)" "$(today)" 'USER=alice' json
    [ "$(jq -r '.TOTAL' <<< "$output")" = "1" ]
    run v-list-sys-audit "$(today)" "$(today)" 'ACTOR=admin' json
    [ "$(jq -r '.TOTAL' <<< "$output")" = "2" ]
    run v-list-sys-audit "$(today)" "$(today)" 'ACTION=web.domains' json
    [ "$(jq -r '.EVENTS[0].action' <<< "$output")" = "web.domains.update.php" ]
    run v-list-sys-audit "$(today)" "$(today)" 'RESULT=denied' json
    [ "$(jq -r '.TOTAL' <<< "$output")" = "1" ]
    run v-list-sys-audit "$(today)" "$(today)" 'CHANNEL=api_v2' json
    [ "$(jq -r '.EVENTS[0].actor.user' <<< "$output")" = "bob" ]
    run v-list-sys-audit "$(today)" "$(today)" '' json 1 1
    [ "$(jq -r '.TOTAL' <<< "$output")" = "3" ]
    [ "$(jq -r '.EVENTS | length' <<< "$output")" = "1" ]
    [ "$(jq -r '.EVENTS[0].action' <<< "$output")" = "web.domains.update.php" ]
    run v-list-sys-audit "$(today)" "$(today)" '' csv
    assert_success
    assert_line --index 0 --partial 'TIME,ID,CHANNEL,ACTOR'
    [ "${#lines[@]}" -eq 4 ]
    run v-list-sys-audit "$(today)" "$(today)"
    assert_success
    assert_output --partial 'web.domains.update.php'
    run v-list-sys-audit "$(today)" "$(today)" '' plain
    [ "${#lines[@]}" -eq 3 ]
}

@test "list: refuses bad days, filters, formats and limits" {
    run v-list-sys-audit 2026-13-01 "$(today)"
    assert_failure 2
    run v-list-sys-audit "$(today)" "$(today)" 'COLOR=red'
    assert_failure 2
    run v-list-sys-audit "$(today)" "$(today)" 'RESULT=maybe'
    assert_failure 2
    run v-list-sys-audit "$(today)" "$(today)" '' xml
    assert_failure 2
    run v-list-sys-audit "$(today)" "$(today)" '' json 100001
    assert_failure 2
    run v-list-sys-audit "$(today)"
    assert_failure 1
}

@test "list: OWNER keeps the events of a reseller's tree" {
    add users.update admin "$CUS_A"
    add users.update "$RES_A" "$RES_A" "$CUS_A"
    add users.update admin "$CUS_B"
    add users.update "$RES_B"
    add users.update admin admin admin
    run v-list-sys-audit "$(today)" "$(today)" "OWNER=$RES_A" json
    assert_success
    [ "$(jq -r .TOTAL <<< "$output")" = "2" ]
    [ "$(jq -r "[.EVENTS[] | select(.effective_user == \"$CUS_B\" or .actor.user == \"$RES_B\")] | length" <<< "$output")" = "0" ]
    run v-list-sys-audit "$(today)" "$(today)" "OWNER=$RES_B" json
    [ "$(jq -r .TOTAL <<< "$output")" = "2" ]
    run v-list-sys-audit "$(today)" "$(today)" "OWNER=$RES_B USER=$CUS_A" json
    [ "$(jq -r .TOTAL <<< "$output")" = "0" ]
    run v-list-sys-audit "$(today)" "$(today)" 'OWNER=arcbatsnosuchuser' json
    assert_failure 3
}

@test "config: the two keys are validated and printed in json and shell" {
    run v-list-sys-config json
    [ "$(jq -r .config.AUDIT_RETENTION_DAYS <<< "$output")" = "180" ]
    [ "$(jq -r .config.AUDIT_SYSLOG <<< "$output")" = "no" ]
    run v-change-sys-config-value AUDIT_RETENTION_DAYS 6
    assert_failure 2
    run v-change-sys-config-value AUDIT_RETENTION_DAYS 3651
    assert_failure 2
    run v-change-sys-config-value AUDIT_SYSLOG maybe
    assert_failure 2
    v-change-sys-config-value AUDIT_RETENTION_DAYS 365
    v-change-sys-config-value AUDIT_SYSLOG yes
    run v-list-sys-config json
    [ "$(jq -r .config.AUDIT_RETENTION_DAYS <<< "$output")" = "365" ]
    [ "$(jq -r .config.AUDIT_SYSLOG <<< "$output")" = "yes" ]
    run v-list-sys-config
    assert_output --partial 'Audit log:                        365 days, syslog yes'
}

@test "syslog: AUDIT_SYSLOG=yes sends each line with the tag arcpanel-audit" {
    if ! command -v journalctl > /dev/null; then
        skip 'no journald'
    fi
    local file id
    v-change-sys-config-value AUDIT_SYSLOG yes
    file=$(event users.store admin)
    id=$(jq -r .id "$file")
    run v-add-sys-audit-event "$file"
    assert_success
    for i in $(seq 1 20); do
        journalctl -t arcpanel-audit --since '-5min' --no-pager -o cat | grep -q "$id" && break
        sleep 0.5
    done
    run bash -c "journalctl -t arcpanel-audit --since '-5min' --no-pager -o cat | grep -c '$id'"
    assert_output 1
}

@test "parallel writers keep one valid chain" {
    for i in $(seq 1 30); do
        add users.store admin &
    done
    wait
    [ "$(lines)" -eq 30 ]
    [ "$(anchor_value "$(today)" COUNT)" = "30" ]
    run v-check-sys-audit
    assert_success
    assert_output --partial 'OK 30 events'
}

@test "cron: the installers and the 2.6.0 step add the daily line" {
    run grep -c 'v-update-sys-audit' "$BATS_TEST_DIRNAME/../install/arcpanel-install-ubuntu.sh"
    assert_output 2
    run grep -c 'v-update-sys-audit' "$BATS_TEST_DIRNAME/../install/arcpanel-install-debian.sh"
    assert_output 2
    run grep -c '20 00 \* \* \* sudo /usr/local/arcpanel/bin/v-update-sys-audit' "$BATS_TEST_DIRNAME/../install/upgrade/versions/2.6.0.sh"
    assert_output 1
}
