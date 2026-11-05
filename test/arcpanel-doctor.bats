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
# The suite's resolver: TEST-NET-1, never routed, so a check that is not
# pointed at the stub times out instead of asking the internet
NORESOLVER=192.0.2.1

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
    v-change-sys-config-value DOCTOR_RESOLVER "$NORESOLVER" > /dev/null
}

teardown_file() {
    rm -rf "$DD"
    [ -d "$SAVE/doctor" ] && mv "$SAVE/doctor" "$DD"
    if [ -s "$SAVE/resolver" ]; then
        v-change-sys-config-value DOCTOR_RESOLVER "$(cut -d\' -f2 "$SAVE/resolver")" > /dev/null
    else
        sed -i '/^DOCTOR_RESOLVER=/d' "$ARCPANEL/conf/arcpanel.conf"
    fi
    rm -f "$CHECKS/zz-test-slow.sh"
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
    v-change-sys-config-value DOCTOR_RESOLVER "$NORESOLVER" > /dev/null
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
    : > "$FIX/php-ini"
    run status_of server.php-ini
    assert_output ok
}

@test "server.docker: problems fail" {
    echo '{"FINDINGS":[{"USER":"alice","APP":"shop","TYPE":"stopped","DETAIL":""}]}' > "$FIX/docker"
    run status_of server.docker
    assert_output fail
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
    v-change-sys-config-value DOCTOR_RESOLVER 127.0.0.1 > /dev/null
    DOCTOR_DNS_PORT=39954 run check server.ptr
    v-change-sys-config-value DOCTOR_RESOLVER "$NORESOLVER" > /dev/null
    if lib 'ip=$(doctor_main_ip); doctor_is_ipv4 "$ip" && doctor_ip4_private "$ip"'; then
        assert_equal "$(jq -r .status <<< "$output")" skip
    else
        assert_equal "$(jq -r .status <<< "$output")" warn
        assert_output --partial 'The resolver 127.0.0.1 did not answer'
    fi
}
