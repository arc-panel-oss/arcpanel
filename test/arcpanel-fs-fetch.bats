#!/usr/bin/env bats
# ArcPanel upload from URL (E3,
# docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md): the
# address checks and file names of func/fetch.php, and the fetch commands
# against a local PHP server on 127.0.0.1 (reached with PRIVATE=yes, refused
# without it). Run as root on a server installed with the ArcPanel installer.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PHP=$ARCPANEL/php/bin/php
HELPER=$ARCPANEL/func/fetch.php
PASS="Tff-Pass-4815162342"
U1=tffu1
H1=/home/$U1
T=$H1/tmp/f
SERVE=/root/arcpanel-fs-fetch-serve
PORT=39911
BASE=http://127.0.0.1:$PORT
REQ=/root/arcpanel-fs-fetch-request
CONF=$ARCPANEL/conf/arcpanel.conf

cleanup() {
    v-delete-user "$U1" > /dev/null 2>&1 || true
    pkill -f "php -S 127.0.0.1:$PORT" > /dev/null 2>&1 || true
    rm -rf "$SERVE" "$REQ"
}

config_value() {
    grep -m1 "^$1=" "$CONF" | cut -d"'" -f2
}

setup_file() {
    cleanup
    v-add-user $U1 "$PASS" $U1@example.invalid default > /dev/null
    echo "$(config_value FILE_FETCH_MAX_SIZE)" > /root/arcpanel-fs-fetch-max
    echo "$(config_value FILE_FETCH)" > /root/arcpanel-fs-fetch-on
    v-change-sys-config-value FILE_FETCH yes
    v-change-sys-config-value FILE_FETCH_MAX_SIZE 2G
    mkdir -p "$SERVE"
    cat > "$SERVE/router.php" << 'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
if (preg_match('#^/file/(\d+)#', $path, $m)) {
    header('Content-Length: '.$m[1]);
    echo str_repeat('a', (int) $m[1]);
} elseif (preg_match('#^/chunked/(\d+)#', $path, $m)) {
    for ($n = (int) $m[1]; $n > 0; $n -= 8192) {
        echo str_repeat('b', min($n, 8192));
        flush();
    }
} elseif ($path === '/named') {
    header("Content-Disposition: attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.txt; filename=\"other.txt\"");
    echo 'x';
} elseif ($path === '/traversal') {
    header('Content-Disposition: attachment; filename="../../etc/passwd"');
    echo 'x';
} elseif (preg_match('#^/redirect/(\d+)$#', $path, $m)) {
    if ((int) $m[1] > 0) {
        header('Location: /redirect/'.((int) $m[1] - 1), true, 302);
    } else {
        echo 'end';
    }
} elseif ($path === '/cross') {
    header('Location: http://localhost:'.$_SERVER['SERVER_PORT'].'/echo', true, 302);
} elseif ($path === '/echo') {
    echo ($headers['authorization'] ?? '').'|'.($headers['x-test'] ?? '').'|'.($headers['user-agent'] ?? '');
} elseif (preg_match('#^/status/(\d+)$#', $path, $m)) {
    http_response_code((int) $m[1]);
    echo 'status';
} elseif ($path === '/slow') {
    set_time_limit(0);
    header('Content-Length: 1000');
    for ($i = 0; $i < 1000; $i++) {
        echo 'c';
        flush();
        usleep(200000);
    }
} else {
    http_response_code(404);
}
PHP
    PHP_CLI_SERVER_WORKERS=24 setsid "$PHP" -S 127.0.0.1:$PORT "$SERVE/router.php" > /dev/null 2>&1 < /dev/null &
    for _ in $(seq 50); do
        curl -s -o /dev/null "$BASE/file/1" && break
        sleep 0.1
    done
}

teardown_file() {
    v-change-sys-config-value FILE_FETCH_MAX_SIZE "$(cat /root/arcpanel-fs-fetch-max)"
    v-change-sys-config-value FILE_FETCH "$(cat /root/arcpanel-fs-fetch-on)"
    rm -f /root/arcpanel-fs-fetch-max /root/arcpanel-fs-fetch-on
    cleanup
}

setup() {
    v-delete-fs-fetch $U1 active > /dev/null 2>&1 || true
    rm -rf "$ARCPANEL/data/users/$U1/fetches"
    rm -rf "$T"
    as_user mkdir -p "$T"
}

as_user() {
    setpriv --groups "$(id -G $U1 | tr ' ' ',')" --reuid $U1 --regid $U1 -- "$@"
}

# Writes the request file: one directive per argument
req() {
    printf '%s\n' "$@" > "$REQ"
}

# KEY of fetch ID
field() {
    grep -o "$2='[^']*'" "$ARCPANEL/data/users/$U1/fetches/$1.conf" | cut -d"'" -f2
}

# Waits until fetch ID has one of the STATUS values (30 seconds at most)
wait_for() {
    local id=$1 status
    shift
    for _ in $(seq 150); do
        status=$(field "$id" STATUS)
        for wanted in "$@"; do
            [ "$status" = "$wanted" ] && return 0
        done
        sleep 0.2
    done
    echo "fetch $id is $status, not $*"
    return 1
}

# Starts a fetch of URL into $T (PRIVATE=yes) and prints its ID
fetch() {
    local url=$1
    shift
    req "url $url" "$@"
    v-add-fs-fetch $U1 "$T" "$REQ" "${CONFLICT:-rename}" yes
}

count() {
    local n=0 file
    for file in "$ARCPANEL/data/users/$U1/fetches/"*.conf; do
        grep -q "STATUS='$1'" "$file" && n=$((n + 1))
    done
    echo "$n"
}

#----------------------------------------------------------#
#                   func/fetch.php                         #
#----------------------------------------------------------#

@test "Helper: private, local, special and own addresses are refused; public ones pass" {
    for url in http://127.0.0.1/ http://10.1.2.3/ http://172.16.0.1/ http://192.168.1.1/ http://169.254.169.254/latest \
        http://100.64.0.1/ http://0.0.0.0/ http://224.0.0.1/ http://[::1]/ http://[fe80::1]/ http://[fd00::1]/ \
        http://[::ffff:127.0.0.1]/ http://[64:ff9b::a00:1]/ http://[2002:c0a8:101::1]/ http://localhost/; do
        FETCH_URL=$url run "$PHP" "$HELPER" check no
        assert_failure 4
    done
    FETCH_URL=http://93.184.215.14:8080/x run "$PHP" "$HELPER" check no
    assert_success
    assert_output "http 93.184.215.14 8080 93.184.215.14"
    FETCH_URL=https://[2606:4700::1111]/ run "$PHP" "$HELPER" check no
    assert_success
    assert_output "https 2606:4700::1111 443 [2606:4700::1111]"
    # The server's own public address
    FETCH_URL=http://93.184.215.14/ run "$PHP" "$HELPER" check no 10.0.0.5/24 93.184.215.14/32
    assert_failure 4
    # An administrator may allow private addresses
    FETCH_URL=http://127.0.0.1:8083/ run "$PHP" "$HELPER" check yes
    assert_success
    assert_output "http 127.0.0.1 8083 127.0.0.1"
}

@test "Helper: only http(s) URLs without user information; unknown hosts fail as dns" {
    for url in ftp://example.com/x file:///etc/passwd gopher://x/ 'http://user:pw@example.com/' http:///x 'http://exa mple.com/'; do
        FETCH_URL=$url run "$PHP" "$HELPER" check no
        assert_failure 2
    done
    FETCH_URL=http://arcpanel-test-nothing.invalid/ run "$PHP" "$HELPER" check no
    assert_failure 3
}

@test "Helper: redirect locations, display URLs and file names" {
    FETCH_URL=https://a.example/x/y?z=1 FETCH_LOCATION=/b run "$PHP" "$HELPER" location
    assert_output https://a.example/b
    FETCH_URL=https://a.example/x/y FETCH_LOCATION=c/d run "$PHP" "$HELPER" location
    assert_output https://a.example/x/c/d
    FETCH_URL=https://a.example/x/y FETCH_LOCATION=//b.example/e run "$PHP" "$HELPER" location
    assert_output https://b.example/e
    FETCH_URL=https://a.example/x FETCH_LOCATION=ftp://b.example/ run "$PHP" "$HELPER" location
    assert_failure 2

    FETCH_URL='https://a.example:8443/p/q.zip?sig=SECRET#frag' run "$PHP" "$HELPER" display
    assert_output 'https://a.example:8443/p/q.zip?…'

    printf 'HTTP/1.1 200 OK\r\nContent-Disposition: attachment; filename="a b.zip"\r\n\r\n' > "$REQ"
    FETCH_URL=https://a.example/x run "$PHP" "$HELPER" name "$REQ"
    assert_output 'a b.zip'
    printf 'HTTP/1.1 200 OK\r\n\r\n' > "$REQ"
    FETCH_URL='https://a.example/dl/r%C3%A9sum%C3%A9.pdf?x=1' run "$PHP" "$HELPER" name "$REQ"
    assert_output 'résumé.pdf'
    FETCH_URL='https://a.example/' run "$PHP" "$HELPER" name "$REQ"
    assert_output download
    FETCH_URL='https://a.example/a%2F..%2F..%2Fetc' run "$PHP" "$HELPER" name "$REQ"
    assert_output etc
}

@test "Helper: the request file is validated, and credentials go only to the request's host" {
    req 'url https://a.example/f' 'header X-Test: one' 'header Accept: */*' 'bearer tok"en'
    run "$PHP" "$HELPER" validate "$REQ"
    assert_success
    FETCH_URL=https://a.example/g run "$PHP" "$HELPER" config "$REQ"
    assert_output "$(printf '%s\n' 'url = "https://a.example/g"' 'header = "X-Test: one"' 'header = "Accept: */*"' 'oauth2-bearer = "tok\"en"')"
    FETCH_URL=https://b.example/g run "$PHP" "$HELPER" config "$REQ"
    assert_output 'url = "https://b.example/g"'

    for bad in 'name x' 'url ftp://a.example/' 'url https://a.example/ x' $'url https://a.example/\nurl https://b.example/' \
        $'url https://a.example/\nheader Host: b.example' $'url https://a.example/\nheader Bad Name: x' \
        $'url https://a.example/\nbasic nocolon' $'url https://a.example/\nbasic a:b\nbearer t' \
        $'url https://a.example/\nname ../x' $'url https://a.example/\nproxy http://x'; do
        printf '%s\n' "$bad" > "$REQ"
        run "$PHP" "$HELPER" validate "$REQ"
        assert_failure 2
    done
}

#----------------------------------------------------------#
#                       Commands                           #
#----------------------------------------------------------#

@test "Config: FILE_FETCH and FILE_FETCH_MAX_SIZE are listed" {
    run v-list-sys-config json
    assert_success
    assert_output --partial '"FILE_FETCH": "yes"'
    assert_output --partial '"FILE_FETCH_MAX_SIZE": "2G"'
}

@test "Add refuses bad arguments, folders outside the home or read-only, and a switched-off server" {
    req "url $BASE/file/3"
    run v-add-fs-fetch $U1 /tmp "$REQ"
    assert_failure 10
    run v-add-fs-fetch $U1 "$H1" "$REQ"
    assert_failure 10
    run v-add-fs-fetch $U1 "$T/missing" "$REQ"
    assert_failure 3
    run v-add-fs-fetch $U1 "$T" "$REQ" replace
    assert_failure 2
    run v-add-fs-fetch $U1 "$T" "$REQ" rename maybe
    assert_failure 2
    run v-add-fs-fetch $U1 "$T" /root/arcpanel-fs-fetch-none
    assert_failure 3
    req "url ftp://127.0.0.1/x"
    run v-add-fs-fetch $U1 "$T" "$REQ"
    assert_failure 2
    v-change-sys-config-value FILE_FETCH no
    req "url $BASE/file/3"
    run v-add-fs-fetch $U1 "$T" "$REQ"
    v-change-sys-config-value FILE_FETCH yes
    assert_failure 11
}

@test "Fetch: the file lands as the user with mode 0644, the request file goes" {
    id=$(fetch "$BASE/file/4096/data.bin")
    wait_for "$id" done
    assert_file_exist "$T/data.bin"
    assert_equal "$(stat -c '%U %a %s' "$T/data.bin")" "$U1 644 4096"
    assert_equal "$(field "$id" BYTES)" 4096
    assert_equal "$(field "$id" TOTAL)" 4096
    assert_equal "$(field "$id" HTTP)" 200
    assert_equal "$(field "$id" URL)" "$BASE/file/4096/data.bin"
    assert_file_not_exist "$ARCPANEL/data/users/$U1/fetches/$id.request"
    run ls -A "$T"
    assert_output data.bin
    run v-list-fs-fetches $U1 json
    assert_success
    assert_equal "$(jq -r ".\"$id\".STATUS" <<< "$output")" done
    assert_equal "$(jq -r ".\"$id\".NAME" <<< "$output")" data.bin
    assert_equal "$(jq -r ".\"$id\" | has(\"PID\")" <<< "$output")" false
}

@test "Fetch: private addresses are refused without PRIVATE=yes" {
    req "url $BASE/file/3/x.txt"
    id=$(v-add-fs-fetch $U1 "$T" "$REQ")
    wait_for "$id" failed
    assert_equal "$(field "$id" ERROR)" blocked
    assert_file_not_exist "$T/x.txt"
    # A redirect to a private address is refused as well (PRIVATE=no from the start)
    req "url http://localhost:$PORT/redirect/1"
    id=$(v-add-fs-fetch $U1 "$T" "$REQ" rename no)
    wait_for "$id" failed
    assert_equal "$(field "$id" ERROR)" blocked
}

@test "Fetch: names from Content-Disposition, the URL, the request, and a traversal stays in the folder" {
    id=$(fetch "$BASE/named")
    wait_for "$id" done
    assert_file_exist "$T/résumé.txt"
    id=$(fetch "$BASE/traversal")
    wait_for "$id" done
    assert_file_exist "$T/passwd"
    id=$(fetch "$BASE/file/3/a.txt" 'name chosen.txt')
    wait_for "$id" done
    assert_file_exist "$T/chosen.txt"
}

@test "Fetch: conflicts rename to name_(n).ext, overwrite files but not folders, or skip" {
    id=$(fetch "$BASE/file/3/a.txt")
    wait_for "$id" done
    id=$(fetch "$BASE/file/4/a.txt")
    wait_for "$id" done
    assert_equal "$(field "$id" NAME)" 'a_(1).txt'
    id=$(fetch "$BASE/file/5/a.txt")
    wait_for "$id" done
    assert_file_exist "$T/a_(2).txt"
    id=$(CONFLICT=overwrite fetch "$BASE/file/6/a.txt")
    wait_for "$id" done
    assert_equal "$(stat -c %s "$T/a.txt")" 6
    id=$(CONFLICT=skip fetch "$BASE/file/7/a.txt")
    wait_for "$id" skipped
    assert_equal "$(stat -c %s "$T/a.txt")" 6
    as_user mkdir "$T/d.txt"
    id=$(CONFLICT=overwrite fetch "$BASE/file/7/d.txt")
    wait_for "$id" failed
    assert_equal "$(field "$id" ERROR)" exists
    run bash -c "ls -A '$T' | grep -c arc-fetch"
    assert_output 0
}

@test "Fetch: 5 redirects are followed, a 6th fails; HTTP errors and dead ports fail" {
    id=$(fetch "$BASE/redirect/5")
    wait_for "$id" done
    assert_file_exist "$T/0"
    id=$(fetch "$BASE/redirect/6")
    wait_for "$id" failed
    assert_equal "$(field "$id" ERROR)" redirects
    id=$(fetch "$BASE/status/404")
    wait_for "$id" failed
    assert_equal "$(field "$id" ERROR)" http
    assert_equal "$(field "$id" HTTP)" 404
    assert_file_not_exist "$T/404"
    id=$(fetch "http://127.0.0.1:1/x")
    wait_for "$id" failed
    assert_equal "$(field "$id" ERROR)" network
    id=$(fetch "http://arcpanel-test-nothing.invalid/x")
    wait_for "$id" failed
    assert_equal "$(field "$id" ERROR)" dns
}

@test "Fetch: headers and credentials are sent, and dropped when a redirect changes the host" {
    id=$(fetch "$BASE/echo" 'basic alice:pw' 'header X-Test: one' 'header User-Agent: arc-test')
    wait_for "$id" done
    assert_equal "$(cat "$T/echo")" "Basic YWxpY2U6cHc=|one|arc-test"
    rm -f "$T/echo"
    id=$(fetch "$BASE/echo" 'bearer tok')
    wait_for "$id" done
    assert_equal "$(cut -d'|' -f1 "$T/echo")" "Bearer tok"
    rm -f "$T/echo"
    id=$(fetch "$BASE/cross" 'bearer tok' 'header X-Test: one')
    wait_for "$id" done
    assert_equal "$(cut -d'|' -f1,2 "$T/echo")" "|"
}

@test "Fetch: FILE_FETCH_MAX_SIZE stops files with and without Content-Length" {
    v-change-sys-config-value FILE_FETCH_MAX_SIZE 1K
    id=$(fetch "$BASE/file/5000/big.bin")
    wait_for "$id" failed
    big=$(field "$id" ERROR)
    id=$(fetch "$BASE/chunked/3000000/stream.bin")
    wait_for "$id" failed
    stream=$(field "$id" ERROR)
    v-change-sys-config-value FILE_FETCH_MAX_SIZE 2G
    assert_equal "$big" too-large
    assert_equal "$stream" too-large
    run bash -c "ls -A '$T'"
    assert_output ''
}

@test "Queue: 3 run at once, the next starts when one ends, 20 at most, cancel removes partial files" {
    ids=()
    for _ in 1 2 3 4 5; do
        ids+=("$(fetch "$BASE/slow")")
    done
    sleep 1
    assert_equal "$(count running)" 3
    assert_equal "$(count queued)" 2
    run v-list-fs-fetches $U1 json
    assert_success
    running=$(jq -r '[.[] | select(.STATUS == "running")][0].ID' <<< "$output")
    assert [ "$(jq -r ".\"$running\".BYTES" <<< "$output")" -ge 0 ]
    v-delete-fs-fetch $U1 "${ids[0]}"
    assert_equal "$(field "${ids[0]}" STATUS)" cancelled
    sleep 1
    assert_equal "$(count running)" 3
    assert_equal "$(count queued)" 1
    # 4 active: 16 more reach the limit of 20
    for _ in $(seq 16); do
        fetch "$BASE/slow" > /dev/null
    done
    req "url $BASE/slow"
    run v-add-fs-fetch $U1 "$T" "$REQ" rename yes
    assert_failure 8
    v-delete-fs-fetch $U1 active
    assert_equal "$(count running)" 0
    assert_equal "$(count queued)" 0
    run bash -c "ls -A '$T'"
    assert_output ''
    # A finished record is removed by delete
    v-delete-fs-fetch $U1 "${ids[0]}"
    assert_file_not_exist "$ARCPANEL/data/users/$U1/fetches/${ids[0]}.conf"
    run v-delete-fs-fetch $U1 "${ids[0]}"
    assert_failure 3
}

@test "Secrets: URL query, headers and token never reach ps, the record or the list" {
    id=$(fetch "$BASE/slow?sig=QUERYSECRET" 'bearer TOKENSECRET' 'header X-Key: HEADERSECRET')
    wait_for "$id" running
    sleep 1
    run bash -c "ps -eo args | grep -v grep | grep -E 'QUERYSECRET|TOKENSECRET|HEADERSECRET'"
    assert_failure
    run grep -rE 'QUERYSECRET|TOKENSECRET|HEADERSECRET' "$ARCPANEL/data/users/$U1/fetches/$id.conf"
    assert_failure
    assert_equal "$(field "$id" URL)" "$BASE/slow?…"
    run v-list-fs-fetches $U1 json
    refute_output --partial SECRET
    run grep -rE 'QUERYSECRET|TOKENSECRET|HEADERSECRET' "$ARCPANEL/log/"
    assert_failure
    v-delete-fs-fetch $U1 "$id"
    assert_file_not_exist "$ARCPANEL/data/users/$U1/fetches/$id.request"
}

@test "List: interrupted runners end as failed, old finished records go" {
    dir=$ARCPANEL/data/users/$U1/fetches
    mkdir -p "$dir"
    echo "ID='17000000000001' URL='http://x/' DIR='$T' NAME='' ON_CONFLICT='rename' PRIVATE='no' STATUS='running' BYTES='0' TOTAL='' ERROR='' HTTP='' CREATED='2026-01-01 00:00:00' START='2026-01-01 00:00:00' END='' UNIT='' PID='999999' TEMP='$T/.arc-fetch-17000000000001'" > "$dir/17000000000001.conf"
    echo "ID='17000000000002' URL='http://x/' DIR='$T' NAME='' ON_CONFLICT='rename' PRIVATE='no' STATUS='done' BYTES='1' TOTAL='1' ERROR='' HTTP='200' CREATED='2026-01-01 00:00:00' START='2026-01-01 00:00:00' END='2026-01-01 00:00:01' UNIT='' PID='' TEMP=''" > "$dir/17000000000002.conf"
    as_user touch "$T/.arc-fetch-17000000000001"
    run v-list-fs-fetches $U1 json
    assert_success
    assert_equal "$(jq -r '."17000000000001".STATUS' <<< "$output")" failed
    assert_equal "$(jq -r '."17000000000001".ERROR' <<< "$output")" interrupted
    assert_equal "$(jq -r 'has("17000000000002")' <<< "$output")" false
    assert_file_not_exist "$T/.arc-fetch-17000000000001"
    run v-list-fs-fetches $U1 shell
    assert_success
    run v-list-fs-fetches $U1 csv
    assert_line --index 0 'ID,URL,DIR,NAME,ON_CONFLICT,STATUS,BYTES,TOTAL,ERROR,HTTP,CREATED,START,END'
}

@test "Suspending the user cancels its fetches" {
    id=$(fetch "$BASE/slow")
    wait_for "$id" running
    v-suspend-user $U1
    run v-add-fs-fetch $U1 "$T" "$REQ" rename yes
    v-unsuspend-user $U1
    assert_failure 5
    assert_equal "$(field "$id" STATUS)" cancelled
    run bash -c "ls -A '$T'"
    assert_output ''
}
