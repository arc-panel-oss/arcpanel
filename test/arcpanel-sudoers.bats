#!/usr/bin/env bats
# The sudo rules of arcpanelweb (E39 W4,
# docs/features/specs/2026-10-16-002-e39-w4-authorization-and-tenancy-design.md §9).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
SUDOERS=/etc/sudoers.d/arcpanelweb
ROOT_ONLY=$ARCPANEL/install/common/sudo/root-only

root_only() {
    grep -v '^[[:space:]]*\(#\|$\)' "$ROOT_ONLY"
}

@test "v-update-sys-sudoers writes a file that visudo accepts" {
    run v-update-sys-sudoers
    assert_success
    run visudo -cf "$SUDOERS"
    assert_success
    run stat -c '%a %U' "$SUDOERS"
    assert_output '440 root'
    # The last rule names the alias, not a glob of bin/
    run grep -c 'bin/\*' "$SUDOERS"
    assert_output '0'
}

@test "arcpanelweb may run the panel's commands but no root-only command" {
    v-update-sys-sudoers
    listing=$(sudo -l -U arcpanelweb | tr ',' '\n' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
    echo "$listing" | grep -qx "$ARCPANEL/bin/v-list-user"
    for command in $(root_only); do
        run grep -cx "$ARCPANEL/bin/$command" <<< "$listing"
        assert_output '0'
        run runuser -u arcpanelweb -- sudo -n "$ARCPANEL/bin/$command"
        assert_failure
    done
    run runuser -u arcpanelweb -- sudo -n "$ARCPANEL/bin/v-list-sys-config" json
    assert_success
}

@test "Every command of the panel allow-list is in the sudo rules, none of the root-only list" {
    v-update-sys-sudoers
    allow_list=$ARCPANEL/web-app/config/arc-commands.php
    assert_file_exists "$allow_list"
    count=0
    for command in $(grep -o "'v-[a-z0-9-]*'" "$allow_list" | tr -d "'"); do
        run grep -c "$ARCPANEL/bin/$command\( \|,\|\$\)" "$SUDOERS"
        assert_output '1'
        count=$((count + 1))
    done
    [ "$count" -gt 300 ]
    for command in $(root_only); do
        run grep -c "'$command'" "$allow_list"
        assert_output '0'
    done
}

@test "A file that visudo refuses is not installed" {
    v-update-sys-sudoers
    before=$(sha256sum "$SUDOERS")
    fake=$(mktemp -d)
    printf '#!/bin/sh\nexit 1\n' > "$fake/visudo"
    chmod 755 "$fake/visudo"
    run env PATH="$fake:$PATH" v-update-sys-sudoers
    assert_failure 2
    assert_output --partial 'was kept'
    rm -rf "$fake"
    run sha256sum "$SUDOERS"
    assert_output "$before"
    run bash -c "ls -a /etc/sudoers.d | grep -c '^\.arcpanelweb'"
    assert_output '0'
}

@test "Only root runs v-update-sys-sudoers" {
    run runuser -u arcpanelweb -- "$ARCPANEL/bin/v-update-sys-sudoers"
    assert_failure
}
