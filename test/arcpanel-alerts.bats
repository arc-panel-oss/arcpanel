#!/usr/bin/env bats
# Alerts and notification channels (E16,
# docs/features/specs/2026-11-05-002-e16-alerts-design.md): the rule
# catalog and its commands, each evaluator against fixtures, the state
# machine (fire, raise, remind, resolve, FOR), the panel channel, the rate
# limit, probe failures and the engine's budget.
# Run as root on a test server. The suite moves data/alerts/ and the alert
# folders of the users aside, takes the engine's cron line out while it
# runs, and puts everything back at the end. Probes read fixture files
# (ALERTS_FIXTURES) and the clock is ALERTS_NOW.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
AD=$ARCPANEL/data/alerts
SAVE=/root/arcpanel-alerts-test-save
FIX=/root/arcpanel-alerts-test-fix
# The local HTTP stub of the webhook and Telegram channels, and a mailer
STUB=/root/arcpanel-alerts-test-stub
PORT=39917
URL=http://127.0.0.1:$PORT
CRONTAB=/var/spool/cron/crontabs/arcpanelweb
HOST=bats-alerts.test
PASS="Alr-$(date +%s)-Pass"
# Users of the suite: a reseller, its customer and a customer of the admin
RES=arcalres
CUS=arcalcus
USR=arcalusr
PKG=arcalpkg
# The suite's clock: 2026-11-06 10:00:00 in the server's time zone
T0=$(date -d '2026-11-06 10:00:00' +%s)
ROOT_USER=$(grep -m1 "^ROOT_USER=" "$ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)
ROOT_USER=${ROOT_USER:-admin}

# Run the engine at minute M after T0
engine() {
    ALERTS_FIXTURES=$FIX ALERTS_HOSTNAME=$HOST ALERTS_NOW=$((T0 + ${1:-0} * 60)) v-check-sys-alerts
}

# Run bash code with the alert library loaded
lib() {
    bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/secret.sh; source $ARCPANEL/func/alerts.sh; source_conf $ARCPANEL/conf/arcpanel.conf; $1"
}

panel_id() {
    v-list-alert-channels "${1:-system}" json | jq -r '.[] | select(.TYPE == "panel") | .ID'
}

# Turn every rule of SCOPE off, then RULE on with the remaining arguments of
# v-change-alert-rule
only_rule() {
    local scope="$1" rule="$2" r
    shift 2
    for r in $(v-list-alert-rules "$scope" json | jq -r 'keys[]'); do
        v-change-alert-rule "$scope" "$r" no > /dev/null
    done
    v-change-alert-rule "$scope" "$rule" yes "$@"
}

# History lines of the suite's month as JSON lines
history() {
    cat "$AD"/history/*.jsonl 2> /dev/null
}

# Panel notifications of USER that came from this suite
notices() {
    v-list-user-notifications "${1:-$ROOT_USER}" json | jq -r --arg h "$HOST" '.[] | select(.TOPIC | contains($h)) | .TOPIC'
}

# Text of the panel notifications of USER
notice_texts() {
    v-list-user-notifications "${1:-$ROOT_USER}" json | jq -r '.[] | .TOPIC + " " + .NOTICE'
}

delete_notices() {
    local user nid
    for user in $(v-list-users json | jq -r 'keys[]'); do
        for nid in $(v-list-user-notifications "$user" json 2> /dev/null | jq -r --arg h "$HOST" 'to_entries[] | select(.value.TOPIC | contains($h) or contains("Alert channel") or contains("Alert rule") or contains("more alerts")) | .key' 2> /dev/null); do
            v-delete-user-notification "$user" "$nid" > /dev/null 2>&1 || true
        done
    done
}

df_fixture() {
    {
        echo 'Filesystem 1024-blocks Used Available Capacity Mounted on'
        local line
        for line in "$@"; do
            echo "/dev/sdx $line"
        done
    } > "$FIX/df"
}

# A df line: df_line MOUNT PERCENT
df_line() {
    echo "100 $2 0 $2% $1"
}

reset_alerts() {
    rm -rf "$AD"
    for dir in "$ARCPANEL"/data/users/*/alerts; do
        [ -d "$dir" ] && rm -rf "$dir"
    done
    rm -f "$ARCPANEL/log/alerts.log"
    lib 'alerts_dir_init; alerts_lock; alerts_panel_channel system > /dev/null; alerts_unlock'
}

setup_file() {
    rm -rf "$SAVE"
    mkdir -p "$SAVE/users"
    [ -d "$AD" ] && mv "$AD" "$SAVE/alerts"
    for dir in "$ARCPANEL"/data/users/*/alerts; do
        [ -d "$dir" ] || continue
        user=$(basename "$(dirname "$dir")")
        mv "$dir" "$SAVE/users/$user"
    done
    [ -f "$ARCPANEL/log/alerts.log" ] && mv "$ARCPANEL/log/alerts.log" "$SAVE/alerts.log"
    if [ -f "$CRONTAB" ]; then
        cp -p "$CRONTAB" "$SAVE/crontab"
        sed -i '/bin\/v-check-sys-alerts/d' "$CRONTAB"
    fi
    for user in $RES $CUS $USR; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    tmp=$(mktemp)
    cp "$ARCPANEL/data/packages/default.pkg" "$tmp"
    v-add-user-package "$tmp" "$PKG" > /dev/null 2>&1 || true
    rm -f "$tmp"
    v-change-package-tenancy "$PKG" USERS 5 > /dev/null
    for user in $RES $CUS $USR; do
        v-add-user "$user" "$PASS" "$user@example.invalid" "$PKG" > /dev/null
    done
    v-change-user-role "$RES" reseller > /dev/null
    v-change-user-owner "$CUS" "$RES" > /dev/null
    mkdir -p "$FIX"
    v-add-sys-secret-key > /dev/null 2>&1 || true
    start_stub
}

# The HTTP stub: records every POST in requests.jsonl and answers with the
# status in the file code (default 200) after the seconds in delay
start_stub() {
    rm -rf "$STUB"
    mkdir -p "$STUB"
    cat > "$STUB/stub.py" << 'EOF'
import http.server, json, os, sys, time
D = sys.argv[1]
def read(name, default):
    try:
        with open(os.path.join(D, name)) as f:
            return f.read().strip()
    except OSError:
        return default
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        n = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(n).decode()
        with open(os.path.join(D, 'requests.jsonl'), 'a') as f:
            f.write(json.dumps({'path': self.path, 'body': body}) + chr(10))
        code = int(read('code', '200'))
        time.sleep(float(read('delay', '0')))
        out = json.dumps({'ok': code < 300, 'description': 'stub says no' if code >= 300 else ''}).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(out)))
        self.end_headers()
        self.wfile.write(out)
    def log_message(self, *args):
        pass
http.server.ThreadingHTTPServer(('127.0.0.1', int(sys.argv[2])), H).serve_forever()
EOF
    cat > "$STUB/mail.sh" << 'EOF'
#!/bin/bash
# mail-wrapper.php -s SUBJECT EMAIL: records the mail; says ERROR when told
{ echo "TO $3 SUBJECT $2"; cat; echo "--"; } >> /root/arcpanel-alerts-test-stub/mail.log
if [ -f /root/arcpanel-alerts-test-stub/mail-fail ]; then
    echo "ERROR: mail not sent: stub refused" >&2
fi
exit 0
EOF
    chmod 755 "$STUB/mail.sh"
    setsid nohup python3 "$STUB/stub.py" "$STUB" "$PORT" > /dev/null 2>&1 < /dev/null &
    echo $! > "$STUB/pid"
    for _ in $(seq 1 50); do
        curl -s -o /dev/null -X POST "$URL/ready" && break
        sleep 0.1
    done
}

stop_stub() {
    [ -f "$STUB/pid" ] && kill "$(cat "$STUB/pid")" 2> /dev/null
    pkill -f "$STUB/stub.py" 2> /dev/null || true
    rm -rf "$STUB"
}

# Requests the stub got, one JSON line each
requests() {
    cat "$STUB/requests.jsonl" 2> /dev/null
}

# A 0600 file holding VALUE
secret_file() {
    local file
    file=$(mktemp /root/arcpanel-alerts-secret.XXXXXX)
    chmod 600 "$file"
    printf '%s' "$1" > "$file"
    echo "$file"
}

# Add a channel; prints its ID
add_channel() {
    local scope="$1" name="$2" type="$3" target="${4-}" secret="${5-}" file=''
    [ -n "$secret" ] && file=$(secret_file "$secret")
    ALERTS_TELEGRAM_API=$URL v-add-alert-channel "$scope" "$name" "$type" "$target" $file
}

teardown_file() {
    stop_stub
    delete_notices
    for id in $(v-list-user-secret-store "$ROOT_USER" json 2> /dev/null | jq -r '.[]?.NAME // empty' 2> /dev/null | grep '^/arcpanel/alerts/'); do
        v-delete-user-secret "$ROOT_USER" "$id" yes > /dev/null 2>&1 || true
    done
    for user in $CUS $USR $RES; do
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    v-delete-user-package "$PKG" > /dev/null 2>&1 || true
    v-delete-package-tenancy "$PKG" > /dev/null 2>&1 || true
    rm -rf "$AD" "$FIX"
    for dir in "$ARCPANEL"/data/users/*/alerts; do
        [ -d "$dir" ] && rm -rf "$dir"
    done
    [ -d "$SAVE/alerts" ] && mv "$SAVE/alerts" "$AD"
    for dir in "$SAVE"/users/*; do
        [ -d "$dir" ] || continue
        user=$(basename "$dir")
        [ -d "$ARCPANEL/data/users/$user" ] && mv "$dir" "$ARCPANEL/data/users/$user/alerts"
    done
    rm -f "$ARCPANEL/log/alerts.log"
    [ -f "$SAVE/alerts.log" ] && mv "$SAVE/alerts.log" "$ARCPANEL/log/alerts.log"
    if [ -f "$SAVE/crontab" ]; then
        cp -p "$SAVE/crontab" "$CRONTAB"
    fi
    rm -rf "$SAVE"
}

setup() {
    reset_alerts
    rm -f "$FIX"/*
    rm -f "$STUB/requests.jsonl" "$STUB/code" "$STUB/delay" "$STUB/mail.log" "$STUB/mail-fail"
    delete_notices
}

#----------------------------------------------------------#
#                     Rules and setup                      #
#----------------------------------------------------------#

@test "Alerts: the server scope lists every rule type, a user scope its own" {
    run v-list-alert-rules system json
    assert_success
    assert_equal "$(jq 'length' <<< "$output")" 14
    assert_equal "$(jq -r '."disk.usage" | [.KIND, .ENABLED, .WARNING, .CRITICAL, .COOLDOWN] | join(" ")' <<< "$output")" "metric no 90 95 1440"
    assert_equal "$(jq -r '."load.average".FOR' <<< "$output")" 10
    assert_equal "$(jq -r '."job.failed" | [.KIND, .SEVERITY] | join(" ")' <<< "$output")" "event info"
    assert_equal "$(jq -r '."disk.usage".CHANNELS' <<< "$output")" "$(panel_id)"
    run v-list-alert-rules "$USR" json
    assert_success
    assert_equal "$(jq -r 'keys | join(" ")' <<< "$output")" "backup.failed doctor.digest doctor.failed job.failed letsencrypt.failed quota.disk ssl.expiring"
    run v-list-alert-rules nosuchuser json
    assert_failure 3
}

@test "Alerts: v-change-alert-rule validates and keeps what it is not given" {
    run v-change-alert-rule system disk.nope yes
    assert_failure 2
    run v-change-alert-rule "$USR" disk.usage yes
    assert_failure 2
    run v-change-alert-rule system disk.usage maybe
    assert_failure 2
    run v-change-alert-rule system disk.usage yes 95 90
    assert_failure 2
    assert_output --partial 'must not be below'
    run v-change-alert-rule system disk.usage yes 101 102
    assert_failure 2
    run v-change-alert-rule system ssl.expiring yes 3 14
    assert_failure 2
    run v-change-alert-rule system disk.usage yes 80 90 0 '' 1440 12345678901234
    assert_failure 2
    assert_output --partial 'does not exist'
    run v-change-alert-rule system service.down yes '' '' 0 '' 60 "$(panel_id)" "nginx,bad;name"
    assert_failure 2
    run v-change-alert-rule system disk.usage yes 80 90 3 '' 30
    assert_success
    run v-change-alert-rule system disk.usage yes 85
    assert_success
    run v-list-alert-rules system json
    assert_equal "$(jq -r '."disk.usage" | [.ENABLED, .WARNING, .CRITICAL, .FOR, .COOLDOWN, .CUSTOM] | join(" ")' <<< "$output")" "yes 85 90 3 30 yes"
    # Thresholds of rules without levels are dropped; events keep a severity
    run v-change-alert-rule system service.down yes 5 6
    assert_success
    run v-change-alert-rule system job.failed yes '' '' 0 critical
    assert_success
    run v-change-alert-rule system job.failed yes '' '' 0 urgent
    assert_failure 2
    run v-list-alert-rules system json
    assert_equal "$(jq -r '."service.down" | [.WARNING, .CRITICAL] | join(",")' <<< "$output")" ","
    assert_equal "$(jq -r '."job.failed".SEVERITY' <<< "$output")" critical
    assert_file_exists "$AD/rules.conf"
    run stat -c '%a %U %G' "$AD/rules.conf"
    assert_output '640 root arcpanelweb'
}

@test "Alerts: each scope has one panel channel; the list prints no secret names" {
    run v-list-alert-channels system json
    assert_success
    assert_equal "$(jq '[.[] | select(.TYPE == "panel")] | length' <<< "$output")" 1
    assert_equal "$(jq -r '.[] | [.NAME, .ENABLED, .SECRET_SET] | join(" ")' <<< "$output")" "Panel yes no"
    refute_output --partial 'SECRET"'
    run v-list-alert-channels "$USR" json
    assert_success
    assert_equal "$(jq '[.[] | select(.TYPE == "panel")] | length' <<< "$output")" 1
    id=$(panel_id "$USR")
    run v-list-alert-channels "$USR" json
    assert_equal "$(panel_id "$USR")" "$id"
}

@test "Alerts: v-add-sys-alerts writes the server defaults once, the cron line and drops notify" {
    cp -p "$CRONTAB" "$FIX/crontab.before"
    sed -i '/bin\/v-check-sys-ports/d' "$CRONTAB"
    sed -i -e '$a10 03 * * * sudo /usr/local/arcpanel/bin/v-check-sys-ports notify' "$CRONTAB"
    rm -rf "$AD"
    run v-add-sys-alerts
    assert_success
    run v-list-alert-rules system json
    assert_equal "$(jq -r '[.[] | select(.ENABLED == "yes" and .KIND == "metric") | .RULE] | sort | join(" ")' <<< "$output")" \
        "disk.usage inode.usage load.average memory.usage ports.orphan service.down ssl.expiring"
    assert_equal "$(jq -r '."disk.usage".CHANNELS' <<< "$output")" "$(panel_id)"
    run grep -c 'bin/v-check-sys-alerts' "$CRONTAB"
    assert_output 1
    run grep -c 'v-check-sys-ports notify' "$CRONTAB"
    assert_output 0
    run grep -c 'bin/v-check-sys-ports' "$CRONTAB"
    assert_output 1
    # Every user got the user defaults
    run v-list-alert-rules "$USR" json
    assert_equal "$(jq -r '[.[] | select(.ENABLED == "yes") | .RULE] | sort | join(" ")' <<< "$output")" "backup.failed ssl.expiring"
    # A second run keeps a changed rule and adds no second cron line
    v-change-alert-rule system disk.usage no > /dev/null
    run v-add-sys-alerts
    assert_success
    run v-list-alert-rules system json
    assert_equal "$(jq -r '."disk.usage".ENABLED' <<< "$output")" no
    run grep -c 'bin/v-check-sys-alerts' "$CRONTAB"
    assert_output 1
    cp -p "$FIX/crontab.before" "$CRONTAB"
}

#----------------------------------------------------------#
#                       Evaluators                         #
#----------------------------------------------------------#

@test "Alerts: disk.usage and inode.usage per mount point, with OPTIONS" {
    df_fixture "$(df_line / 50)" "$(df_line /home 91)" "$(df_line /backup 97)"
    export ALERTS_FIXTURES=$FIX
    run lib "RL=([WARNING]=90 [CRITICAL]=95 [OPTIONS]=''); alerts_evaluate system disk.usage" < /dev/null
    assert_success
    assert_line 'mount:/ 50 ok'
    assert_line 'mount:/home 91 warning'
    assert_line 'mount:/backup 97 critical'
    run lib "RL=([WARNING]=90 [CRITICAL]=95 [OPTIONS]='/,/backup'); alerts_evaluate system disk.usage"
    refute_line --partial 'mount:/home'
    assert_line 'mount:/backup 97 critical'
    printf '%s\n' 'Filesystem Inodes IUsed IFree IUse% Mounted on' '/dev/sdx 100 92 8 92% /' 'fs 0 0 0 - /boot/efi' > "$FIX/dfi"
    run lib "RL=([WARNING]=90 [CRITICAL]=95 [OPTIONS]=''); alerts_evaluate system inode.usage"
    assert_success
    assert_output 'mount:/ 92 warning'
    unset ALERTS_FIXTURES
}

@test "Alerts: load.average per core, memory.usage with swap, service.down with OPTIONS" {
    export ALERTS_FIXTURES=$FIX
    echo '9.10 8.40 7.00 2/300 1234' > "$FIX/loadavg"
    echo 4 > "$FIX/nproc"
    run lib "RL=([WARNING]=2.0 [CRITICAL]=4.0); alerts_evaluate system load.average"
    assert_success
    assert_output 'system 2.10 warning load 8.40 on 4 cores'
    printf '%s\n' 'MemTotal: 1000000 kB' 'MemAvailable: 50000 kB' 'SwapTotal: 1000000 kB' 'SwapFree: 200000 kB' > "$FIX/meminfo"
    run lib "RL=([WARNING]=90 [CRITICAL]=''); alerts_evaluate system memory.usage"
    assert_success
    assert_output --regexp '^system 88 ok '
    printf '%s\n' 'MemTotal: 1000000 kB' 'MemAvailable: 50000 kB' 'SwapTotal: 0 kB' 'SwapFree: 0 kB' > "$FIX/meminfo"
    run lib "RL=([WARNING]=90 [CRITICAL]=''); alerts_evaluate system memory.usage"
    assert_output --regexp '^system 95 warning '
    echo '{"nginx": {"STATE": "running"}, "exim4": {"STATE": "stopped"}, "mariadb": {"STATE": "running"}}' > "$FIX/services"
    run lib "RL=([OPTIONS]=''); alerts_evaluate system service.down"
    assert_line 'service:nginx running ok'
    assert_line 'service:exim4 stopped critical'
    run lib "RL=([OPTIONS]='nginx,mariadb'); alerts_evaluate system service.down"
    refute_line --partial exim4
    # A probe that fails makes the evaluator fail
    rm -f "$FIX/loadavg"
    touch "$FIX/loadavg.fail"
    run lib "RL=([WARNING]=2.0 [CRITICAL]=4.0); alerts_evaluate system load.average"
    assert_failure
    unset ALERTS_FIXTURES
}

@test "Alerts: ports.orphan counts the findings of the port check" {
    export ALERTS_FIXTURES=$FIX
    echo '{"FINDINGS": [{"TYPE": "orphan-web"}, {"TYPE": "orphan-web"}, {"TYPE": "stale-rule"}]}' > "$FIX/ports"
    run lib "RL=(); alerts_evaluate system ports.orphan"
    assert_success
    assert_output 'system 3 warning 2 orphan-web, 1 stale-rule'
    echo '{"FINDINGS": []}' > "$FIX/ports"
    run lib "RL=(); alerts_evaluate system ports.orphan"
    assert_output 'system 0 ok '
    unset ALERTS_FIXTURES
}

@test "Alerts: ssl.expiring counts days; lower is worse" {
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$FIX/k1" -out "$FIX/c1.crt" -days 2 -subj /CN=a.example > /dev/null 2>&1
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$FIX/k2" -out "$FIX/c2.crt" -days 10 -subj /CN=b.example > /dev/null 2>&1
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$FIX/k3" -out "$FIX/c3.crt" -days 90 -subj /CN=c.example > /dev/null 2>&1
    printf '%s\n' "web:a.example $FIX/c1.crt" "mail:b.example $FIX/c2.crt" "panel:certificate $FIX/c3.crt" > "$FIX/ssl-certs.system"
    export ALERTS_FIXTURES=$FIX
    run lib "RL=([WARNING]=14 [CRITICAL]=3); alerts_evaluate system ssl.expiring"
    assert_success
    assert_line --regexp '^web:a\.example [12] critical expires '
    assert_line --regexp '^mail:b\.example (9|10) warning '
    assert_line --regexp '^panel:certificate (89|90) ok '
    # Another scope reads its own certificates only
    run lib "RL=([WARNING]=14 [CRITICAL]=3); alerts_evaluate $USR ssl.expiring"
    assert_output ''
    unset ALERTS_FIXTURES
    # Without fixtures the scope's own domains count (none here)
    run lib "RL=([WARNING]=14 [CRITICAL]=3); alerts_evaluate $USR ssl.expiring"
    assert_success
    assert_output ''
}

@test "Alerts: quota.disk against the package quota; a reseller's scope covers its customers" {
    export ALERTS_FIXTURES=$FIX
    printf '%s\n' 'alice 950 1000' 'bob 10 unlimited' 'carol 500 1000' > "$FIX/quota.system"
    run lib "RL=([WARNING]=90 [CRITICAL]=''); alerts_evaluate system quota.disk"
    assert_line 'user:alice 95 warning 950 of 1000 MB'
    assert_line 'user:carol 50 ok 500 of 1000 MB'
    refute_line --partial bob
    unset ALERTS_FIXTURES
    run lib "alerts_scope_users $RES"
    assert_line "$RES"
    assert_line "$CUS"
    refute_line "$USR"
    run lib "alerts_scope_users $CUS"
    assert_output "$CUS"
    run lib "alerts_scopes_of_user $CUS"
    assert_equal "$(echo $output)" "system $CUS $RES"
    run lib "alerts_scopes_of_user $USR"
    assert_equal "$(echo $output)" "system $USR"
}

#----------------------------------------------------------#
#                      State machine                       #
#----------------------------------------------------------#

@test "Alerts: an alert fires once, rises, reminds after the cooldown and resolves after two good runs" {
    only_rule system disk.usage 90 95 0 '' 60 "$(panel_id)" > /dev/null
    df_fixture "$(df_line /home 91)"
    run engine 0
    assert_success
    run v-list-alerts system json
    assert_equal "$(jq -r '.[] | [.RULE, .OBJECT, .SEVERITY, .COUNT] | join(" ")' <<< "$output")" "disk.usage mount:/home warning 1"
    assert_equal "$(history | jq -r 'select(.event == "fired") | [.rule, .object, .severity, .deliveries[0].type, .deliveries[0].result] | join(" ")')" \
        "disk.usage mount:/home warning panel sent"
    assert_equal "$(notices | grep -c 'WARNING.*mount:/home')" 1
    # Still over the threshold: no second message within the cooldown
    run engine 1
    assert_equal "$(history | jq -s 'length')" 1
    # Critical: one raised message
    df_fixture "$(df_line /home 96)"
    run engine 2
    assert_equal "$(history | jq -r 'select(.event == "raised") | .severity')" critical
    assert_equal "$(notices | grep -c 'CRITICAL.*mount:/home on .*(raised)')" 1
    # After the cooldown, a reminder
    run engine 63
    assert_equal "$(history | jq -r 'select(.event == "reminded") | .severity')" critical
    run v-list-alerts system json
    assert_equal "$(jq -r '.[].COUNT' <<< "$output")" 3
    # One good run is not enough; the second resolves it
    df_fixture "$(df_line /home 40)"
    run engine 64
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 1
    run engine 65
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
    assert_equal "$(history | jq -r 'select(.event == "resolved") | [.object, .severity] | join(" ")')" "mount:/home critical"
    run notice_texts
    assert_output --partial 'Lasted: 1 h 5 min'
    assert_equal "$(notices | grep -c 'RESOLVED.*mount:/home')" 1
    # The ids of one alert match through the history
    assert_equal "$(history | jq -r .id | sort -u | wc -l)" 1
}

@test "Alerts: a flapping value does not resolve; FOR delays the first message" {
    only_rule system memory.usage 90 '' 5 '' 1440 "$(panel_id)" > /dev/null
    printf '%s\n' 'MemTotal: 1000 kB' 'MemAvailable: 50 kB' 'SwapTotal: 0 kB' 'SwapFree: 0 kB' > "$FIX/meminfo"
    run engine 0
    run engine 4
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
    assert_equal "$(history | jq -s 'length')" 0
    run engine 5
    run v-list-alerts system json
    assert_equal "$(jq -r '.[] | .RULE' <<< "$output")" memory.usage
    assert_equal "$(history | jq -r .event)" fired
    # good, bad, good: still open
    printf '%s\n' 'MemTotal: 1000 kB' 'MemAvailable: 500 kB' 'SwapTotal: 0 kB' 'SwapFree: 0 kB' > "$FIX/meminfo.ok"
    cp "$FIX/meminfo" "$FIX/meminfo.bad"
    cp "$FIX/meminfo.ok" "$FIX/meminfo"
    run engine 6
    cp "$FIX/meminfo.bad" "$FIX/meminfo"
    run engine 7
    cp "$FIX/meminfo.ok" "$FIX/meminfo"
    run engine 8
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 1
    run engine 9
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
    # A condition that clears before FOR leaves nothing behind
    cp "$FIX/meminfo.bad" "$FIX/meminfo"
    run engine 20
    cp "$FIX/meminfo.ok" "$FIX/meminfo"
    run engine 21
    run grep -c 'memory.usage' "$AD/state.conf"
    assert_output 0
}

@test "Alerts: a vanished object and a rule turned off close without a message" {
    only_rule system service.down '' '' 0 '' 1440 "$(panel_id)" > /dev/null
    echo '{"nginx": {"STATE": "stopped"}, "exim4": {"STATE": "stopped"}}' > "$FIX/services"
    run engine 0
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 2
    echo '{"nginx": {"STATE": "stopped"}}' > "$FIX/services"
    run engine 1
    assert_equal "$(history | jq -r 'select(.event == "closed") | .object')" service:exim4
    assert_equal "$(history | jq -r 'select(.event == "closed") | .deliveries | length')" 0
    run v-change-alert-rule system service.down no
    assert_success
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
    assert_equal "$(notices | grep -c 'RESOLVED')" 0
}

@test "Alerts: the interval of a rule type is kept per scope" {
    only_rule system ports.orphan > /dev/null
    echo '{"FINDINGS": [{"TYPE": "orphan-web"}]}' > "$FIX/ports"
    run engine 0
    assert_equal "$(history | jq -r 'select(.event == "fired") | .rule')" ports.orphan
    echo '{"FINDINGS": []}' > "$FIX/ports"
    run engine 30
    run engine 31
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 1
    run engine 60
    run engine 120
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
}

@test "Alerts: user scopes evaluate their own rules with their own channels" {
    for r in $(v-list-alert-rules system json | jq -r 'keys[]'); do
        v-change-alert-rule system "$r" no > /dev/null
    done
    v-change-alert-rule "$USR" quota.disk yes 90 '' 0 '' 1440 "$(panel_id "$USR")" > /dev/null
    echo "$USR 95 100" > "$FIX/quota.$USR"
    run engine 0
    assert_success
    run v-list-alerts "$USR" json
    assert_equal "$(jq -r '.[] | [.RULE, .OBJECT] | join(" ")' <<< "$output")" "quota.disk user:$USR"
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
    assert_equal "$(notices "$USR" | grep -c "user:$USR")" 1
    assert_equal "$(history | jq -r '[.scope, .user, .owner] | join(" ")')" "$USR $USR $ROOT_USER"
    run v-list-alert-history "$USR" '' json
    assert_equal "$(jq 'length' <<< "$output")" 1
    run v-list-alert-history system '' json
    assert_equal "$(jq 'length' <<< "$output")" 0
    run v-list-alert-history all '2026-11-06 09:00:00' json
    assert_equal "$(jq 'length' <<< "$output")" 1
    run v-list-alert-history all '2026-11-07' json
    assert_equal "$(jq 'length' <<< "$output")" 0
    run v-list-alert-history all 'yesterday' json
    assert_failure 2
}

#----------------------------------------------------------#
#                    Limits and failures                   #
#----------------------------------------------------------#

@test "Alerts: at most 30 messages per channel and hour, then one 'N more' message" {
    only_rule system disk.usage 90 95 0 '' 1440 "$(panel_id)" > /dev/null
    lines=()
    for i in $(seq 1 33); do
        lines+=("$(df_line "/m$i" 99)")
    done
    df_fixture "${lines[@]}"
    run engine 0
    assert_equal "$(history | jq -r 'select(.event == "fired") | .deliveries[0].result' | sort | uniq -c | awk '{print $2 "=" $1}' | xargs)" "limited=3 sent=30"
    df_fixture "${lines[@]}" "$(df_line /late 99)"
    run engine 61
    assert_equal "$(notices | grep -c '^3 more alerts on bats-alerts.test')" 1
    assert_equal "$(history | jq -r 'select(.object == "mount:/late") | .deliveries[0].result')" sent
}

@test "Alerts: a failing probe is skipped and logged; ten failures in a row say so" {
    only_rule system load.average 2.0 4.0 0 '' 1440 "$(panel_id)" > /dev/null
    touch "$FIX/loadavg.fail"
    for m in $(seq 0 8); do
        run engine "$m"
        assert_success
    done
    run grep -c 'probe of load.average (system) failed' "$ARCPANEL/log/alerts.log"
    assert_output 9
    run notice_texts
    refute_output --partial 'Alert rule load.average cannot check'
    run engine 9
    run notice_texts
    assert_output --partial 'Alert rule load.average cannot check the server'
}

@test "Alerts: the run budget leaves rule types to the next run; a second engine exits" {
    only_rule system disk.usage 90 95 0 '' 1440 "$(panel_id)" > /dev/null
    df_fixture "$(df_line /home 99)"
    export ALERTS_BUDGET=0
    run engine 0
    assert_success
    unset ALERTS_BUDGET
    run grep -c 'took longer than 0 s' "$ARCPANEL/log/alerts.log"
    assert_output 1
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
    # Another run holds the run lock: this one exits at once
    exec {fd}> "$AD/.run.lock"
    flock -n "$fd"
    run engine 1
    assert_success
    flock -u "$fd"
    exec {fd}>&-
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 0
    run engine 2
    run v-list-alerts system json
    assert_equal "$(jq 'length' <<< "$output")" 1
}

@test "Alerts: the commands refuse non-root engine runs and history files are pruned after a year" {
    run sudo -u arcpanelweb /usr/local/arcpanel/bin/v-check-sys-alerts
    assert_failure
    mkdir -p "$AD/history"
    echo '{}' > "$AD/history/2020-01.jsonl"
    echo '{}' > "$AD/history/$(date +%Y-%m).jsonl"
    rm -f "$AD/.pruned"
    run engine 0
    assert_file_not_exists "$AD/history/2020-01.jsonl"
    assert_file_exists "$AD/history/$(date +%Y-%m).jsonl"
}
