#!/usr/bin/env bats
# Supply chain of the build, the installers and the upgrades (E39 W3,
# docs/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md §5–§7).
# Runs as root on a test server, from a clone that has src/ and func/ next
# to test/. The build case downloads the PHP source from php.net.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
SC_ROOT=/tmp/arcbats-supply-chain

setup_file() {
    rm -rf "$SC_ROOT"
    mkdir -p "$SC_ROOT"
}

teardown_file() {
    rm -rf "$SC_ROOT"
}

#----------------------------------------------------------#
#                     Build (H5)                           #
#----------------------------------------------------------#

@test "the build stops when an engine source does not match its pin" {
    local repo="$BATS_TEST_DIRNAME/.."
    [ -f "$repo/src/arcpanel-build.sh" ] || skip "no build script next to the suite"
    # A copy of the build inputs with the pin of the PHP source changed
    local tree="$SC_ROOT/tree"
    mkdir -p "$tree/src/deb" "$tree/func"
    cp "$repo/src/arcpanel-build.sh" "$repo/src/sources.sha256" "$tree/src/"
    cp -r "$repo/src/deb/." "$tree/src/deb/"
    cp "$repo/func/download.sh" "$tree/func/"
    local php_v archive
    php_v=$(awk '/^Version:/ { print $2 }' "$tree/src/deb/php/control" | cut -d- -f1)
    archive="php-$php_v.tar.gz"
    grep -q "  $archive\$" "$tree/src/sources.sha256" || fail "$archive is not pinned"
    sed -i "s/^[0-9a-f]\{64\}  $archive\$/$(printf '0%.0s' $(seq 1 64))  $archive/" "$tree/src/sources.sha256"

    install -d -m 700 "$SC_ROOT/build"
    run env TERM=dumb BUILD_DIR="$SC_ROOT/build" bash "$tree/src/arcpanel-build.sh" \
        --php --noinstall --keepbuild --dontinstalldeps '~localsrc'
    assert_failure
    assert_output --partial "checksum mismatch for $archive"
    assert_output --partial "Engine source $archive failed its check, stopping the build"
    # Nothing was unpacked or compiled, and no unchecked archive is kept
    assert_dir_not_exist "$SC_ROOT/build/php-$php_v"
    assert_file_not_exist "$tree/src/archive/$archive"
}

@test "every engine source the build downloads is pinned" {
    local repo="$BATS_TEST_DIRNAME/.."
    [ -f "$repo/src/arcpanel-build.sh" ] || skip "no build script next to the suite"
    local v
    v=$(awk '/^Version:/ { print $2 }' "$repo/src/deb/php/control" | cut -d- -f1)
    assert_file_contains "$repo/src/sources.sha256" "  php-$v.tar.gz\$"
    v=$(awk '/^Version:/ { print $2 }' "$repo/src/deb/nginx/control" | cut -d- -f1)
    assert_file_contains "$repo/src/sources.sha256" "  nginx-$v.tar.gz\$"
    for v in OPENSSL_V PCRE_V ZLIB_V; do
        local value prefix
        value=$(sed -n "s/^$v='\(.*\)'\$/\1/p" "$repo/src/arcpanel-build.sh")
        [ -n "$value" ] || fail "no $v in the build script"
        case "$v" in
            OPENSSL_V) prefix=openssl ;;
            PCRE_V) prefix=pcre2 ;;
            ZLIB_V) prefix=zlib ;;
        esac
        assert_file_contains "$repo/src/sources.sha256" "  $prefix-$value.tar.gz\$"
    done
}

#----------------------------------------------------------#
#                 rclone (M11, D8)                          #
#----------------------------------------------------------#

# Puts the installed rclone and upgrade.conf aside; restore_rclone puts them back
save_rclone() {
    mkdir -p "$SC_ROOT/saved"
    [ -f /usr/bin/rclone ] && cp -p /usr/bin/rclone "$SC_ROOT/saved/rclone"
    cp -p "$ARCPANEL/install/upgrade/upgrade.conf" "$SC_ROOT/saved/upgrade.conf"
}

restore_rclone() {
    cp -p "$SC_ROOT/saved/upgrade.conf" "$ARCPANEL/install/upgrade/upgrade.conf"
    if [ -f "$SC_ROOT/saved/rclone" ]; then
        cp -p "$SC_ROOT/saved/rclone" /usr/bin/rclone
    fi
}

pinned() {
    sed -n "s/^$1='\(.*\)'\$/\1/p" "$ARCPANEL/install/upgrade/upgrade.conf"
}

# A case that put rclone or upgrade.conf aside gets them back, also on failure
teardown() {
    if [ -d "$SC_ROOT/saved" ]; then
        restore_rclone
        rm -rf "$SC_ROOT/saved"
    fi
}

@test "v-add-sys-rclone installs the pinned rclone and leaves a current one alone" {
    save_rclone
    rm -f /usr/bin/rclone
    run v-add-sys-rclone
    assert_success
    assert_output "rclone $(pinned rclone_v) installed"
    assert_file_executable /usr/bin/rclone
    run /usr/bin/rclone version
    assert_line --index 0 "rclone v$(pinned rclone_v)"
    run v-add-sys-rclone
    assert_success
    assert_output "rclone $(pinned rclone_v) is up to date"
}

@test "v-add-sys-rclone refuses a release that does not match its pin" {
    save_rclone
    sed -i "s/^rclone_sha256='.*'\$/rclone_sha256='$(printf '0%.0s' $(seq 1 64))'/" "$ARCPANEL/install/upgrade/upgrade.conf"
    rm -f /usr/bin/rclone
    run v-add-sys-rclone
    assert_equal "$status" 15
    assert_output --partial 'failed its checksum'
    assert_file_not_exist /usr/bin/rclone
}

#----------------------------------------------------------#
#        Panel certificate and phpMyAdmin SSO (L18)         #
#----------------------------------------------------------#

@test "v-change-sys-arcpanel-ssl leaves the certificate readable and the key private" {
    local dir="$SC_ROOT/ssl"
    install -d -m 700 "$dir"
    cp "$ARCPANEL/ssl/certificate.crt" "$ARCPANEL/ssl/certificate.key" "$dir/"
    chmod 600 "$ARCPANEL/ssl/certificate.crt"
    run v-change-sys-arcpanel-ssl "$dir" no
    assert_success
    assert_equal "$(stat -c %a "$ARCPANEL/ssl/certificate.crt")" '644'
    assert_equal "$(stat -c %a "$ARCPANEL/ssl/certificate.key")" '660'
    rm -rf "$dir"
}

@test "the 2.1.3 upgrade step opens the certificate and reports an unexpected APT key" {
    [ ! -e /usr/share/keyrings/postgresql-keyring.gpg ] || skip "PostgreSQL is set up on this server"
    chmod 600 "$ARCPANEL/ssl/certificate.crt"
    # A keyring with a key that is not the PostgreSQL key
    local gnupg="$SC_ROOT/gnupg"
    install -d -m 700 "$gnupg"
    GNUPGHOME="$gnupg" gpg --batch --pinentry-mode loopback --passphrase '' \
        --quick-gen-key arcbats-foreign@example.invalid ed25519 sign 1d > /dev/null 2>&1
    GNUPGHOME="$gnupg" gpg --batch --export arcbats-foreign@example.invalid > /usr/share/keyrings/postgresql-keyring.gpg
    mkdir -p "$SC_ROOT/backup"
    run env SC_BACKUP="$SC_ROOT/backup" bash -c '
        source /etc/arcpanel/arcpanel.conf
        source "$ARCPANEL/func/main.sh"
        source "$ARCPANEL/func/upgrade.sh"
        ARCPANEL_BACKUP="$SC_BACKUP"
        source "$ARCPANEL/install/upgrade/versions/2.1.3.sh"'
    rm -f /usr/share/keyrings/postgresql-keyring.gpg
    assert_success
    assert_output --partial '/usr/share/keyrings/postgresql-keyring.gpg holds a key that ArcPanel does not expect'
    refute_output --partial 'arcpanel-keyring.gpg holds'
    assert_file_contains "$SC_ROOT/backup/message.log" 'not the known key of the postgresql repository'
    assert_equal "$(stat -c %a "$ARCPANEL/ssl/certificate.crt")" '644'
}

# Runs the request() of arcpanel-sso.php against PORT on 127.0.0.1
sso_request() {
    local php_file="$SC_ROOT/sso-request.php"
    {
        echo '<?php'
        echo 'define("PHPMYADMIN_KEY", "x"); define("API_KEY", "x");'
        echo "define(\"API_HOST_NAME\", \"$(hostname -f)\"); define(\"API_ARCPANEL_PORT\", \"$1\");"
        tr -d '\r' < "$BATS_TEST_DIRNAME/../install/deb/phpmyadmin/arcpanel-sso.php" | sed -n '/^class ArcPanel_API {/,/^}/p'
        echo '$answer = (new ArcPanel_API())->request(["cmd" => "v-list-sys-info"]);'
        echo 'echo $answer === false ? "REFUSED" : "ANSWERED";'
    } > "$php_file"
    sudo -u arcpanelmail php "$php_file"
}

@test "the phpMyAdmin SSO reaches the panel over loopback with TLS verified" {
    [ -f "$BATS_TEST_DIRNAME/../install/deb/phpmyadmin/arcpanel-sso.php" ] || skip "no checkout next to the suite"
    local port
    port=$(sed -n "s/^BACKEND_PORT='\([0-9]*\)'\$/\1/p" "$ARCPANEL/conf/arcpanel.conf")
    chmod 644 "$ARCPANEL/ssl/certificate.crt"
    run sso_request "${port:-8083}"
    assert_output 'ANSWERED'
}

@test "the phpMyAdmin SSO refuses a server without the panel's certificate" {
    [ -f "$BATS_TEST_DIRNAME/../install/deb/phpmyadmin/arcpanel-sso.php" ] || skip "no checkout next to the suite"
    # A TLS server on loopback with a certificate of its own
    openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' \
        -keyout "$SC_ROOT/other.key" -out "$SC_ROOT/other.crt" > /dev/null 2>&1
    openssl s_server -accept 39933 -cert "$SC_ROOT/other.crt" -key "$SC_ROOT/other.key" -www -quiet > /dev/null 2>&1 &
    local pid=$!
    sleep 1
    run sso_request 39933
    kill "$pid"
    assert_output 'REFUSED'
    # The SSO file never turns verification off
    run grep -nE 'VERIFY(PEER|HOST), *(false|0)' "$BATS_TEST_DIRNAME/../install/deb/phpmyadmin/arcpanel-sso.php"
    assert_failure
}
