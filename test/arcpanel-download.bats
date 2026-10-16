#!/usr/bin/env bats
# Checked downloads of func/download.sh (E39 W3,
# docs/features/specs/2026-10-16-001-e39-w3-supply-chain-design.md §4).
# Runs as root on a test server. A throw-away HTTPS server on 127.0.0.1
# serves the fixtures; curl trusts its certificate through CURL_CA_BUNDLE.
# Signing keys are generated at test time in a temporary GNUPGHOME.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

DL_ROOT=/tmp/arcbats-download
DL_PORT=39931
DL_URL="https://localhost:$DL_PORT"
DL_HTTP_PORT=39932

setup_file() {
    rm -rf "$DL_ROOT"
    mkdir -p "$DL_ROOT/www" "$DL_ROOT/gnupg" "$DL_ROOT/out"
    chmod 700 "$DL_ROOT/gnupg"

    openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' \
        -addext 'subjectAltName=DNS:localhost' \
        -keyout "$DL_ROOT/tls.key" -out "$DL_ROOT/tls.crt" > /dev/null 2>&1

    printf 'archive one\n' > "$DL_ROOT/www/one.tar.gz"
    printf 'archive two\n' > "$DL_ROOT/www/two.tar.gz"

    # Two signing keys: "good" is pinned by the tests, "other" never is
    local name
    for name in good other; do
        GNUPGHOME="$DL_ROOT/gnupg" gpg --batch --pinentry-mode loopback --passphrase '' \
            --quick-gen-key "arcbats-$name@example.invalid" ed25519 sign 1d > /dev/null 2>&1
    done
    GNUPGHOME="$DL_ROOT/gnupg" gpg --batch --armor --export arcbats-good@example.invalid > "$DL_ROOT/www/good.asc"
    GNUPGHOME="$DL_ROOT/gnupg" gpg --batch --export arcbats-good@example.invalid > "$DL_ROOT/www/good.gpg"
    GNUPGHOME="$DL_ROOT/gnupg" gpg --batch --armor --export arcbats-other@example.invalid > "$DL_ROOT/www/other.asc"
    GNUPGHOME="$DL_ROOT/gnupg" gpg --batch --armor --export arcbats-good@example.invalid arcbats-other@example.invalid > "$DL_ROOT/www/both.asc"

    cat > "$DL_ROOT/serve.py" << 'PY'
import http.server, ssl, sys
root, port, crt, key = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
handler = lambda *a, **k: http.server.SimpleHTTPRequestHandler(*a, directory=root, **k)
server = http.server.ThreadingHTTPServer(("127.0.0.1", port), handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(crt, key)
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
PY
    python3 "$DL_ROOT/serve.py" "$DL_ROOT/www" "$DL_PORT" "$DL_ROOT/tls.crt" "$DL_ROOT/tls.key" > /dev/null 2>&1 &
    echo $! > "$DL_ROOT/serve.pid"
    # The same files over plain HTTP, which every download must refuse
    python3 -m http.server --bind 127.0.0.1 --directory "$DL_ROOT/www" "$DL_HTTP_PORT" > /dev/null 2>&1 &
    echo $! > "$DL_ROOT/http.pid"
    local i
    for i in $(seq 1 50); do
        curl -fs --cacert "$DL_ROOT/tls.crt" -o /dev/null "$DL_URL/one.tar.gz" \
            && curl -fs -o /dev/null "http://127.0.0.1:$DL_HTTP_PORT/one.tar.gz" && return 0
        sleep 0.1
    done
    echo "the test HTTPS server did not start" >&2
    return 1
}

teardown_file() {
    local pid
    for pid in "$DL_ROOT/serve.pid" "$DL_ROOT/http.pid"; do
        [ -f "$pid" ] && kill "$(cat "$pid")" 2> /dev/null
    done
    rm -rf "$DL_ROOT"
}

setup() {
    export CURL_CA_BUNDLE="$DL_ROOT/tls.crt"
    # shellcheck source=/usr/local/arcpanel/func/download.sh
    source "$BATS_TEST_DIRNAME/../func/download.sh"
    rm -rf "${DL_ROOT:?}/out"
    mkdir -p "$DL_ROOT/out"
}

sum_of() {
    sha256sum "$DL_ROOT/www/$1" | cut -d' ' -f1
}

fingerprint_of() {
    GNUPGHOME="$DL_ROOT/gnupg" gpg --batch --with-colons --fingerprint "arcbats-$1@example.invalid" \
        | awk -F: '$1 == "fpr" { print $10; exit }'
}

# The output folder holds exactly the given names (and nothing half-written)
assert_out_files() {
    run ls -A "$DL_ROOT/out"
    assert_output "$*"
}

#----------------------------------------------------------#
#                     fetch_verified                       #
#----------------------------------------------------------#

@test "fetch_verified keeps a file whose checksum matches" {
    run fetch_verified "$DL_URL/one.tar.gz" "$(sum_of one.tar.gz)" "$DL_ROOT/out/one.tar.gz"
    assert_success
    assert_file_exist "$DL_ROOT/out/one.tar.gz"
    assert_equal "$(cat "$DL_ROOT/out/one.tar.gz")" 'archive one'
    assert_equal "$(stat -c %a "$DL_ROOT/out/one.tar.gz")" '644'
}

@test "fetch_verified refuses a file whose checksum differs and leaves nothing" {
    run fetch_verified "$DL_URL/one.tar.gz" "$(sum_of two.tar.gz)" "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_output --partial 'checksum mismatch for one.tar.gz'
    assert_out_files ''
}

@test "fetch_verified keeps the old file when the new one does not match" {
    printf 'installed\n' > "$DL_ROOT/out/one.tar.gz"
    run fetch_verified "$DL_URL/one.tar.gz" "$(sum_of two.tar.gz)" "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_equal "$(cat "$DL_ROOT/out/one.tar.gz")" 'installed'
    assert_out_files 'one.tar.gz'
}

@test "fetch_verified refuses an empty or malformed checksum before downloading" {
    run fetch_verified "$DL_URL/one.tar.gz" '' "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_output --partial 'no checksum pinned for one.tar.gz'
    run fetch_verified "$DL_URL/one.tar.gz" "$(sum_of one.tar.gz | tr 'a-f' 'A-F')" "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_output --partial 'no checksum pinned'
    run fetch_verified "$DL_URL/one.tar.gz" 'abc123' "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_out_files ''
}

@test "fetch_verified fails on a missing file and leaves nothing" {
    run fetch_verified "$DL_URL/missing.tar.gz" "$(sum_of one.tar.gz)" "$DL_ROOT/out/missing.tar.gz"
    assert_failure
    assert_output --partial 'download of missing.tar.gz failed'
    assert_out_files ''
}

@test "fetch_verified refuses plain HTTP" {
    curl -fs -o /dev/null "http://127.0.0.1:$DL_HTTP_PORT/one.tar.gz" || fail "the plain HTTP server is not up"
    run fetch_verified "http://127.0.0.1:$DL_HTTP_PORT/one.tar.gz" "$(sum_of one.tar.gz)" "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_output --partial 'download of one.tar.gz failed'
    assert_out_files ''
}

@test "fetch_verified refuses a certificate that is not trusted" {
    unset CURL_CA_BUNDLE
    run fetch_verified "$DL_URL/one.tar.gz" "$(sum_of one.tar.gz)" "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_out_files ''
}

#----------------------------------------------------------#
#                      fetch_pinned                        #
#----------------------------------------------------------#

@test "fetch_pinned looks the checksum up by file name" {
    printf '%s  two.tar.gz\n%s  one.tar.gz\n' "$(sum_of two.tar.gz)" "$(sum_of one.tar.gz)" > "$DL_ROOT/sums"
    run fetch_pinned "$DL_URL/one.tar.gz" "$DL_ROOT/sums" "$DL_ROOT/out/one.tar.gz"
    assert_success
    assert_equal "$(cat "$DL_ROOT/out/one.tar.gz")" 'archive one'
}

@test "fetch_pinned refuses a file with no line in the checksum file" {
    printf '%s  two.tar.gz\n' "$(sum_of two.tar.gz)" > "$DL_ROOT/sums"
    run fetch_pinned "$DL_URL/one.tar.gz" "$DL_ROOT/sums" "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_output --partial 'no checksum pinned for one.tar.gz in sums'
    assert_out_files ''
}

@test "fetch_pinned stops when a pin changes" {
    printf '%s  one.tar.gz\n' "$(sum_of two.tar.gz)" > "$DL_ROOT/sums"
    run fetch_pinned "$DL_URL/one.tar.gz" "$DL_ROOT/sums" "$DL_ROOT/out/one.tar.gz"
    assert_failure
    assert_output --partial 'checksum mismatch for one.tar.gz'
}

@test "fetch_pinned uses a cached file only when it matches" {
    printf '%s  one.tar.gz\n%s  gone.tar.gz\n' "$(sum_of one.tar.gz)" "$(sum_of one.tar.gz)" > "$DL_ROOT/sums"
    # A matching cached file is used without a download (the URL does not exist)
    cp "$DL_ROOT/www/one.tar.gz" "$DL_ROOT/out/gone.tar.gz"
    run fetch_pinned "$DL_URL/gone.tar.gz" "$DL_ROOT/sums" "$DL_ROOT/out/gone.tar.gz"
    assert_success
    # A tampered cached file is downloaded again
    printf 'tampered\n' > "$DL_ROOT/out/one.tar.gz"
    run fetch_pinned "$DL_URL/one.tar.gz" "$DL_ROOT/sums" "$DL_ROOT/out/one.tar.gz"
    assert_success
    assert_equal "$(cat "$DL_ROOT/out/one.tar.gz")" 'archive one'
}

#----------------------------------------------------------#
#                     install_apt_key                      #
#----------------------------------------------------------#

@test "install_apt_key writes an armored key whose fingerprint is pinned" {
    APT_KEY_FPR_ARCBATS="$(fingerprint_of other) $(fingerprint_of good)"
    run install_apt_key arcbats "$DL_URL/good.asc" "$DL_ROOT/out/keyrings/arcbats.gpg"
    assert_success
    assert_equal "$(stat -c %a "$DL_ROOT/out/keyrings/arcbats.gpg")" '644'
    run gpg --batch --show-keys --with-colons "$DL_ROOT/out/keyrings/arcbats.gpg"
    assert_output --partial "$(fingerprint_of good)"
    # The keyring is binary, as apt's signed-by expects
    run grep -c 'BEGIN PGP' "$DL_ROOT/out/keyrings/arcbats.gpg"
    assert_output '0'
}

@test "install_apt_key writes a binary key whose fingerprint is pinned" {
    APT_KEY_FPR_ARCBATS="$(fingerprint_of good)"
    run install_apt_key arcbats "$DL_URL/good.gpg" "$DL_ROOT/out/arcbats.gpg"
    assert_success
    assert_equal "$(sha256sum < "$DL_ROOT/out/arcbats.gpg")" "$(sha256sum < "$DL_ROOT/www/good.gpg")"
}

@test "install_apt_key refuses a key with another fingerprint and keeps the old keyring" {
    APT_KEY_FPR_ARCBATS="$(fingerprint_of good)"
    printf 'old keyring\n' > "$DL_ROOT/out/arcbats.gpg"
    run install_apt_key arcbats "$DL_URL/other.asc" "$DL_ROOT/out/arcbats.gpg"
    assert_failure
    assert_output --partial 'the arcbats key does not match its pinned fingerprint'
    assert_output --partial "$(fingerprint_of other)"
    assert_equal "$(cat "$DL_ROOT/out/arcbats.gpg")" 'old keyring'
}

@test "install_apt_key refuses a file that adds an unpinned key" {
    APT_KEY_FPR_ARCBATS="$(fingerprint_of good)"
    run install_apt_key arcbats "$DL_URL/both.asc" "$DL_ROOT/out/arcbats.gpg"
    assert_failure
    assert_out_files ''
}

@test "install_apt_key refuses plain HTTP" {
    APT_KEY_FPR_ARCBATS="$(fingerprint_of good)"
    run install_apt_key arcbats "http://127.0.0.1:$DL_HTTP_PORT/good.asc" "$DL_ROOT/out/arcbats.gpg"
    assert_failure
    assert_out_files ''
}

@test "install_apt_key refuses a name with no pinned fingerprint and a file that is no key" {
    run install_apt_key arcbats-unknown "$DL_URL/good.asc" "$DL_ROOT/out/arcbats.gpg"
    assert_failure
    assert_output --partial 'no fingerprint pinned for the arcbats-unknown key'
    APT_KEY_FPR_ARCBATS="$(fingerprint_of good)"
    run install_apt_key arcbats "$DL_URL/one.tar.gz" "$DL_ROOT/out/arcbats.gpg"
    assert_failure
    assert_output --partial 'got: none'
    run install_apt_key arcbats "$DL_URL/missing.asc" "$DL_ROOT/out/arcbats.gpg"
    assert_failure
    assert_output --partial 'download of the arcbats key failed'
    assert_out_files ''
}

@test "the pinned fingerprints are full upper-case fingerprints" {
    local var fpr
    for var in ${!APT_KEY_FPR_*}; do
        for fpr in ${!var}; do
            [[ "$fpr" =~ ^[0-9A-F]{40}$ ]] || fail "$var holds '$fpr'"
        done
    done
    assert_equal "$APT_KEY_FPR_ARCPANEL" '0615B3DD8398E546A060B6F74DE318A606E4002A'
}
