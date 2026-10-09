#!/usr/bin/env bats
# ArcPanel secret store (E38,
# docs/features/specs/2026-10-09-001-e38-secret-store-design.md).
# Run as root on a server installed with the ArcPanel installer. The values
# used here are synthetic; the tests read the store only to prove that no
# value is kept in plain text.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
STORE=$ARCPANEL/data/secrets
CONF=$ARCPANEL/conf/arcpanel.conf
PASS="Tse-$(date +%s)-Pass"
USERS="tse1 tse2 tse3 tsetmp tsebak tsebak2"
U1=tse1
U2=tse2
U3=tse3
WORK=/root/arcpanel-secret-store-test

cleanup() {
    for user in $USERS; do
        v-delete-user "$user" > /dev/null 2>&1 || true
        rm -rf "${STORE:?}/$user"
    done
    sed -i "/^SECRETS_LIMIT=/d" "$CONF"
}

setup_file() {
    cleanup
    rm -rf "$WORK"
    mkdir -p "$WORK"
    chmod 700 "$WORK"
    v-add-sys-secret-key > /dev/null
    for user in $U1 $U2; do
        v-add-user "$user" "$PASS" "$user@example.invalid" default > /dev/null
    done
}

teardown_file() {
    cleanup
    rm -rf "$WORK"
}

# Write VALUE (printf %s, no newline) to a value file and print its path
value_file() {
    local file
    file=$(mktemp "$WORK/value.XXXXXX")
    printf '%s' "$1" > "$file"
    echo "$file"
}

# Succeeds when TEXT is nowhere in the store in plain text
not_in_store() {
    ! grep -rqF -- "$1" "$STORE"
}

#----------------------------------------------------------#
#                         Setup                            #
#----------------------------------------------------------#

@test "Setup: the master key is root only" {
    assert_file_exists "$STORE/.master/identity"
    assert_file_exists "$STORE/.master/recipient"
    run stat -c '%a %U' "$STORE" "$STORE/.master" "$STORE/.master/identity"
    assert_line --index 0 '700 root'
    assert_line --index 1 '700 root'
    assert_line --index 2 '400 root'
}

@test "Setup: v-add-sys-secret-key keeps an existing key" {
    before=$(sha256sum "$STORE/.master/identity")
    run v-add-sys-secret-key
    assert_success
    assert_equal "$(sha256sum "$STORE/.master/identity")" "$before"
}

@test "Setup: a new user gets a user key" {
    assert_file_exists "$STORE/$U1/key.age"
    assert_file_exists "$STORE/$U1/recipient"
    assert_file_exists "$STORE/$U1/fingerprint.key"
    run stat -c '%a' "$STORE/$U1" "$STORE/$U1/key.age"
    assert_line --index 0 '700'
    assert_line --index 1 '600'
    run grep -c 'AGE-SECRET-KEY' "$STORE/$U1/key.age"
    assert_output '0'
}

#----------------------------------------------------------#
#                     Add and read                         #
#----------------------------------------------------------#

@test "Add: a secret is listed with version 1 and a fingerprint, never its value" {
    value="tse-db-$RANDOM-$RANDOM"
    run v-add-user-secret $U1 /shop/db_password "$(value_file "$value")" 'Shop database'
    assert_success
    run v-list-user-secret-store $U1 json
    assert_success
    refute_output --partial "$value"
    run jq -r '.["/shop/db_password"] | "\(.DESCRIPTION)|\(.VERSION)|\(.FINGERPRINT | test("^[0-9a-f]{8}$"))"' <<< "$output"
    assert_output 'Shop database|1|true'
    not_in_store "$value"
}

@test "Add: v-get-user-secret prints the value" {
    value="tse-get-$RANDOM-$RANDOM"
    v-add-user-secret $U1 /get/one "$(value_file "$value")"
    run v-get-user-secret $U1 /get/one
    assert_success
    assert_output "$value"
}

@test "Add: one trailing newline of the value file is dropped" {
    file=$(mktemp "$WORK/value.XXXXXX")
    printf 'tse-nl-value\n' > "$file"
    v-add-user-secret $U1 /get/newline "$file"
    assert_equal "$(v-get-user-secret $U1 /get/newline | wc -c)" '12'
}

@test "Add: a multi-line value is stored" {
    file=$(mktemp "$WORK/value.XXXXXX")
    printf 'line one\nline two' > "$file"
    run v-add-user-secret $U1 /get/pem "$file"
    assert_success
    run v-get-user-secret $U1 /get/pem
    assert_output $'line one\nline two'
}

@test "Add: the same name twice answers E_EXISTS" {
    v-add-user-secret $U1 /dup/one "$(value_file dup-value-1)"
    run v-add-user-secret $U1 /dup/one "$(value_file dup-value-2)"
    assert_failure 4
}

@test "Add: invalid names are refused" {
    for name in noslash /a/../b /a//b /a/. '/a b' "/a'b" /1/2/3/4/5/6 "/$(printf 'x%.0s' $(seq 1 65))" / '/a/'; do
        run v-add-user-secret $U1 "$name" "$(value_file name-test-value)"
        assert_failure 2
    done
}

@test "Add: names are case-sensitive" {
    v-add-user-secret $U1 /Case/Key "$(value_file upper-value)"
    run v-add-user-secret $U1 /case/key "$(value_file lower-value)"
    assert_success
    assert_equal "$(v-get-user-secret $U1 /Case/Key)" 'upper-value'
}

@test "Add: a missing value file answers E_NOTEXIST" {
    run v-add-user-secret $U1 /missing/file "$WORK/no-such-file"
    assert_failure 3
}

@test "Add: an empty, binary or oversized value is refused" {
    run v-add-user-secret $U1 /bad/empty "$(value_file '')"
    assert_failure 2
    file=$(mktemp "$WORK/value.XXXXXX")
    printf 'a\000b' > "$file"
    run v-add-user-secret $U1 /bad/binary "$file"
    assert_failure 2
    file=$(mktemp "$WORK/value.XXXXXX")
    head -c 32769 /dev/zero | tr '\0' 'x' > "$file"
    run v-add-user-secret $U1 /bad/large "$file"
    assert_failure 2
    file=$(mktemp "$WORK/value.XXXXXX")
    head -c 32768 /dev/zero | tr '\0' 'x' > "$file"
    run v-add-user-secret $U1 /ok/large "$file"
    assert_success
}

@test "Add: a description with a quote or a pipe is refused" {
    run v-add-user-secret $U1 /desc/quote "$(value_file desc-value)" "it's"
    assert_failure 2
    run v-add-user-secret $U1 /desc/pipe "$(value_file desc-value)" 'db|prod'
    assert_failure 2
}

@test "Add: SECRETS_LIMIT caps the number of secrets" {
    v-add-user $U3 "$PASS" $U3@example.invalid default > /dev/null
    v-change-sys-config-value SECRETS_LIMIT 2
    v-add-user-secret $U3 /lim/one "$(value_file limit-1)"
    v-add-user-secret $U3 /lim/two "$(value_file limit-2)"
    run v-add-user-secret $U3 /lim/three "$(value_file limit-3)"
    assert_failure 8
    sed -i "/^SECRETS_LIMIT=/d" "$CONF"
}

@test "Add: the value is never a command argument" {
    command -v strace > /dev/null || skip "strace is not installed"
    value="tse-argv-$RANDOM-$RANDOM"
    file=$(value_file "$value")
    strace -f -s 4096 -e trace=execve -o "$WORK/trace" v-add-user-secret $U1 /argv/check "$file"
    strace -f -s 4096 -e trace=execve -o "$WORK/trace2" v-get-user-secret $U1 /argv/check > /dev/null
    run grep -cF -- "$value" "$WORK/trace" "$WORK/trace2"
    assert_line --partial 'trace:0'
    assert_line --partial 'trace2:0'
}

#----------------------------------------------------------#
#                       Versions                           #
#----------------------------------------------------------#

@test "Versions: a change creates the next version and keeps five" {
    v-add-user-secret $U1 /ver/key "$(value_file ver-value-1)"
    for i in 2 3 4 5 6 7; do
        run v-change-user-secret $U1 /ver/key "$(value_file "ver-value-$i")"
        assert_success
    done
    assert_equal "$(v-get-user-secret $U1 /ver/key)" 'ver-value-7'
    run bash -c "v-list-user-secret $U1 /ver/key json | jq -r '.[\"/ver/key\"] | \"\(.VERSION) \([.VERSIONS[].VERSION] | join(\",\"))\"'"
    assert_output '7 3,4,5,6,7'
    assert_equal "$(v-get-user-secret $U1 /ver/key 4)" 'ver-value-4'
    run v-get-user-secret $U1 /ver/key 2
    assert_failure 3
    not_in_store 'ver-value-5'
}

@test "Versions: a rollback copies an old version into a new one" {
    v-add-user-secret $U1 /rb/key "$(value_file rb-value-1)"
    v-change-user-secret $U1 /rb/key "$(value_file rb-value-2)"
    run v-rollback-user-secret $U1 /rb/key 1
    assert_success
    assert_equal "$(v-get-user-secret $U1 /rb/key)" 'rb-value-1'
    run bash -c "v-list-user-secret $U1 /rb/key json | jq -r '.[\"/rb/key\"].VERSION'"
    assert_output '3'
    run v-rollback-user-secret $U1 /rb/key 9
    assert_failure 3
}

@test "Versions: a description change keeps the version" {
    v-add-user-secret $U1 /desc/key "$(value_file desc-value-1)" 'First'
    run v-change-user-secret-description $U1 /desc/key 'Second text'
    assert_success
    run bash -c "v-list-user-secret $U1 /desc/key json | jq -r '.[\"/desc/key\"] | \"\(.DESCRIPTION)|\(.VERSION)\"'"
    assert_output 'Second text|1'
}

@test "Versions: equal values have equal fingerprints for one user only" {
    v-add-user-secret $U1 /fp/a "$(value_file same-value)"
    v-add-user-secret $U1 /fp/b "$(value_file same-value)"
    v-add-user-secret $U1 /fp/c "$(value_file other-value)"
    v-add-user-secret $U2 /fp/a "$(value_file same-value)"
    list1=$(v-list-user-secret-store $U1 json)
    a=$(jq -r '.["/fp/a"].FINGERPRINT' <<< "$list1")
    b=$(jq -r '.["/fp/b"].FINGERPRINT' <<< "$list1")
    c=$(jq -r '.["/fp/c"].FINGERPRINT' <<< "$list1")
    d=$(v-list-user-secret-store $U2 json | jq -r '.["/fp/a"].FINGERPRINT')
    assert_equal "$a" "$b"
    assert [ "$a" != "$c" ]
    assert [ "$a" != "$d" ]
}

@test "Versions: USAGE=no lists the secrets without reading the apps" {
    v-add-user-secret $U2 /usage/key "$(value_file usage-value)"
    run bash -c "v-list-user-secret-store $U2 json no | jq -r '.[\"/usage/key\"] | \"\(.VERSION)|\(.USED_BY)\"'"
    assert_output '1|'
    run bash -c "v-list-user-secret-store $U2 json | jq -r '.[\"/usage/key\"].USED_BY'"
    assert_output '0'
}

@test "Versions: list formats plain, csv and shell work" {
    v-add-user-secret $U2 /fmt/key "$(value_file fmt-value)" 'Formats'
    run v-list-user-secret-store $U2 plain
    assert_success
    assert_output --partial '/fmt/key'
    run v-list-user-secret-store $U2 csv
    assert_line --index 0 --partial 'NAME'
    run v-list-user-secret-store $U2 shell
    assert_success
    refute_output --partial 'fmt-value'
}

#----------------------------------------------------------#
#                        Delete                            #
#----------------------------------------------------------#

@test "Delete: a deleted secret is gone with its versions" {
    v-add-user-secret $U1 /del/key "$(value_file del-value-1)"
    v-change-user-secret $U1 /del/key "$(value_file del-value-2)"
    run v-delete-user-secret $U1 /del/key
    assert_success
    run v-get-user-secret $U1 /del/key
    assert_failure 3
    run v-delete-user-secret $U1 /del/key
    assert_failure 3
    run bash -c "v-list-user-secret-store $U1 json | jq 'has(\"/del/key\")'"
    assert_output 'false'
}

@test "Delete: another user cannot read or delete a secret" {
    v-add-user-secret $U1 /own/key "$(value_file own-value)"
    run v-get-user-secret $U2 /own/key
    assert_failure 3
    run v-delete-user-secret $U2 /own/key
    assert_failure 3
}

@test "Delete: an unknown user answers E_NOTEXIST" {
    run v-list-user-secret-store tsenouser json
    assert_failure 3
}

@test "Delete: deleting the user removes its secrets" {
    v-add-user tsetmp "$PASS" tsetmp@example.invalid default > /dev/null
    v-add-user-secret tsetmp /tmp/key "$(value_file tmp-value)"
    assert_dir_exists "$STORE/tsetmp"
    v-delete-user tsetmp
    assert_dir_not_exists "$STORE/tsetmp"
}

#----------------------------------------------------------#
#                     Keys and backups                     #
#----------------------------------------------------------#

@test "Keys: a wrong master key cannot decrypt" {
    v-add-user-secret $U2 /key/check "$(value_file key-check-value)"
    cp -a "$STORE/.master" "$WORK/master.saved"
    rm -rf "$STORE/.master"
    mkdir -m 700 "$STORE/.master"
    age-keygen -o "$STORE/.master/identity" 2> /dev/null
    run v-get-user-secret $U2 /key/check
    rm -rf "$STORE/.master"
    cp -a "$WORK/master.saved" "$STORE/.master"
    assert_failure
    refute_output --partial 'key-check-value'
    assert_equal "$(v-get-user-secret $U2 /key/check)" 'key-check-value'
}

@test "Backups: a user backup holds the encrypted store and restores it" {
    v-add-user tsebak "$PASS" tsebak@example.invalid default > /dev/null
    v-add-user-secret tsebak /bak/key "$(value_file bak-value-123)"
    v-backup-user tsebak > /dev/null
    archive=$(ls -t /backup/tsebak.*.tar | head -n1)
    run tar -tf "$archive" ./arcpanel/secret-store/key.age
    assert_success
    mkdir -p "$WORK/unpacked"
    tar -xf "$archive" -C "$WORK/unpacked" ./arcpanel
    run grep -rqF bak-value-123 "$WORK/unpacked"
    assert_failure
    v-delete-user tsebak
    run v-restore-user tsebak "$(basename "$archive")"
    assert_success
    assert_equal "$(v-get-user-secret tsebak /bak/key)" 'bak-value-123'
    v-delete-user tsebak
    rm -f "$archive"
}

@test "Backups: a restore with another master key skips the secrets" {
    v-add-user tsebak2 "$PASS" tsebak2@example.invalid default > /dev/null
    v-add-user-secret tsebak2 /bak/key "$(value_file bak-value-456)"
    v-backup-user tsebak2 > /dev/null
    archive=$(ls -t /backup/tsebak2.*.tar | head -n1)
    v-delete-user tsebak2
    cp -a "$STORE/.master" "$WORK/master.saved2"
    rm -rf "$STORE/.master"
    v-add-sys-secret-key > /dev/null
    run v-restore-user tsebak2 "$(basename "$archive")"
    rm -rf "$STORE/.master"
    cp -a "$WORK/master.saved2" "$STORE/.master"
    assert_success
    assert_output --partial 'secrets skipped'
    run v-get-user-secret tsebak2 /bak/key
    assert_failure 3
    v-delete-user tsebak2
    rm -f "$archive"
}
