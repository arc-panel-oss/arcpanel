#!/usr/bin/env bats
# The bash fast path of parse_object_kv_list (func/main.sh) loads the same
# variables as the PHP parser for the object lines the commands write, and
# leaves every other line to the PHP parser. Run as root on a test server.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

setup() {
    # its last line tests for local.conf and returns 1 when there is none
    source /etc/arcpanel/arcpanel.conf || true
    source "$ARCPANEL/func/main.sh"
}

# Variables a parser loads from LINE, as "KEY=value" lines
loaded() {
    (
        "$1" "$2" > /dev/null 2>&1 || exit $?
        for key in $(grep -oE '[A-Za-z_][A-Za-z0-9_]*=' <<< "$2" | tr -d = | sort -u); do
            [ -n "${!key+set}" ] && printf '%s=%s\n' "$key" "${!key}"
        done
    )
}

@test "simple lines load the same variables as the PHP parser" {
    local line
    for line in \
        "DOMAIN='example.com' IP='192.0.2.1' ALIAS='www.example.com mail.example.com' SSL='no' SUSPENDED=''" \
        "  A='x y'   B=''  " \
        "C='\$(id) \`id\` \ \\\" ; rm -rf /'" \
        "K='first' K='second'" \
        "LANGVAL='é ü 中'"; do
        run _parse_object_kv_list_simple "$line"
        assert_success
        assert_equal "$(loaded _parse_object_kv_list_simple "$line")" "$(loaded _parse_object_kv_list_php "$line")"
    done
}

@test "reserved keys are skipped and BACKUP stays allowed, as in the PHP parser" {
    local line="PATH='/tmp/evil' IFS='x' BASH_FUNC_x='y' BACKUP='u.2026.tar' KEEP='1'"
    assert_equal "$(loaded _parse_object_kv_list_simple "$line")" "$(loaded _parse_object_kv_list_php "$line")"
    run bash -c "export ARCPANEL=$ARCPANEL; source \$ARCPANEL/func/main.sh; _parse_object_kv_list_simple \"$line\"; echo \"\$PATH|\$BACKUP|\$KEEP\""
    refute_output --partial '/tmp/evil'
    assert_output --partial '|u.2026.tar|1'
}

@test "lines outside the simple form go to the PHP parser" {
    local line
    for line in "K=abc" "K='a'b" "K=a\ b" "1K='x'" "K='open" "__kv_rest='x'" "LC_ALL='C' N='1'" "K='a'	L='b'"$'\n'"M='c'"; do
        if [[ "$line" == "K='a'	L='b'"* ]]; then
            # tabs and line breaks are blanks: still the fast path
            run _parse_object_kv_list_simple "$line"
            assert_success
            continue
        fi
        run _parse_object_kv_list_simple "$line"
        assert_failure 1
    done
    # parse_object_kv_list still accepts them through PHP
    assert_equal "$(loaded parse_object_kv_list "K=a\ b")" "K=a b"
}
