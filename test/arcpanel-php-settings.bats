#!/usr/bin/env bats
# Per-domain PHP settings (E19 M1,
# docs/features/specs/2026-10-23-001-e19-per-domain-php-settings-design.md).
# Run as root on a test server with PHP 8.4 and 8.5 (the default) installed
# and a PHP-FPM backend.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
TPL_DIR=$ARCPANEL/data/templates/web/php-fpm
V=8.4
DEFAULT_V=8.5
PASS="Tt-e19-m1-Pass"
U1=e19su1
R1=e19sr1
P1=e19-plan
P2=e19-small
RP=e19-rplan
RC=e19-rcust
D1=e19s1.example
D2=e19s2.example
SAVE=/root/arcbats-php-settings

pool() {
    find /etc/php/*/fpm/pool.d -name "$1.conf" | head -n1
}

sidecar_line() {
    grep -F "DOMAIN='$1' " "$ARCPANEL/data/users/$U1/web.php.conf"
}

# The last pool line of a directive (php_admin_value or php_admin_flag)
pool_value() {
    grep -E "^php_admin_(value|flag)\[$2\] = " "$(pool "$1")" | tail -n1 | sed 's/.* = //'
}

settings() {
    v-list-web-domain-php-settings "$U1" "$1" json | jq -r "$2"
}

make_package() {
    local tmp
    tmp=$(mktemp)
    cp "$PKG_DIR/default.pkg" "$tmp"
    v-add-user-package "$tmp" "$1" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$1" > /dev/null
    rm -f "$tmp"
}

cleanup() {
    for user in "$U1" "$R1"; do
        v-delete-user "$user" yes > /dev/null 2>&1 || true
    done
    for package in "$P1" "$P2" "$RP" "$RC"; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
    rm -f "$TPL_DIR/arcbats-noprofile.tpl" "$TPL_DIR/arcbats-noprofile.sh"
}

setup_file() {
    cleanup
    mkdir -p "$SAVE"
    cp -p "$ARCPANEL/conf/arcpanel.conf" "$SAVE/"
    make_package "$P1"
    make_package "$P2"
    make_package "$RP"
    make_package "$RC"
    v-add-user "$U1" "$PASS" "$U1@example.invalid" "$P1" > /dev/null
    v-add-web-domain "$U1" "$D1" > /dev/null
    v-add-web-domain "$U1" "$D2" > /dev/null
    v-add-user "$R1" "$PASS" "$R1@example.invalid" "$RP" > /dev/null
    v-change-user-role "$R1" reseller > /dev/null
    echo "RESELLER_OWNER='$R1'" >> "$PKG_DIR/$RC.tenancy"
}

teardown_file() {
    cp -p "$SAVE/arcpanel.conf" "$ARCPANEL/conf/arcpanel.conf"
    cleanup
    rm -rf "$SAVE"
}

#----------------------------------------------------------#
#                     Set, list, reset                     #
#----------------------------------------------------------#

@test "v-change-web-domain-php-setting: sets, lists and resets one setting" {
    run v-change-web-domain-php-setting "$U1" "$D1" memory_limit 384M
    assert_success
    run sidecar_line "$D1"
    assert_output "DOMAIN='$D1' PROFILE='default' MEMORY_LIMIT='384M'"
    run pool_value "$D1" memory_limit
    assert_output '384M'
    run grep -c '^; E19 per-domain settings$' "$(pool "$D1")"
    assert_output 1
    run settings "$D1" '.SETTINGS.memory_limit | [.VALUE, .MAX, .EFFECTIVE, .SOURCE] | join(" ")'
    assert_output '384M 512M 384M domain'
    run settings "$D1" '[.SUPPORTED, .POOL] | join(" ")'
    assert_output 'yes domain'
    run bash -c "v-list-web-domain-php $U1 $D1 json | jq -r '.EFFECTIVE.memory_limit | [.VALUE, .SOURCE] | join(\" \")'"
    assert_output '384M domain'
    run systemctl is-active php$DEFAULT_V-fpm
    assert_output 'active'

    run v-delete-web-domain-php-setting "$U1" "$D1" memory_limit
    assert_success
    run sidecar_line "$D1"
    assert_failure
    run grep -c '^; E19 per-domain settings$' "$(pool "$D1")"
    assert_output 0
    run settings "$D1" '.SETTINGS.memory_limit | [.VALUE, .SOURCE] | join(" ")'
    assert_output ' php.ini'
}

@test "v-change-web-domain-php-setting: the seven settings and their pool lines" {
    v-change-web-domain-php-setting "$U1" "$D1" memory_limit 256M no
    v-change-web-domain-php-setting "$U1" "$D1" upload_max_filesize 64M no
    v-change-web-domain-php-setting "$U1" "$D1" post_max_size 80M no
    v-change-web-domain-php-setting "$U1" "$D1" max_execution_time 120 no
    v-change-web-domain-php-setting "$U1" "$D1" max_input_vars 3000 no
    v-change-web-domain-php-setting "$U1" "$D1" display_errors on no
    run v-change-web-domain-php-setting "$U1" "$D1" date.timezone Asia/Ho_Chi_Minh
    assert_success
    run grep -A7 '^; E19 per-domain settings$' "$(pool "$D1")"
    assert_output "$(printf '%s\n' '; E19 per-domain settings' \
        'php_admin_value[memory_limit] = 256M' 'php_admin_value[upload_max_filesize] = 64M' \
        'php_admin_value[post_max_size] = 80M' 'php_admin_value[max_execution_time] = 120' \
        'php_admin_value[max_input_vars] = 3000' 'php_admin_flag[display_errors] = on' \
        'php_admin_value[date.timezone] = Asia/Ho_Chi_Minh')"
    run systemctl is-active php$DEFAULT_V-fpm
    assert_output 'active'
    run settings "$D1" '[.SETTINGS[] | .SOURCE] | unique | join(" ")'
    assert_output 'domain'

    run v-delete-web-domain-php-setting "$U1" "$D1" all
    assert_success
    run sidecar_line "$D1"
    assert_failure
    run grep -c 'E19 per-domain settings' "$(pool "$D1")"
    assert_output 0
}

@test "v-change-web-domain-php-setting: invalid keys and values are refused" {
    run v-change-web-domain-php-setting "$U1" "$D1" opcache.enable 1
    assert_failure 2
    for value in -1 0 12X 1T "64M'" '1M;id' 1234567M; do
        run v-change-web-domain-php-setting "$U1" "$D1" memory_limit "$value"
        assert_failure 2
    done
    for value in 0 abc 1.5 -3; do
        run v-change-web-domain-php-setting "$U1" "$D1" max_input_vars "$value"
        assert_failure 2
    done
    run v-change-web-domain-php-setting "$U1" "$D1" max_execution_time 86401
    assert_failure 2
    run v-change-web-domain-php-setting "$U1" "$D1" display_errors stderr
    assert_failure 2
    for zone in Mars/Olympus ../../etc/passwd 'Asia/Tokyo;id' ''; do
        run v-change-web-domain-php-setting "$U1" "$D1" date.timezone "$zone"
        assert_failure
    done
    run v-change-web-domain-php-setting "$U1" nosuch.example memory_limit 64M
    assert_failure 3
    run v-change-web-domain-php-setting nosuchuser "$D1" memory_limit 64M
    assert_failure 3
    run v-delete-web-domain-php-setting "$U1" "$D1" opcache.enable
    assert_failure 2
    run sidecar_line "$D1"
    assert_failure
}

@test "v-change-web-domain-php-setting: post_max_size below upload_max_filesize is refused, an inherited one and memory below post warn" {
    # post_max_size still comes from the profile small (32M)
    v-change-package-tenancy "$P1" PHP_PROFILES '*'
    v-change-web-domain-php "$U1" "$D1" $DEFAULT_V small > /dev/null
    run v-change-web-domain-php-setting "$U1" "$D1" upload_max_filesize 64M
    assert_success
    assert_output --partial 'post_max_size 32M (profile) is below upload_max_filesize 64M'
    run v-change-web-domain-php-setting "$U1" "$D1" post_max_size 32M
    assert_failure 2
    assert_output --partial 'post_max_size'
    run v-change-web-domain-php-setting "$U1" "$D1" post_max_size 200M
    assert_success
    run v-change-web-domain-php-setting "$U1" "$D1" upload_max_filesize 256M
    assert_failure 2
    run v-change-web-domain-php-setting "$U1" "$D1" memory_limit 128M
    assert_success
    assert_output --partial 'memory_limit 128M is below post_max_size 200M'
    run settings "$D1" '.WARNINGS | length'
    assert_output 1
    v-delete-web-domain-php-setting "$U1" "$D1" all
    v-change-web-domain-php "$U1" "$D1" $DEFAULT_V default > /dev/null
}

#----------------------------------------------------------#
#                    Package maximums                      #
#----------------------------------------------------------#

@test "Packages: the default maximums refuse larger values" {
    run bash -c "v-list-package-tenancy $P1 json | jq -r '.[] | [.PHP_MAX_MEMORY, .PHP_MAX_UPLOAD, .PHP_MAX_EXECUTION_TIME, .PHP_MAX_INPUT_VARS] | join(\" \")'"
    assert_output '512M 256M 300 10000'
    run v-change-web-domain-php-setting "$U1" "$D1" memory_limit 600M
    assert_failure 8
    assert_output --partial '512M'
    run v-change-web-domain-php-setting "$U1" "$D1" upload_max_filesize 300M
    assert_failure 8
    run v-change-web-domain-php-setting "$U1" "$D1" post_max_size 257M
    assert_failure 8
    run v-change-web-domain-php-setting "$U1" "$D1" max_execution_time 301
    assert_failure 8
    run v-change-web-domain-php-setting "$U1" "$D1" max_input_vars 10001
    assert_failure 8
    run v-change-web-domain-php-setting "$U1" "$D1" memory_limit 512M
    assert_success
    v-delete-web-domain-php-setting "$U1" "$D1" all
}

@test "Packages: a lowered maximum clamps the pool and a raised one restores the value" {
    run v-change-package-tenancy "$P1" PHP_MAX_MEMORY 1G
    assert_success
    run v-change-web-domain-php-setting "$U1" "$D1" memory_limit 768M
    assert_success
    run v-change-package-tenancy "$P1" PHP_MAX_MEMORY 256M
    assert_success
    run pool_value "$D1" memory_limit
    assert_output '256M'
    run sidecar_line "$D1"
    assert_output --partial "MEMORY_LIMIT='768M'"
    run settings "$D1" '.SETTINGS.memory_limit | [.VALUE, .MAX, .EFFECTIVE, .SOURCE] | join(" ")'
    assert_output '768M 256M 256M capped'
    run v-change-package-tenancy "$P1" PHP_MAX_MEMORY unlimited
    assert_success
    run pool_value "$D1" memory_limit
    assert_output '768M'
    run systemctl is-active php$DEFAULT_V-fpm
    assert_output 'active'
}

@test "Packages: a new package of the user renders its pools again" {
    v-change-package-tenancy "$P2" PHP_MAX_MEMORY 128M
    run v-change-user-package "$U1" "$P2"
    assert_success
    run pool_value "$D1" memory_limit
    assert_output '128M'
    run v-change-user-package "$U1" "$P1"
    assert_success
    run pool_value "$D1" memory_limit
    assert_output '768M'
    v-delete-web-domain-php-setting "$U1" "$D1" all
    v-change-package-tenancy "$P1" PHP_MAX_MEMORY 512M
}

@test "Packages: invalid maximums and maximums above the reseller's are refused" {
    for value in 1T -1 12X 1234567M; do
        run v-change-package-tenancy "$P1" PHP_MAX_MEMORY "$value"
        assert_failure 2
    done
    for value in 0 86401 abc; do
        run v-change-package-tenancy "$P1" PHP_MAX_EXECUTION_TIME "$value"
        assert_failure 2
    done
    run v-change-package-tenancy "$P1" PHP_MAX_INPUT_VARS 1000001
    assert_failure 2
    run v-change-package-tenancy "$P1" PHP_MAX_UPLOAD 2G
    assert_success
    run v-change-package-tenancy "$P1" PHP_MAX_UPLOAD 256M
    assert_success

    run v-change-package-tenancy "$RP" PHP_MAX_MEMORY 256M
    assert_success
    run v-change-package-tenancy "$RC" PHP_MAX_MEMORY 512M
    assert_failure 8
    run v-change-package-tenancy "$RC" PHP_MAX_MEMORY unlimited
    assert_failure 8
    run v-change-package-tenancy "$RC" PHP_MAX_MEMORY 128M
    assert_success
    # without a key the reseller's package has the default maximum
    run v-change-package-tenancy "$RC" PHP_MAX_INPUT_VARS 20000
    assert_failure 8
}

#----------------------------------------------------------#
#                 Pools that change around                 #
#----------------------------------------------------------#

@test "Domains: settings stay through a rebuild, a version, a profile and a template change" {
    v-change-package-tenancy "$P1" PHP_PROFILES '*'
    v-change-package-tenancy "$P1" PHP_VERSIONS '*'
    run v-change-web-domain-php-setting "$U1" "$D1" memory_limit 300M
    assert_success
    run v-rebuild-web-domain "$U1" "$D1"
    assert_success
    run pool_value "$D1" memory_limit
    assert_output '300M'
    # the profile's own value comes first, the domain's wins
    run v-change-web-domain-php "$U1" "$D1" $V medium
    assert_success
    run grep -E '^php_admin_value\[memory_limit\] = ' "$(pool "$D1")"
    assert_output "$(printf '%s\n' 'php_admin_value[memory_limit] = 512M' 'php_admin_value[memory_limit] = 300M')"
    run settings "$D1" '.SETTINGS.memory_limit | [.PROFILE, .EFFECTIVE, .SOURCE] | join(" ")'
    assert_output '512M 300M domain'
    run v-change-web-domain-php "$U1" "$D1" $DEFAULT_V default
    assert_success
    run sidecar_line "$D1"
    assert_output "DOMAIN='$D1' PROFILE='default' MEMORY_LIMIT='300M'"
    run v-change-web-domain-backend-tpl "$U1" "$D1" "PHP-${DEFAULT_V/./_}"
    assert_success
    run pool_value "$D1" memory_limit
    assert_output '300M'
    run systemctl is-active php$V-fpm php$DEFAULT_V-fpm
    assert_output "$(printf 'active\nactive')"
    v-delete-web-domain-php-setting "$U1" "$D1" all
}

@test "v-change-web-domain-php-setting: a reload that fails restores the previous value" {
    run v-change-web-domain-php-setting "$U1" "$D1" max_input_vars 2000
    assert_success
    ARC_PHP_TEST_INACTIVE=$DEFAULT_V ARC_PHP_HEALTH_DELAY=1 run v-change-web-domain-php-setting "$U1" "$D1" max_input_vars 4000
    assert_failure 20
    run sidecar_line "$D1"
    assert_output --partial "MAX_INPUT_VARS='2000'"
    run pool_value "$D1" max_input_vars
    assert_output '2000'
    run systemctl is-active php$DEFAULT_V-fpm
    assert_output 'active'
    v-delete-web-domain-php-setting "$U1" "$D1" all
}

@test "Domains: a template without the profile line and no-php are refused" {
    grep -v '^%php_profile%$' "$TPL_DIR/default.tpl" > "$TPL_DIR/arcbats-noprofile.tpl"
    run v-change-web-domain-backend-tpl "$U1" "$D2" arcbats-noprofile
    assert_success
    run v-change-web-domain-php-setting "$U1" "$D2" memory_limit 64M
    assert_failure 11
    run settings "$D2" '.SUPPORTED'
    assert_output 'no'
    run v-change-web-domain-backend-tpl "$U1" "$D2" no-php
    assert_success
    run v-change-web-domain-php-setting "$U1" "$D2" memory_limit 64M
    assert_failure 11
    run v-change-web-domain-backend-tpl "$U1" "$D2" default
    assert_success
    rm -f "$TPL_DIR/arcbats-noprofile.tpl"
}

@test "Domains: settings follow a rename and go with the domain" {
    run v-change-web-domain-php-setting "$U1" "$D2" upload_max_filesize 32M
    assert_success
    run v-change-web-domain-name "$U1" "$D2" "renamed-$D2"
    assert_success
    run sidecar_line "renamed-$D2"
    assert_output "DOMAIN='renamed-$D2' PROFILE='default' UPLOAD_MAX_FILESIZE='32M'"
    run pool_value "renamed-$D2" upload_max_filesize
    assert_output '32M'
    run v-delete-web-domain "$U1" "renamed-$D2"
    assert_success
    run grep -c "renamed-$D2" "$ARCPANEL/data/users/$U1/web.php.conf"
    assert_output 0
    v-add-web-domain "$U1" "$D2" > /dev/null
}

@test "Domains: with one pool per user the settings are the user's" {
    rm -f /etc/php/*/fpm/pool.d/"$D1".conf /etc/php/*/fpm/pool.d/"$D2".conf
    v-change-sys-config-value WEB_BACKEND_POOL user
    v-rebuild-web-domains "$U1" yes > /dev/null
    run v-change-web-domain-php-setting "$U1" "$D1" memory_limit 200M
    assert_success
    run grep -F "DOMAIN='*' " "$ARCPANEL/data/users/$U1/web.php.conf"
    assert_output "DOMAIN='*' PROFILE='default' MEMORY_LIMIT='200M'"
    run pool_value "$U1" memory_limit
    assert_output '200M'
    run settings "$D2" '[.POOL, .SETTINGS.memory_limit.VALUE] | join(" ")'
    assert_output 'user 200M'
    v-delete-web-domain-php-setting "$U1" "$D1" all
    cp -p "$SAVE/arcpanel.conf" "$ARCPANEL/conf/arcpanel.conf"
    rm -f /etc/php/*/fpm/pool.d/"$U1".conf
    v-rebuild-web-domains "$U1" yes > /dev/null
    run pool "$D1"
    assert_output --partial "$D1.conf"
}

@test "Restores: a backup carries the settings, kept and clamped under a smaller maximum" {
    v-change-web-domain-php-setting "$U1" "$D2" memory_limit 300M no
    run v-change-web-domain-php-setting "$U1" "$D2" date.timezone Europe/Berlin
    assert_success
    run v-backup-user "$U1"
    assert_success
    backup=$(ls -t /backup/$U1.*.tar | head -n1)
    v-delete-web-domain "$U1" "$D2" > /dev/null
    v-change-package-tenancy "$P1" PHP_MAX_MEMORY 256M
    run v-restore-user "$U1" "$(basename "$backup")" "$D2" no no no no no no
    rm -f "$backup"
    assert_success
    run sidecar_line "$D2"
    assert_output "DOMAIN='$D2' PROFILE='default' MEMORY_LIMIT='300M' DATE_TIMEZONE='Europe/Berlin'"
    run pool_value "$D2" memory_limit
    assert_output '256M'
    run pool_value "$D2" date.timezone
    assert_output 'Europe/Berlin'
    v-change-package-tenancy "$P1" PHP_MAX_MEMORY 512M
    v-delete-web-domain-php-setting "$U1" "$D2" all
}

@test "v-list-web-domain-php-settings: warns when uploads are above the nginx body size" {
    run settings "$D1" '.NGINX_MAX_BODY'
    assert_output '1024m'
    v-change-package-tenancy "$P1" PHP_MAX_UPLOAD unlimited
    v-change-web-domain-php-setting "$U1" "$D1" post_max_size 2G no
    run v-change-web-domain-php-setting "$U1" "$D1" upload_max_filesize 2G
    assert_success
    assert_output --partial 'nginx'
    run settings "$D1" '.WARNINGS | map(select(test("nginx"))) | length'
    assert_output 1
    v-delete-web-domain-php-setting "$U1" "$D1" all
    v-change-package-tenancy "$P1" PHP_MAX_UPLOAD 256M
}
