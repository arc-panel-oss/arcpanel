#!/usr/bin/env bats
# ArcPanel compatibility layer (ADR-0006, spec WP4).
# Run on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ALIASES=(
    "v-add-cron-hestia-autoupdate:v-add-cron-arcpanel-autoupdate"
    "v-change-sys-hestia-ssl:v-change-sys-arcpanel-ssl"
    "v-delete-cron-hestia-autoupdate:v-delete-cron-arcpanel-autoupdate"
    "v-list-sys-hestia-autoupdate:v-list-sys-arcpanel-autoupdate"
    "v-list-sys-hestia-ssl:v-list-sys-arcpanel-ssl"
    "v-list-sys-hestia-updates:v-list-sys-arcpanel-updates"
    "v-update-sys-hestia:v-update-sys-arcpanel"
    "v-update-sys-hestia-all:v-update-sys-arcpanel-all"
    "v-update-sys-hestia-git:v-update-sys-arcpanel-git"
)

@test "ArcPanel path alias points to the panel directory" {
    assert_link_exists /usr/local/arcpanel
    run readlink -f /usr/local/arcpanel
    assert_output "$(readlink -f /usr/local/hestia)"
}

@test "ARCPANEL and HESTIA are set in a login shell" {
    run bash -lc 'echo "$ARCPANEL|$HESTIA"'
    assert_success
    assert_output "/usr/local/hestia|/usr/local/hestia"
}

@test "Every v-*-hestia-* command has an executable v-*-arcpanel-* alias" {
    for pair in "${ALIASES[@]}"; do
        alias_cmd="${pair#*:}"
        assert_file_executable "$HESTIA/bin/$alias_cmd"
    done
}

@test "Read-only aliases return the same output and exit code as the original" {
    for pair in "v-list-sys-hestia-autoupdate:v-list-sys-arcpanel-autoupdate" \
        "v-list-sys-hestia-ssl:v-list-sys-arcpanel-ssl" \
        "v-list-sys-hestia-updates:v-list-sys-arcpanel-updates"; do
        original="${pair%%:*}"
        alias_cmd="${pair#*:}"
        expected="$("$HESTIA/bin/$original" json)"
        expected_rc=$?
        run "$HESTIA/bin/$alias_cmd" json
        assert_equal "$status" "$expected_rc"
        assert_equal "$output" "$expected"
    done
}

@test "Alias returns the original exit code on invalid input" {
    run v-update-sys-hestia not-a-package
    expected_rc=$status
    run v-update-sys-arcpanel not-a-package
    assert_equal "$status" "$expected_rc"
    assert_failure
}

@test "arcpanel and hestia service names resolve to one unit" {
    run systemctl show -p Id --value arcpanel
    assert_success
    arcpanel_unit="$output"
    run systemctl show -p Id --value hestia
    assert_success
    assert_equal "$output" "$arcpanel_unit"
    run systemctl is-active arcpanel
    assert_output "active"
}

@test "No duplicate hestia.service from the SysV generator" {
    refute [ -f /run/systemd/generator.late/hestia.service ]
}

@test "Package arcpanel satisfies dependencies on hestia" {
    run dpkg-query -W -f='${Provides}' arcpanel
    assert_success
    assert_output --partial "hestia"
    run dpkg-query -W -f='${Status}' arcpanel-nginx
    assert_output --partial "installed"
    run dpkg-query -W -f='${Status}' arcpanel-php
    assert_output --partial "installed"
}

@test "APT uses the ArcPanel repository only" {
    assert_file_exists /etc/apt/sources.list.d/arcpanel.list
    assert_file_not_exists /etc/apt/sources.list.d/hestia.list
    run grep -rh "hestiacp.com" /etc/apt/sources.list.d/
    assert_failure
}
