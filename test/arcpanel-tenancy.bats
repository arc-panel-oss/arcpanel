#!/usr/bin/env bats
# ArcPanel resellers (E11,
# docs/features/specs/2026-10-05-003-e11-reseller-design.md).
# Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
RESELLER=tnres
RESELLER2=tnres2
PASS="Tn-$(date +%s)-Pass"
ROOT_USER=$(grep "^ROOT_USER=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)

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
    for package in tn-plan tn-small tn-big tn-hidden tn-renamed "${RESELLER}_own" "${RESELLER}_rp"; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
}

# Policies the cases change, saved by setup_file and restored by teardown_file
POLICIES="POLICY_RESELLER_CREATE_PACKAGES POLICY_RESELLER_PACKAGE_SHELLS POLICY_USER_EDIT_WEB_TEMPLATES POLICY_USER_EDIT_DNS_TEMPLATES"

setup_file() {
    for key in $POLICIES; do
        echo "$key=$(grep "^$key=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)"
    done > "$BATS_FILE_TMPDIR/policies"
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
    while IFS='=' read -r key value; do
        if [ -z "$value" ]; then
            [ "$key" = 'POLICY_RESELLER_PACKAGE_SHELLS' ] && value='nologin' || value='no'
        fi
        v-change-sys-config-value "$key" "$value"
    done < "$BATS_FILE_TMPDIR/policies"
}

@test "Role reseller is accepted" {
    run v-change-user-role "$RESELLER" reseller
    assert_success
    run v-change-user-role "$RESELLER2" reseller
    assert_success
    run grep "^ROLE='reseller'" "$ARCPANEL/data/users/$RESELLER/user.conf"
    assert_success
}

@test "Reseller policies are listed" {
    run v-list-sys-config json
    assert_success
    assert_output --partial '"POLICY_RESELLER_CREATE_PACKAGES"'
    assert_output --partial '"POLICY_RESELLER_LOGIN_AS"'
    assert_output --partial '"POLICY_RESELLER_PACKAGE_SHELLS"'
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
    run cat "$ARCPANEL/data/users/tnc1/tenancy.conf"
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
    run grep "^R_USERS=" "$ARCPANEL/data/users/$RESELLER/tenancy.conf"
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
    run grep "^OWNER=" "$ARCPANEL/data/users/tnc1/tenancy.conf"
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
    run grep "^R_USERS=" "$ARCPANEL/data/users/$RESELLER/tenancy.conf"
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
    run v-delete-reseller-package "$RESELLER2" "${RESELLER}_own"
    assert_failure 10
    run v-delete-reseller-package "$RESELLER" "${RESELLER}_own"
    assert_success
    assert_file_not_exists "$PKG_DIR/${RESELLER}_own.tenancy"
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
    run grep -c "v-update-reseller-counters" /var/spool/cron/crontabs/arcpanelweb
    assert_output "1"
}

# The limit table shared with the panel's Pest suite (E39 W4 spec §8.2)
limit_table() {
    local file
    for file in "$BATS_TEST_DIRNAME/../web-app/tests/Fixtures/package-limits.tsv" "$ARCPANEL/web-app/tests/Fixtures/package-limits.tsv"; do
        if [ -f "$file" ]; then
            echo "$file"
            return 0
        fi
    done
    return 1
}

@test "Limits of reseller packages compare after their units" {
    table=$(limit_table)
    # shellcheck source=/usr/local/arcpanel/func/main.sh
    source "$ARCPANEL/func/main.sh"
    # shellcheck source=/usr/local/arcpanel/func/tenancy.sh
    source "$ARCPANEL/func/tenancy.sh"
    failures='' cases=0
    while IFS=$'\t' read -r key value limit expected; do
        if [ -z "$key" ] || [[ "$key" == \#* ]]; then
            continue
        fi
        if tenancy_pkg_tenancy_above "$key" "$value" "$limit"; then got=above; else got=within; fi
        if [ "$got" != "$expected" ]; then
            failures+="$key '$value' against '$limit': $got, expected $expected"$'\n'
        fi
        cases=$((cases + 1))
    done < "$table"
    echo "$failures"
    [ -z "$failures" ]
    [ "$cases" -gt 40 ]
}

@test "Reseller packages keep to the shells, templates and units of the plan" {
    v-change-sys-config-value POLICY_RESELLER_CREATE_PACKAGES yes
    v-change-sys-config-value POLICY_RESELLER_PACKAGE_SHELLS nologin
    v-change-sys-config-value POLICY_USER_EDIT_WEB_TEMPLATES no
    v-change-sys-config-value POLICY_USER_EDIT_DNS_TEMPLATES no
    # The reseller's plan: 1G of memory, no incremental backups, 1G per Docker app
    sed -i -e "s/^MEMORY_LIMIT=.*/MEMORY_LIMIT='1G'/" -e "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='no'/" "$PKG_DIR/tn-plan.pkg"
    v-update-user-package tn-plan > /dev/null
    v-change-package-tenancy tn-plan DOCKER_MEMORY 1G
    package="${RESELLER}_rp"
    tmp=$(mktemp)
    sed -e "s/^WEB_DOMAINS=.*/WEB_DOMAINS='1'/" -e "s/^MEMORY_LIMIT=.*/MEMORY_LIMIT='512M'/" -e "s/^SHELL=.*/SHELL='bash'/" \
        "$PKG_DIR/tn-small.pkg" > "$tmp"

    # Shells from POLICY_RESELLER_PACKAGE_SHELLS; the one a package holds may stay
    run v-add-reseller-package "$RESELLER" "$tmp" rp
    assert_failure 8
    assert_output --partial 'SHELL'
    v-change-sys-config-value POLICY_RESELLER_PACKAGE_SHELLS 'nologin,bash'
    run v-add-reseller-package "$RESELLER" "$tmp" rp
    assert_success
    v-change-sys-config-value POLICY_RESELLER_PACKAGE_SHELLS nologin
    run v-change-reseller-package "$RESELLER" "$tmp" "$package"
    assert_success
    sed -i "s/^SHELL=.*/SHELL='sh'/" "$tmp"
    run v-change-reseller-package "$RESELLER" "$tmp" "$package"
    assert_failure 8
    assert_output --partial 'SHELL'
    sed -i "s/^SHELL=.*/SHELL='nologin'/" "$tmp"

    # Units: nothing above 1G, and nothing that cannot be compared
    for value in 2048M 2G 1073741825 unlimited 1g; do
        sed -i "s/^MEMORY_LIMIT=.*/MEMORY_LIMIT='$value'/" "$tmp"
        run v-change-reseller-package "$RESELLER" "$tmp" "$package"
        assert_failure 8
        assert_output --partial 'MEMORY_LIMIT'
    done
    sed -i "s/^MEMORY_LIMIT=.*/MEMORY_LIMIT='1024M'/" "$tmp"
    run v-change-reseller-package "$RESELLER" "$tmp" "$package"
    assert_success
    sed -i "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='yes'/" "$tmp"
    run v-change-reseller-package "$RESELLER" "$tmp" "$package"
    assert_failure 8
    assert_output --partial 'BACKUPS_INCREMENTAL'
    sed -i "s/^BACKUPS_INCREMENTAL=.*/BACKUPS_INCREMENTAL='no'/" "$tmp"

    # Templates are the reseller's own while users may not choose them
    other=$(v-list-web-templates-proxy plain | cut -f1 | grep -vx default | head -n1)
    [ -n "$other" ]
    sed -i "s/^PROXY_TEMPLATE=.*/PROXY_TEMPLATE='$other'/" "$tmp"
    run v-change-reseller-package "$RESELLER" "$tmp" "$package"
    assert_failure 8
    assert_output --partial 'PROXY_TEMPLATE'
    v-change-sys-config-value POLICY_USER_EDIT_WEB_TEMPLATES yes
    run v-change-reseller-package "$RESELLER" "$tmp" "$package"
    assert_success
    sed -i "s/^PROXY_TEMPLATE=.*/PROXY_TEMPLATE='default'/" "$tmp"
    v-change-reseller-package "$RESELLER" "$tmp" "$package"
    rm -f "$tmp"

    # Sidecar keys: only what the reseller's own package allows
    run v-change-package-tenancy "$package" NODE_ALLOWED '18,20'
    assert_failure 8
    run v-change-package-tenancy "$package" NODE_ALLOWED lts
    assert_success
    run v-change-package-tenancy "$package" DOCKER_MEMORY 2048
    assert_failure 8
    run v-change-package-tenancy "$package" DOCKER_MEMORY 512M
    assert_success
}

@test "The 2.1.3 upgrade step reports reseller packages beyond the plan" {
    package="${RESELLER}_rp"
    # As a package written before 2.1.3 may be
    sed -i "s/^SHELL=.*/SHELL='bash'/" "$PKG_DIR/$package.pkg"
    sed -i "s/^DOCKER_MEMORY=.*/DOCKER_MEMORY='4G'/" "$PKG_DIR/$package.tenancy"

    run bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh
        add_upgrade_message() { echo \"MESSAGE: \$1\"; }
        source $ARCPANEL/install/upgrade/versions/2.1.3.sh"
    assert_success
    assert_output --partial "Reseller package $package of $RESELLER: SHELL goes beyond"
    assert_output --partial "Reseller package $package of $RESELLER: DOCKER_MEMORY goes beyond"
    refute_output --partial "Reseller package $package of $RESELLER: MEMORY_LIMIT"
    assert_output --partial 'MESSAGE: Warning: some packages of resellers'
    # Nothing is changed
    run grep -c "^SHELL='bash'" "$PKG_DIR/$package.pkg"
    assert_output '1'
}
