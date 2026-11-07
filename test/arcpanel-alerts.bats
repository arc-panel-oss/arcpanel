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
    grep -E "^(BACKUP_REPORT|UPGRADE_SEND_EMAIL)=" "$ARCPANEL/conf/arcpanel.conf" > "$SAVE/switches" || true
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
    # The mail switches as they were (no alert files yet: no alias runs)
    while IFS= read -r line; do
        v-change-sys-config-value "${line%%=*}" "$(cut -d"'" -f2 <<< "$line")" > /dev/null
    done < "$SAVE/switches"
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
    assert_equal "$(jq -r '."disk.usage".CHANNELS | split(",")[0]' <<< "$output")" "$(panel_id)"
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

#----------------------------------------------------------#
#                        Channels                          #
#----------------------------------------------------------#

@test "Channels: v-add-alert-channel validates the type, target and secret" {
    run v-add-alert-channel system Ops sms ''
    assert_failure 2
    run v-add-alert-channel system Ops panel ''
    assert_failure 2
    run v-add-alert-channel system "Ops'mail" email ops@example.invalid
    assert_failure 2
    run v-add-alert-channel system Ops email ''
    assert_failure 2
    run v-add-alert-channel system Ops email 'a@example.com,b@example.com,c@example.com,d@example.com,e@example.com,f@example.com'
    assert_failure 2
    run v-add-alert-channel system Ops email 'not-an-address'
    assert_failure 2
    run v-add-alert-channel system Ops telegram 'chat 1' "$(secret_file 123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ012345)"
    assert_failure 2
    run v-add-alert-channel system Ops telegram 123456789
    assert_failure 1
    run v-add-alert-channel system Ops telegram 123456789 "$(secret_file not-a-token)"
    assert_failure 2
    run v-add-alert-channel system Ops slack '' "$(secret_file 'ftp://hooks.example.invalid/x')"
    assert_failure 2
    run v-add-alert-channel system Ops slack 'target' "$(secret_file "$URL/slack")"
    assert_failure 2
    run v-add-alert-channel system Ops email ops@example.invalid "$(secret_file x)"
    assert_failure 2
    run v-add-alert-channel nosuchuser Ops email ops@example.invalid
    assert_failure 3
    run v-list-alert-channels system json
    assert_equal "$(jq 'length' <<< "$output")" 1
    rm -f /root/arcpanel-alerts-secret.*
}

@test "Channels: the secret goes to the scope's secret store and is never shown again" {
    token='123456789:AAbbCCddEEffGGhhIIjjKKllMMnnOOppQQr'
    file=$(secret_file "$token")
    run v-add-alert-channel system 'Ops chat' telegram -1001234567 "$file"
    assert_success
    id=$output
    assert_regex "$id" '^[0-9]{14}$'
    assert_file_not_exists "$file"
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$id" '.[$id] | [.NAME, .TYPE, .TARGET, .ENABLED, .SECRET_SET] | join("|")' <<< "$output")" "Ops chat|telegram|-1001234567|yes|yes"
    refute_output --partial "$token"
    refute_output --partial 'arcpanel/alerts'
    run grep -rF "$token" "$AD"
    assert_failure
    run v-list-user-secret-store "$ROOT_USER" json
    assert_output --partial "/arcpanel/alerts/$id"
    # A user's channel uses the user's store
    run v-add-alert-channel "$USR" Hook slack '' "$(secret_file "$URL/hooks/user")"
    assert_success
    uid=$output
    run v-list-user-secret-store "$USR" json
    assert_output --partial "/arcpanel/alerts/$uid"
    run v-list-user-secret-store "$ROOT_USER" json
    refute_output --partial "/arcpanel/alerts/$uid"
    run grep -c "ID='$uid'" "$ARCPANEL/data/users/$USR/alerts/channels.conf"
    assert_output 1
}

@test "Channels: Slack, Discord, Mattermost and Telegram messages reach the stub in their formats" {
    s=$(add_channel system Slack slack '' "$URL/hooks/slack")
    d=$(add_channel system Discord discord '' "$URL/api/webhooks/1/discord")
    m=$(add_channel system MM mattermost '' "$URL/hooks/mm")
    t=$(add_channel system TG telegram 4242 '123456789:AAbbCCddEEffGGhhIIjjKKllMMnnOOppQQr')
    only_rule system service.down '' '' 0 '' 1440 "$s,$d,$m,$t" > /dev/null
    echo '{"nginx": {"STATE": "stopped"}}' > "$FIX/services"
    export ALERTS_TELEGRAM_API=$URL
    run engine 0
    assert_success
    assert_equal "$(requests | wc -l)" 4
    assert_equal "$(requests | jq -r 'select(.path == "/hooks/slack") | .body | fromjson | .text' | head -n1)" "[CRITICAL] Service down: service:nginx on $HOST"
    assert_equal "$(requests | jq -r 'select(.path == "/api/webhooks/1/discord") | .body | fromjson | .content | split("\n")[0]')" "[CRITICAL] Service down: service:nginx on $HOST"
    assert_equal "$(requests | jq -r 'select(.path == "/hooks/mm") | .body | fromjson | has("text")')" true
    tg=$(requests | jq -c 'select(.path | startswith("/bot"))')
    assert_equal "$(jq -r .path <<< "$tg")" "/bot123456789:AAbbCCddEEffGGhhIIjjKKllMMnnOOppQQr/sendMessage"
    assert_equal "$(jq -r '.body | fromjson | [.chat_id, .parse_mode] | join(" ")' <<< "$tg")" "4242 HTML"
    assert_equal "$(jq -r '.body | fromjson | .text | split("\n")[0]' <<< "$tg")" "<b>[CRITICAL] Service down: service:nginx on $HOST</b>"
    assert_equal "$(history | jq -r 'select(.event == "fired") | [.deliveries[].result] | join(",")')" "sent,sent,sent,sent"
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$s" '.[$id] | [.STATUS, .FAILS] | join(" ")' <<< "$output")" "ok 0"
    assert_regex "$(jq -r --arg id "$s" '.[$id].LAST_SENT' <<< "$output")" '^20[0-9]{2}-'
}

@test "Channels: Telegram escapes HTML; email goes through the mailer" {
    t=$(add_channel system TG telegram 4242 '123456789:AAbbCCddEEffGGhhIIjjKKllMMnnOOppQQr')
    e=$(add_channel system Mail email 'ops@example.invalid,noc@example.invalid')
    only_rule system ports.orphan '' '' 0 '' 1440 "$t,$e" > /dev/null
    echo '{"FINDINGS": [{"TYPE": "a<b>&c"}]}' > "$FIX/ports"
    export ALERTS_TELEGRAM_API=$URL ALERTS_SENDMAIL=$STUB/mail.sh
    run engine 0
    assert_success
    run jq -r '.body | fromjson | .text' "$STUB/requests.jsonl"
    assert_output --partial '1 a&lt;b&gt;&amp;c'
    run grep -c '^TO ' "$STUB/mail.log"
    assert_output 2
    run grep '^TO ' "$STUB/mail.log"
    assert_line "TO ops@example.invalid SUBJECT [WARNING] Port registry findings: system on $HOST"
    assert_line --partial 'TO noc@example.invalid'
    run grep -c 'a&lt;b&gt;&amp;c' "$STUB/mail.log"
    assert_output 2
    # A mailer that says ERROR is a failed delivery
    touch "$STUB/mail-fail"
    run v-test-alert-channel system "$e"
    assert_failure 15
    assert_output --partial 'mail to ops@example.invalid failed: mail not sent: stub refused'
}

@test "Channels: a failed delivery waits in the outbox, is retried three times and dropped" {
    s=$(add_channel system Slack slack '' "$URL/hooks/slack")
    only_rule system service.down '' '' 0 '' 1440 "$s" > /dev/null
    echo '{"nginx": {"STATE": "stopped"}}' > "$FIX/services"
    echo 500 > "$STUB/code"
    run engine 0
    assert_equal "$(history | jq -r 'select(.event == "fired") | .deliveries[0] | [.result, .error] | join(" ")')" "failed HTTP 500: stub says no"
    assert_equal "$(ls "$AD"/outbox/*.json | wc -l)" 1
    run engine 1
    run engine 2
    assert_equal "$(ls "$AD"/outbox/*.json | wc -l)" 1
    run engine 3
    assert_equal "$(ls "$AD"/outbox/ | wc -l)" 0
    assert_equal "$(history | jq -r 'select(.event == "delivery") | .deliveries[0].result')" dropped
    assert_equal "$(requests | wc -l)" 4
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$s" '.[$id] | [.STATUS, .FAILS, .ENABLED] | join("|")' <<< "$output")" "HTTP 500: stub says no|4|yes"
    # A retry that succeeds empties the outbox and resets the failures
    echo '{"nginx": {"STATE": "stopped"}, "exim4": {"STATE": "stopped"}}' > "$FIX/services"
    run engine 4
    assert_equal "$(ls "$AD"/outbox/*.json | wc -l)" 1
    echo 200 > "$STUB/code"
    run engine 5
    assert_equal "$(ls "$AD"/outbox/ | wc -l)" 0
    assert_equal "$(history | jq -r 'select(.event == "delivery") | .deliveries[0].result' | tail -n1)" sent
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$s" '.[$id] | [.STATUS, .FAILS] | join("|")' <<< "$output")" "ok|0"
}

@test "Channels: ten failures in a row turn a channel off and the panel channel says so" {
    s=$(add_channel system Slack slack '' "$URL/hooks/slack")
    only_rule system service.down '' '' 0 '' 1440 "$s" > /dev/null
    lib "alerts_lock; alerts_channel_set system $s FAILS=9; alerts_unlock"
    echo '{"nginx": {"STATE": "stopped"}}' > "$FIX/services"
    echo 502 > "$STUB/code"
    run engine 0
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$s" '.[$id] | [.ENABLED, .FAILS] | join(" ")' <<< "$output")" "no 10"
    run notice_texts
    assert_output --partial 'Alert channel Slack disabled'
    # A disabled channel is skipped; turning it on clears the count
    echo '{"nginx": {"STATE": "stopped"}, "exim4": {"STATE": "stopped"}}' > "$FIX/services"
    run engine 1
    assert_equal "$(history | jq -r 'select(.object == "service:exim4") | .deliveries[0].result')" skipped
    run v-change-alert-channel system "$s" Slack yes
    assert_success
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$s" '.[$id] | [.ENABLED, .FAILS] | join(" ")' <<< "$output")" "yes 0"
}

@test "Channels: v-test-alert-channel sends now and reports the result" {
    s=$(add_channel system Slack slack '' "$URL/hooks/slack")
    run v-test-alert-channel system "$s"
    assert_success
    assert_output ok
    assert_equal "$(requests | jq -r '.body | fromjson | .text | split("\n")[0]')" "Test message from ArcPanel on $(hostname -f)"
    v-change-alert-channel system "$s" Slack no > /dev/null
    echo 404 > "$STUB/code"
    run v-test-alert-channel system "$s" json
    assert_failure 15
    assert_equal "$(jq -r '[.RESULT, .ERROR] | join("|")' <<< "$output")" "failed|HTTP 404: stub says no"
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$s" '.[$id] | [.STATUS, .FAILS, .ENABLED] | join("|")' <<< "$output")" "HTTP 404: stub says no|0|no"
    assert_equal "$(history | jq -r 'select(.event == "test") | .deliveries[0].result' | xargs)" "sent failed"
    run v-test-alert-channel system "$(panel_id)"
    assert_success
    run v-test-alert-channel system 12345678901234
    assert_failure 3
    # A dead endpoint fails within the time limit
    stop_stub
    run v-test-alert-channel system "$s"
    assert_failure 15
    assert_output --partial 'connection failed'
    refute_output --partial "$URL/hooks/slack"
    start_stub
}

@test "Channels: v-change-alert-channel keeps or replaces the secret; the panel channel takes no target" {
    s=$(add_channel system Slack slack '' "$URL/hooks/one")
    run v-change-alert-channel system "$s" 'Slack two' yes
    assert_success
    v-test-alert-channel system "$s" > /dev/null
    assert_equal "$(requests | jq -r .path)" /hooks/one
    run v-change-alert-channel system "$s" 'Slack two' yes '' "$(secret_file "$URL/hooks/two")"
    assert_success
    v-test-alert-channel system "$s" > /dev/null
    assert_equal "$(requests | jq -r .path | tail -n1)" /hooks/two
    run v-change-alert-channel system "$s" 'Slack two' yes '' "$(secret_file 'not a url')"
    assert_failure 2
    run v-change-alert-channel system "$s" 'Slack two' maybe
    assert_failure 2
    run v-change-alert-channel system "$(panel_id)" 'Panel' yes 'x@example.invalid'
    assert_failure 2
    run v-change-alert-channel system "$(panel_id)" 'Our panel' yes
    assert_success
    e=$(add_channel system Mail email ops@example.invalid)
    run v-change-alert-channel system "$e" Mail yes 'noc@example.invalid'
    assert_success
    run v-list-alert-channels system json
    assert_equal "$(jq -r --arg id "$e" '.[$id].TARGET' <<< "$output")" noc@example.invalid
    assert_equal "$(jq -r --arg id "$s" '.[$id].NAME' <<< "$output")" 'Slack two'
}

@test "Channels: v-delete-alert-channel removes the secret, the rules' reference and the outbox" {
    s=$(add_channel system Slack slack '' "$URL/hooks/slack")
    p=$(panel_id)
    only_rule system service.down '' '' 0 '' 1440 "$p,$s" > /dev/null
    echo '{"nginx": {"STATE": "stopped"}}' > "$FIX/services"
    echo 500 > "$STUB/code"
    run engine 0
    assert_equal "$(ls "$AD"/outbox/*.json | wc -l)" 1
    run v-delete-alert-channel system "$s"
    assert_success
    assert_equal "$(ls "$AD"/outbox/ | wc -l)" 0
    run v-list-alert-rules system json
    assert_equal "$(jq -r '."service.down".CHANNELS' <<< "$output")" "$p"
    run v-list-user-secret-store "$ROOT_USER" json
    refute_output --partial "/arcpanel/alerts/$s"
    run v-delete-alert-channel system "$p"
    assert_failure 10
    run v-delete-alert-channel system "$s"
    assert_failure 3
}

@test "Channels: the token never appears on a command line or in a log" {
    token='987654321:ZZyyXXwwVVuuTTssRRqqPPooNNmmLLkkJJi'
    t=$(add_channel system TG telegram 4242 "$token")
    echo 3 > "$STUB/delay"
    ALERTS_TELEGRAM_API=$URL v-test-alert-channel system "$t" > /dev/null &
    pid=$!
    seen=0
    for _ in $(seq 1 20); do
        if ps -eo args | grep -v grep | grep -qF "${token#*:}"; then
            seen=1
        fi
        sleep 0.1
    done
    wait "$pid"
    assert_equal "$seen" 0
    assert_equal "$(requests | wc -l)" 1
    run grep -rF "${token#*:}" "$ARCPANEL/log/alerts.log" "$ARCPANEL/log/system.log" "$ARCPANEL/log/error.log"
    assert_failure
}

#----------------------------------------------------------#
#                          Events                          #
#----------------------------------------------------------#

# Write an event at minute M after T0: event M TYPE USER OBJECT SEVERITY MESSAGE [REF]
event() {
    local m="$1"
    shift
    (
        export ALERTS_NOW=$((T0 + m * 60))
        lib "alert_event $(printf '%q ' "$@")"
    )
}

@test "Events: alert_event writes one JSON line and refuses unknown types" {
    run event 0 job.failed "$USR" 'job:12345678901234' warning $'Job failed\nsecond line' 12345678901234
    assert_success
    run jq -c . "$AD/events.log"
    assert_success
    assert_equal "$(jq -r '[.type, .user, .object, .severity, .message, .ref] | join("|")' "$AD/events.log")" \
        "job.failed|$USR|job:12345678901234|warning|Job failed second line|12345678901234"
    run event 0 disk.usage system system warning x
    assert_failure 1
    run event 0 nope system system warning x
    assert_failure 1
    event 0 backup.failed system 'backup-job:1 <x>' urgent 'odd'
    assert_equal "$(tail -n1 "$AD/events.log" | jq -r '[.object, .severity] | join("|")')" "backup-job:1__x_|warning"
    (
        export ALERT_EVENT_USER_ONLY=yes
        event 0 backup.failed "$USR" "backup:$USR" critical 'only the user'
    )
    assert_equal "$(tail -n1 "$AD/events.log" | jq -r '.system')" false
    run stat -c '%a %U %G' "$AD/events.log"
    assert_output '640 root arcpanelweb'
}

@test "Events: an event reaches the server scope and the scopes of its user and reseller" {
    only_rule system job.failed '' '' 0 info 1440 "$(panel_id)" > /dev/null
    v-change-alert-rule "$CUS" job.failed yes '' '' 0 info 1440 "$(panel_id "$CUS")" > /dev/null
    v-change-alert-rule "$RES" job.failed yes '' '' 0 info 1440 "$(panel_id "$RES")" > /dev/null
    event 0 job.failed "$CUS" job:11111111111111 warning 'Job node.build on app failed: exit 1' 11111111111111
    run engine 0
    assert_success
    assert_equal "$(history | jq -r 'select(.event == "event") | .scope' | sort | xargs)" "$(echo "$CUS $RES system" | tr ' ' '\n' | sort | xargs)"
    assert_equal "$(notices "$CUS" | grep -c 'Job failed: job:11111111111111')" 1
    assert_equal "$(notices "$RES" | grep -c 'Job failed: job:11111111111111')" 1
    assert_equal "$(notices | grep -c '\[WARNING\] Job failed: job:11111111111111')" 1
    run notice_texts "$CUS"
    assert_output --partial 'Job node.build on app failed: exit 1'
    # Read once: a second run sends nothing
    run engine 1
    assert_equal "$(history | jq -s 'map(select(.event == "event")) | length')" 3
    # The per-user results of a backup run skip the server scope
    v-change-alert-rule "$CUS" backup.failed yes '' '' 0 info 1440 "$(panel_id "$CUS")" > /dev/null
    v-change-alert-rule system backup.failed yes '' '' 0 info 1440 "$(panel_id)" > /dev/null
    (
        export ALERT_EVENT_USER_ONLY=yes
        event 2 backup.failed "$CUS" "backup:$CUS" warning 'partial'
    )
    run engine 2
    assert_equal "$(history | jq -r 'select(.rule == "backup.failed") | .scope' | sort | xargs)" "$(echo "$CUS $RES" | tr ' ' '\n' | sort | xargs)"
}

@test "Events: events within the cooldown fold into one message with a count" {
    only_rule system backup.failed '' '' 0 info 60 "$(panel_id)" > /dev/null
    event 0 backup.failed system backup-job:7 warning 'run 1 partial'
    run engine 0
    event 5 backup.failed system backup-job:7 critical 'run 2 failed'
    event 6 backup.failed system backup-job:7 warning 'run 3 partial'
    event 6 backup.failed system backup-job:8 warning 'other job'
    run engine 7
    assert_equal "$(notices | grep -c 'backup-job:7')" 1
    assert_equal "$(notices | grep -c 'backup-job:8')" 1
    assert_equal "$(history | jq -r 'select(.object == "backup-job:7" and .event == "event") | (.deliveries | length)' | xargs)" "1 0 0"
    # After the cooldown the folded events go out in one message
    run engine 61
    assert_equal "$(history | jq -r 'select(.event == "folded") | [.object, .severity, .count] | join(" ")')" "backup-job:7 critical 2"
    assert_equal "$(notices | grep -c '\[CRITICAL\] Backup failed: backup-job:7')" 1
    run notice_texts
    assert_output --partial '2 more since the last message; the last: run 3 partial'
    # Quiet objects are forgotten after the cooldown
    run engine 200
    run grep -c "KIND='event'" "$AD/state.conf"
    assert_output 0
}

@test "Events: an event message names its reference on a line of its own" {
    run lib "alerts_message event system doctor.failed doctor:system:server.ptr warning 'Ref: 17940000001234' 'It failed.' ''; printf '%s\n' \"\$MSG_TEXT\""
    assert_success
    assert_line 'Ref: 17940000001234'
    refute_line --partial 'Value:'
    # A metric value keeps its label
    run lib "alerts_message fired system disk.usage mount:/ warning '93% (warning 90%)' '' ''; printf '%s\n' \"\$MSG_TEXT\""
    assert_line 'Value: 93% (warning 90%)'
}

@test "Events: below the rule's severity, or older than a day, an event is kept without a message" {
    only_rule system update.result '' '' 0 critical 1440 "$(panel_id)" > /dev/null
    event 0 update.result system update:2.7.1 info 'ArcPanel 2.7.1 is available'
    event 0 update.result system update:2.7.1 critical 'The update to ArcPanel 2.7.1 failed'
    run engine 0
    assert_equal "$(history | jq -r 'select(.event == "event") | [.severity, (.deliveries | length)] | join(":")' | xargs)" "info:0 critical:1"
    v-change-alert-rule system update.result yes '' '' 0 info > /dev/null
    event 10 update.result system update:2.7.2 critical 'old news'
    run engine $((10 + 25 * 60))
    assert_equal "$(history | jq -r 'select(.message == "old news") | .deliveries | length')" 0
    assert_equal "$(notices | grep -c 'update:2.7.2')" 0
}

@test "Events: a failed job sends job.failed, a certificate job letsencrypt.failed, backups nothing" {
    lib_jobs() {
        bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/tenancy.sh; source $ARCPANEL/func/job.sh; source_conf $ARCPANEL/conf/arcpanel.conf; $1"
    }
    for spec in '99000000000001 node.build' '99000000000002 letsencrypt.domain' '99000000000003 backup.user'; do
        id=${spec% *}
        type=${spec#* }
        echo "ID='$id' TYPE='$type' USER='$USR' OWNER='$ROOT_USER' ACTOR='bats' OBJECT='bats-alerts-$id' ARGS='x' STATUS='failed' EXIT='3' ERROR='it broke' UNIT='' PID='' QUEUED='2026-11-06 10:00:00' START='2026-11-06 10:00:00' END='2026-11-06 10:00:01' HEARTBEAT='' ATTEMPTS='1' RETRY_OF='' RESOURCE='' UPDATED='2026-11-06 10:00:01'" > "$ARCPANEL/data/jobs/$id.conf"
        lib_jobs "job_finish $id"
        rm -f "$ARCPANEL/data/jobs/$id.conf"
    done
    run jq -r '[.type, .user, .object, .severity, .ref] | join("|")' "$AD/events.log"
    assert_line "job.failed|$USR|job:99000000000001|warning|99000000000001"
    assert_line "letsencrypt.failed|$USR|job:99000000000002|warning|99000000000002"
    refute_line --partial 99000000000003
    assert_equal "$(jq -r 'select(.ref == "99000000000001") | .message' "$AD/events.log")" "Job node.build on bats-alerts-99000000000001 failed: it broke"
}

@test "Events: the backup and update sources write their events instead of mails" {
    lib_bj() {
        bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/backup-job.sh; source_conf $ARCPANEL/conf/arcpanel.conf; $1"
    }
    lib_bj "bj_value() { echo Nightly; }; bj_notify_admins 17000000000001 17000000000009 partial 'alice (failed)'"
    lib_bj "bj_notify_user $USR failed 'disk full'"
    lib_bj "ARC_BACKUP_JOB_RUN=yes; user=$USR; echo reason | bj_mail_failure 'Backup of $USR failed'"
    lib_bj "user=$USR; echo 'no space' | bj_mail_failure 'Backup of $USR failed'"
    run jq -r '[.type, .user, .object, .severity, .message] | join("|")' "$AD/events.log"
    assert_line "backup.failed|system|backup-job:17000000000001|warning|The backup job Nightly (run 17000000000009) ended partial. alice (failed)"
    assert_line "backup.failed|$USR|backup:$USR|critical|Backup of $USR ended failed. disk full"
    assert_line "backup.failed|$USR|backup:$USR|critical|Backup of $USR failed: no space "
    assert_equal "$(jq -s 'length' "$AD/events.log")" 3
    bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/updates.sh; source_conf $ARCPANEL/conf/arcpanel.conf; upd_notify_admin 'The update to ArcPanel 9.9.9 failed' '<p>x</p>' 'line one
line two' critical 9.9.9"
    assert_equal "$(tail -n1 "$AD/events.log" | jq -r '[.type, .object, .severity, .message] | join("|")')" \
        "update.result|update:9.9.9|critical|The update to ArcPanel 9.9.9 failed. line one line two"
}

@test "Events: events.log starts again in a new month once it is read" {
    only_rule system job.failed '' '' 0 info 1440 "$(panel_id)" > /dev/null
    event 0 job.failed system job:1 warning one
    run engine 0
    touch -d '2026-10-15 10:00:00' "$AD/events.log"
    run engine 1
    assert_file_not_exists "$AD/events.log"
    assert_file_exists "$AD/events.log.1"
    assert_equal "$(cat "$AD/events.offset")" 0
    event 2 job.failed system job:2 warning two
    run engine 2
    assert_equal "$(history | jq -r 'select(.event == "event") | .object' | xargs)" "job:1 job:2"
}

@test "Events: the open alerts of a deleted user close" {
    v-change-alert-rule "$USR" quota.disk yes 90 '' 0 '' 1440 "$(panel_id "$USR")" > /dev/null
    echo "$USR 95 100" > "$FIX/quota.$USR"
    run engine 0
    run grep -c "SCOPE='$USR'" "$AD/state.conf"
    assert_output 1
    sed -i "s/SCOPE='$USR'/SCOPE='arcalgone'/" "$AD/state.conf"
    run engine 1
    run grep -c "SCOPE='arcalgone'" "$AD/state.conf"
    assert_output 0
}

#----------------------------------------------------------#
#                   Upgrade defaults (§9)                  #
#----------------------------------------------------------#

contact() {
    v-list-user "$ROOT_USER" json | jq -r '.[].CONTACT'
}

# Rules with the administrator email channel, sorted
email_rules() {
    local email
    email=$(cat "$AD/.admin-email")
    v-list-alert-rules system json | jq -r --arg e "$email" '[.[] | select(.CHANNELS | split(",") | index($e)) | .RULE] | sort | join(" ")'
}

defaults_with() {
    rm -rf "$AD"
    v-change-sys-config-value BACKUP_REPORT "$1" > /dev/null
    v-change-sys-config-value UPGRADE_SEND_EMAIL "$2" > /dev/null
    v-add-sys-alerts > /dev/null
}

@test "Upgrade: the server defaults follow BACKUP_REPORT and UPGRADE_SEND_EMAIL; the aliases keep them in step" {
    [ -n "$(contact)" ] || skip "the administrator account has no contact address"
    cp -p "$CRONTAB" "$FIX/crontab.before"
    cp "$ARCPANEL/conf/arcpanel.conf" "$FIX/conf.before"
    defaults_with failures true
    run v-list-alert-rules system json
    assert_equal "$(jq -r '[.[] | select(.ENABLED == "yes") | .RULE] | sort | join(" ")' <<< "$output")" \
        "backup.failed disk.usage inode.usage job.failed letsencrypt.failed load.average memory.usage ports.orphan service.down ssl.expiring update.result"
    run v-list-alert-channels system json
    assert_equal "$(jq -r '.[] | select(.TYPE == "email") | [.NAME, .TARGET] | join("|")' <<< "$output")" "Administrator email|$(contact)"
    assert_equal "$(email_rules)" "backup.failed disk.usage inode.usage job.failed letsencrypt.failed load.average memory.usage ports.orphan service.down ssl.expiring update.result"
    defaults_with off false
    assert_equal "$(email_rules)" "disk.usage inode.usage job.failed letsencrypt.failed load.average memory.usage ports.orphan service.down ssl.expiring"
    run v-list-alert-rules system json
    assert_equal "$(jq -r '."backup.failed".CHANNELS, ."update.result".CHANNELS' <<< "$output" | xargs)" "$(panel_id) $(panel_id)"
    defaults_with daily true
    assert_equal "$(email_rules)" "backup.failed disk.usage inode.usage job.failed letsencrypt.failed load.average memory.usage ports.orphan service.down ssl.expiring update.result"
    # The aliases: the settings change the email channel of their rule
    v-change-sys-config-value BACKUP_REPORT off > /dev/null
    v-change-sys-config-value UPGRADE_SEND_EMAIL false > /dev/null
    assert_equal "$(email_rules)" "disk.usage inode.usage job.failed letsencrypt.failed load.average memory.usage ports.orphan service.down ssl.expiring"
    v-change-sys-config-value BACKUP_REPORT failures > /dev/null
    v-change-sys-config-value UPGRADE_SEND_EMAIL true > /dev/null
    assert_equal "$(email_rules)" "backup.failed disk.usage inode.usage job.failed letsencrypt.failed load.average memory.usage ports.orphan service.down ssl.expiring update.result"
    # Every user scope: certificates and failed backups on its panel channel
    run v-list-alert-rules "$CUS" json
    assert_equal "$(jq -r '[.[] | select(.ENABLED == "yes") | .RULE + ":" + .CHANNELS] | join(" ")' <<< "$output")" \
        "ssl.expiring:$(panel_id "$CUS") backup.failed:$(panel_id "$CUS")"
    cp "$FIX/conf.before" "$ARCPANEL/conf/arcpanel.conf"
    cp -p "$FIX/crontab.before" "$CRONTAB"
}
