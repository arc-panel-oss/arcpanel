#!/usr/bin/env bats
# ArcPanel versioning (ADR-0007, spec 2026-10-06-005 D5 and §7): VERSION in
# hestia.conf is the ArcPanel version and selects the upgrade steps in
# install/upgrade/versions/.
# Run on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

setup() {
    source "$HESTIA/func/main.sh"
    source_conf "$HESTIA/conf/hestia.conf"
    STEP_DIR="$HESTIA/install/upgrade/versions"
    MARKER="/tmp/arcpanel-version-test.log"
    rm -f "$MARKER"
}

teardown() {
    rm -f "$STEP_DIR/1.9.9.sh" "$STEP_DIR/90.0.1.sh" "$STEP_DIR/90.0.2.sh" "$STEP_DIR/99.0.0.sh" "$MARKER"
    if [ -n "${SAVED_VERSION:-}" ]; then
        "$HESTIA/bin/v-change-sys-config-value" "VERSION" "$SAVED_VERSION"
    fi
}

@test "VERSION matches the installed arcpanel package" {
    package_version="$(dpkg-query -W -f='${Version}' arcpanel | sed -E 's/-[0-9]+(\+[a-z0-9.]+)?//')"
    assert_equal "$VERSION" "$package_version"
}

@test "hestia.conf has no second version key" {
    run grep -c "^ARCPANEL_VERSION=" "$HESTIA/conf/hestia.conf"
    assert_output "0"
    assert_not_exists "$HESTIA/install/upstream.conf"
    assert_not_exists "$HESTIA/install/upgrade/arcpanel"
}

@test "v-list-sys-config shows VERSION only" {
    run v-list-sys-config json
    assert_success
    assert_output --partial "\"VERSION\": \"$VERSION\""
    refute_output --partial "ARCPANEL_VERSION"
}

@test "v-list-sys-info shows VERSION as the ArcPanel version" {
    run bash -c "v-list-sys-info json | jq -r '.sysinfo.ARCPANEL + \" \" + (.sysinfo | has(\"HESTIA\") | tostring)'"
    assert_success
    assert_output "$VERSION false"
    run v-list-sys-info csv
    assert_line --index 0 "HOSTNAME,OS,VERSION,ARCH,ARCPANEL,RELEASE,UPTIME,LOADAVERAGE"
}

@test "upgrade_get_version strips the Debian revision and keeps the channel" {
    source "$HESTIA/func/upgrade.sh"
    dpkg-query() { echo "$FAKE_PACKAGE_VERSION"; }
    VERSION="2.0.0"

    FAKE_PACKAGE_VERSION="2.0.1-1+ubuntu24.04"
    upgrade_get_version
    assert_equal "$new_version" "2.0.1"
    assert_equal "$previous_version" "2.0.0"

    FAKE_PACKAGE_VERSION="2.1.0-1+debian12~beta"
    upgrade_get_version
    assert_equal "$new_version" "2.1.0~beta"

    FAKE_PACKAGE_VERSION=""
    upgrade_get_version
    assert_equal "$new_version" "2.0.0"
}

@test "Upgrade runs only steps newer than VERSION and not newer than the package, then sets VERSION" {
    SAVED_VERSION="$VERSION"
    source "$HESTIA/func/upgrade.sh"
    BIN="$HESTIA/bin"
    mkdir -p "$STEP_DIR"
    for v in 1.9.9 90.0.1 90.0.2 99.0.0; do
        echo "echo $v >> $MARKER" > "$STEP_DIR/$v.sh"
    done

    VERSION="90.0.0"
    new_version="90.0.2~beta"
    run upgrade_start_routine
    assert_success
    assert_output --partial "patches and updates for version v90.0.1"

    run cat "$MARKER"
    assert_output "$(printf '90.0.1\n90.0.2')"
    run grep "^VERSION=" "$HESTIA/conf/hestia.conf"
    assert_output "VERSION='90.0.2~beta'"
}

@test "Upgrade with no newer step only sets VERSION" {
    SAVED_VERSION="$VERSION"
    source "$HESTIA/func/upgrade.sh"
    BIN="$HESTIA/bin"

    VERSION="$SAVED_VERSION"
    new_version="$SAVED_VERSION"
    run upgrade_start_routine
    assert_success
    assert_not_exists "$MARKER"
    run grep "^VERSION=" "$HESTIA/conf/hestia.conf"
    assert_output "VERSION='$SAVED_VERSION'"
}
