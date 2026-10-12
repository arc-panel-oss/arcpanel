#!/usr/bin/env bats
# PHP Manager: php.ini per version and SAPI (E41 M3,
# docs/features/specs/2026-10-12-001-e41-php-manager-design.md §6).
# Run as root on a test server with PHP 8.4 and 8.5 installed. The suite
# changes the php.ini files of PHP 8.4 and restores them at the end.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
V=8.4
OTHER=8.5
FPM_INI=/etc/php/$V/fpm/php.ini
CLI_INI=/etc/php/$V/cli/php.ini
SAVE=/root/arcbats-php-ini
BACKUPS=$ARCPANEL/data/php/ini-backups/$V
export ARC_PHP_HEALTH_DELAY=1

setup_file() {
    mkdir -p "$SAVE"
    cp -p "$FPM_INI" "$SAVE/fpm.ini"
    cp -p "$CLI_INI" "$SAVE/cli.ini"
    cp -p "/etc/php/$OTHER/fpm/php.ini" "$SAVE/other-fpm.ini"
    cp -p "/etc/php/$OTHER/cli/php.ini" "$SAVE/other-cli.ini"
    rm -rf "$BACKUPS"
}

teardown_file() {
    cp -p "$SAVE/fpm.ini" "$FPM_INI"
    cp -p "$SAVE/cli.ini" "$CLI_INI"
    rm -rf "$SAVE" "$BACKUPS"
    systemctl restart php$V-fpm
}

setup() {
    CAND="$BATS_TEST_TMPDIR/php.ini"
    cp "$SAVE/fpm.ini" "$CAND"
}

# The findings of v-check-sys-php-ini for the candidate, one per line:
# "LINE LEVEL DIRECTIVE"
findings() {
    v-check-sys-php-ini $V "${1:-fpm}" "$CAND" json | jq -r '.[] | "\(.line) \(.level) \(.directive)"'
}

# Line number of the last line of the candidate
last_line() {
    wc -l < "$CAND"
}

value() {
    v-list-sys-php-ini $V "${2:-fpm}" json | jq -r --arg d "$1" '.VALUES[$d].VALUE'
}

#----------------------------------------------------------#
#                        Reading                           #
#----------------------------------------------------------#

@test "v-list-sys-php-ini: form values with their source" {
    run v-list-sys-php-ini $V fpm json
    assert_success
    run bash -c "v-list-sys-php-ini $V fpm json | jq -r '.FILE, .VALUES.upload_max_filesize.SOURCE, .VALUES.max_input_vars.VALUE'"
    assert_line --index 0 "$FPM_INI"
    assert_line --index 1 'file'
    assert_line --index 2 '4000'
    # not set in Debian's php.ini: PHP's default
    run bash -c "v-list-sys-php-ini $V fpm json | jq -r '.VALUES[\"cgi.fix_pathinfo\"] | .SOURCE, .VALUE'"
    assert_output "$(printf 'default\n1')"
    run bash -c "v-list-sys-php-ini $V cli json | jq -r '.VALUES.max_execution_time | .VALUE'"
    assert_output '30'
    run v-list-sys-php-ini $V fpm raw
    assert_output "$(cat "$FPM_INI")"
    run v-list-sys-php-ini $V apache2
    assert_failure 2
    run v-list-sys-php-ini 7.4 fpm
    assert_failure 3
}

#----------------------------------------------------------#
#                       Validation                         #
#----------------------------------------------------------#

@test "v-check-sys-php-ini: the installed file has no errors" {
    run bash -c "v-check-sys-php-ini $V fpm $FPM_INI json | jq '[.[] | select(.level == \"error\")] | length'"
    assert_output 0
}

@test "v-check-sys-php-ini: a syntax error is reported with its line" {
    printf 'memory_limit = 128M\n[broken\n' >> "$CAND"
    n=$(last_line)
    run findings
    assert_success
    assert_output "$n error "
    run v-change-sys-php-ini $V fpm "$CAND"
    assert_failure 2
    assert_output --partial "line $n: error"
    run cmp "$FPM_INI" "$SAVE/fpm.ini"
    assert_success
}

@test "v-check-sys-php-ini: values of known directives are checked" {
    {
        echo 'memory_limit = lots'
        echo 'upload_max_filesize = -1'
        echo 'max_execution_time = abc'
        echo 'display_errors = maybe'
        echo 'date.timezone = Mars/Base'
        echo 'disable_functions = exec system'
        echo 'error_reporting = E_ALL & ~E_FOO'
        echo 'max_input_vars = 0'
    } >> "$CAND"
    n=$(last_line)
    run findings
    assert_line "$((n - 7)) error memory_limit"
    assert_line "$((n - 6)) error upload_max_filesize"
    assert_line "$((n - 5)) error max_execution_time"
    assert_line "$((n - 4)) error display_errors"
    assert_line "$((n - 3)) error date.timezone"
    assert_line "$((n - 2)) error disable_functions"
    assert_line "$((n - 1)) error error_reporting"
    assert_line "$n error max_input_vars"
}

@test "v-check-sys-php-ini: upload limits, unknown directives and good values" {
    {
        echo 'upload_max_filesize = 64M'
        echo 'post_max_size = 32M'
    } >> "$CAND"
    n=$(last_line)
    run findings
    assert_line "$n error post_max_size"

    cp "$SAVE/fpm.ini" "$CAND"
    {
        echo 'memory_limit = 16M'
        echo 'upload_max_filesize = 32M'
        echo 'post_max_size = 64M'
        echo 'arcbats.nosuch = 1'
        echo 'date.timezone = Europe/Berlin'
        echo 'disable_functions = exec,system, passthru,'
        echo 'error_reporting = E_ALL & ~E_DEPRECATED'
        echo 'memory_limit = -1'
    } >> "$CAND"
    n=$(last_line)
    run findings
    refute_output --partial 'error'
    assert_line "$((n - 4)) warning arcbats.nosuch"

    cp "$SAVE/fpm.ini" "$CAND"
    printf 'memory_limit = 16M\npost_max_size = 64M\n' >> "$CAND"
    n=$(last_line)
    run findings
    assert_line "$((n - 1)) warning memory_limit"
}

@test "v-check-sys-php-ini: extensions that do not load or lie outside the extension folder" {
    echo 'extension=arcbats_nosuch' >> "$CAND"
    run findings
    assert_output --partial '0 error'
    run bash -c "v-check-sys-php-ini $V fpm $CAND json | jq -r '.[0].message'"
    assert_output --partial 'arcbats_nosuch'

    cp "$SAVE/fpm.ini" "$CAND"
    echo 'extension=/tmp/arcbats.so' >> "$CAND"
    n=$(last_line)
    run findings
    assert_line --partial "$n error extension"

    cp "$SAVE/fpm.ini" "$CAND"
    echo 'extension_dir = /tmp' >> "$CAND"
    n=$(last_line)
    run findings
    assert_line "$n error extension_dir"
}

@test "v-check-sys-php-ini: large files and NUL bytes are refused" {
    head -c 1100000 /dev/zero | tr '\0' ';' > "$CAND"
    run v-check-sys-php-ini $V fpm "$CAND"
    assert_failure 2
    printf 'memory_limit = 1\0M\n' > "$CAND"
    run v-check-sys-php-ini $V fpm "$CAND"
    assert_failure 2
    run v-check-sys-php-ini $V fpm /nonexistent
    assert_failure 3
}

#----------------------------------------------------------#
#                        Saving                            #
#----------------------------------------------------------#

@test "v-change-sys-php-ini-values: changes and adds directives in place" {
    comments=$(grep -c '^;' "$FPM_INI")
    printf 'memory_limit=192M\ncgi.fix_pathinfo=0\n' > "$BATS_TEST_TMPDIR/values"
    run v-change-sys-php-ini-values $V fpm "$BATS_TEST_TMPDIR/values"
    assert_success
    run value memory_limit
    assert_output '192M'
    run value cgi.fix_pathinfo
    assert_output '0'
    run grep -c '^;' "$FPM_INI"
    assert_output "$comments"
    run grep -c '^memory_limit = 192M$' "$FPM_INI"
    assert_output 1
    # added at the end of the [PHP] section, before the next one
    php_end=$(grep -n '^\[' "$FPM_INI" | sed -n 2p | cut -d: -f1)
    added=$(grep -n '^cgi.fix_pathinfo = 0$' "$FPM_INI" | cut -d: -f1)
    [ "$added" -lt "$php_end" ]
    run ls "$BACKUPS/fpm"
    assert_output --regexp '^[0-9]{8}-[0-9]{6}\.ini$'
    run systemctl is-active php$V-fpm
    assert_output 'active'
    # the other version and the CLI file are untouched
    run cmp "/etc/php/$OTHER/fpm/php.ini" "$SAVE/other-fpm.ini"
    assert_success
    run cmp "$CLI_INI" "$SAVE/cli.ini"
    assert_success
}

@test "v-change-sys-php-ini-values: refuses bad lines and invalid values" {
    before=$(md5sum < "$FPM_INI")
    for line in 'memory_limit' 'arcbats.nosuch=1' 'memory_limit=1"M' 'memory_limit=${HOME}' 'post_max_size=1M'; do
        printf '%s\n' "$line" > "$BATS_TEST_TMPDIR/values"
        run v-change-sys-php-ini-values $V fpm "$BATS_TEST_TMPDIR/values"
        assert_failure 2
    done
    [ "$(md5sum < "$FPM_INI")" = "$before" ]
}

@test "v-change-sys-php-ini: the CLI file is changed without touching PHP-FPM" {
    sed -i 's/^max_input_vars.*//' "$CAND"
    cp "$SAVE/cli.ini" "$CAND"
    echo 'max_input_vars = 3333' >> "$CAND"
    run v-change-sys-php-ini $V cli "$CAND"
    assert_success
    run value max_input_vars cli
    assert_output '3333'
    run cmp "$FPM_INI" "$SAVE/fpm.ini"
    assert_failure
    run ls "$BACKUPS/cli"
    assert_success
}

@test "v-change-sys-php-ini: a file that stops PHP-FPM is rolled back" {
    before=$(md5sum < "$FPM_INI")
    echo 'max_input_vars = 2222' >> "$CAND"
    ARC_PHP_TEST_INACTIVE=$V run v-change-sys-php-ini $V fpm "$CAND"
    assert_failure 20
    assert_output --partial 'previous file was restored'
    [ "$(md5sum < "$FPM_INI")" = "$before" ]
    run systemctl is-active php$V-fpm
    assert_output 'active'
}

@test "php.ini backups: ten are kept and one is restored" {
    for i in $(seq 1 11); do
        printf 'max_input_vars=%s\n' "$((5000 + i))" > "$BATS_TEST_TMPDIR/values"
        run v-change-sys-php-ini-values $V fpm "$BATS_TEST_TMPDIR/values"
        assert_success
    done
    run bash -c "v-list-sys-php-ini-backups $V fpm json | jq length"
    assert_output 10
    oldest=$(v-list-sys-php-ini-backups $V fpm json | jq -r 'keys | sort | first')
    content=$(cat "$BACKUPS/fpm/$oldest.ini")
    run v-rollback-sys-php-ini $V fpm "$oldest"
    assert_success
    [ "$(cat "$FPM_INI")" = "$content" ]
    run bash -c "v-list-sys-php-ini-backups $V fpm json | jq length"
    assert_output 10
    run v-rollback-sys-php-ini $V fpm 20200101-000000
    assert_failure 3
    run v-rollback-sys-php-ini $V fpm '../x'
    assert_failure 2
}

#----------------------------------------------------------#
#                 Service config editor                    #
#----------------------------------------------------------#

@test "v-change-sys-service-config: php.ini files are no longer written there" {
    run v-change-sys-service-config "$CAND" php no
    assert_failure 10
    assert_output --partial 'v-change-sys-php-ini'
    run cmp "/etc/php/$OTHER/cli/php.ini" "$SAVE/other-cli.ini"
    assert_success
    default=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    run bash -c "v-list-sys-php-config json | jq -r .CONFIG.config_path"
    assert_output "/etc/php/$default/fpm/php.ini"
}
