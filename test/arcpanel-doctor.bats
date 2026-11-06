#!/usr/bin/env bats
# Server and Site Doctor (E17,
# docs/features/specs/2026-11-05-003-e17-doctor-design.md): the runner, the
# result files and the score, each check against fixtures (DOCTOR_FIXTURES,
# test certificates, a local DNS stub with DOCTOR_RESOLVER=127.0.0.1 and the
# server's own web server), the fix allow-list, the doctor.run and
# doctor.fix jobs and the alert events on change.
# Run as root on a test server. The suite moves data/doctor/ aside, keeps
# DOCTOR_RESOLVER and puts both back at the end. No third-party domain is
# ever queried: every name resolves through the local stub.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
DD=$ARCPANEL/data/doctor
JOB_DIR=$ARCPANEL/data/jobs
SAVE=/root/arcpanel-doctor-test-save
FIX=/root/arcpanel-doctor-test-fix
CERTS=/root/arcpanel-doctor-test-certs
CHECKS=$ARCPANEL/func/doctor
# The DNS stub (test/doctor-dns-stub.py) answers on 127.0.0.1:DNS_PORT from
# the zone file in DNS; DOCTOR_RESOLVER is 127.0.0.1 while the suite runs.
# Runs that cannot get DOCTOR_DNS_PORT (jobs) use TEST-NET-1, never routed,
# so that they time out instead of asking the internet.
DNS=/root/arcpanel-doctor-test-dns
DNS_PORT=39953
NORESOLVER=192.0.2.1
# The suite's hosting user and its web and mail domain
USR=arcdocusr
DOM=doctor-web.test
PASS="Doc-$(date +%s)-Pass"

# Run bash code with the Doctor library loaded
lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/doctor.sh; source_conf $ARCPANEL/conf/arcpanel.conf; $1"
}

# Run check ID for USER and OBJECT (default the server) with the fixtures
check() {
    local id="$1" user="${2:-system}" object="${3:-system}"
    DOCTOR_FIXTURES=$FIX bash "$ARCPANEL/func/doctor-check.sh" "$CHECKS/${id/./-}.sh" "$user" "$object"
}

# Field of the result of check ID: field ID FIELD [USER OBJECT]
status_of() {
    check "$@" | jq -r .status
}

conf_value() {
    grep -m1 "^$1=" "$ARCPANEL/conf/arcpanel.conf" | cut -d\' -f2
}

# Wait until job ID is finished; print its status
wait_job() {
    local status
    for _ in $(seq 1 "${2:-180}"); do
        status=$(grep -oE "STATUS='[^']*'" "$JOB_DIR/$1.conf" 2> /dev/null | cut -d\' -f2)
        if [ "$status" != queued ] && [ "$status" != running ]; then
            echo "$status"
            return
        fi
        sleep 1
    done
    echo timeout
}

job_field() {
    grep -oE "(^| )$2='[^']*'" "$JOB_DIR/$1.conf" | head -n1 | cut -d\' -f2
}

# A certificate signed by the suite's CA: signed NAME DAYS SANS
signed() {
    local name="$1" days="$2" sans="$3"
    openssl req -newkey rsa:2048 -nodes -keyout "$CERTS/$name.key" -out "$CERTS/$name.csr" \
        -subj "/CN=${sans%%,*}" > /dev/null 2>&1
    printf 'subjectAltName=%s\n' "$(sed 's/\([^,]*\)/DNS:\1/g' <<< "$sans")" > "$CERTS/$name.ext"
    openssl x509 -req -in "$CERTS/$name.csr" -CA "$CERTS/ca.crt" -CAkey "$CERTS/ca.key" -CAcreateserial \
        -days "$days" -out "$CERTS/$name.crt" -extfile "$CERTS/$name.ext" > /dev/null 2>&1
}

# The zone of the DNS stub: zone "NAME TYPE VALUE"...
zone() {
    printf '%s\n' "$@" > "$DNS/zone"
    rm -f "$DNS/drop"
}

stub_resolver() {
    v-change-sys-config-value DOCTOR_RESOLVER 127.0.0.1 > /dev/null
}

start_dns() {
    mkdir -p "$DNS"
    : > "$DNS/zone"
    setsid nohup python3 "$BATS_TEST_DIRNAME/doctor-dns-stub.py" "$DNS" "$DNS_PORT" > /dev/null 2>&1 < /dev/null &
    echo $! > "$DNS/pid"
    for _ in $(seq 1 50); do
        dig +short +time=1 +tries=1 -p "$DNS_PORT" @127.0.0.1 A ready.test > /dev/null 2>&1 && break
        sleep 0.1
    done
}

stop_dns() {
    [ -f "$DNS/pid" ] && kill "$(cat "$DNS/pid")" 2> /dev/null
    pkill -f "doctor-dns-stub.py $DNS" 2> /dev/null || true
    rm -rf "$DNS"
}

# The public and the local address of the suite's web domain
pub_ip() {
    lib "doctor_public_ip \"\$(doctor_field \"\$(doctor_domain_line $USR web $DOM)\" IP)\""
}
local_ip() {
    lib "doctor_local_ip \"\$(doctor_field \"\$(doctor_domain_line $USR web $DOM)\" IP)\""
}

# Retry COMMAND for up to 20 seconds until it succeeds (NGINX reloads in
# the background)
eventually() {
    for _ in $(seq 1 40); do
        "$@" && return 0
        sleep 0.5
    done
    "$@"
}

# The SAN of the certificate served for the suite's domain
served_names() {
    timeout 10 openssl s_client -connect "$(local_ip):443" -servername "$DOM" < /dev/null 2> /dev/null \
        | openssl x509 -noout -ext subjectAltName 2> /dev/null | grep -oE 'DNS:[^, ]+' | cut -d: -f2 | sort | paste -sd, -
}

# A folder with DOMAIN.crt/.key/.ca of the certificate NAME for v-*-ssl
ssl_dir() {
    local name="$1" domain="$2"
    rm -rf "$CERTS/dir-$name"
    mkdir -p "$CERTS/dir-$name"
    cp "$CERTS/$name.crt" "$CERTS/dir-$name/$domain.crt"
    cp "$CERTS/$name.key" "$CERTS/dir-$name/$domain.key"
    cp "$CERTS/ca.crt" "$CERTS/dir-$name/$domain.ca"
    echo "$CERTS/dir-$name"
}

setup_file() {
    rm -rf "$SAVE" "$FIX" "$CERTS"
    mkdir -p "$SAVE" "$FIX" "$CERTS"
    [ -d "$DD" ] && mv "$DD" "$SAVE/doctor"
    grep -E "^DOCTOR_RESOLVER=" "$ARCPANEL/conf/arcpanel.conf" > "$SAVE/resolver" || true
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$CERTS/ca.key" -out "$CERTS/ca.crt" -days 60 \
        -subj '/CN=ArcPanel Doctor Test CA' > /dev/null 2>&1
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$CERTS/self.key" -out "$CERTS/self.crt" -days 365 \
        -subj '/CN=self.doctor.test' -addext 'subjectAltName=DNS:self.doctor.test' > /dev/null 2>&1
    signed panel 30 panel.doctor.test
    signed short 3 panel.doctor.test
    signed web1 30 "$DOM"
    signed web2 30 "$DOM,www.$DOM"
    signed mail1 30 "mail.$DOM"
    signed mailx 30 "other.$DOM"
    stop_dns
    start_dns
    stub_resolver
    export DOCTOR_DNS_PORT=$DNS_PORT
    v-delete-user "$USR" > /dev/null 2>&1 || true
    v-add-user "$USR" "$PASS" "$USR@example.invalid" default > /dev/null
    v-add-web-domain "$USR" "$DOM" '' yes "www.$DOM" > /dev/null
    v-add-mail-domain "$USR" "$DOM" > /dev/null
}

teardown_file() {
    v-delete-user "$USR" > /dev/null 2>&1 || true
    stop_dns
    rm -rf "$DD"
    [ -d "$SAVE/doctor" ] && mv "$SAVE/doctor" "$DD"
    if [ -s "$SAVE/resolver" ]; then
        v-change-sys-config-value DOCTOR_RESOLVER "$(cut -d\' -f2 "$SAVE/resolver")" > /dev/null
    else
        sed -i '/^DOCTOR_RESOLVER=/d' "$ARCPANEL/conf/arcpanel.conf"
    fi
    rm -f "$CHECKS/zz-test-slow.sh" "$CHECKS/server-zz-test-fix.sh"
    rm -rf "$SAVE" "$FIX" "$CERTS"
}

setup() {
    rm -rf "${FIX:?}"/*
}

#----------------------------------------------------------#
#                       Framework                          #
#----------------------------------------------------------#

@test "Doctor: 15 server checks, each with its id, scope, title and docs anchor" {
    run lib 'for f in $(doctor_check_files server); do doctor_check_meta "$f"; done'
    assert_success
    assert_equal "$(wc -l <<< "$output")" 15
    run jq -rs '[.[] | select((.scope == "server") and (.id | startswith("server."))
        and (.title != "") and (.docs | startswith("/docs/guides/doctor#")))] | length' <<< "$output"
    assert_output 15
    for file in "$CHECKS"/server-*.sh; do
        id=$(lib "doctor_check_meta $file" | jq -r .id)
        assert_equal "$(basename "$file" .sh)" "${id/./-}"
    done
}

@test "Doctor: the score is (ok + 0.5 x warn) / checks not skipped, rounded; null when all skip" {
    run lib "printf '%s\n' '{\"status\":\"ok\"}' '{\"status\":\"warn\"}' '{\"status\":\"fail\"}' '{\"status\":\"skip\"}' | jq -s \"\$DOCTOR_JQ_SCORE . | score\""
    assert_output 50
    run lib "printf '%s\n' '{\"status\":\"ok\"}' '{\"status\":\"ok\"}' '{\"status\":\"warn\"}' | jq -s \"\$DOCTOR_JQ_SCORE . | score\""
    assert_output 83
    run lib "printf '%s\n' '{\"status\":\"skip\"}' | jq -s \"\$DOCTOR_JQ_SCORE . | score\""
    assert_output null
}

@test "Doctor: a check that runs past its budget answers skip" {
    cat > "$CHECKS/zz-test-slow.sh" << 'EOF'
DOCTOR_ID=server.zz-test-slow
DOCTOR_SCOPE=server
DOCTOR_TITLE='A slow check'
DOCTOR_DOCS='/docs/guides/doctor#test'
doctor_check() { sleep 10; dr_ok 'never'; }
EOF
    run lib "DOCTOR_BUDGET=2 doctor_run_check $CHECKS/zz-test-slow.sh system system"
    rm -f "$CHECKS/zz-test-slow.sh"
    assert_success
    assert_equal "$(jq -r .status <<< "$output")" skip
    assert_equal "$(jq -r .message <<< "$output")" "The check did not finish within 2 seconds."
    assert_equal "$(jq -r .id <<< "$output")" server.zz-test-slow
}

@test "Doctor: a fix outside the check's allow-list is dropped" {
    cat > "$CHECKS/zz-test-slow.sh" << 'EOF'
DOCTOR_ID=server.zz-test-slow
DOCTOR_SCOPE=server
DOCTOR_TITLE='A check with a bad fix'
DOCTOR_DOCS='/docs/guides/doctor#test'
DOCTOR_FIX_ALLOWED='v-restart-service'
doctor_check() { dr_fail 'broken'; dr_fix 'Delete everything' v-delete-user admin; }
EOF
    run bash "$ARCPANEL/func/doctor-check.sh" "$CHECKS/zz-test-slow.sh" system system
    rm -f "$CHECKS/zz-test-slow.sh"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -c .fix <<< "$output")" null
}

@test "Doctor: v-list-sys-doctor prints an empty result before the first run" {
    rm -rf "$DD"
    run v-list-sys-doctor system json
    assert_success
    assert_equal "$(jq -c . <<< "$output")" '{"checked_at":null,"score":null,"checks":[]}'
}

@test "Doctor: the arguments are checked" {
    run v-run-sys-doctor system example.com
    assert_failure 2
    run v-run-sys-doctor arcnosuchuser
    assert_failure 3
    run v-run-sys-doctor system '' cli later
    assert_failure 2
    run v-run-sys-doctor system '' 'bad actor!'
    assert_failure 2
    run v-list-sys-doctor system '' xml
    assert_failure 2
}

@test "Doctor: DOCTOR_RESOLVER takes an address or system and is listed" {
    run v-change-sys-config-value DOCTOR_RESOLVER 'not-an-ip'
    assert_failure 2
    run v-change-sys-config-value DOCTOR_RESOLVER 9.9.9.9
    assert_success
    run v-list-sys-config json
    assert_equal "$(jq -r .config.DOCTOR_RESOLVER <<< "$output")" 9.9.9.9
    run v-change-sys-config-value DOCTOR_RESOLVER system
    assert_success
    run v-change-sys-config-value DOCTOR_RESOLVER 2606:4700:4700::1111
    assert_success
    sed -i '/^DOCTOR_RESOLVER=/d' "$ARCPANEL/conf/arcpanel.conf"
    run v-list-sys-config json
    assert_equal "$(jq -r .config.DOCTOR_RESOLVER <<< "$output")" 1.1.1.1
    stub_resolver
}

@test "Doctor: a server run writes server.json (root:arcpanelweb 0640) with 15 results and the score" {
    rm -rf "$DD"
    run v-run-sys-doctor system '' cli now
    assert_success
    assert_output --regexp '^server: score [0-9-]+ \([0-9]+ failed, [0-9]+ warnings\)$'
    assert_file_exists "$DD/server.json"
    assert_equal "$(stat -c '%U:%G %a' "$DD/server.json")" 'root:arcpanelweb 640'
    assert_equal "$(stat -c '%U:%G %a' "$DD")" 'root:arcpanelweb 750'
    run jq -r '.checks | length' "$DD/server.json"
    assert_output 15
    # Every check gives a result
    run jq -r '[.checks[] | select(.status | IN("ok", "warn", "fail", "skip") | not)] | length' "$DD/server.json"
    assert_output 0
    run jq -r '[.checks[] | select(.message == "")] | length' "$DD/server.json"
    assert_output 0
    # The stored score is the score of the stored checks
    expected=$(lib "jq \"\$DOCTOR_JQ_SCORE .checks | score\" $DD/server.json")
    assert_equal "$(jq -r .score "$DD/server.json")" "$expected"
    run v-list-sys-doctor system json
    assert_equal "$(jq -c . <<< "$output")" "$(jq -c . "$DD/server.json")"
    run v-list-sys-doctor system
    assert_output --partial 'server.firewall'
}

@test "Doctor: v-run-sys-doctor starts a doctor.run job and the job writes the result" {
    rm -rf "$DD"
    v-change-sys-config-value DOCTOR_RESOLVER "$NORESOLVER" > /dev/null
    run v-run-sys-doctor system '' bats-doctor
    assert_success
    id=$output
    [[ "$id" =~ ^[0-9]{14}$ ]]
    assert_equal "$(job_field "$id" TYPE)" doctor.run
    assert_equal "$(job_field "$id" OBJECT)" doctor:system
    assert_equal "$(job_field "$id" USER)" system
    assert_equal "$(job_field "$id" ACTOR)" bats-doctor
    assert_equal "$(job_field "$id" RESOURCE)" /system/doctor
    run wait_job "$id"
    stub_resolver
    assert_output succeeded
    assert_file_exists "$DD/server.json"
    run grep -c '^server: score' "$JOB_DIR/$id.log"
    assert_output 1
}

#----------------------------------------------------------#
#                     Server checks                        #
#----------------------------------------------------------#

@test "server.ssh-root-password: fails when root may sign in with a password" {
    printf 'permitrootlogin yes\npasswordauthentication yes\n' > "$FIX/sshd"
    run check server.ssh-root-password
    assert_equal "$(jq -r .status <<< "$output")" fail
    printf 'permitrootlogin prohibit-password\npasswordauthentication yes\n' > "$FIX/sshd"
    run status_of server.ssh-root-password
    assert_output ok
    printf 'permitrootlogin yes\npasswordauthentication no\n' > "$FIX/sshd"
    run status_of server.ssh-root-password
    assert_output ok
    rm -f "$FIX/sshd"
    touch "$FIX/sshd.fail"
    run status_of server.ssh-root-password
    assert_output skip
}

@test "server.fail2ban: fails when stopped (fix: restart), warns without the SSH or panel jail" {
    echo inactive > "$FIX/fail2ban-state"
    run check server.fail2ban
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -r .fix.command <<< "$output")" 'v-restart-service fail2ban'
    assert_equal "$(jq -c .fix.argv <<< "$output")" '["v-restart-service","fail2ban"]'
    echo active > "$FIX/fail2ban-state"
    printf 'Status\n|- Number of jail:\t1\n`- Jail list:\tssh-iptables\n' > "$FIX/fail2ban-jails"
    run check server.fail2ban
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_equal "$(jq -r .message <<< "$output")" 'Fail2ban runs, but the panel jail is not active.'
    printf 'Status\n`- Jail list:\tarcpanel-iptables, recidive\n' > "$FIX/fail2ban-jails"
    run check server.fail2ban
    assert_equal "$(jq -r .message <<< "$output")" 'Fail2ban runs, but the SSH jail is not active.'
    printf 'Status\n`- Jail list:\tarcpanel-iptables, ssh-iptables\n' > "$FIX/fail2ban-jails"
    run status_of server.fail2ban
    assert_output ok
}

@test "server.firewall: missing table fails with the fix v-update-firewall, drift warns, off fails" {
    echo '{"STATUS":"missing","WARNINGS":[]}' > "$FIX/firewall"
    run check server.firewall
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -r .fix.command <<< "$output")" v-update-firewall
    echo '{"STATUS":"drift","WARNINGS":[]}' > "$FIX/firewall"
    run check server.firewall
    assert_equal "$(jq -r .status <<< "$output")" warn
    echo '{"STATUS":"off","WARNINGS":[]}' > "$FIX/firewall"
    run check server.firewall
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -c .fix <<< "$output")" null
    echo '{"STATUS":"ok","WARNINGS":[]}' > "$FIX/firewall"
    run status_of server.firewall
    assert_output ok
}

@test "server.panel-port: warns on the default port 8083" {
    run check server.panel-port
    if [ "$(conf_value BACKEND_PORT)" = 8083 ]; then
        assert_equal "$(jq -r .status <<< "$output")" warn
    else
        assert_equal "$(jq -r .status <<< "$output")" ok
    fi
    assert_equal "$(jq -r .value <<< "$output")" "$(conf_value BACKEND_PORT)"
}

@test "server.panel-update: warns for a newer release of the installed line" {
    echo '[{"VERSION":"9.9.1","KIND":"patch","WITHDRAWN":false},{"VERSION":"9.10.0","KIND":"minor","WITHDRAWN":false}]' > "$FIX/releases"
    run check server.panel-update
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_equal "$(jq -r .link <<< "$output")" updates
    echo '[{"VERSION":"9.10.0","KIND":"minor","WITHDRAWN":false}]' > "$FIX/releases"
    run status_of server.panel-update
    assert_output ok
    echo '[{"VERSION":"9.9.1","KIND":"patch","WITHDRAWN":true}]' > "$FIX/releases"
    run status_of server.panel-update
    assert_output ok
    rm -f "$FIX/releases"
    touch "$FIX/releases.fail"
    run status_of server.panel-update
    assert_output skip
}

@test "server.os-updates: warns for pending security updates, fails after 30 days without updates" {
    cat > "$FIX/apt-upgrade" << 'EOF'
Reading package lists...
Inst libssl3t64 [3.0.13-0ubuntu3.4] (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-security [amd64])
Inst openssl [3.0.13-0ubuntu3.4] (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst vim [2:9.1] (2:9.1.1 Ubuntu:24.04/noble-updates [amd64])
Conf openssl (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-security [amd64])
EOF
    date +%s > "$FIX/dpkg-changed"
    run check server.os-updates
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_equal "$(jq -r .value <<< "$output")" 2
    echo $(($(date +%s) - 40 * 86400)) > "$FIX/dpkg-changed"
    run status_of server.os-updates
    assert_output fail
    echo 'Inst vim [2:9.1] (2:9.1.1 Ubuntu:24.04/noble-updates [amd64])' > "$FIX/apt-upgrade"
    run status_of server.os-updates
    assert_output ok
}

@test "server.swap: warns without swap below 4 GB of memory" {
    printf 'MemTotal:        2000000 kB\nSwapTotal:             0 kB\n' > "$FIX/meminfo"
    run status_of server.swap
    assert_output warn
    printf 'MemTotal:        2000000 kB\nSwapTotal:       1048572 kB\n' > "$FIX/meminfo"
    run status_of server.swap
    assert_output ok
    printf 'MemTotal:        8000000 kB\nSwapTotal:             0 kB\n' > "$FIX/meminfo"
    run status_of server.swap
    assert_output ok
}

@test "server.inodes: warns below 10% free, fails below 5%" {
    dfi() {
        echo 'Filesystem Inodes IUsed IFree IUse% Mounted on'
        echo "/dev/sda 1000 100 900 10% /"
        echo "/dev/sdb 1000 $((1000 - $1)) $1 x% /home"
    }
    dfi 40 > "$FIX/dfi"
    run check server.inodes
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -r .value <<< "$output")" '/home 4% free'
    dfi 80 > "$FIX/dfi"
    run status_of server.inodes
    assert_output warn
    dfi 500 > "$FIX/dfi"
    run status_of server.inodes
    assert_output ok
}

@test "server.ports: findings warn with the fix v-check-sys-ports repair" {
    printf 'orphan-socket|root|38300|tcp||listening without an entry\nmissing-rule|alice|38400|tcp||public port without a rule\n' > "$FIX/ports"
    run check server.ports
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_equal "$(jq -r .value <<< "$output")" 2
    assert_equal "$(jq -r .fix.command <<< "$output")" 'v-check-sys-ports repair'
    assert_equal "$(jq -r .message <<< "$output")" 'The port registry report has 2 findings (missing-rule orphan-socket).'
    printf 'orphan-socket|root|38300|tcp||listening without an entry\n' > "$FIX/ports"
    run check server.ports
    assert_equal "$(jq -r .message <<< "$output")" 'The port registry report has 1 finding (orphan-socket).'
    : > "$FIX/ports"
    run status_of server.ports
    assert_output ok
}

@test "server.time-sync: fails without NTP synchronisation" {
    echo no > "$FIX/time-sync"
    run status_of server.time-sync
    assert_output fail
    echo yes > "$FIX/time-sync"
    run status_of server.time-sync
    assert_output ok
}

@test "server.php-ini: findings warn" {
    printf '8.3\twarning\tfoo_bar: unknown directive\n' > "$FIX/php-ini"
    run check server.php-ini
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_output --partial 'PHP 8.3'
    assert_equal "$(jq -r .message <<< "$output")" 'The php.ini of PHP 8.3 has 1 finding, for example: foo_bar: unknown directive'
    printf '8.3\twarning\tfoo_bar: unknown directive\n8.4\terror\tbad line\n' > "$FIX/php-ini"
    run check server.php-ini
    assert_equal "$(jq -r .message <<< "$output")" 'The php.ini of PHP 8.3 8.4 has 2 findings, for example: foo_bar: unknown directive'
    : > "$FIX/php-ini"
    run status_of server.php-ini
    assert_output ok
}

@test "server.docker: problems fail" {
    echo '{"FINDINGS":[{"USER":"alice","APP":"shop","TYPE":"stopped","DETAIL":""}]}' > "$FIX/docker"
    run status_of server.docker
    assert_output fail
    run check server.docker
    assert_equal "$(jq -r .message <<< "$output")" 'The Docker check reports 1 problem (stopped). The repair that runs every 5 minutes did not clear it.'
    echo '{"FINDINGS":[]}' > "$FIX/docker"
    run status_of server.docker
    assert_output ok
}

@test "server.app-processes: skipped without processes, a process down warns" {
    echo '{"PROCESSES":"0","FINDINGS":[]}' > "$FIX/app-processes"
    run status_of server.app-processes
    assert_output skip
    echo '{"PROCESSES":"2","FINDINGS":[{"USER":"alice","TYPE":"process-down","DOMAIN":"a.example","NAME":"worker","DETAIL":""}]}' > "$FIX/app-processes"
    run status_of server.app-processes
    assert_output warn
    echo '{"PROCESSES":"2","FINDINGS":[{"USER":"alice","TYPE":"over-limit","DOMAIN":"","NAME":"","DETAIL":""}]}' > "$FIX/app-processes"
    run status_of server.app-processes
    assert_output ok
}

@test "server.panel-ssl: self-signed warns, untrusted, expiring or expired fails, trusted passes" {
    cp "$CERTS/self.crt" "$FIX/panel-cert"
    run status_of server.panel-ssl
    assert_output warn
    cp "$CERTS/panel.crt" "$FIX/panel-cert"
    run status_of server.panel-ssl
    assert_output fail
    DOCTOR_CA_FILE=$CERTS/ca.crt run check server.panel-ssl
    assert_equal "$(jq -r .status <<< "$output")" ok
    assert_equal "$(jq -r .value <<< "$output")" panel.doctor.test
    cp "$CERTS/short.crt" "$FIX/panel-cert"
    DOCTOR_CA_FILE=$CERTS/ca.crt run status_of server.panel-ssl
    assert_output fail
    cp "$CERTS/panel.crt" "$FIX/panel-cert"
    DOCTOR_CA_FILE=$CERTS/ca.crt DOCTOR_NOW=$(($(date +%s) + 40 * 86400)) run check server.panel-ssl
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_output --partial 'expired'
    echo 'not a certificate' > "$FIX/panel-cert"
    run status_of server.panel-ssl
    assert_output fail
}

@test "server.ptr: a resolver that does not answer warns that the DNS checks were skipped" {
    DOCTOR_DNS_PORT=39954 run check server.ptr
    if lib 'ip=$(doctor_main_ip); doctor_is_ipv4 "$ip" && doctor_ip4_private "$ip"'; then
        assert_equal "$(jq -r .status <<< "$output")" skip
    else
        assert_equal "$(jq -r .status <<< "$output")" warn
        assert_output --partial 'The resolver 127.0.0.1 did not answer'
    fi
}

@test "server.ptr: the PTR of the main address must name the hostname" {
    ip=$(lib doctor_main_ip)
    if lib "doctor_is_ipv4 $ip && doctor_ip4_private $ip"; then
        skip "the main address $ip is private"
    fi
    rev=$(awk -F. '{ print $4 "." $3 "." $2 "." $1 }' <<< "$ip").in-addr.arpa
    zone "$rev PTR host.doctor.test" "host.doctor.test A $ip"
    DOCTOR_HOSTNAME=host.doctor.test run check server.ptr
    assert_equal "$(jq -r .status <<< "$output")" ok
    DOCTOR_HOSTNAME=other.doctor.test run check server.ptr
    assert_equal "$(jq -r .status <<< "$output")" warn
    zone "host.doctor.test A $ip"
    DOCTOR_HOSTNAME=host.doctor.test run check server.ptr
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_output --partial 'has no PTR record'
}

#----------------------------------------------------------#
#                      Web checks                          #
#----------------------------------------------------------#

@test "Doctor: 7 web and 5 mail checks" {
    run lib 'for s in web mail; do for f in $(doctor_check_files $s); do doctor_check_meta "$f"; done; done'
    assert_equal "$(jq -rs '[.[] | select(.scope == "web")] | length' <<< "$output")" 7
    assert_equal "$(jq -rs '[.[] | select(.scope == "mail")] | length' <<< "$output")" 5
    assert_equal "$(jq -rs '[.[] | select(.scope as $s | .id | startswith($s + "."))] | length' <<< "$output")" 12
}

@test "web.dns-a: every name must resolve to the domain's address (NAT-aware); a proxy warns" {
    ip=$(pub_ip)
    zone "$DOM A $ip" "www.$DOM A $ip"
    run check web.dns-a "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" ok
    assert_equal "$(jq -r .value <<< "$output")" "$ip"
    zone "$DOM A $ip" "www.$DOM A 203.0.113.9"
    run check web.dns-a "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_output --partial "www.$DOM (203.0.113.9)"
    assert_output --partial "Publish an A record with the address $ip"
    assert_equal "$(jq -r .link <<< "$output")" ''
    zone "$DOM A $ip"
    run check web.dns-a "$USR" "$DOM"
    assert_output --partial "www.$DOM (no A record)"
    zone "$DOM A 104.16.0.1" "www.$DOM A 104.16.0.2"
    run check web.dns-a "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_output --partial 'proxy'
    zone "$DOM A $ip"
    echo "$DOM" > "$DNS/drop"
    run check web.dns-a "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" skip
    assert_output --partial 'The resolver 127.0.0.1 did not answer.'
}

@test "web.dns-a: a zone on this server links the DNS page" {
    v-add-dns-domain "$USR" "$DOM" "$(pub_ip)" > /dev/null
    zone "$DOM A 203.0.113.9"
    run check web.dns-a "$USR" "$DOM"
    v-delete-dns-domain "$USR" "$DOM" > /dev/null
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -r .link <<< "$output")" dns
    assert_output --partial "in the DNS zone $DOM on this server"
}

@test "web.dns-aaaa: skipped without IPv6, warns when the AAAA record is missing or elsewhere" {
    run status_of web.dns-aaaa "$USR" "$DOM"
    assert_output skip
    conf=$ARCPANEL/data/users/$USR/web.conf
    cp "$conf" "$FIX/web.conf.save"
    sed -i "/^DOMAIN='$DOM'/ s/IP6='[^']*'/IP6='2001:db8::10'/" "$conf"
    zone "$DOM AAAA 2001:db8::10"
    run status_of web.dns-aaaa "$USR" "$DOM"
    ok=$output
    zone "$DOM AAAA 2001:db8::99"
    run status_of web.dns-aaaa "$USR" "$DOM"
    elsewhere=$output
    zone "$DOM A 192.0.2.10"
    run status_of web.dns-aaaa "$USR" "$DOM"
    missing=$output
    cp "$FIX/web.conf.save" "$conf"
    assert_equal "$ok $elsewhere $missing" 'ok warn warn'
}

@test "web.ssl-*: skipped while SSL is off" {
    for id in web.ssl-valid web.ssl-aliases web.ssl-expiry web.https-redirect; do
        run status_of "$id" "$USR" "$DOM"
        assert_output skip
    done
}

@test "web.ssl-valid and web.ssl-aliases: an untrusted certificate without the alias fails with the Let's Encrypt fix" {
    v-add-web-domain-ssl "$USR" "$DOM" "$(ssl_dir web1 "$DOM")" > /dev/null
    eventually test "$(served_names)" = "$DOM"
    run check web.ssl-valid "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -c .fix.argv <<< "$output")" "[\"v-add-letsencrypt-domain\",\"$USR\",\"$DOM\",\"www.$DOM\"]"
    assert_equal "$(jq -r .fix.command <<< "$output")" "v-add-letsencrypt-domain $USR $DOM www.$DOM"
    DOCTOR_CA_FILE=$CERTS/ca.crt run check web.ssl-valid "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" ok
    assert_equal "$(jq -c .fix <<< "$output")" null
    DOCTOR_CA_FILE=$CERTS/ca.crt run check web.ssl-aliases "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -r .message <<< "$output")" "www.$DOM is not in the certificate."
    assert_equal "$(jq -r .fix.argv[0] <<< "$output")" v-add-letsencrypt-domain
    DOCTOR_CA_FILE=$CERTS/ca.crt DOCTOR_NOW=$(($(date +%s) + 40 * 86400)) run check web.ssl-valid "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_output --partial 'expired'
}

@test "web.ssl-expiry: warns within 14 days, fails within 3 days" {
    run status_of web.ssl-expiry "$USR" "$DOM"
    assert_output ok
    DOCTOR_NOW=$(($(date +%s) + 20 * 86400)) run check web.ssl-expiry "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_equal "$(jq -r .fix.argv[0] <<< "$output")" v-add-letsencrypt-domain
    DOCTOR_NOW=$(($(date +%s) + 28 * 86400)) run status_of web.ssl-expiry "$USR" "$DOM"
    assert_output fail
    DOCTOR_NOW=$(($(date +%s) + 40 * 86400)) run check web.ssl-expiry "$USR" "$DOM"
    assert_output --partial 'expired'
}

@test "web.ssl-aliases: a certificate with every alias passes" {
    v-change-web-domain-sslcert "$USR" "$DOM" "$(ssl_dir web2 "$DOM")" > /dev/null
    eventually test "$(served_names)" = "$DOM,www.$DOM"
    run status_of web.ssl-aliases "$USR" "$DOM"
    assert_output ok
}

@test "web.https-redirect: warns without a permanent redirect, with the fix v-add-web-domain-ssl-force" {
    run check web.https-redirect "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_equal "$(jq -r .fix.command <<< "$output")" "v-add-web-domain-ssl-force $USR $DOM"
    v-add-web-domain-ssl-force "$USR" "$DOM" > /dev/null
    eventually test "$(status_of web.https-redirect "$USR" "$DOM")" = ok
    run check web.https-redirect "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" ok
    echo '302 https://x/' > "$FIX/http-$DOM"
    run status_of web.https-redirect "$USR" "$DOM"
    assert_output warn
}

@test "web.php-eol: an eol version fails, a security-only version warns" {
    printf '{"8.1":{"USED_BY":"other/a.test,%s/%s","STATUS":"eol","EOL":"2025-12-31"},"8.4":{"USED_BY":"","STATUS":"active"}}\n' "$USR" "$DOM" > "$FIX/php-versions"
    run check web.php-eol "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -r .value <<< "$output")" 8.1
    assert_equal "$(jq -r .link <<< "$output")" php
    sed -i 's/"eol"/"security"/' "$FIX/php-versions"
    run status_of web.php-eol "$USR" "$DOM"
    assert_output warn
    sed -i 's/"security"/"active"/' "$FIX/php-versions"
    run status_of web.php-eol "$USR" "$DOM"
    assert_output ok
    echo '{"8.4":{"USED_BY":"","STATUS":"active"}}' > "$FIX/php-versions"
    run status_of web.php-eol "$USR" "$DOM"
    assert_output skip
}

#----------------------------------------------------------#
#                      Mail checks                         #
#----------------------------------------------------------#

@test "mail.mx: an MX record must point to this server" {
    ip=$(pub_ip)
    zone "$DOM MX 10 mail.$DOM" "mail.$DOM A $ip"
    run status_of mail.mx "$USR" "$DOM"
    assert_output ok
    zone "$DOM MX 10 mx.elsewhere.test" "mx.elsewhere.test A 203.0.113.5"
    run check mail.mx "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -r .value <<< "$output")" mx.elsewhere.test
    zone "$DOM A $ip"
    run status_of mail.mx "$USR" "$DOM"
    assert_output fail
    echo "$DOM" > "$DNS/drop"
    run status_of mail.mx "$USR" "$DOM"
    assert_output skip
}

@test "mail.spf: missing fails; ip4, a, mx and include allow this server; another server warns" {
    ip=$(pub_ip)
    zone "$DOM A $ip"
    run status_of mail.spf "$USR" "$DOM"
    assert_output fail
    zone "$DOM TXT v=spf1 ip4:$ip -all"
    run status_of mail.spf "$USR" "$DOM"
    assert_output ok
    zone "$DOM TXT v=spf1 ip4:${ip%.*}.0/24 ?all"
    run status_of mail.spf "$USR" "$DOM"
    assert_output ok
    zone "$DOM TXT v=spf1 a -all" "$DOM A $ip"
    run status_of mail.spf "$USR" "$DOM"
    assert_output ok
    zone "$DOM TXT v=spf1 mx -all" "$DOM MX 10 mail.$DOM" "mail.$DOM A $ip"
    run status_of mail.spf "$USR" "$DOM"
    assert_output ok
    zone "$DOM TXT v=spf1 include:_spf.$DOM -all" "_spf.$DOM TXT v=spf1 ip4:$ip ~all"
    run status_of mail.spf "$USR" "$DOM"
    assert_output ok
    zone "$DOM TXT v=spf1 ip4:203.0.113.5 -all"
    run check mail.spf "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_output --partial 'does not allow this server'
    zone "$DOM TXT v=spf1 ip4:$ip -all" "$DOM TXT v=spf1 a -all"
    run check mail.spf "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_output --partial '2 SPF records'
}

@test "mail.dkim: off fails with the fix v-add-mail-domain-dkim; the published key must match" {
    key=$(grep -v 'KEY-----' "$ARCPANEL/data/users/$USR/mail/$DOM.pub" | tr -d '\n')
    zone "mail._domainkey.$DOM TXT v=DKIM1; k=rsa; p=$key"
    run status_of mail.dkim "$USR" "$DOM"
    assert_output ok
    zone "mail._domainkey.$DOM TXT v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAother"
    run status_of mail.dkim "$USR" "$DOM"
    assert_output warn
    zone "$DOM A 192.0.2.10"
    run check mail.dkim "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" warn
    assert_output --partial 'is not published'
    v-delete-mail-domain-dkim "$USR" "$DOM" > /dev/null
    run check mail.dkim "$USR" "$DOM"
    v-add-mail-domain-dkim "$USR" "$DOM" > /dev/null
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -c .fix.argv <<< "$output")" "[\"v-add-mail-domain-dkim\",\"$USR\",\"$DOM\"]"
}

@test "mail.dmarc: warns without a _dmarc record" {
    zone "_dmarc.$DOM TXT v=DMARC1; p=quarantine"
    run status_of mail.dmarc "$USR" "$DOM"
    assert_output ok
    zone "$DOM A 192.0.2.10"
    run status_of mail.dmarc "$USR" "$DOM"
    assert_output warn
}

@test "mail.ssl: no certificate fails with the Let's Encrypt fix for mail; the certificate must cover mail.DOMAIN" {
    run check mail.ssl "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_equal "$(jq -c .fix.argv <<< "$output")" "[\"v-add-letsencrypt-domain\",\"$USR\",\"$DOM\",\"\",\"yes\"]"
    assert_equal "$(jq -r .fix.command <<< "$output")" "v-add-letsencrypt-domain $USR $DOM '' yes"
    v-add-mail-domain-ssl "$USR" "$DOM" "$(ssl_dir mail1 "$DOM")" > /dev/null
    DOCTOR_CA_FILE=$CERTS/ca.crt run status_of mail.ssl "$USR" "$DOM"
    assert_output ok
    run status_of mail.ssl "$USR" "$DOM"
    assert_output fail
    v-delete-mail-domain-ssl "$USR" "$DOM" > /dev/null
    v-add-mail-domain-ssl "$USR" "$DOM" "$(ssl_dir mailx "$DOM")" > /dev/null
    DOCTOR_CA_FILE=$CERTS/ca.crt run check mail.ssl "$USR" "$DOM"
    assert_equal "$(jq -r .status <<< "$output")" fail
    assert_output --partial "does not cover mail.$DOM"
}

#----------------------------------------------------------#
#                     Domain runs                          #
#----------------------------------------------------------#

@test "Doctor: a domain run writes the 12 checks of the domain; the summaries list it" {
    zone "$DOM A $(pub_ip)" "www.$DOM A $(pub_ip)"
    run v-run-sys-doctor "$USR" "$DOM" cli now
    assert_success
    assert_output --regexp "^$DOM: score [0-9]+ "
    file=$ARCPANEL/data/users/$USR/doctor/$DOM.json
    assert_file_exists "$file"
    assert_equal "$(stat -c '%U:%G %a' "$file")" 'root:arcpanelweb 640'
    assert_equal "$(jq '.checks | length' "$file")" 12
    assert_equal "$(jq -r '[.checks[] | select(.id == "web.dns-a")][0].status' "$file")" ok
    run v-list-sys-doctor "$USR" "$DOM" json
    assert_equal "$(jq -c . <<< "$output")" "$(jq -c . "$file")"
    run v-list-sys-doctor "$USR" json
    assert_equal "$(jq -r '.[0].domain' <<< "$output")" "$DOM"
    assert_equal "$(jq -r '.[0].score' <<< "$output")" "$(jq -r .score "$file")"
    run v-list-sys-doctor all json
    assert_equal "$(jq -r --arg u "$USR" '[.[] | select(.user == $u)] | length' <<< "$output")" 1
    run v-run-sys-doctor "$USR" nosuch.test
    assert_failure 3
}

@test "Doctor: a user run checks every domain of the user as a doctor.run job" {
    v-change-sys-config-value DOCTOR_RESOLVER "$NORESOLVER" > /dev/null
    run v-run-sys-doctor "$USR" "$DOM" bats-doctor
    id=$output
    run wait_job "$id" 300
    stub_resolver
    assert_output succeeded
    assert_equal "$(job_field "$id" OBJECT)" "doctor:$USR:$DOM"
    assert_equal "$(job_field "$id" USER)" "$USR"
    assert_equal "$(job_field "$id" RESOURCE)" "/users/$USR/doctor/$DOM"
    run grep -c "^$DOM: score" "$JOB_DIR/$id.log"
    assert_output 1
}

@test "Doctor: a suspended web domain skips its checks" {
    v-suspend-web-domain "$USR" "$DOM" > /dev/null
    run v-run-sys-doctor "$USR" "$DOM" cli now
    v-unsuspend-web-domain "$USR" "$DOM" > /dev/null
    file=$ARCPANEL/data/users/$USR/doctor/$DOM.json
    assert_equal "$(jq -r '[.checks[] | select(.id | startswith("web.")) | .status] | unique | join(",")' "$file")" skip
    assert_equal "$(jq -r '[.checks[] | select(.id | startswith("web."))][0].message' "$file")" 'The web domain is suspended.'
}

@test "Doctor: deleting the domain deletes its result" {
    file=$ARCPANEL/data/users/$USR/doctor/$DOM.json
    v-run-sys-doctor "$USR" "$DOM" cli now > /dev/null
    assert_file_exists "$file"
    v-delete-mail-domain "$USR" "$DOM" > /dev/null
    assert_file_not_exists "$file"
    v-add-mail-domain "$USR" "$DOM" > /dev/null
}

#----------------------------------------------------------#
#                Fix, nightly run, alerts                  #
#----------------------------------------------------------#

# The doctor events of events.log (E16) as JSON lines
doctor_events() {
    grep -h '"type":"doctor\.' "$ARCPANEL/data/alerts/events.log" 2> /dev/null || true
}

# A server check of the suite whose fix fails
fake_fix_check() {
    cat > "$CHECKS/server-zz-test-fix.sh" << 'EOF'
DOCTOR_ID=server.zz-test-fix
DOCTOR_SCOPE=server
DOCTOR_TITLE='A check of the test suite'
DOCTOR_DOCS='/docs/guides/doctor#test'
DOCTOR_FIX_ALLOWED='v-restart-service'
doctor_check() { dr_fail 'The test check fails.'; dr_fix 'Restart nothing' v-restart-service arcnosuchservice; }
EOF
}

@test "v-fix-sys-doctor: the arguments are checked" {
    run v-fix-sys-doctor system server.nosuch
    assert_failure 3
    run v-fix-sys-doctor system web.https-redirect
    assert_failure 2
    run v-fix-sys-doctor "$USR" server.fail2ban "$DOM"
    assert_failure 2
    run v-fix-sys-doctor "$USR" web.https-redirect
    assert_failure 1
    run v-fix-sys-doctor "$USR" web.https-redirect nosuch.test
    assert_failure 3
    run v-fix-sys-doctor system server.fail2ban '' 'bad actor!'
    assert_failure 2
}

@test "v-fix-sys-doctor: a check that passes or has no fix answers E_EXISTS" {
    zone "$DOM A $(pub_ip)" "www.$DOM A $(pub_ip)"
    run v-fix-sys-doctor "$USR" web.dns-a "$DOM" bats-doctor
    assert_failure 4
    assert_output --partial 'does not fail (ok)'
    zone "$DOM A 203.0.113.9"
    run v-fix-sys-doctor "$USR" web.dns-a "$DOM" bats-doctor
    assert_failure 4
    assert_output --partial 'has no fix'
}

@test "v-fix-sys-doctor: a doctor.fix job runs the fix and the check again, and updates the result" {
    v-delete-web-domain-ssl-force "$USR" "$DOM" > /dev/null
    eventually test "$(status_of web.https-redirect "$USR" "$DOM")" = warn
    v-run-sys-doctor "$USR" "$DOM" cli now > /dev/null
    file=$ARCPANEL/data/users/$USR/doctor/$DOM.json
    assert_equal "$(jq -r '.checks[] | select(.id == "web.https-redirect") | .status' "$file")" warn
    before=$(jq -r .score "$file")
    run v-fix-sys-doctor "$USR" web.https-redirect "$DOM" bats-doctor
    assert_success
    id=$output
    assert_equal "$(job_field "$id" TYPE)" doctor.fix
    assert_equal "$(job_field "$id" OBJECT)" "doctor:$USR:$DOM:web.https-redirect"
    assert_equal "$(job_field "$id" USER)" "$USR"
    assert_equal "$(job_field "$id" ACTOR)" bats-doctor
    run wait_job "$id"
    assert_output succeeded
    run cat "$JOB_DIR/$id.log"
    assert_line --regexp '^Check: web.https-redirect: warn - '
    assert_line "Running: v-add-web-domain-ssl-force $USR $DOM"
    assert_line --regexp '^Check again: web.https-redirect: ok - '
    assert_equal "$(jq -r '.checks[] | select(.id == "web.https-redirect") | .status' "$file")" ok
    [ "$(jq -r .score "$file")" -gt "$before" ]
}

@test "v-fix-sys-doctor: a fix that fails fails the job and keeps the result" {
    fake_fix_check
    v-run-sys-doctor system '' cli now > /dev/null
    stored=$(jq -c '.checks[] | select(.id == "server.zz-test-fix")' "$DD/server.json")
    run v-fix-sys-doctor system server.zz-test-fix '' bats-doctor
    assert_success
    id=$output
    assert_equal "$(job_field "$id" OBJECT)" doctor:system:server.zz-test-fix
    run wait_job "$id"
    rm -f "$CHECKS/server-zz-test-fix.sh"
    assert_output failed
    run grep -c 'Error: the fix failed' "$JOB_DIR/$id.log"
    assert_output 1
    assert_equal "$(jq -c '.checks[] | select(.id == "server.zz-test-fix")' "$DD/server.json")" "$stored"
}

@test "Doctor: a check that changes to fail emits doctor.failed once" {
    rm -rf "$DD"
    echo yes > "$FIX/time-sync"
    DOCTOR_FIXTURES=$FIX v-run-sys-doctor system '' cli now > /dev/null
    n0=$(doctor_events | grep -c 'server.time-sync' || true)
    echo no > "$FIX/time-sync"
    DOCTOR_FIXTURES=$FIX run v-run-sys-doctor system '' cli now
    assert_line 'Alert: server.time-sync fails on the server'
    n1=$(doctor_events | grep -c 'server.time-sync' || true)
    assert_equal "$n1" $((n0 + 1))
    event=$(doctor_events | grep 'server.time-sync' | tail -n1)
    assert_equal "$(jq -r .type <<< "$event")" doctor.failed
    assert_equal "$(jq -r .user <<< "$event")" system
    assert_equal "$(jq -r .object <<< "$event")" doctor:system:server.time-sync
    assert_equal "$(jq -r .severity <<< "$event")" warning
    DOCTOR_FIXTURES=$FIX v-run-sys-doctor system '' cli now > /dev/null
    assert_equal "$(doctor_events | grep -c 'server.time-sync')" "$n1"
}

@test "Doctor: a failing domain check emits doctor.failed for its user" {
    zone "$DOM A 203.0.113.9"
    rm -f "$ARCPANEL/data/users/$USR/doctor/$DOM.json"
    run v-run-sys-doctor "$USR" "$DOM" cli now
    assert_line "Alert: web.dns-a fails on $DOM"
    event=$(doctor_events | grep "doctor:$DOM:web.dns-a" | tail -n1)
    assert_equal "$(jq -r .user <<< "$event")" "$USR"
    assert_output --partial 'fails on'
}

@test "Doctor: the Monday run of cron sends the weekly digest per scope" {
    run lib "DOCTOR_NOW=$(date -d '2026-11-09 04:40' +%s) doctor_digest_day"
    assert_success
    run lib "DOCTOR_NOW=$(date -d '2026-11-10 04:40' +%s) doctor_digest_day"
    assert_failure
    zone "$DOM A 203.0.113.9"
    v-run-sys-doctor "$USR" "$DOM" cli now > /dev/null
    n0=$(doctor_events | grep -c '"type":"doctor.digest"' || true)
    run lib "doctor_digest $USR"
    assert_line --regexp "^Digest: $USR, ([2-9]|[1-9][0-9]+) checks$|^Digest: $USR, 1 check$"
    run lib "doctor_checks_text 1 verb; doctor_checks_text 3; doctor_checks_text 2 verb"
    assert_output "$(printf '1 check is\n3 checks\n2 checks are')"
    event=$(doctor_events | grep '"type":"doctor.digest"' | grep "\"user\":\"$USR\"" | tail -n1)
    assert_equal "$(jq -r .object <<< "$event")" "doctor:$USR"
    assert_equal "$(jq -r .severity <<< "$event")" info
    assert_equal "$(jq -r .system <<< "$event")" false
    assert_equal "$(jq -r .message <<< "$event" | grep -c "$DOM web.dns-a (fail)")" 1
    [ "$(doctor_events | grep -c '"type":"doctor.digest"')" -gt "$n0" ]
}

@test "Doctor: the alert links of doctor objects open the Doctor pages" {
    run bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/alerts.sh; source_conf $ARCPANEL/conf/arcpanel.conf; alerts_object_url system doctor:system:server.ptr; alerts_object_url $USR doctor:$DOM:web.dns-a"
    assert_line --regexp '/server/doctor$'
    assert_line --regexp ':[0-9]+/doctor$'
}

@test "Doctor: the 2.7.0 step adds the nightly cron line once" {
    cron=/var/spool/cron/crontabs/arcpanelweb
    cp -p "$cron" "$FIX/crontab.save"
    sed -i '/bin\/v-run-sys-doctor/d' "$cron"
    sed -n '/^# E17 Doctor/,/^unset upd_step_cron/p' "$BATS_TEST_DIRNAME/../install/upgrade/versions/2.7.0.sh" > "$FIX/step.sh"
    run bash "$FIX/step.sh"
    run bash "$FIX/step.sh"
    run grep -c 'bin/v-run-sys-doctor' "$cron"
    line=$(grep 'bin/v-run-sys-doctor' "$cron")
    cp -p "$FIX/crontab.save" "$cron"
    assert_output 1
    assert_equal "$line" "40 04 * * * sudo /usr/local/arcpanel/bin/v-run-sys-doctor all '' cron > /dev/null 2>&1"
}
