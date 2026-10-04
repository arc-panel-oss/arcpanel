#!/usr/bin/env bats
# ArcPanel versioning (ADR-0006, spec WP3).
# Run on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

setup() {
    source /etc/hestiacp/hestia.conf
    source "$HESTIA/func/main.sh"
    source_conf "$HESTIA/conf/hestia.conf"
    source_conf "$HESTIA/install/upstream.conf"
    STEP_DIR="$HESTIA/install/upgrade/arcpanel"
    MARKER="/tmp/arcpanel-version-test.log"
    rm -f "$MARKER"
}

teardown() {
    rm -f "$STEP_DIR/0.9.9.sh" "$STEP_DIR/1.0.1.sh" "$STEP_DIR/9.9.9.sh" "$MARKER"
    if [ -n "$SAVED_ARCPANEL_VERSION" ]; then
        "$HESTIA/bin/v-change-sys-config-value" "ARCPANEL_VERSION" "$SAVED_ARCPANEL_VERSION"
    fi
}

@test "VERSION keeps the upstream HestiaCP base version" {
    assert_equal "$VERSION" "$UPSTREAM_VERSION"
}

@test "ARCPANEL_VERSION matches the installed arcpanel package" {
    package_version="$(dpkg-query -W -f='${Version}' arcpanel | sed -E 's/-[0-9]{1,}\+[a-z0-9\.]{1,}//')"
    assert_equal "$ARCPANEL_VERSION" "$package_version"
}

@test "v-list-sys-config exposes both versions" {
    run v-list-sys-config json
    assert_success
    assert_output --partial "\"ARCPANEL_VERSION\": \"$ARCPANEL_VERSION\""
    assert_output --partial "\"VERSION\": \"$VERSION\""
}

@test "ArcPanel upgrade runs only steps newer than ARCPANEL_VERSION and not newer than the package" {
    SAVED_ARCPANEL_VERSION="$ARCPANEL_VERSION"
    source "$HESTIA/func/arcpanel-upgrade.sh"
    BIN="$HESTIA/bin"

    for v in 0.9.9 1.0.1 9.9.9; do
        echo "echo $v >> $MARKER" > "$STEP_DIR/$v.sh"
    done

    ARCPANEL_VERSION="1.0.0"
    arcpanel_new_version="1.0.1"
    run arcpanel_upgrade_start_routine
    assert_success

    assert_file_exists "$MARKER"
    run cat "$MARKER"
    assert_output "1.0.1"

    source_conf "$HESTIA/conf/hestia.conf"
    assert_equal "$ARCPANEL_VERSION" "1.0.1"
}

@test "ArcPanel upgrade does not run upstream version scripts" {
    SAVED_ARCPANEL_VERSION="$ARCPANEL_VERSION"
    source "$HESTIA/func/arcpanel-upgrade.sh"
    BIN="$HESTIA/bin"
    before="$VERSION"

    ARCPANEL_VERSION="1.0.0"
    arcpanel_new_version="1.0.1"
    run arcpanel_upgrade_start_routine
    assert_success
    refute_output --partial "install/upgrade/versions"

    source_conf "$HESTIA/conf/hestia.conf"
    assert_equal "$VERSION" "$before"
}
