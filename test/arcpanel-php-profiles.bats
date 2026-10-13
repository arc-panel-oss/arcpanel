#!/usr/bin/env bats
# PHP Manager: PHP-FPM profiles, packages and domains (E41 M4,
# docs/features/specs/2026-10-12-001-e41-php-manager-design.md §7).
# Run as root on a test server with PHP 8.4 and 8.5 (the default) installed
# and a PHP-FPM backend.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
TPL_DIR=$ARCPANEL/data/templates/web/php-fpm
V=8.4
DEFAULT_V=8.5
PASS="Tt-e41-m4-Pass"
U1=e41pu1
R1=e41pr1
P1=e41-plan
RP=e41-rplan
RC=e41-rcust
D1=e41p1.example
D2=e41p2.example
D3=e41p3.example
SAVE=/root/arcbats-php-profiles

# Run a function of func/php.sh in a fresh shell
php_lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source \$ARCPANEL/func/main.sh; source \$ARCPANEL/func/php.sh; source_conf \$ARCPANEL/conf/arcpanel.conf; $*"
}

pool() {
    find /etc/php/*/fpm/pool.d -name "$1.conf" | head -n1
}

make_package() {
    local tmp
    tmp=$(mktemp)
    cp "$PKG_DIR/default.pkg" "$tmp"
    v-add-user-package "$tmp" "$1" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$1" > /dev/null
    rm -f "$tmp"
}

profile_file() {
    printf '%s\n' "$@" > "$BATS_TEST_TMPDIR/profile"
    echo "$BATS_TEST_TMPDIR/profile"
}

cleanup() {
    for user in "$U1" "$R1"; do
        v-delete-user "$user" yes > /dev/null 2>&1 || true
    done
    for package in "$P1" "$RP" "$RC"; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
    for profile in arcbats arcbats2; do
        v-delete-sys-php-profile "$profile" yes > /dev/null 2>&1 || true
    done
}

setup_file() {
    cleanup
    # what the upgrade of the arcpanel package runs
    v-add-sys-php-profiles > /dev/null
    mkdir -p "$SAVE"
    cp -p "$ARCPANEL/data/php/profiles.conf" "$SAVE/" 2> /dev/null || true
    cp -p "$ARCPANEL/conf/arcpanel.conf" "$SAVE/"
    make_package "$P1"
    make_package "$RP"
    make_package "$RC"
    v-add-user "$U1" "$PASS" "$U1@example.invalid" "$P1" > /dev/null
    v-add-web-domain "$U1" "$D1" > /dev/null
    v-add-web-domain "$U1" "$D2" > /dev/null
    v-add-user "$R1" "$PASS" "$R1@example.invalid" "$RP" > /dev/null
    v-change-user-role "$R1" reseller > /dev/null
    echo "RESELLER_OWNER='$R1'" >> "$PKG_DIR/$RC.tenancy"
}

teardown_file() {
    cp -p "$SAVE/arcpanel.conf" "$ARCPANEL/conf/arcpanel.conf"
    cleanup
    if [ -f "$SAVE/profiles.conf" ]; then
        cp -p "$SAVE/profiles.conf" "$ARCPANEL/data/php/profiles.conf"
    fi
    rm -rf "$SAVE"
}

#----------------------------------------------------------#
#                       Profiles                           #
#----------------------------------------------------------#

@test "PHP profiles: the shipped profiles and their pool lines" {
    run bash -c "v-list-sys-php-profiles json | jq -r 'keys | join(\" \")'"
    assert_output 'default large medium small'
    run bash -c "v-list-sys-php-profile default json | jq -r .BLOCK"
    assert_output "$(printf '%s\n' 'pm = ondemand' 'pm.max_children = 8' 'pm.max_requests = 4000' 'pm.process_idle_timeout = 10s')"
    run bash -c "v-list-sys-php-profile medium json | jq -r .BLOCK"
    assert_output "$(printf '%s\n' 'pm = dynamic' 'pm.max_children = 20' 'pm.start_servers = 4' \
        'pm.min_spare_servers = 2' 'pm.max_spare_servers = 6' 'pm.max_requests = 1000' \
        'request_terminate_timeout = 300s' 'php_admin_value[memory_limit] = 512M' \
        'php_admin_value[upload_max_filesize] = 128M' 'php_admin_value[post_max_size] = 128M' \
        'php_admin_value[max_execution_time] = 300' 'php_admin_value[max_input_vars] = 5000')"
    run v-list-sys-php-profile nosuch
    assert_failure 3
    for tpl in default socket "PHP-${V/./_}"; do
        run grep -c '^%php_profile%$' "$TPL_DIR/$tpl.tpl"
        assert_output 1
    done
}

@test "v-add-sys-php-profiles: an upgraded server keeps byte-identical pools" {
    tpl="$TPL_DIR/default.tpl"
    cp -p "$tpl" "$SAVE/default.tpl"
    # the template as ArcPanel 2.0.0 shipped it
    sed -i 's/^%php_profile%$/pm = ondemand\npm.max_children = 8\npm.max_requests = 4000\npm.process_idle_timeout = 10s/' "$tpl"
    run grep -c '%php_profile%' "$tpl"
    assert_output 0
    v-rebuild-web-domain "$U1" "$D1" no > /dev/null
    cp "$(pool "$D1")" "$SAVE/before.conf"
    run v-add-sys-php-profiles
    assert_success
    run grep -c '^%php_profile%$' "$tpl"
    assert_output 1
    v-rebuild-web-domain "$U1" "$D1" no > /dev/null
    run cmp "$SAVE/before.conf" "$(pool "$D1")"
    assert_success
    run v-add-sys-php-profiles
    assert_success
    run cmp "$tpl" "$SAVE/default.tpl"
    assert_success
}

@test "v-add-sys-php-profile: invalid profiles are refused" {
    run v-add-sys-php-profile 'Bad_Name' "$(profile_file "PM='ondemand'" "MAX_CHILDREN='4'" "MAX_REQUESTS='100'")"
    assert_failure 2
    run v-add-sys-php-profile default "$(profile_file "PM='ondemand'" "MAX_CHILDREN='4'" "MAX_REQUESTS='100'")"
    assert_failure 4
    for case in "PM='forking' MAX_CHILDREN='4' MAX_REQUESTS='0'" \
        "PM='static' MAX_CHILDREN='0' MAX_REQUESTS='0'" \
        "PM='static' MAX_CHILDREN='501' MAX_REQUESTS='0'" \
        "PM='dynamic' MAX_CHILDREN='10' START_SERVERS='5' MIN_SPARE='6' MAX_SPARE='8' MAX_REQUESTS='0'" \
        "PM='dynamic' MAX_CHILDREN='10' START_SERVERS='5' MIN_SPARE='2' MAX_SPARE='12' MAX_REQUESTS='0'" \
        "PM='ondemand' MAX_CHILDREN='4' MAX_REQUESTS='0' IDLE_TIMEOUT='10'" \
        "PM='static' MAX_CHILDREN='4' MAX_REQUESTS='200000'" \
        "PM='static' MAX_CHILDREN='4' MAX_REQUESTS='0' UPLOAD_MAX_FILESIZE='64M' POST_MAX_SIZE='32M'" \
        "PM='static' MAX_CHILDREN='4' MAX_REQUESTS='0' MEMORY_LIMIT='lots'" \
        "PM='static' MAX_CHILDREN='4' MAX_REQUESTS='0' NOSUCH='1'"; do
        # shellcheck disable=SC2086
        run v-add-sys-php-profile arcbats "$(profile_file $case)"
        assert_failure 2
    done
    run v-list-sys-php-profile arcbats
    assert_failure 3
}

@test "v-add-sys-php-profile: adds a profile and warns about memory" {
    run v-add-sys-php-profile arcbats "$(profile_file "DESCRIPTION='BATS profile'" "PM='static'" "MAX_CHILDREN='3'" \
        "MAX_REQUESTS='300'" "MEMORY_LIMIT='192M'" "START_SERVERS='9'")"
    assert_success
    run bash -c "v-list-sys-php-profile arcbats json | jq -r '[.PM, .MAX_CHILDREN, .START_SERVERS, .BUILTIN, .DOMAINS] | join(\" \")'"
    assert_output 'static 3  no 0'
    run v-add-sys-php-profile arcbats2 "$(profile_file "PM='static'" "MAX_CHILDREN='500'" "MAX_REQUESTS='0'" "MEMORY_LIMIT='4096G'")"
    assert_success
    assert_output --partial 'more than the memory of the server'
}

#----------------------------------------------------------#
#                  Domains and packages                    #
#----------------------------------------------------------#

@test "v-change-web-domain-php: the package decides the version and the profile" {
    run v-change-web-domain-php "$U1" "$D1" $V large
    assert_failure 10
    assert_output --partial 'profile large is not allowed'
    run v-change-package-tenancy "$P1" PHP_PROFILES '*'
    assert_success
    run v-change-web-domain-php "$U1" "$D1" $V large
    assert_success
    run grep "DOMAIN='$D1'" "$ARCPANEL/data/users/$U1/web.conf"
    assert_output --partial "BACKEND='PHP-${V/./_}'"
    run pool "$D1"
    assert_output "/etc/php/$V/fpm/pool.d/$D1.conf"
    run grep -E '^(pm|pm.max_children|php_admin_value\[memory_limit\]) =' "$(pool "$D1")"
    assert_output "$(printf '%s\n' 'pm = dynamic' 'pm.max_children = 50' 'php_admin_value[memory_limit] = 1024M')"
    run bash -c "v-list-web-domain-php $U1 $D1 json | jq -r '[.VERSION, .PROFILE, .PROFILE_APPLIED, .ALLOWED, .EFFECTIVE.memory_limit.VALUE, .EFFECTIVE.memory_limit.SOURCE] | join(\" \")'"
    assert_output "$V large yes yes 1024M profile"
    # the php.ini page counts the domains whose profile overrides a directive
    run bash -c "v-list-sys-php-ini $V fpm json | jq -r '.VALUES.memory_limit.PROFILE_OVERRIDES, .VALUES.display_errors.PROFILE_OVERRIDES'"
    assert_output "$(printf '1\n0')"
    run systemctl is-active php$V-fpm
    assert_output 'active'
    run v-change-web-domain-php "$U1" "$D1" 7.4 large
    assert_failure 3
    run v-change-web-domain-php "$U1" "$D1" $V nosuch
    assert_failure 3
    run v-change-web-domain-php "$U1" "$D1" '8.4;id' large
    assert_failure 2
}

@test "v-change-sys-php-profile: rewrites every pool that uses the profile" {
    run v-change-web-domain-php "$U1" "$D1" $V arcbats
    assert_success
    run v-change-web-domain-php "$U1" "$D2" $DEFAULT_V arcbats
    assert_success
    run bash -c "v-list-sys-php-profile arcbats json | jq -r .DOMAINS"
    assert_output 2
    run v-change-sys-php-profile arcbats "$(profile_file "PM='static'" "MAX_CHILDREN='7'" "MAX_REQUESTS='300'" "MEMORY_LIMIT='160M'")"
    assert_success
    for d in "$D1" "$D2"; do
        run grep -E '^pm.max_children =' "$(pool "$d")"
        assert_output 'pm.max_children = 7'
    done
    run systemctl is-active php$V-fpm php$DEFAULT_V-fpm
    assert_output "$(printf 'active\nactive')"
}

@test "v-change-sys-php-profile: a version that stops is rolled back" {
    before=$(grep -E '^pm.max_children =' "$(pool "$D1")")
    ARC_PHP_TEST_INACTIVE=$V ARC_PHP_HEALTH_DELAY=1 run v-change-sys-php-profile arcbats \
        "$(profile_file "PM='static'" "MAX_CHILDREN='9'" "MAX_REQUESTS='300'")"
    assert_failure 20
    run grep -E '^pm.max_children =' "$(pool "$D1")"
    assert_output "$before"
    run bash -c "v-list-sys-php-profile arcbats json | jq -r .MAX_CHILDREN"
    assert_output 7
}

@test "v-delete-sys-php-profile: in use, forced and the default profile" {
    v-change-package-tenancy "$P1" PHP_PROFILES 'default,arcbats'
    run v-delete-sys-php-profile default
    assert_failure 10
    run v-delete-sys-php-profile arcbats
    assert_failure 7
    assert_output --partial "$U1/$D1"
    assert_output --partial "$P1"
    run v-delete-sys-php-profile arcbats yes
    assert_success
    run v-list-sys-php-profile arcbats
    assert_failure 3
    run grep -E '^pm =' "$(pool "$D1")"
    assert_output 'pm = ondemand'
    run bash -c "v-list-web-domain-php $U1 $D2 json | jq -r .PROFILE"
    assert_output 'default'
    run grep "^PHP_PROFILES=" "$PKG_DIR/$P1.tenancy"
    assert_output "PHP_PROFILES='default'"
}

@test "Packages: allowed versions, grandfathered domains and the default profile" {
    run v-change-package-tenancy "$P1" PHP_VERSIONS '8.4;id'
    assert_failure 2
    run v-change-package-tenancy "$P1" PHP_VERSIONS '9.9'
    assert_failure 2
    # the package's backend template uses the default version
    run v-change-package-tenancy "$P1" PHP_VERSIONS "$V"
    assert_failure 2
    run v-change-package-tenancy "$P1" PHP_VERSIONS "$V,$DEFAULT_V"
    assert_success
    run v-change-web-domain-php "$U1" "$D1" $V default
    assert_success
    run v-change-package-tenancy "$P1" PHP_VERSIONS "$DEFAULT_V"
    assert_success
    # D1 keeps 8.4, outside the package, and can still change its profile
    run bash -c "v-list-web-domain-php $U1 $D1 json | jq -r '[.VERSION, .VERSION_ALLOWED, .ALLOWED, (.VERSIONS | keys | join(\",\"))] | join(\" \")'"
    assert_output "$V no no $DEFAULT_V"
    run v-change-package-tenancy "$P1" PHP_PROFILES 'default,small'
    assert_success
    run v-change-web-domain-php "$U1" "$D1" $V small
    assert_success
    run v-change-web-domain-php "$U1" "$D1" $DEFAULT_V small
    assert_success
    run v-change-web-domain-php "$U1" "$D1" $V small
    assert_failure 10
    # the default profile must be allowed, and new domains get it
    run v-change-package-tenancy "$P1" PHP_DEFAULT_PROFILE medium
    assert_failure 2
    run v-change-package-tenancy "$P1" PHP_DEFAULT_PROFILE small
    assert_success
    run v-change-package-tenancy "$P1" PHP_PROFILES 'default'
    assert_failure 2
    run v-add-web-domain "$U1" "$D3"
    assert_success
    run grep -F "DOMAIN='$D3' " "$ARCPANEL/data/users/$U1/web.php.conf"
    assert_output "DOMAIN='$D3' PROFILE='small'"
    run grep -E '^pm.max_children =' "$(pool "$D3")"
    assert_output 'pm.max_children = 4'
}

@test "Packages: * leaves out versions that reached their end of life" {
    catalog="$BATS_TEST_TMPDIR/versions.conf"
    sed "s/^VERSION='$V' .*/VERSION='$V' RELEASED='2000-01-01' ACTIVE_UNTIL='2000-06-01' EOL='2001-01-01' INSTALLABLE='yes'/" \
        "$ARCPANEL/install/common/php/versions.conf" > "$catalog"
    export ARC_PHP_CATALOG="$catalog"
    v-change-package-tenancy "$P1" PHP_VERSIONS '*'
    run php_lib php_pkg_allows_version "$P1" "$V"
    assert_failure
    run php_lib php_pkg_allows_version "$P1" "$DEFAULT_V"
    assert_success
    v-change-package-tenancy "$P1" PHP_VERSIONS "$V,$DEFAULT_V"
    run php_lib php_pkg_allows_version "$P1" "$V"
    assert_success
    unset ARC_PHP_CATALOG
}

@test "Packages: a reseller's package allows only what the reseller's package allows" {
    v-change-package-tenancy "$RP" PHP_VERSIONS "$DEFAULT_V"
    v-change-package-tenancy "$RP" PHP_PROFILES 'default,small'
    run v-change-package-tenancy "$RC" PHP_VERSIONS '*'
    assert_failure 8
    run v-change-package-tenancy "$RC" PHP_VERSIONS "$V,$DEFAULT_V"
    assert_failure 8
    run v-change-package-tenancy "$RC" PHP_VERSIONS "$DEFAULT_V"
    assert_success
    run v-change-package-tenancy "$RC" PHP_PROFILES 'default,medium'
    assert_failure 8
    run v-change-package-tenancy "$RC" PHP_PROFILES 'default,small'
    assert_success
    run v-change-package-tenancy "$RC" PHP_DEFAULT_PROFILE small
    assert_success
    run bash -c "v-list-package-tenancy $RC json | jq -r '.[\"$RC\"] | [.PHP_VERSIONS, .PHP_PROFILES, .PHP_DEFAULT_PROFILE] | join(\" \")'"
    assert_output "$DEFAULT_V default,small small"
}

@test "Domains: the profile follows a rename and goes with the domain" {
    v-change-package-tenancy "$P1" PHP_PROFILES '*'
    run v-change-web-domain-php "$U1" "$D2" $DEFAULT_V medium
    assert_success
    run v-change-web-domain-name "$U1" "$D2" "renamed-$D2"
    assert_success
    run grep -c "DOMAIN='$D2' " "$ARCPANEL/data/users/$U1/web.php.conf"
    assert_output 0
    run grep -E '^pm =' "$(pool "renamed-$D2")"
    assert_output 'pm = dynamic'
    run v-delete-web-domain "$U1" "renamed-$D2"
    assert_success
    run grep -c "renamed-$D2" "$ARCPANEL/data/users/$U1/web.php.conf"
    assert_output 0
}

@test "Domains: with one pool per user the profile is the user's" {
    # the per-domain pools go first: rebuilds only delete the pools of the
    # current mode
    rm -f /etc/php/*/fpm/pool.d/"$D1".conf /etc/php/*/fpm/pool.d/"$D3".conf
    v-change-sys-config-value WEB_BACKEND_POOL user
    v-rebuild-web-domains "$U1" yes > /dev/null
    run pool "$U1"
    assert_output --partial "$U1.conf"
    run v-change-web-domain-php "$U1" "$D1" $DEFAULT_V medium
    assert_success
    run grep -F "DOMAIN='*' " "$ARCPANEL/data/users/$U1/web.php.conf"
    assert_output "DOMAIN='*' PROFILE='medium'"
    run grep -E '^pm =' "$(pool "$U1")"
    assert_output 'pm = dynamic'
    run bash -c "v-list-web-domain-php $U1 $D3 json | jq -r .PROFILE"
    assert_output 'medium'
    cp -p "$SAVE/arcpanel.conf" "$ARCPANEL/conf/arcpanel.conf"
    rm -f "$(pool "$U1")"
    v-rebuild-web-domains "$U1" yes > /dev/null
    run pool "$D1"
    assert_output --partial "$D1.conf"
}
