#!/usr/bin/env bats
# ArcPanel resellers (E11,
# docs/features/specs/2026-10-05-003-e11-reseller-design.md).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

HESTIA=/usr/local/hestia
PKG_DIR=$HESTIA/data/packages
RESELLER=tnres
RESELLER2=tnres2
PASS="Tn-$(date +%s)-Pass"
ROOT_USER=$(grep "^ROOT_USER=" "$HESTIA/conf/hestia.conf" | cut -d"'" -f2)

# A package with every quota key finite
make_package() {
    local name=$1 amount=$2 tmp
    tmp=$(mktemp)
    sed -e "s/^\(WEB_DOMAINS\|DNS_DOMAINS\|MAIL_DOMAINS\|MAIL_ACCOUNTS\|DATABASES\|CRON_JOBS\)=.*/\1='$amount'/" \
        -e "s/^DISK_QUOTA=.*/DISK_QUOTA='$((amount * 1000))'/" \
        -e "s/^BANDWIDTH=.*/BANDWIDTH='$((amount * 1000))'/" \
        "$PKG_DIR/default.pkg" > "$tmp"
    v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
    rm -f "$tmp"
}

cleanup() {
    for user in tnc1 tnc2 tnc3 tnc4 "$RESELLER" "$RESELLER2"; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    for package in tn-plan tn-small tn-big tn-hidden tn-renamed "${RESELLER}_own"; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
}

setup_file() {
    cleanup
    make_package tn-plan 5
    make_package tn-small 2
    make_package tn-big 10
    make_package tn-hidden 1
    v-change-package-tenancy tn-plan USERS 2
    v-change-package-tenancy tn-plan RESELLER_PACKAGES 'tn-small,tn-big'
    v-add-user "$RESELLER" "$PASS" "$RESELLER@example.invalid" tn-plan > /dev/null
    v-add-user "$RESELLER2" "$PASS" "$RESELLER2@example.invalid" tn-plan > /dev/null
    v-change-sys-config-value POLICY_RESELLER_CREATE_PACKAGES no
}

teardown_file() {
    cleanup
    v-change-sys-config-value POLICY_RESELLER_CREATE_PACKAGES no
}

@test "Role reseller is accepted" {
    run v-change-user-role "$RESELLER" reseller
    assert_success
    run v-change-user-role "$RESELLER2" reseller
    assert_success
    run grep "^ROLE='reseller'" "$HESTIA/data/users/$RESELLER/user.conf"
    assert_success
}

@test "Reseller policies are listed" {
    run v-list-sys-config json
    assert_success
    assert_output --partial '"POLICY_RESELLER_CREATE_PACKAGES"'
    assert_output --partial '"POLICY_RESELLER_LOGIN_AS"'
}

@test "Package tenancy has defaults and validates values" {
    run v-list-package-tenancy tn-small json
    assert_success
    assert_output --partial '"USERS": "0"'
    assert_output --partial '"RESELLER_VISIBLE": "no"'
    run v-change-package-tenancy tn-small USERS lots
    assert_failure 2
    run v-change-package-tenancy tn-small OWNER "$RESELLER"
    assert_failure 2
}

@test "Reseller lists the packages it may assign" {
    run v-list-reseller-packages "$RESELLER" plain
    assert_success
    assert_line --partial "tn-small"
    assert_line --partial "tn-big"
    refute_output --partial "tn-hidden"
    refute_output --partial "tn-plan"
}

@test "Only a reseller can add customers" {
    run v-add-reseller-user "$ROOT_USER" tnc9 "$PASS" tnc9@example.invalid tn-small
    assert_failure
}

@test "Reseller adds a customer with an assignable package" {
    run v-add-reseller-user "$RESELLER" tnc1 "$PASS" tnc1@example.invalid tn-small
    assert_success
    run cat "$HESTIA/data/users/tnc1/tenancy.conf"
    assert_output --partial "OWNER='$RESELLER'"
}

@test "A package that is not assignable is refused" {
    run v-add-reseller-user "$RESELLER" tnc2 "$PASS" tnc2@example.invalid tn-hidden
    assert_failure 10
    run v-list-user tnc2
    assert_failure
}

@test "A package that does not fit is refused with the key" {
    run v-check-reseller-quota "$RESELLER" tn-big
    assert_failure 8
    assert_output "WEB_DOMAINS"
    run v-add-reseller-user "$RESELLER" tnc2 "$PASS" tnc2@example.invalid tn-big
    assert_failure 8
    assert_output --partial "WEB_DOMAINS"
}

@test "Second customer fits, third is refused by USERS" {
    run v-add-reseller-user "$RESELLER" tnc2 "$PASS" tnc2@example.invalid tn-small
    assert_success
    run v-check-reseller-quota "$RESELLER" tn-small
    assert_failure 8
    assert_output "USERS"
    run v-add-reseller-user "$RESELLER" tnc3 "$PASS" tnc3@example.invalid tn-small
    assert_failure 8
}

@test "Reseller lists its customers only" {
    run v-list-reseller-users "$RESELLER" plain
    assert_success
    assert_line --partial "tnc1"
    assert_line --partial "tnc2"
    run v-list-reseller-users "$RESELLER2" plain
    assert_success
    assert_output ""
    run v-list-reseller-users "$RESELLER" json
    assert_success
    assert_output --partial "\"OWNER\": \"$RESELLER\""
}

@test "Owners are resolved for every user" {
    run v-list-user-owners plain
    assert_success
    assert_line --regexp "^tnc1"$'\t'"user"$'\t'"$RESELLER"
    assert_line --regexp "^$RESELLER"$'\t'"reseller"$'\t'
}

@test "Counters sum the tree" {
    run v-update-reseller-counters "$RESELLER"
    assert_success
    run grep "^R_USERS=" "$HESTIA/data/users/$RESELLER/tenancy.conf"
    assert_output "R_USERS='2'"
}

@test "Package change of a customer is checked against the plan" {
    run v-change-reseller-user-package "$RESELLER" tnc1 tn-big
    assert_failure 8
    run v-change-reseller-user-package "$RESELLER2" tnc1 tn-small
    assert_failure 10
    run v-change-reseller-user-package "$RESELLER" tnc1 tn-small
    assert_success
}

@test "Sidecar survives a package change and a rebuild" {
    run v-change-user-package tnc1 tn-small
    assert_success
    run v-rebuild-user tnc1
    assert_success
    run grep "^OWNER=" "$HESTIA/data/users/tnc1/tenancy.conf"
    assert_output "OWNER='$RESELLER'"
}

@test "Owner change refuses nested resellers and full plans" {
    run v-change-user-owner "$RESELLER2" "$RESELLER"
    assert_failure 10
    run v-change-user-owner tnc2 tnc1
    assert_failure 2
    v-add-user tnc3 "$PASS" tnc3@example.invalid tn-small > /dev/null
    run v-change-user-owner tnc3 "$RESELLER"
    assert_failure 8
}

@test "Owner change moves a customer and updates both counters" {
    run v-change-user-owner tnc2 "$RESELLER2"
    assert_success
    run v-list-reseller-users "$RESELLER2" plain
    assert_line --partial "tnc2"
    run grep "^R_USERS=" "$HESTIA/data/users/$RESELLER/tenancy.conf"
    assert_output "R_USERS='1'"
    run v-change-user-owner tnc2 "$ROOT_USER"
    assert_success
    run v-list-user-owners plain
    assert_line --regexp "^tnc2"$'\t'"user"$'\t'"$ROOT_USER"
}

@test "Reseller packages need the policy and fit inside the plan" {
    tmp=$(mktemp)
    sed -e "s/^WEB_DOMAINS=.*/WEB_DOMAINS='3'/" "$PKG_DIR/tn-small.pkg" > "$tmp"
    run v-add-reseller-package "$RESELLER" "$tmp" own
    assert_failure 11
    v-change-sys-config-value POLICY_RESELLER_CREATE_PACKAGES yes
    run v-add-reseller-package "$RESELLER" "$tmp" own
    assert_success
    run v-list-reseller-packages "$RESELLER" plain
    assert_line --partial "${RESELLER}_own"
    run v-list-reseller-packages "$RESELLER2" plain
    refute_output --partial "${RESELLER}_own"
    sed -i -e "s/^WEB_DOMAINS=.*/WEB_DOMAINS='50'/" "$tmp"
    run v-change-reseller-package "$RESELLER" "$tmp" "${RESELLER}_own"
    assert_failure 8
    run v-change-reseller-package "$RESELLER2" "$tmp" "${RESELLER}_own"
    assert_failure 10
    rm -f "$tmp"
}

@test "Package rename and delete keep the sidecar in step" {
    v-change-package-tenancy tn-plan RESELLER_PACKAGES 'tn-small,tn-hidden'
    run v-rename-user-package tn-hidden tn-renamed
    assert_success
    run v-rename-package-tenancy tn-hidden tn-renamed
    assert_success
    run v-list-package-tenancy tn-plan json
    assert_output --partial '"RESELLER_PACKAGES": "tn-small,tn-renamed"'
    v-change-package-tenancy tn-renamed USERS 3
    run v-delete-user-package tn-renamed
    assert_success
    run v-delete-package-tenancy tn-renamed
    assert_success
    assert_file_not_exists "$PKG_DIR/tn-renamed.tenancy"
}

@test "Orphans are reported" {
    v-add-reseller-user "$RESELLER2" tnc4 "$PASS" tnc4@example.invalid tn-small > /dev/null
    v-change-user-role "$RESELLER2" user
    run v-update-reseller-counters all
    assert_success
    assert_output --partial "Orphan: user tnc4"
    run v-list-user-owners plain
    assert_line --regexp "^tnc4"$'\t'"user"$'\t'"$ROOT_USER"
}

@test "Tenancy setup is idempotent" {
    run v-add-sys-tenancy
    assert_success
    run v-add-sys-tenancy
    assert_success
    assert_file_exists "$PKG_DIR/reseller.pkg"
    run grep -c "v-update-reseller-counters" /var/spool/cron/crontabs/hestiaweb
    assert_output "1"
}
