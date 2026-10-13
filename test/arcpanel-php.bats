#!/usr/bin/env bats
# PHP Manager: version catalog and lifecycle (E41 M1,
# docs/features/specs/2026-10-12-001-e41-php-manager-design.md §4).
# Run as root on a test server with internet access and a PHP-FPM backend:
# the suite installs and removes PHP 8.3 from the PHP package repository,
# which must not be installed before.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PHP_TEST=8.3
PASS="Tt-e41-m1-Pass"
U1=e41tu1
D1=e41a.example
STUB="$BATS_FILE_TMPDIR/stub"

# Run a function of func/php.sh in a fresh shell
php_lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source \$ARCPANEL/func/main.sh; source \$ARCPANEL/func/php.sh; $*"
}

cleanup() {
    v-delete-user $U1 yes > /dev/null 2>&1 || true
    rm -f "$ARCPANEL/data/php/apt-cache.conf"
}

setup_file() {
    cleanup
    if [ -f "/etc/init.d/php$PHP_TEST-fpm" ]; then
        echo "PHP $PHP_TEST is installed on this server; the suite needs it absent" >&3
        return 1
    fi
    v-add-user $U1 "$PASS" $U1@example.invalid default > /dev/null
    v-add-web-domain $U1 $D1 > /dev/null
}

teardown_file() {
    cleanup
    if [ -f "/etc/init.d/php$PHP_TEST-fpm" ]; then
        v-delete-web-php $PHP_TEST > /dev/null 2>&1 || true
    fi
}

# A fake apt-cache that knows no package, and an apt-get that does nothing
stub_repository() {
    mkdir -p "$STUB"
    printf '#!/bin/sh\nexit 0\n' > "$STUB/apt-cache"
    printf '#!/bin/sh\nexit 0\n' > "$STUB/apt-get"
    chmod 755 "$STUB/apt-cache" "$STUB/apt-get"
    rm -f "$ARCPANEL/data/php/apt-cache.conf"
}

#----------------------------------------------------------#
#                       Catalog                            #
#----------------------------------------------------------#

@test "PHP catalog: lists 5.6 to 8.5, 7.4 and later installable" {
    run php_lib php_catalog_versions
    assert_success
    assert_output "$(printf '%s\n' 5.6 7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5)"
    run php_lib 'for v in $(php_catalog_versions); do php_version_installable $v && echo $v; done'
    assert_output "$(printf '%s\n' 7.4 8.0 8.1 8.2 8.3 8.4 8.5)"
    run php_lib php_catalog_get 8.4 EOL
    assert_output '2028-12-31'
}

@test "PHP catalog: the status comes from the dates" {
    run php_lib php_version_status 5.6
    assert_output 'eol'
    run php_lib php_version_status 8.5
    assert_output 'active'
    run php_lib php_version_status 9.9
    assert_output 'unknown'
    catalog="$BATS_FILE_TMPDIR/versions.conf"
    {
        echo "VERSION='9.0' RELEASED='2020-01-01' ACTIVE_UNTIL='2020-06-01' EOL='2999-01-01' INSTALLABLE='yes'"
        echo "VERSION='9.10' RELEASED='2020-01-01' ACTIVE_UNTIL='2999-01-01' EOL='2999-01-01' INSTALLABLE='yes'"
    } > "$catalog"
    export ARC_PHP_CATALOG="$catalog"
    run php_lib php_version_status 9.0
    assert_output 'security'
    run php_lib php_version_valid 9.10
    assert_success
    unset ARC_PHP_CATALOG
}

@test "PHP catalog: versions match exactly" {
    run php_lib php_version_valid 8.3
    assert_success
    for bad in 8x3 8. 83 .3 8.3.1 '8.3;id' 10.0 '' '8\.3'; do
        run php_lib php_version_valid "'$bad'"
        assert_failure
    done
    run php_lib php_version_format_valid 10.10
    assert_success
    run php_lib php_version_format_valid 100.1
    assert_failure
}

@test "PHP catalog: backend templates with two digits name their version" {
    run php_lib php_backend_version PHP-8_10
    assert_output '8.10'
    run php_lib php_backend_version PHP-10_0
    assert_output '10.0'
    run php_lib php_backend_version custom-PHP-8_3
    assert_output '8.3'
    run php_lib php_backend_version default
    assert_output "$(php_lib multiphp_default_version)"
}

@test "PHP catalog: no copy of the version list is left in the installers" {
    run grep -l 'multiphp_v=("5.6"' "$ARCPANEL/install/upgrade/upgrade.conf"
    assert_failure
}

#----------------------------------------------------------#
#                     Install checks                       #
#----------------------------------------------------------#

@test "v-add-web-php: refuses invalid, unknown, installed and end-of-life versions" {
    run v-add-web-php 8x3
    assert_failure 2
    run v-add-web-php 8.
    assert_failure 2
    run v-add-web-php 9.9
    assert_failure 2
    assert_output --partial 'not in the version catalog'
    default=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    run v-add-web-php "$default"
    assert_failure 4
    run v-add-web-php 7.3
    assert_failure 10
    assert_output --partial 'end of life'
}

@test "v-add-web-php: a version the repository does not provide is refused" {
    stub_repository
    PATH="$STUB:$PATH" run v-add-web-php $PHP_TEST
    assert_failure 3
    assert_output --partial "PHP $PHP_TEST is not available from the PHP repository of this system"
    rm -f "$ARCPANEL/data/php/apt-cache.conf"
}

@test "v-start-sys-task php: two-digit versions pass the format check" {
    run v-start-sys-task php 8.10 -
    assert_failure 2
    assert_output --partial 'not supported'
    refute_output --partial 'invalid PHP version list'
    stub_repository
    PATH="$STUB:$PATH" run v-start-sys-task php $PHP_TEST -
    assert_failure 3
    rm -f "$ARCPANEL/data/php/apt-cache.conf"
}

#----------------------------------------------------------#
#                    Install and remove                    #
#----------------------------------------------------------#

@test "v-list-sys-php-versions: catalog columns, availability and extensions" {
    run bash -c "v-list-sys-php-versions json | jq -r '.\"5.6\" | [.STATUS, .INSTALLABLE, .EOL] | join(\" \")'"
    assert_output 'eol no 2018-12-31'
    run bash -c "v-list-sys-php-versions json | jq -r '.\"$PHP_TEST\" | [.INSTALLED, .INSTALLABLE, .AVAILABLE] | join(\" \")'"
    assert_output 'no yes yes'
    default=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    run bash -c "v-list-sys-php-versions json | jq -r '.\"$default\" | [.INSTALLED, .DEFAULT, (.EXTENSIONS | tonumber > 10)] | map(tostring) | join(\" \")'"
    assert_output 'yes yes true'
    run v-list-sys-php-versions
    assert_success
    assert_line --partial 'VERSION'
}

@test "v-add-web-php: installs an available version" {
    run v-add-web-php $PHP_TEST
    assert_success
    assert_file_exist "/etc/init.d/php$PHP_TEST-fpm"
    assert_file_exist "$ARCPANEL/data/templates/web/php-fpm/PHP-${PHP_TEST/./_}.tpl"
    run systemctl is-active "php$PHP_TEST-fpm"
    assert_output 'active'
}

@test "v-delete-web-php: refuses a version in use, the default and an absent one" {
    run v-change-web-domain-backend-tpl $U1 $D1 "PHP-${PHP_TEST/./_}"
    assert_success
    assert_file_exist "/etc/php/$PHP_TEST/fpm/pool.d/$D1.conf"
    run v-delete-web-php $PHP_TEST
    assert_failure 10
    assert_output --partial "$U1/$D1"
    assert_file_exist "$ARCPANEL/data/templates/web/php-fpm/PHP-${PHP_TEST/./_}.tpl"
    default=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    run v-delete-web-php "$default"
    assert_failure 10
    assert_output --partial 'default version'
    run v-delete-web-php 7.4
    assert_failure 3
    run v-delete-web-php '8.3;id'
    assert_failure 2
}

@test "v-delete-web-php: removes an unused version with its packages" {
    run v-change-web-domain-backend-tpl $U1 $D1 default
    assert_success
    run v-delete-web-php $PHP_TEST
    assert_success
    assert_file_not_exist "/etc/init.d/php$PHP_TEST-fpm"
    assert_file_not_exist "$ARCPANEL/data/templates/web/php-fpm/PHP-${PHP_TEST/./_}.tpl"
    run bash -c "dpkg-query -W -f='\${Package} \${db:Status-Status}\n' 'php$PHP_TEST-*' 2>/dev/null | awk '\$2 == \"installed\"'"
    assert_output ''
}
