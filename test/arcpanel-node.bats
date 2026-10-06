#!/usr/bin/env bats
# ArcPanel Node.js manager (E13,
# docs/features/specs/2026-10-05-006-e13-node-manager-design.md).
# Run as root on a server installed with the ArcPanel installer. The first
# run downloads two Node.js releases into a local mirror
# (/root/arcpanel-node-mirror) and points NODE_RELEASES_URL at it; later
# runs work offline. Package tests need the npm registry.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
CACHE=$ARCPANEL/data/cache/node
CONF=$ARCPANEL/conf/arcpanel.conf
MIRROR=/root/arcpanel-node-mirror
BAD_MIRROR=/root/arcpanel-node-mirror-bad
PASS="Tn-$(date +%s)-Pass"
USERS="tnu1 tnu2 tnu3 tnoff tnres tncust tnjail"

make_package() {
    local name=$1 node=$2 versions=$3 allowed=$4 shell=${5:-bash} tmp
    tmp=$(mktemp)
    sed "s/^SHELL=.*/SHELL='$shell'/" "$PKG_DIR/default.pkg" > "$tmp"
    v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy "$name" NODE "$node"
    v-change-package-tenancy "$name" NODE_VERSIONS "$versions"
    v-change-package-tenancy "$name" NODE_ALLOWED "$allowed"
}

# Newest X.Y.Z of a major in the real index
latest_of() {
    jq -r --arg m "$1" '[.[] | select(.version | startswith("v" + $m + ".")) | .version][0] | ltrimstr("v")' "$MIRROR/index.json"
}

mirror_version() {
    local version=$1 name
    name="node-v$version-linux-$(node_arch_name)"
    mkdir -p "$MIRROR/v$version"
    for file in SHASUMS256.txt SHASUMS256.txt.sig "$name.tar.xz"; do
        if [ ! -s "$MIRROR/v$version/$file" ]; then
            curl -sSfL -o "$MIRROR/v$version/$file" "https://nodejs.org/dist/v$version/$file"
        fi
    done
}

node_arch_name() {
    case "$(uname -m)" in
        x86_64) echo x64 ;;
        aarch64) echo arm64 ;;
    esac
}

build_mirrors() {
    mkdir -p "$MIRROR"
    if [ ! -s "$MIRROR/index.json" ]; then
        curl -sSfL -o "$MIRROR/index.json" https://nodejs.org/dist/index.json
        curl -sSfL -o "$MIRROR/schedule.json" https://raw.githubusercontent.com/nodejs/Release/main/schedule.json
    fi
    if [ ! -s "$MIRROR/nvm.tar.gz" ]; then
        cp "$CACHE/nvm-"*.tar.gz "$MIRROR/nvm.tar.gz" 2> /dev/null \
            || curl -sSfL -o "$MIRROR/nvm.tar.gz" https://github.com/nvm-sh/nvm/archive/refs/tags/v0.40.8.tar.gz
    fi
    V24=$(latest_of 24)
    V22=$(latest_of 22)
    mirror_version "$V24"
    mirror_version "$V22"
    mkdir -p "$MIRROR/nvm"
    cp -f "$MIRROR/nvm.tar.gz" "$MIRROR/nvm/v0.40.8.tar.gz"

    # Broken mirror: v24 with a forged checksum list, v22 with a damaged tarball
    rm -rf "$BAD_MIRROR"
    mkdir -p "$BAD_MIRROR/v$V24" "$BAD_MIRROR/v$V22"
    cp "$MIRROR/index.json" "$MIRROR/schedule.json" "$BAD_MIRROR/"
    cp "$MIRROR/v$V24/"* "$BAD_MIRROR/v$V24/"
    echo "0000000000000000000000000000000000000000000000000000000000000000  forged.tar.xz" >> "$BAD_MIRROR/v$V24/SHASUMS256.txt"
    cp "$MIRROR/v$V22/SHASUMS256.txt" "$MIRROR/v$V22/SHASUMS256.txt.sig" "$BAD_MIRROR/v$V22/"
    head -c 100000 "$MIRROR/v$V22/node-v$V22-linux-$(node_arch_name).tar.xz" > "$BAD_MIRROR/v$V22/node-v$V22-linux-$(node_arch_name).tar.xz"
}

use_mirror() {
    v-change-sys-config-value NODE_RELEASES_URL "file://$1"
    v-change-sys-config-value NODE_SCHEDULE_URL "file://$1/schedule.json"
    v-change-sys-config-value NODE_NVM_URL "file://$MIRROR/nvm"
}

# A nologin user gets an SFTP jail, which makes the home root-owned
# (v-add-user-sftp-jail); a server without the jail gets that state by hand
jail_home() {
    if [ "$(stat -c %U "/home/$1")" != root ]; then
        chown root:root "/home/$1"
    fi
}

cleanup() {
    for user in $USERS; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    for package in tn-dev tn-off tn-one tn-list tn-all tn-res tnres_tn-cust tn-jail; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
}

setup_file() {
    cleanup
    build_mirrors
    use_mirror "$MIRROR"
    rm -rf "$CACHE/v$V24" "$CACHE/v$V22" "$CACHE/index.json" "$CACHE/schedule.json"
    make_package tn-dev yes 3 lts
    make_package tn-off no 3 lts
    make_package tn-one yes 1 '*'
    make_package tn-list yes unlimited 22
    make_package tn-all yes unlimited '*'
    make_package tn-jail yes 3 lts nologin
    v-add-user tnu1 "$PASS" tnu1@example.invalid tn-dev > /dev/null
    v-add-user tnu2 "$PASS" tnu2@example.invalid tn-dev > /dev/null
    v-add-user tnu3 "$PASS" tnu3@example.invalid tn-one > /dev/null
    v-add-user tnoff "$PASS" tnoff@example.invalid tn-off > /dev/null
    v-add-user tnjail "$PASS" tnjail@example.invalid tn-jail > /dev/null
    jail_home tnjail
    export V24 V22
}

teardown_file() {
    cleanup
    sed -i "/^NODE_RELEASES_URL=/d; /^NODE_SCHEDULE_URL=/d; /^NODE_NVM_URL=/d" "$CONF"
}

setup() {
    V24=$(latest_of 24)
    V22=$(latest_of 22)
}

#----------------------------------------------------------#
#                     Setup and releases                   #
#----------------------------------------------------------#

@test "Setup: v-add-sys-node is idempotent" {
    run v-add-sys-node
    assert_success
    run v-add-sys-node
    assert_success
    assert_file_exists "$CACHE/release-keys.gpg"
    assert_file_exists "$PKG_DIR/developer.pkg"
    run grep -c "v-update-sys-node-releases" /var/spool/cron/crontabs/arcpanelweb
    assert_output "1"
    run v-list-package-tenancy developer json
    assert_success
    assert_output --partial '"NODE": "yes"'
    grep -q "^SHELL='bash'" "$PKG_DIR/developer.pkg"
}

@test "Releases: index from the mirror, majors with LTS and status" {
    run v-update-sys-node-releases
    assert_success
    run v-list-sys-node-releases json
    assert_success
    echo "$output" | jq -e '.RELEASES | length > 4'
    echo "$output" | jq -e --arg v "$V24" '.RELEASES[] | select(.MAJOR == "24") | .LATEST == $v and .LTS == "Krypton"'
    echo "$output" | jq -e '.RELEASES[] | select(.MAJOR == "18") | .STATUS == "eol"'
    run v-list-sys-node-releases
    assert_success
    assert_line --partial "MAJOR"
}

@test "Releases: an unreachable server keeps the old index" {
    v-change-sys-config-value NODE_RELEASES_URL "file:///nonexistent"
    run v-update-sys-node-releases
    assert_failure 15
    use_mirror "$MIRROR"
    assert_file_exists "$CACHE/index.json"
}

#----------------------------------------------------------#
#                         Package keys                     #
#----------------------------------------------------------#

@test "Package keys: validation" {
    run v-change-package-tenancy tn-dev NODE maybe
    assert_failure 2
    run v-change-package-tenancy tn-dev NODE_VERSIONS -1
    assert_failure 2
    run v-change-package-tenancy tn-dev NODE_ALLOWED '20;22'
    assert_failure 2
    run v-change-package-tenancy tn-dev NODE_ALLOWED '20,22'
    assert_success
    v-change-package-tenancy tn-dev NODE_ALLOWED lts
}

@test "Package keys: a reseller cannot grant more Node.js than it has" {
    make_package tn-res no 2 lts
    v-change-package-tenancy tn-res USERS 5
    v-change-package-tenancy tn-res RESELLER_PACKAGES '*'
    v-add-user tnres "$PASS" tnres@example.invalid tn-res > /dev/null
    v-change-user-role tnres reseller
    policy=$(grep "^POLICY_RESELLER_CREATE_PACKAGES=" "$CONF" | cut -d"'" -f2)
    v-change-sys-config-value POLICY_RESELLER_CREATE_PACKAGES yes
    tmp=$(mktemp)
    cp "$PKG_DIR/default.pkg" "$tmp"
    run v-add-reseller-package tnres "$tmp" tn-cust
    rm -f "$tmp"
    v-change-sys-config-value POLICY_RESELLER_CREATE_PACKAGES "${policy:-no}"
    assert_success
    run v-change-package-tenancy tnres_tn-cust NODE yes
    assert_failure 8
    assert_output --partial "NODE"
    v-change-package-tenancy tn-res NODE yes
    run v-change-package-tenancy tnres_tn-cust NODE yes
    assert_success
    run v-change-package-tenancy tnres_tn-cust NODE_VERSIONS 3
    assert_failure 8
    assert_output --partial "NODE_VERSIONS"
    run v-change-package-tenancy tnres_tn-cust NODE_VERSIONS 2
    assert_success
}

#----------------------------------------------------------#
#                           nvm                            #
#----------------------------------------------------------#

@test "nvm: refused when the package has NODE='no'" {
    run v-add-user-nvm tnoff
    assert_failure 11
    assert_dir_not_exists /home/tnoff/.nvm
}

@test "nvm: enable twice, shell block once" {
    run v-add-user-nvm tnu1
    assert_success
    run v-add-user-nvm tnu1
    assert_success
    assert_file_exists /home/tnu1/.nvm/nvm.sh
    run grep -c "# >>> arcpanel nvm >>>" /home/tnu1/.bashrc
    assert_output "1"
    run stat -c %U /home/tnu1/.nvm/nvm.sh
    assert_output "tnu1"
}

#----------------------------------------------------------#
#                        Runtimes                          #
#----------------------------------------------------------#

@test "Runtimes: refused before nvm is enabled" {
    run v-add-user-node-version tnu2 lts
    assert_failure 3
}

@test "Runtimes: install lts, then a major and an exact version" {
    run v-add-user-node-version tnu1 lts
    assert_success
    assert_output --partial "v$V24 installed"
    assert_dir_exists "/home/tnu1/.nvm/versions/node/v$V24"
    run cat /home/tnu1/.nvm/alias/default
    assert_output "v$V24"
    run v-add-user-node-version tnu1 "$V22"
    assert_success
    run v-add-user-node-version tnu1 22
    assert_failure 4
    run stat -c %U "/home/tnu1/.nvm/versions/node/v$V22/bin/node"
    assert_output "tnu1"
}

@test "Runtimes: the default runtime answers in a login shell; nvm use works" {
    run su - tnu1 -c 'bash -ic "node -v" 2> /dev/null'
    assert_output --partial "v$V24"
    run su - tnu1 -c "bash -ic 'nvm use $V22 > /dev/null; node -v' 2> /dev/null"
    assert_output --partial "v$V22"
}

@test "Runtimes: list shows versions, default, LTS and the package settings" {
    run v-list-user-node-versions tnu1 json
    assert_success
    echo "$output" | jq -e '.ENABLED == "yes" and .NODE == "yes" and .LIMIT == "3" and .COUNT == "2"'
    echo "$output" | jq -e --arg v "$V24" '.DEFAULT == $v'
    echo "$output" | jq -e --arg v "$V24" '.VERSIONS[] | select(.VERSION == $v) | .DEFAULT == "yes" and .LTS == "Krypton"'
    echo "$output" | jq -e '.VERSIONS[0].SIZE | tonumber > 10'
    run v-list-user-node-versions tnu1
    assert_success
    assert_line --partial "$V22"
}

@test "Runtimes: NODE_ALLOWED refuses other majors" {
    v-change-package-tenancy tn-dev NODE_ALLOWED 22
    run v-add-user-node-version tnu1 20
    assert_failure 10
    assert_output --partial "NODE_ALLOWED"
    v-change-package-tenancy tn-dev NODE_ALLOWED lts
    run v-add-user-node-version tnu1 18
    assert_failure 10
}

@test "Runtimes: NODE_VERSIONS limits the number of runtimes" {
    v-add-user-nvm tnu3
    run v-add-user-node-version tnu3 22
    assert_success
    run v-add-user-node-version tnu3 24
    assert_failure 8
    assert_output --partial "NODE_VERSIONS"
}

@test "Runtimes: invalid and unknown specs" {
    run v-add-user-node-version tnu1 'lts; id'
    assert_failure 2
    run v-add-user-node-version tnu1 99
    assert_failure 3
    run v-add-user-node-version tnu1 '24.999.0'
    assert_failure 3
}

@test "Runtimes: change the default and move it when the default is deleted" {
    run v-change-user-node-default tnu1 "$V22"
    assert_success
    run cat /home/tnu1/.nvm/alias/default
    assert_output "v$V22"
    run v-change-user-node-default tnu1 1.2.3
    assert_failure 3
    run v-delete-user-node-version tnu1 "$V22"
    assert_success
    assert_dir_not_exists "/home/tnu1/.nvm/versions/node/v$V22"
    run cat /home/tnu1/.nvm/alias/default
    assert_output "v$V24"
    run v-delete-user-node-version tnu1 "$V22"
    assert_failure 3
}

@test "Runtimes: a forged checksum list is refused and nothing is installed" {
    v-add-user-nvm tnu2
    v-delete-sys-node-cache "$V24" > /dev/null 2>&1 || true
    use_mirror "$BAD_MIRROR"
    run v-add-user-node-version tnu2 "$V24"
    use_mirror "$MIRROR"
    assert_failure 2
    assert_output --partial "signature"
    assert_dir_not_exists "/home/tnu2/.nvm/versions/node/v$V24"
    assert_dir_not_exists "$CACHE/v$V24"
}

@test "Runtimes: a damaged tarball is refused and nothing is installed" {
    v-delete-sys-node-cache "$V22" > /dev/null 2>&1 || true
    use_mirror "$BAD_MIRROR"
    run v-add-user-node-version tnu2 "$V22"
    use_mirror "$MIRROR"
    assert_failure 2
    assert_output --partial "checksum"
    assert_dir_not_exists "/home/tnu2/.nvm/versions/node/v$V22"
    run find "$CACHE" -maxdepth 1 -name '.fetch.*'
    assert_output ""
}

@test "Runtimes: two users install one version at the same time, one download" {
    v-delete-sys-node-cache "$V22" > /dev/null 2>&1 || true
    v-delete-user-node-version tnu1 "$V22" > /dev/null 2>&1 || true
    log=$(mktemp)
    v-add-user-node-version tnu1 "$V22" > "$log.1" 2>&1 &
    v-add-user-node-version tnu2 "$V22" > "$log.2" 2>&1 &
    wait
    cat "$log.1" "$log.2"
    assert_dir_exists "/home/tnu1/.nvm/versions/node/v$V22"
    assert_dir_exists "/home/tnu2/.nvm/versions/node/v$V22"
    [ "$(cat "$log.1" "$log.2" | grep -c '^Downloading')" -eq 1 ]
    rm -f "$log" "$log.1" "$log.2"
}

@test "Runtimes: v-rebuild-user keeps runtimes, default and shell block" {
    run v-rebuild-user tnu1
    assert_success
    run v-list-user-node-versions tnu1 json
    echo "$output" | jq -e --arg v "$V24" '.DEFAULT == $v and .SHELL == "yes" and .ENABLED == "yes"'
}

@test "Runtimes: backup, delete and restore keep runtimes and the default" {
    run v-backup-user tnu1
    assert_success
    backup=$(ls -t /backup/tnu1.*.tar | head -n1)
    run v-delete-user tnu1
    assert_success
    run v-restore-user tnu1 "$(basename "$backup")"
    assert_success
    run v-list-user-node-versions tnu1 json
    echo "$output" | jq -e --arg v "$V24" '.DEFAULT == $v and .SHELL == "yes" and .COUNT == "2"'
    run su - tnu1 -c 'bash -ic "node -v" 2> /dev/null'
    assert_output --partial "v$V24"
    rm -f /backup/tnu1.*.tar
}

@test "Runtimes: a package changed to NODE='no' keeps runtimes but refuses installs" {
    v-change-package-tenancy tn-dev NODE no
    run v-add-user-node-version tnu2 lts
    assert_failure 11
    run v-list-user-node-versions tnu2 json
    echo "$output" | jq -e '.NODE == "no" and (.COUNT | tonumber) >= 1'
    v-change-package-tenancy tn-dev NODE yes
}

#----------------------------------------------------------#
#                     Global packages                      #
#----------------------------------------------------------#

@test "Packages: invalid specs are refused" {
    for spec in 'https://example.invalid/x.tgz' 'git+ssh://example.invalid/x' '../x' 'file:x' 'a b' 'is-number@' '@scope' 'UPPER' 'x@1;id'; do
        run v-add-user-npm-package tnu1 "$V24" "$spec"
        assert_failure 2
    done
    run v-add-user-npm-package tnu1 1.2.3 is-number
    assert_failure 3
}

@test "Packages: install a package and a scoped package, list them" {
    run v-add-user-npm-package tnu1 "$V24" is-number@6.0.0
    assert_success
    run v-add-user-npm-package tnu1 "$V24" @sindresorhus/is@4.0.0
    assert_success
    run v-list-user-npm-packages tnu1 "$V24" json
    assert_success
    echo "$output" | jq -e '.PACKAGES[] | select(.NAME == "is-number") | .VERSION == "6.0.0" and .BUNDLED == "no" and .OUTDATED == ""'
    echo "$output" | jq -e '.PACKAGES[] | select(.NAME == "@sindresorhus/is") | .VERSION == "4.0.0"'
    echo "$output" | jq -e '.PACKAGES[] | select(.NAME == "npm") | .BUNDLED == "yes"'
    run stat -c %U "/home/tnu1/.nvm/versions/node/v$V24/lib/node_modules/is-number/package.json"
    assert_output "tnu1"
    run v-list-user-node-versions tnu1 json
    echo "$output" | jq -e --arg v "$V24" '.VERSIONS[] | select(.VERSION == $v) | .PACKAGES == "2"'
}

@test "Packages: outdated check, then update" {
    run v-check-user-npm-outdated tnu1 "$V24"
    assert_success
    run v-list-user-npm-packages tnu1 "$V24" json
    echo "$output" | jq -e '.CHECKED != ""'
    echo "$output" | jq -e '.PACKAGES[] | select(.NAME == "is-number") | .OUTDATED == "yes" and .LATEST != "6.0.0"'
    run v-update-user-npm-packages tnu1 "$V24"
    assert_success
    run v-list-user-npm-packages tnu1 "$V24" json
    echo "$output" | jq -e '.CHECKED == ""'
    echo "$output" | jq -e '.PACKAGES[] | select(.NAME == "is-number") | .VERSION != "6.0.0"'
}

@test "Packages: remove a package; npm cannot be removed" {
    run v-delete-user-npm-package tnu1 "$V24" @sindresorhus/is
    assert_success
    run v-delete-user-npm-package tnu1 "$V24" @sindresorhus/is
    assert_failure 3
    run v-delete-user-npm-package tnu1 "$V24" npm
    assert_failure 10
    run v-list-user-npm-packages tnu1 "$V24" json
    echo "$output" | jq -e '[.PACKAGES[] | select(.NAME == "@sindresorhus/is")] | length == 0'
}

@test "Packages: refused when the package has NODE='no'" {
    v-change-package-tenancy tn-dev NODE no
    run v-add-user-npm-package tnu1 "$V24" is-odd
    v-change-package-tenancy tn-dev NODE yes
    assert_failure 11
}

#----------------------------------------------------------#
#                          Tasks                           #
#----------------------------------------------------------#

wait_task() {
    local i
    for i in $(seq 1 300); do
        status=$(v-list-user-node-task "$1" 50 json | jq -r .STATUS)
        if [ "$status" != 'running' ]; then
            return 0
        fi
        sleep 1
    done
    return 1
}

@test "Tasks: an install runs in the background and its log can be read" {
    run v-start-user-node-task tnu1 add-package "$V24" is-odd
    assert_success
    [[ "$output" =~ ^[0-9]+$ ]]
    id=$output
    wait_task tnu1
    run v-list-user-node-task tnu1 100 json
    echo "$output" | jq -e --arg id "$id" '.ID == $id and .STATUS == "done" and .EXIT == "0" and .ACTION == "add-package"'
    echo "$output" | jq -e '.LOG | contains("Installing is-odd")'
    run v-list-user-npm-packages tnu1 "$V24" json
    echo "$output" | jq -e '[.PACKAGES[] | select(.NAME == "is-odd")] | length == 1'
}

@test "Tasks: a failing command marks the task failed" {
    run v-start-user-node-task tnu1 add-package "$V24" arcpanel-no-such-package-e13
    assert_success
    wait_task tnu1
    run v-list-user-node-task tnu1 100 json
    echo "$output" | jq -e '.STATUS == "failed" and .EXIT != "0"'
}

@test "Tasks: one task at a time; a task whose process died is failed" {
    bash -c 'exec -a v-run-user-node-task sleep 60' &
    pid=$!
    conf=$ARCPANEL/data/users/tnu1/node-task.conf
    echo "ID='1' ACTION='add-version' ARGS='lts' STATUS='running' PID='$pid' EXIT='' START='' END=''" > "$conf"
    run v-start-user-node-task tnu1 add-version lts
    assert_failure 7
    run v-delete-user-node-version tnu1 "$V24"
    assert_failure 7
    kill "$pid"
    wait "$pid" 2> /dev/null || true
    run v-list-user-node-task tnu1 10 json
    echo "$output" | jq -e '.STATUS == "failed"'
    run grep -c "STATUS='failed'" "$conf"
    assert_output "1"
}

@test "Tasks: arguments are checked before the start" {
    run v-start-user-node-task tnu1 add-version 'lts;id'
    assert_failure 2
    run v-start-user-node-task tnu1 add-package "$V24" 'file:../x'
    assert_failure 2
    run v-start-user-node-task tnu1 check-outdated 1.2.3
    assert_failure 3
    run v-start-user-node-task tnu1 rm-rf "$V24"
    assert_failure 2
    run v-start-user-node-task tnoff add-version lts
    assert_failure 11
    run v-list-user-node-task tnu1 0
    assert_failure 2
}

@test "Tasks: the runner refuses a task it was not given" {
    run v-run-user-node-task 42 tnu1 add-version lts
    assert_failure 10
}

@test "nvm: remove deletes ~/.nvm and the shell block" {
    run v-delete-user-nvm tnu3
    assert_success
    assert_dir_not_exists /home/tnu3/.nvm
    run grep -c "# >>> arcpanel nvm >>>" /home/tnu3/.bashrc
    assert_output "0"
    run v-delete-user-nvm tnu3
    assert_failure 3
}

#----------------------------------------------------------#
#                 Root-owned home (SFTP jail)              #
#----------------------------------------------------------#

@test "Jail: a user with a root-owned home enables Node.js and installs a runtime" {
    assert_equal "$(stat -c %U /home/tnjail)" "root"
    run v-add-user-nvm tnjail
    assert_success
    assert_equal "$(stat -c %U:%G /home/tnjail/.nvm)" "tnjail:tnjail"
    assert_equal "$(stat -c %U /home/tnjail/.nvm/nvm.sh)" "tnjail"
    assert_equal "$(stat -c %U /home/tnjail)" "root"
    run v-add-user-node-version tnjail lts
    assert_success
    assert_equal "$(stat -c %U "/home/tnjail/.nvm/versions/node/v$V24/bin/node")" "tnjail"
}

@test "Jail: removing Node.js deletes ~/.nvm from a root-owned home" {
    run v-delete-user-nvm tnjail
    assert_success
    assert_dir_not_exists /home/tnjail/.nvm
    assert_equal "$(stat -c %U /home/tnjail)" "root"
}

@test "Jail: a symbolic link at ~/.nvm is refused" {
    mkdir -p /root/tn-elsewhere
    ln -s /root/tn-elsewhere /home/tnjail/.nvm
    run v-add-user-nvm tnjail
    rm -f /home/tnjail/.nvm
    assert_failure 10
    run ls -A /root/tn-elsewhere
    rmdir /root/tn-elsewhere
    assert_output ""
}

@test "Cache: delete one version and all versions" {
    v-add-user-node-version tnu2 "$V24" > /dev/null
    run v-delete-sys-node-cache "$V24"
    assert_success
    assert_dir_not_exists "$CACHE/v$V24"
    run v-delete-sys-node-cache 1.2.3
    assert_failure 3
    run v-delete-sys-node-cache
    assert_success
    run v-list-sys-node-releases json
    echo "$output" | jq -e '[.RELEASES[] | select(.CACHED == "yes")] | length == 0'
}
