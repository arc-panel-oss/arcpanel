#!/usr/bin/env bats
# System packages of the catalog (E5,
# docs/features/specs/2026-10-06-006-e5-e6b2-system-packages-templates-design.md §4–§6).
# Run as root on a test server with internet access: the suite installs and
# removes GNU hello from the distribution's archive. The fixture catalog is
# test/fixtures/sys-packages.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
FIXTURES="$BATS_TEST_DIRNAME/fixtures/sys-packages"
BROKEN="$BATS_TEST_DIRNAME/fixtures/sys-packages-broken"
CATALOG=$ARCPANEL/install/common/apps/system
SP_CONF=$ARCPANEL/data/sys-packages.conf
PASS="Tt-$(date +%s)-Pass"
U1=e5tu1
D1=e5a.example
export ARC_SYS_PACKAGES_CATALOG="$FIXTURES"

source_value() {
    grep "^$1=" "$ARCPANEL/conf/arcpanel.conf" | tail -n1 | cut -d"'" -f2
}

# Client-facing template of the test domain
set_tpl() {
    if [ "$(source_value WEB_SYSTEM)" = 'nginx' ]; then
        v-change-web-domain-tpl $U1 $D1 "$1"
    else
        v-change-web-domain-proxy-tpl $U1 $D1 "$1"
    fi
}

entry() {
    v-list-sys-packages json | jq -c --arg s "$1" '.[] | select(.SLUG == $s)'
}

field() {
    v-list-sys-packages json | jq -r --arg s "$1" --arg f "$2" '.[] | select(.SLUG == $s) | .[$f] | tostring'
}

hello_installed() {
    [ "$(dpkg-query -W -f='${db:Status-Status}' hello 2> /dev/null)" = 'installed' ]
}

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

cleanup() {
    v-delete-user $U1 > /dev/null 2>&1 || true
    if [ -f "$SP_CONF" ]; then
        sed -i "/^SLUG='arctest-/d" "$SP_CONF"
    fi
    rm -f "$CATALOG"/arctest-*.json
    if hello_installed; then
        DEBIAN_FRONTEND=noninteractive apt-get -y -qq remove hello > /dev/null 2>&1
    fi
}

setup_file() {
    cleanup
    [ "$(wait_task 600)" != timeout ]
    v-add-sys-web-templates-meta > /dev/null
    v-add-user $U1 "$PASS" $U1@example.invalid default > /dev/null
    v-add-web-domain $U1 $D1 > /dev/null
}

teardown_file() {
    cleanup
}

#----------------------------------------------------------#
#                       Catalog                            #
#----------------------------------------------------------#

@test "System packages: the shipped catalog is valid and has the six entries" {
    run env -u ARC_SYS_PACKAGES_CATALOG v-check-sys-packages
    assert_success
    run bash -c "env -u ARC_SYS_PACKAGES_CATALOG v-list-sys-packages json | jq -r '[.[].SLUG] | join(\" \")'"
    assert_output "build-tools ffmpeg go imagemagick python ruby"
    run bash -c "env -u ARC_SYS_PACKAGES_CATALOG v-list-sys-packages json | jq -r '.[] | select(.SLUG == \"python\") | .PROVIDES | join(\",\")'"
    assert_output "python"
}

@test "System packages: the check refuses broken manifests with one line per problem" {
    dir=$(mktemp -d)
    cp "$BROKEN"/*.json "$dir/"
    printf '{ "schema": 1,\n' > "$dir/arctest-json.json"
    cp "$FIXTURES/arctest-present.json" "$dir/Bad_Name.json"
    run v-check-sys-packages "$dir"
    assert_failure 2
    assert_output --partial "arctest-json: not valid JSON"
    assert_output --partial "Bad_Name: invalid file name"
    assert_output --partial 'slug must be "arctest-slug"'
    assert_output --partial "unknown key source"
    assert_output --partial "schema must be 1"
    assert_output --partial "name must be a string"
    assert_output --partial "category must be one of"
    assert_output --partial "packages must be"
    assert_output --partial "unknown release debian-11"
    assert_output --partial "provides must list"
    run bash -c "v-check-sys-packages '$dir' json | jq -r '[.[] | select(.STATUS == \"ok\")] | length'"
    assert_output "0"
    rm -rf "$dir"
    run v-check-sys-packages "$FIXTURES"
    assert_success
    run v-check-sys-packages /nonexistent
    assert_failure 3
}

@test "System packages: the list shows state, source and availability in every format" {
    run bash -c "v-list-sys-packages json | jq -e 'type == \"array\" and length == 3'"
    assert_success
    run field arctest-hello STATUS
    assert_output "missing"
    run field arctest-hello SOURCE
    assert_output "-"
    run field arctest-present STATUS
    assert_output "installed"
    run field arctest-present SOURCE
    assert_output "system"
    run field arctest-nowhere AVAILABLE
    assert_output "no"
    run field arctest-nowhere PACKAGES
    assert_output "[]"
    run bash -c "v-list-sys-packages plain | grep -c ."
    assert_output "3"
    run bash -c "v-list-sys-packages csv | head -n1"
    assert_output "SLUG,NAME,CATEGORY,STATUS,SOURCE,VERSION,AVAILABLE,UPGRADABLE,USED_BY"
    run bash -c "v-list-sys-packages shell | head -n1"
    assert_output --partial "SLUG"
    run v-list-sys-packages yaml
    assert_failure 2
}

#----------------------------------------------------------#
#                   Install and remove                     #
#----------------------------------------------------------#

@test "System packages: arguments are checked before apt runs" {
    run v-add-sys-package
    assert_failure 1
    run v-add-sys-package 'arctest;id'
    assert_failure 3
    run v-add-sys-package arctest-unknown
    assert_failure 3
    run v-add-sys-package arctest-nowhere
    assert_failure 2
    assert_output --partial "not available"
    run v-add-sys-package arctest-present
    assert_failure 4
    run v-delete-sys-package arctest-present
    assert_failure 10
    assert_output --partial "not installed by ArcPanel"
    run v-delete-sys-package arctest-hello maybe
    assert_failure 2
}

@test "System packages: install records what ArcPanel installed, remove takes it away" {
    hello_installed && skip "hello is installed on this server"
    run v-add-sys-package arctest-hello
    assert_success
    assert_output --partial "is installed"
    hello_installed
    run grep "^SLUG='arctest-hello' PACKAGES='hello' " "$SP_CONF"
    assert_success
    run field arctest-hello STATUS
    assert_output "installed"
    run field arctest-hello SOURCE
    assert_output "arcpanel"
    run field arctest-hello VERSION
    assert_output --regexp '^[0-9][0-9.]*'
    run stat -c '%a %U' "$SP_CONF"
    assert_output "640 root"
    run v-add-sys-package arctest-hello
    assert_failure 4

    run v-delete-sys-package arctest-hello
    assert_success
    ! hello_installed
    run grep "^SLUG='arctest-hello' " "$SP_CONF"
    assert_failure
    run v-delete-sys-package arctest-hello
    assert_failure 10
}

@test "System packages: a package removed outside ArcPanel shows as missing and installs again" {
    hello_installed && skip "hello is installed on this server"
    v-add-sys-package arctest-hello > /dev/null
    DEBIAN_FRONTEND=noninteractive apt-get -y -qq remove hello > /dev/null 2>&1
    run field arctest-hello STATUS
    assert_output "missing"
    run field arctest-hello SOURCE
    assert_output "arcpanel"
    run v-add-sys-package arctest-hello
    assert_success
    hello_installed
    run v-delete-sys-package arctest-hello
    assert_success
}

@test "System packages: a package that another entry recorded stays installed" {
    hello_installed && skip "hello is installed on this server"
    v-add-sys-package arctest-hello > /dev/null
    echo "SLUG='arctest-other' PACKAGES='hello' TIME='10:00:00' DATE='2026-10-06'" >> "$SP_CONF"
    run v-delete-sys-package arctest-hello
    assert_success
    hello_installed
    sed -i "/^SLUG='arctest-other'/d" "$SP_CONF"
    DEBIAN_FRONTEND=noninteractive apt-get -y -qq remove hello > /dev/null 2>&1
}

@test "System packages: removal is refused while a domain's template needs the runtime" {
    hello_installed && skip "hello is installed on this server"
    v-add-sys-package arctest-hello > /dev/null
    set_tpl python-gunicorn > /dev/null
    run field arctest-hello USED_BY
    assert_output "1"
    run field arctest-hello DOMAINS
    assert_output "[\"$U1/$D1\"]"
    run v-delete-sys-package arctest-hello
    assert_failure 10
    assert_output --partial "$U1/$D1"
    hello_installed
    run v-delete-sys-package arctest-hello force
    assert_success
    ! hello_installed
    set_tpl default > /dev/null
    run field arctest-hello USED_BY
    assert_output "0"
}

@test "System packages: demo mode refuses changes" {
    conf=$ARCPANEL/conf/arcpanel.conf
    cp "$conf" "$conf.e5"
    sed -i "/^DEMO_MODE=/d" "$conf"
    echo "DEMO_MODE='yes'" >> "$conf"
    run v-add-sys-package arctest-hello
    mv -f "$conf.e5" "$conf"
    assert_failure 1
    assert_output --partial "security restrictions"
    ! hello_installed
}
