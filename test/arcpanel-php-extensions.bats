#!/usr/bin/env bats
# PHP Manager: extensions (E41 M2,
# docs/features/specs/2026-10-12-001-e41-php-manager-design.md §5).
# Run as root on a test server with internet access and PHP 8.4 and 8.5
# installed: the suite installs and removes redis and uuid for PHP 8.4.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
V=8.4
OTHER=8.5
BROKEN=/etc/php/$V/fpm/conf.d/99-arcbats-broken.ini

# Wait until the system task is no longer running; print its status
wait_task() {
    local status i
    for i in $(seq 1 "${1:-300}"); do
        status=$(v-list-sys-task 10 json | jq -r .STATUS)
        if [ "$status" != running ]; then
            echo "$status"
            return
        fi
        sleep 2
    done
    echo timeout
}

ext() {
    v-list-sys-php-extensions "$1" json | jq -r --arg n "$2" '.[$n] | [.INSTALLED, .FPM, .CLI] | join(" ")'
}

setup_file() {
    [ "$(wait_task 600)" != timeout ]
    rm -f "$BROKEN"
    for pkg in php$V-redis php$V-uuid; do
        if dpkg -s "$pkg" > /dev/null 2>&1; then
            apt-get -y purge "$pkg" > /dev/null 2>&1
        fi
    done
    phpenmod -v $V -s fpm exif
    phpenmod -v $V -s cli exif
}

teardown_file() {
    rm -f "$BROKEN"
    phpenmod -v $V -s fpm exif
    phpenmod -v $V -s cli exif
    apt-get -y purge php$V-redis php$V-uuid > /dev/null 2>&1 || true
    systemctl restart php$V-fpm
}

#----------------------------------------------------------#
#                       Catalog                            #
#----------------------------------------------------------#

@test "PHP extensions: the catalog has unique names and valid keys" {
    catalog=$ARCPANEL/install/common/php/extensions.conf
    run bash -c "grep -E \"^NAME=\" $catalog | cut -d\\' -f2 | sort | uniq -d"
    assert_output ''
    run bash -c "grep -E \"^NAME=\" $catalog | grep -vE \"^NAME='[a-z0-9_]{1,32}' PACKAGE='(php%v-[a-z0-9]+)?' MODULE='[a-z0-9_]+' GROUP='(cache|database|image|debug|network|format|security|async|other)' MIN='[0-9.]*' MAX='[0-9.]*' REQUIRED='(yes|no)' CONFLICTS='[a-z0-9_,]*' NOTE='[^']*'\$\""
    assert_output ''
    for name in curl intl mbstring mysqli pdo_mysql xml zip gd; do
        run bash -c "grep -F \"NAME='$name' \" $catalog | grep -c \"REQUIRED='yes'\""
        assert_output 1
    done
}

@test "v-list-sys-php-extensions: catalog entries and server modules with their state" {
    run v-list-sys-php-extensions $V json
    assert_success
    run ext $V curl
    assert_output 'yes on on'
    run bash -c "v-list-sys-php-extensions $V json | jq -r '.curl | [.REQUIRED, .CATALOG, .TOGGLE_ONLY, .PACKAGE] | join(\" \")'"
    assert_output "yes yes no php$V-curl"
    run bash -c "v-list-sys-php-extensions $V json | jq -r '.opcache | [.TOGGLE_ONLY, .INSTALLED] | join(\" \")'"
    assert_output 'yes yes'
    # a module of the server that the catalog does not list
    run bash -c "v-list-sys-php-extensions $V json | jq -r '.ctype | [.CATALOG, .FPM] | join(\" \")'"
    assert_output 'no on'
    run bash -c "v-list-sys-php-extensions $V json | jq -r '.redis | [.INSTALLED, .AVAILABLE] | join(\" \")'"
    assert_output 'no yes'
    run v-list-sys-php-extensions 7.4 json
    assert_failure 3
    run v-list-sys-php-extensions '8.4;id' json
    assert_failure 2
}

#----------------------------------------------------------#
#                   Install and toggle                     #
#----------------------------------------------------------#

@test "v-add-sys-php-extension: installs redis on one version only" {
    run v-add-sys-php-extension $V redis
    assert_success
    run ext $V redis
    assert_output 'yes on on'
    run ext $OTHER redis
    refute_output --partial 'yes'
    run systemctl is-active php$V-fpm
    assert_output 'active'
    run v-add-sys-php-extension $V redis
    assert_failure 4
}

@test "v-change-sys-php-extension: turns a module off and on per SAPI" {
    run v-change-sys-php-extension $V redis off fpm
    assert_success
    run ext $V redis
    assert_output 'yes off on'
    run ext $OTHER redis
    refute_output --partial 'on'
    run v-change-sys-php-extension $V redis on
    assert_success
    run ext $V redis
    assert_output 'yes on on'
    run v-change-sys-php-extension $V redis maybe
    assert_failure 2
    run v-change-sys-php-extension $V redis off apache2
    assert_failure 2
}

@test "v-delete-sys-php-extension: refuses a package that others need" {
    run v-delete-sys-php-extension $V igbinary
    assert_failure 7
    assert_output --partial "php$V-redis"
}

@test "PHP extensions: required modules and toggles are protected" {
    run v-delete-sys-php-extension $V curl
    assert_failure 10
    run v-change-sys-php-extension $V curl off
    assert_failure 10
    run v-delete-sys-php-extension $V opcache
    assert_failure 10
    run v-add-sys-php-extension $V opcache
    assert_failure 10
    run v-add-sys-php-extension $V nosuch
    assert_failure 2
    run v-add-sys-php-extension 7.4 redis
    assert_failure 3
}

@test "PHP extensions: a conflicting extension is refused" {
    # imagick is part of the base install
    run ext $V imagick
    assert_output 'yes on on'
    run v-add-sys-php-extension $V gmagick
    assert_failure 7
    assert_output --partial 'imagick'
}

@test "v-change-sys-php-extension: a failing php-fpm test undoes the change" {
    run v-change-sys-php-extension $V exif off fpm
    assert_success
    run ext $V exif
    assert_output 'yes off on'
    echo 'extension=arcbats_nosuch.so' > "$BROKEN"
    run v-change-sys-php-extension $V exif on fpm
    assert_failure 2
    assert_output --partial 'undone'
    run ext $V exif
    assert_output 'yes off on'
    rm -f "$BROKEN"
    run v-change-sys-php-extension $V exif on fpm
    assert_success
    run ext $V exif
    assert_output 'yes on on'
    run systemctl is-active php$V-fpm
    assert_output 'active'
}

@test "v-add-sys-php-extension: a failing php-fpm test removes the package again" {
    echo 'extension=arcbats_nosuch.so' > "$BROKEN"
    run v-add-sys-php-extension $V uuid
    assert_failure 2
    rm -f "$BROKEN"
    run ext $V uuid
    assert_output --partial 'no'
    run dpkg -s php$V-uuid
    assert_failure
}

@test "v-delete-sys-php-extension: removes redis" {
    run v-delete-sys-php-extension $V redis
    assert_success
    run ext $V redis
    assert_output --partial 'no'
    run v-delete-sys-php-extension $V redis
    assert_failure 3
}

#----------------------------------------------------------#
#                      System task                         #
#----------------------------------------------------------#

@test "v-start-sys-task php-ext: arguments are checked" {
    run v-start-sys-task php-ext $V - -
    assert_failure 1
    run v-start-sys-task php-ext $V 'redis;id' -
    assert_failure 2
    run v-start-sys-task php-ext $V nosuch -
    assert_failure 2
    run v-start-sys-task php-ext $V - curl
    assert_failure 10
    run v-start-sys-task php-ext 7.4 redis -
    assert_failure 3
    run v-start-sys-task php-ext $V curl -
    assert_failure 4
}

@test "v-start-sys-task php-ext: installs and removes in the background" {
    run v-start-sys-task php-ext $V uuid -
    assert_success
    run wait_task
    assert_output 'done'
    run ext $V uuid
    assert_output 'yes on on'
    run v-start-sys-task php-ext $V - uuid
    assert_success
    run wait_task
    assert_output 'done'
    run ext $V uuid
    assert_output --partial 'no'
}
