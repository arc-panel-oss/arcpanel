#!/usr/bin/env bats
# ArcPanel app processes (E42,
# docs/features/specs/2026-10-16-003-e42-app-processes-design.md).
# Run as root on a server installed with the ArcPanel installer, with
# python3 (Ubuntu and Debian have it) and NGINX as web server or proxy.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
PORTS_DIR=$ARCPANEL/data/ports
CONF=$ARCPANEL/conf/arcpanel.conf
PASS="Tap-Fixed-Pass-42"
USERS="tap1 tap2 tapoff"
U1=tap1
U2=tap2
DA=a.tap.example
DB=b.tap.example
DC=c.tap.example
DD=d.tap.example

source_value() {
    grep "^$1=" "$CONF" | tail -n1 | cut -d"'" -f2
}
if [ "$(source_value WEB_SYSTEM)" = 'nginx' ]; then
    tpl_of() { grep "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" | grep -o " TPL='[^']*'" | cut -d"'" -f2; }
else
    tpl_of() { grep "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" | grep -o " PROXY='[^']*'" | cut -d"'" -f2; }
fi

make_package() {
    local name=$1 enabled=$2 max=$3 tmp
    tmp=$(mktemp)
    sed "s/^SHELL=.*/SHELL='bash'/" "$PKG_DIR/default.pkg" > "$tmp"
    v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy "$name" APP_PROCESSES "$enabled"
    v-change-package-tenancy "$name" APP_PROCESSES_MAX "$max"
    v-change-package-tenancy "$name" APP_PROCESSES_MEMORY 256
    v-change-package-tenancy "$name" APP_PROCESSES_CPUS 1
    v-change-package-tenancy "$name" PORTS 10
}

# Local address of the domain (web.conf may hold the NAT address)
web_ip() {
    local ip
    ip=$(grep "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
    if [ -f "$ARCPANEL/data/ips/$ip" ]; then
        echo "$ip"
    else
        basename "$(grep -l "^NAT='$ip'" "$ARCPANEL"/data/ips/* | head -n1)"
    fi
}

fetch() {
    curl -s --noproxy '*' --max-time 10 --resolve "$2:80:$(web_ip "$1" "$2")" "http://$2${3:-/}"
}

# Run CMD until it prints EXPECTED, at most 20 seconds; prints the last output
eventually() {
    local expected="$1" out i
    shift
    for i in $(seq 1 80); do
        out=$("$@")
        if [ "$out" = "$expected" ]; then
            break
        fi
        sleep 0.25
    done
    echo "$out"
}

as_user() {
    local user=$1
    shift
    setpriv --reuid "$user" --regid "$user" --init-groups -- "$@"
}

write_file() {
    local user=$1 path=$2 content=$3
    printf '%s\n' "$content" > "$path"
    chown "$user:$user" "$path"
}

field() {
    grep " DOMAIN='$2' NAME='$3' " "$ARCPANEL/data/users/$1/appprocesses.conf" | grep -oE "(^| )$4='[^']*'" | cut -d"'" -f2
}

status_of() {
    v-list-app-processes "$1" json | jq -r --arg d "$2" --arg n "$3" '.PROCESSES[] | select(.DOMAIN == $d and .NAME == $n) | .STATUS'
}

port_app() {
    grep "^PORT='$2' " "$PORTS_DIR/$1.conf" | grep -o "APP='[^']*'" | cut -d"'" -f2
}

cleanup() {
    local user unit
    for user in $USERS; do
        for unit in /etc/systemd/system/arcpanel-app-"$user"-*.service; do
            [ -e "$unit" ] || continue
            systemctl disable --now "${unit##*/}" > /dev/null 2>&1 || true
            rm -f "$unit"
        done
        rm -rf "$ARCPANEL/data/app-processes/$user"
        rm -f /run/arcpanel/app/"$user"-*.env "/etc/logrotate.d/arcpanel-app-$user"
        if id "$user" > /dev/null 2>&1; then
            pkill -9 -u "$user" > /dev/null 2>&1 || true
        fi
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    systemctl daemon-reload
    for package in tap-dev tap-off; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
}

# A small web app: answers "<tag> <PORT> <GREETING>" on every path
write_web_app() {
    local user=$1 domain=$2 tag=$3 dir
    dir=/home/$user/web/$domain/public_html
    write_file "$user" "$dir/server.py" "import http.server, os
class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        body = ('$tag ' + os.environ.get('PORT', '-') + ' ' + os.environ.get('GREETING', '-')).encode()
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def log_message(self, *a):
        print('request', self.path, flush=True)
http.server.HTTPServer((os.environ['HOST'], int(os.environ['PORT'])), H).serve_forever()"
}

setup_file() {
    cleanup
    v-add-sys-app-processes
    make_package tap-dev yes 3
    make_package tap-off no 3
    v-add-user $U1 "$PASS" tap1@example.invalid tap-dev > /dev/null
    v-add-user $U2 "$PASS" tap2@example.invalid tap-dev > /dev/null
    v-add-user tapoff "$PASS" tapoff@example.invalid tap-off > /dev/null
    v-add-web-domain $U1 $DA > /dev/null
    v-add-web-domain $U1 $DB > /dev/null
    v-add-web-domain $U1 $DC > /dev/null
    v-add-web-domain $U2 $DD > /dev/null
    v-add-web-domain tapoff off.tap.example > /dev/null
    write_web_app $U1 $DA tap-a
    write_web_app $U2 $DD tap-d
    write_file $U1 "/home/$U1/web/$DA/public_html/worker.py" "import time
print('worker up', flush=True)
while True:
    time.sleep(1)"
    write_file $U1 "/home/$U1/web/$DB/public_html/bind.py" "import socket
s = socket.socket()
s.bind(('127.0.0.1', 8999))
print('bound 8999', flush=True)"
}

teardown_file() {
    cleanup
}

#----------------------------------------------------------#
#                     Command lines                        #
#----------------------------------------------------------#

split() {
    (
        source /etc/arcpanel/arcpanel.conf
        source $ARCPANEL/func/main.sh
        source $ARCPANEL/func/tenancy.sh
        source $ARCPANEL/func/appprocess.sh
        appproc_split "$1" || exit 1
        printf '[%s]' "${APPPROC_ARGS[@]}"
    )
}

@test "Command lines: words, quotes and escapes without a shell" {
    run split "php artisan octane:start --port=20001"
    assert_output "[php][artisan][octane:start][--port=20001]"
    run split "bash -c 'echo \$HOME | wc -c'"
    assert_output "[bash][-c][echo \$HOME | wc -c]"
    run split 'a "b c" d\ e "f\"g" '"''"
    assert_output '[a][b c][d e][f"g][]'
    run split "a > b; c"
    assert_output "[a][>][b;][c]"
    run split "broken 'quote"
    assert_failure
    run split 'trailing\'
    assert_failure
    run split "   "
    assert_failure
}

#----------------------------------------------------------#
#                         Package                          #
#----------------------------------------------------------#

@test "Package: the app process keys are package keys with defaults" {
    run v-list-package-tenancy tap-dev json
    assert_success
    echo "$output" | jq -e '.["tap-dev"] | .APP_PROCESSES == "yes" and .APP_PROCESSES_MAX == "3" and .APP_PROCESSES_MEMORY == "256" and .APP_PROCESSES_CPUS == "1"'
    run v-list-package-tenancy default json
    echo "$output" | jq -e '.default | .APP_PROCESSES == "no" and .APP_PROCESSES_MAX == "3" and .APP_PROCESSES_CPUS == "1"'
    run v-change-package-tenancy tap-dev APP_PROCESSES maybe
    assert_failure 2
    run v-change-package-tenancy tap-dev APP_PROCESSES_CPUS lots
    assert_failure 2
    grep -q "^APP_PROCESSES='yes'" "$PKG_DIR/developer.tenancy"
    grep -q "^APP_PROCESSES_MAX='10'" "$PKG_DIR/developer.tenancy"
}

@test "Package: a package without APP_PROCESSES refuses" {
    run v-add-app-process tapoff off.tap.example web custom 'python3 -m http.server' public_html '' 1 web flask-fastapi
    assert_failure 11
    assert_output --partial "not allowed by the package"
}

#----------------------------------------------------------#
#                           Core                           #
#----------------------------------------------------------#

@test "Core: add a custom web process behind the domain" {
    prev=$(tpl_of $U1 $DA)
    run v-add-app-process $U1 $DA web custom 'python3 server.py' public_html 128 0.5 web flask-fastapi
    assert_success
    id=$(field $U1 $DA web ID)
    port=$(field $U1 $DA web PORT)
    assert_equal "$(field $U1 $DA web KIND)" "web"
    assert_equal "$(field $U1 $DA web PREV_TPL)" "$prev"
    assert_equal "$(tpl_of $U1 $DA)" "flask-fastapi"
    assert_equal "$(port_app $U1 "$port")" "app:$DA"
    grep -q "^BACKEND_PORT='$port'" "/home/$U1/conf/web/$DA/nginx.conf.tplvars"
    run nginx -t
    assert_success
    run eventually "tap-a $port -" fetch $U1 $DA
    assert_output "tap-a $port -"
}

@test "Core: the unit runs the process as the user with the package limits" {
    id=$(field $U1 $DA web ID)
    unit=/etc/systemd/system/arcpanel-app-$U1-$id.service
    assert_file_exists "$unit"
    assert_equal "$(stat -c '%U %a' "$unit")" "root 644"
    grep -qx "User=$U1" "$unit"
    grep -qx "Slice=user-$(id -u $U1).slice" "$unit"
    grep -qx "MemoryMax=128M" "$unit"
    grep -qx "CPUQuota=50%" "$unit"
    grep -qx "TasksMax=256" "$unit"
    grep -qx "NoNewPrivileges=yes" "$unit"
    grep -qx "SocketBindDeny=any" "$unit"
    grep -q "^SocketBindAllow=" "$unit"
    run status_of $U1 $DA web
    assert_output "running"
    pid=$(systemctl show -p MainPID --value "arcpanel-app-$U1-$id.service")
    assert_equal "$(stat -c %U "/proc/$pid")" "$U1"
    grep -q "user-$(id -u $U1).slice" "/proc/$pid/cgroup"
    tr '\0' '\n' < "/proc/$pid/environ" | grep -qx "HOST=127.0.0.1"
}

@test "Core: the run file is root's, readable by the user only; the copy is the user's" {
    id=$(field $U1 $DA web ID)
    run_file=$ARCPANEL/data/app-processes/$U1/$id.json
    assert_equal "$(stat -c '%U:%G %a' "$run_file")" "root:$U1 640"
    assert_equal "$(stat -c '%U:%G %a' "$ARCPANEL/data/app-processes/$U1")" "root:$U1 750"
    jq -e '.start == ["python3", "server.py"] and .kind == "web"' "$run_file"
    run as_user $U2 cat "$run_file"
    assert_failure
    copy=/home/$U1/web/$DA/app-process/web.conf
    assert_equal "$(stat -c %U "$copy")" "$U1"
    grep -q "^ID='$id' DOMAIN='$DA' NAME='web' " "$copy"
    assert_equal "$(stat -c '%U %a' "/home/$U1/web/$DA/app-process/web.env")" "$U1 600"
    grep -q "start: python3 server.py" "/home/$U1/web/$DA/app-process/logs/web.log"
    assert_file_exists "/etc/logrotate.d/arcpanel-app-$U1"
}

@test "Core: list commands" {
    run v-list-app-processes $U1 json
    assert_success
    echo "$output" | jq -e --arg d "$DA" '.ENABLED == "yes" and .LIMIT == "3" and .COUNT == "1" and .MAX_MEMORY == "256" and (.PROCESSES[0] | .DOMAIN == $d and .STATUS == "running" and .START == "python3 server.py" and (.PID | length) > 0)'
    run v-list-app-processes $U1
    assert_success
    assert_output --partial "$DA"
    run v-list-app-process $U1 $DA web json
    assert_success
    echo "$output" | jq -e '.NAME == "web" and .RELOAD == "restart" and (.LOG | endswith("/app-process/logs/web.log"))'
    run v-list-app-process $U1 $DA nothing json
    assert_failure 3
}

@test "Core: a worker has no port and does not change the template" {
    run v-add-app-process $U1 $DA worker custom 'python3 worker.py'
    assert_success
    assert_equal "$(field $U1 $DA worker PORT)" ""
    assert_equal "$(field $U1 $DA worker MEMORY)" "$(v-list-app-processes $U1 json | jq -r .DEFAULT_MEMORY)"
    assert_equal "$(tpl_of $U1 $DA)" "flask-fastapi"
    run eventually "running" status_of $U1 $DA worker
    assert_output "running"
    run eventually "1" grep -c "worker up" "/home/$U1/web/$DA/app-process/logs/worker.log"
    assert_output "1"
}

@test "Core: refusals" {
    run v-add-app-process $U1 $DA web2 custom 'python3 server.py' public_html '' 1 web flask-fastapi
    assert_failure 4
    assert_output --partial "already has a web app process"
    run v-add-app-process $U1 $DA worker custom 'python3 worker.py'
    assert_failure 4
    run v-add-app-process $U1 $DB Bad custom 'true'
    assert_failure 2
    run v-add-app-process $U1 $DB w1 nopreset
    assert_failure 3
    run v-add-app-process $U1 $DB w1 custom
    assert_failure 2
    run v-add-app-process $U1 $DB w1 custom "$(printf 'true\nreboot')"
    assert_failure 2
    run v-add-app-process $U1 $DB w1 custom "echo 'open"
    assert_failure 2
    run v-add-app-process $U1 $DB w1 custom 'true' ../../..
    assert_failure 2
    ln -s /etc "/home/$U1/web/$DB/etcdir"
    chown -h $U1:$U1 "/home/$U1/web/$DB/etcdir"
    run v-add-app-process $U1 $DB w1 custom 'true' etcdir
    assert_failure 10
    rm -f "/home/$U1/web/$DB/etcdir"
    run v-add-app-process $U1 $DB w1 custom 'true' public_html 512
    assert_failure 8
    run v-add-app-process $U1 $DB w1 custom 'true' public_html 64 2
    assert_failure 8
    run v-add-app-process $U1 $DB w1 custom 'true' public_html 64 lots
    assert_failure 2
    run v-add-app-process $U1 $DB w1 laravel-queue '' public_html 64 1 web
    assert_failure 2
    run v-add-app-process $U1 $DB w1 custom 'true' public_html 64 1 web
    assert_failure 2
    assert_output --partial "needs a template"
    run v-add-app-process $U1 $DB w1 custom 'true' public_html 64 1 web default
    assert_failure 2
}

@test "Core: the package count limits the processes" {
    run v-add-app-process $U1 $DB bind custom 'python3 bind.py'
    assert_success
    run v-add-app-process $U1 $DC more custom 'true'
    assert_failure 8
    assert_output --partial "APP_PROCESSES_MAX"
}

@test "Core: a process cannot bind a port outside the user's pool" {
    id=$(field $U1 $DB bind ID)
    grep -qx "SocketBindDeny=any" "/etc/systemd/system/arcpanel-app-$U1-$id.service"
    if ! systemctl --version | grep -q "+BPF_FRAMEWORK"; then
        v-delete-app-process $U1 $DB bind
        skip "systemd of this server is built without BPF_FRAMEWORK: SocketBindAllow is not enforced"
    fi
    run eventually "1" grep -c "PermissionError" "/home/$U1/web/$DB/app-process/logs/bind.log"
    assert_output "1"
    run grep -c "bound 8999" "/home/$U1/web/$DB/app-process/logs/bind.log"
    assert_output "0"
    run v-delete-app-process $U1 $DB bind
    assert_success
}

@test "Core: presets fill the command for the domain" {
    run v-list-app-process-presets json
    assert_success
    echo "$output" | jq -e '[.PRESETS[].NAME] | index("octane-swoole") != null and index("laravel-queue") != null and index("custom") != null'
    write_file $U1 "/home/$U1/web/$DC/public_html/artisan" "<?php"
    run v-list-app-process-presets $U1 $DC public_html json
    assert_success
    echo "$output" | jq -e '.PRESETS[] | select(.NAME == "laravel-queue") | .DETECTED == "yes" and (.START | test("^/usr/bin/php[0-9.]+ artisan queue:work"))'
    echo "$output" | jq -e '.PRESETS[] | select(.NAME == "gunicorn") | .DETECTED == "no"'
}

@test "Core: other users cannot see a process" {
    run v-list-app-processes $U2 json
    assert_success
    echo "$output" | jq -e '.COUNT == "0"'
    run v-delete-app-process $U2 $DA web
    assert_failure 3
}

#----------------------------------------------------------#
#                 Lifecycle and settings                   #
#----------------------------------------------------------#

main_pid() {
    systemctl show -p MainPID --value "arcpanel-app-$1-$(field "$1" "$2" "$3" ID).service"
}

@test "Lifecycle: stop and start" {
    port=$(field $U1 $DA web PORT)
    run v-stop-app-process $U1 $DA web
    assert_success
    assert_equal "$(field $U1 $DA web STATE)" "stopped"
    run status_of $U1 $DA web
    assert_output "stopped"
    run systemctl is-enabled "arcpanel-app-$U1-$(field $U1 $DA web ID).service"
    assert_output "disabled"
    run v-reload-app-process $U1 $DA web
    assert_failure 2
    run v-start-app-process $U1 $DA web
    assert_success
    assert_equal "$(field $U1 $DA web STATE)" "running"
    run eventually "tap-a $port -" fetch $U1 $DA
    assert_output "tap-a $port -"
}

@test "Lifecycle: restart and reload by restart give a new process" {
    before=$(main_pid $U1 $DA web)
    run v-restart-app-process $U1 $DA web
    assert_success
    after=$(main_pid $U1 $DA web)
    [ "$after" != "$before" ] && [ "$after" != 0 ]
    run v-reload-app-process $U1 $DA web
    assert_success
    [ "$(main_pid $U1 $DA web)" != "$after" ]
}

@test "Lifecycle: a preset reload command runs as the user" {
    dir=/home/$U1/web/$DC/public_html
    write_file $U1 "$dir/artisan" '<?php
if (($argv[1] ?? "") === "queue:work") { echo "queue up\n"; while (true) { sleep(1); } }
if (($argv[1] ?? "") === "queue:restart") { file_put_contents(__DIR__ . "/restarted.txt", get_current_user()); echo "queue restarted\n"; }
if (($argv[1] ?? "") === "migrate") { echo "migrated " . implode(" ", array_slice($argv, 2)) . "\n"; }'
    run v-add-app-process $U1 $DC queue laravel-queue
    assert_success
    run v-list-app-process $U1 $DC queue json
    echo "$output" | jq -e '.RELOAD == "command" and (.START | test("^/usr/bin/php[0-9.]+ artisan queue:work --sleep=3"))'
    run eventually "running" status_of $U1 $DC queue
    assert_output "running"
    run eventually "1" grep -c "queue up" "/home/$U1/web/$DC/app-process/logs/queue.log"
    assert_output "1"
    pid=$(main_pid $U1 $DC queue)
    run v-reload-app-process $U1 $DC queue
    assert_success
    assert_equal "$(stat -c %U "$dir/restarted.txt")" "$U1"
    grep -q "queue restarted" "/home/$U1/web/$DC/app-process/logs/queue.log"
    assert_equal "$(main_pid $U1 $DC queue)" "$pid"
}

@test "Lifecycle: logs by offset" {
    run v-list-app-process-logs $U1 $DA web process '' 5 json
    assert_success
    offset=$(echo "$output" | jq -r .OFFSET)
    fetch $U1 $DA /log-check > /dev/null
    run eventually "1" bash -c "v-list-app-process-logs $U1 $DA web process $offset '' json | jq -r '.LINES | map(select(contains(\"/log-check\"))) | length'"
    assert_output "1"
    run v-list-app-process-logs $U1 $DA web other
    assert_failure 2
    run v-list-app-process-logs $U1 $DA web process 12x
    assert_failure 2
}

@test "Settings: the environment reaches the process and the panel's keys are refused" {
    tmp=$(mktemp)
    printf '# greeting\nGREETING="hello world"\n' > "$tmp"
    run v-change-app-process-env $U1 $DA web "$tmp"
    assert_success
    port=$(field $U1 $DA web PORT)
    run eventually "tap-a $port hello world" fetch $U1 $DA
    assert_output "tap-a $port hello world"
    run v-list-app-process-env $U1 $DA web json
    echo "$output" | jq -e '.VARS == [{"KEY": "GREETING", "VALUE": "hello world"}]'
    assert_equal "$(stat -c '%U %a' "/home/$U1/web/$DA/app-process/web.env")" "$U1 600"
    printf 'PORT=1\n' > "$tmp"
    run v-change-app-process-env $U1 $DA web "$tmp"
    assert_failure 2
    printf 'not a line\n' > "$tmp"
    run v-change-app-process-env $U1 $DA web "$tmp"
    assert_failure 2
    rm -f "$tmp"
}

@test "Settings: secret references are resolved outside the home" {
    tmp=$(mktemp)
    printf 'from-the-store' > "$tmp"
    v-add-user-secret $U1 /tap/greeting "$tmp" || skip "the secret store is not set up"
    printf 'GREETING={{secret:/tap/greeting}}\n' > "$tmp"
    run v-change-app-process-env $U1 $DA web "$tmp"
    assert_success
    port=$(field $U1 $DA web PORT)
    run eventually "tap-a $port from-the-store" fetch $U1 $DA
    assert_output "tap-a $port from-the-store"
    grep -q '{{secret:/tap/greeting}}' "/home/$U1/web/$DA/app-process/web.env"
    run grep -rq "from-the-store" "/home/$U1/web/$DA/app-process/"
    assert_failure
    env_file=/run/arcpanel/app/$U1-$(field $U1 $DA web ID).env
    assert_equal "$(stat -c '%U:%G %a' "$env_file")" "root:$U1 640"
    printf 'GREETING={{secret:/tap/missing}}\n' > "$tmp"
    run v-change-app-process-env $U1 $DA web "$tmp"
    assert_failure
    printf 'GREETING=plain\n' > "$tmp"
    v-change-app-process-env $U1 $DA web "$tmp"
    v-delete-user-secret $U1 /tap/greeting > /dev/null 2>&1 || true
    rm -f "$tmp"
}

@test "Settings: change the command" {
    write_file $U1 "/home/$U1/web/$DA/public_html/server2.py" "$(sed 's/tap-a/tap-a2/' "/home/$U1/web/$DA/public_html/server.py")"
    run v-change-app-process-start $U1 $DA web 'python3 server2.py'
    assert_success
    port=$(field $U1 $DA web PORT)
    run eventually "tap-a2 $port plain" fetch $U1 $DA
    assert_output "tap-a2 $port plain"
    run v-change-app-process-start $U1 $DA web "$(printf 'python3\tserver.py\x01')"
    assert_failure 2
    run v-change-app-process-start $U1 $DA web 'python3 server.py' ../x
    assert_failure 2
}

@test "Settings: limits within the package" {
    run v-change-app-process-limits $U1 $DA web 96 0.25
    assert_success
    unit=/etc/systemd/system/arcpanel-app-$U1-$(field $U1 $DA web ID).service
    grep -qx "MemoryMax=96M" "$unit"
    grep -qx "CPUQuota=25%" "$unit"
    assert_equal "$(systemctl show -p MemoryMax --value "${unit##*/}")" "$((96 * 1024 * 1024))"
    run v-change-app-process-limits $U1 $DA web 300 1
    assert_failure 8
    run v-change-app-process-limits $U1 $DA web 64 1.5
    assert_failure 8
}

@test "Settings: a process above its memory limit is killed and restarted" {
    write_file $U1 "/home/$U1/web/$DA/public_html/hog.py" "import time
print('hog start', flush=True)
data = bytearray(200 * 1024 * 1024)
time.sleep(60)"
    run v-change-app-process-start $U1 $DA worker 'python3 hog.py'
    assert_success
    run v-change-app-process-limits $U1 $DA worker 48 1
    assert_success
    unit=arcpanel-app-$U1-$(field $U1 $DA worker ID).service
    run eventually "yes" bash -c "[ \"\$(systemctl show -p NRestarts --value $unit)\" -ge 1 ] && echo yes"
    assert_output "yes"
    [ "$(grep -c 'hog start' "/home/$U1/web/$DA/app-process/logs/worker.log")" -ge 2 ]
    run v-change-app-process-start $U1 $DA worker 'python3 worker.py'
    assert_success
    run v-start-app-process $U1 $DA worker
    assert_success
}

#----------------------------------------------------------#
#                     One-off tasks                        #
#----------------------------------------------------------#

task_status() {
    v-list-app-process-task "$1" json | jq -r '.TASK.STATUS // ""'
}

@test "Tasks: a custom task runs as the user and logs its output" {
    run v-start-app-process-task $U1 $DA web custom 'bash -c "id -un; pwd"'
    assert_success
    [[ "$output" =~ ^[0-9]+$ ]]
    run eventually "done" task_status $U1
    assert_output "done"
    log=/home/$U1/web/$DA/app-process/logs/task.log
    assert_equal "$(stat -c %U "$log")" "$U1"
    grep -qx "$U1" "$log"
    grep -qx "/home/$U1/web/$DA/public_html" "$log"
    run v-list-app-process-logs $U1 $DA web task
    assert_success
    assert_output --partial "exit 0"
}

@test "Tasks: one task at a time, refusals and failures" {
    run v-start-app-process-task $U1 $DA web custom 'sleep 4'
    assert_success
    run v-start-app-process-task $U1 $DA web custom 'true'
    assert_failure 7
    run eventually "done" task_status $U1
    assert_output "done"
    run v-start-app-process-task $U1 $DA web composer-install
    assert_failure 2
    run v-start-app-process-task $U1 $DA web pip-install
    assert_failure 2
    run v-start-app-process-task $U1 $DA web nothing
    assert_failure 2
    run v-start-app-process-task $U1 $DA web custom "$(printf 'a\nb')"
    assert_failure 2
    run v-start-app-process-task $U1 $DA web custom 'false'
    assert_success
    run eventually "failed" task_status $U1
    assert_output "failed"
    v-list-app-process-task $U1 json | jq -e '.TASK.EXIT == "1" and .TASK.TASK == "custom"'
}

@test "Tasks: migrate runs artisan with the domain's PHP" {
    run v-start-app-process-task $U1 $DC queue migrate
    assert_success
    run eventually "done" task_status $U1
    assert_output "done"
    grep -q "migrated --force --no-interaction" "/home/$U1/web/$DC/app-process/logs/task.log"
    run v-delete-app-process $U1 $DC queue
    assert_success
}

#----------------------------------------------------------#
#                       Persistence                        #
#----------------------------------------------------------#

check_types() {
    v-check-sys-app-processes check json | jq -r --arg u "$1" '[.FINDINGS[] | select(.USER == $u) | .TYPE] | sort | join(",")'
}

@test "Persistence: the setup command adds the repair job" {
    grep -q "v-check-sys-app-processes repair" /var/spool/cron/crontabs/arcpanelweb
    for hook in post_suspend_user post_unsuspend_user pre_delete_user; do
        assert_file_exists "$ARCPANEL/func/hooks.d/$hook/65-appprocesses.sh"
    done
}

@test "Persistence: suspending the user stops its processes" {
    run v-add-app-process $U2 $DD web custom 'python3 server.py' public_html 64 1 web flask-fastapi
    assert_success
    port=$(field $U2 $DD web PORT)
    run eventually "tap-d $port -" fetch $U2 $DD
    assert_output "tap-d $port -"
    v-suspend-user $U2 > /dev/null
    assert_equal "$(field $U2 $DD web SUSPENDED)" "yes"
    run status_of $U2 $DD web
    assert_output "suspended"
    run systemctl is-active "arcpanel-app-$U2-$(field $U2 $DD web ID).service"
    assert_failure
    run v-start-app-process $U2 $DD web
    assert_failure
    run check_types $U2
    assert_output ""
    v-unsuspend-user $U2 > /dev/null
    assert_equal "$(field $U2 $DD web SUSPENDED)" "no"
    run eventually "running" status_of $U2 $DD web
    assert_output "running"
}

@test "Persistence: the check stops the processes of a suspended domain and starts them again" {
    v-suspend-web-domain $U2 $DD > /dev/null
    run check_types $U2
    assert_output "process-suspended"
    run v-check-sys-app-processes repair
    assert_success
    run systemctl is-active "arcpanel-app-$U2-$(field $U2 $DD web ID).service"
    assert_failure
    assert_equal "$(field $U2 $DD web STATE)" "running"
    v-unsuspend-web-domain $U2 $DD > /dev/null
    run check_types $U2
    assert_output "process-down"
    run v-check-sys-app-processes repair
    assert_success
    run eventually "running" status_of $U2 $DD web
    assert_output "running"
    run check_types $U2
    assert_output ""
}

@test "Persistence: a missing unit is written again and an orphan unit removed" {
    id=$(field $U2 $DD web ID)
    systemctl disable --now "arcpanel-app-$U2-$id.service" > /dev/null 2>&1
    rm -f "/etc/systemd/system/arcpanel-app-$U2-$id.service"
    printf '[Service]\nExecStart=/bin/true\n' > "/etc/systemd/system/arcpanel-app-$U2-999.service"
    systemctl daemon-reload
    run check_types $U2
    assert_output "process-down,unit-missing,unit-orphan"
    run v-check-sys-app-processes repair
    assert_success
    assert_file_exists "/etc/systemd/system/arcpanel-app-$U2-$id.service"
    assert_file_not_exists "/etc/systemd/system/arcpanel-app-$U2-999.service"
    run eventually "running" status_of $U2 $DD web
    assert_output "running"
}

@test "Persistence: backup, delete and restore bring the process back on its port" {
    port=$(field $U2 $DD web PORT)
    id=$(field $U2 $DD web ID)
    run v-backup-user $U2
    assert_success
    backup=$(ls -t /backup/$U2.*.tar | head -n1)
    run v-delete-user $U2
    assert_success
    assert_file_not_exists "/etc/systemd/system/arcpanel-app-$U2-$id.service"
    assert_file_not_exists "$ARCPANEL/data/app-processes/$U2"
    assert_file_not_exists "/etc/logrotate.d/arcpanel-app-$U2"
    run v-restore-user $U2 "$(basename "$backup")"
    assert_success
    run check_types $U2
    assert_output "import"
    run v-check-sys-app-processes repair
    assert_success
    run eventually "running" status_of $U2 $DD web
    assert_output "running"
    assert_equal "$(field $U2 $DD web PORT)" "$port"
    assert_equal "$(port_app $U2 "$port")" "app:$DD"
    assert_equal "$(tpl_of $U2 $DD)" "flask-fastapi"
    run eventually "tap-d $port -" fetch $U2 $DD
    assert_output "tap-d $port -"
    rm -f /backup/$U2.*.tar
}

@test "Persistence: a forged copy is not imported" {
    other=$(field $U1 $DA web PORT)
    dir=/home/$U2/web/$DD/app-process
    start=$(printf 'python3 server.py' | base64 -w0)
    printf "ID='1' DOMAIN='%s' NAME='evil' KIND='worker' PRESET='custom' START='%s' CWD='../../../../etc' PORT='' MEMORY='999999' CPUS='64' TEMPLATE='' PREV_TPL='' STATE='running' SUSPENDED='no' TIME='' DATE=''\n" "$DD" "$start" \
        | runuser -u $U2 -- tee "$dir/evil.conf" > /dev/null
    run v-rebuild-app-processes $U2
    assert_success
    assert_output --partial "evil not imported: invalid working directory"
    assert_equal "$(field $U2 $DD evil ID)" ""
    printf "ID='1' DOMAIN='%s' NAME='other' KIND='worker' PRESET='custom' START='%s' CWD='public_html' PORT='%s' MEMORY='999999' CPUS='64' TEMPLATE='' PREV_TPL='' STATE='stopped' SUSPENDED='no' TIME='' DATE=''\n" "$DD" "$start" "$other" \
        | runuser -u $U2 -- tee "$dir/other.conf" > /dev/null
    run v-rebuild-app-processes $U2
    assert_success
    assert_equal "$(field $U2 $DD other PORT)" ""
    assert_equal "$(field $U2 $DD other MEMORY)" "256"
    assert_equal "$(field $U2 $DD other CPUS)" "1"
    assert_equal "$(field $U2 $DD other STATE)" "stopped"
    assert_equal "$(port_app $U1 "$other")" "app:$DA"
    run v-delete-app-process $U2 $DD other
    assert_success
    runuser -u $U2 -- rm -f "$dir/evil.conf"
}

@test "Persistence: deleting the domain removes its processes at the next check" {
    port=$(field $U2 $DD web PORT)
    v-delete-web-domain $U2 $DD > /dev/null
    run check_types $U2
    assert_output "domain-missing"
    run v-check-sys-app-processes repair
    assert_success
    assert_equal "$(field $U2 $DD web ID)" ""
    run port_app $U2 "$port"
    refute_output "app:$DD"
    run v-check-sys-app-processes json
    echo "$output" | jq -e '(.PROCESSES | tonumber) >= 2 and (.RUNNING | tonumber) >= 2'
}

@test "Core: delete the web process" {
    id=$(field $U1 $DA web ID)
    port=$(field $U1 $DA web PORT)
    prev=$(field $U1 $DA web PREV_TPL)
    run v-delete-app-process $U1 $DA web
    assert_success
    assert_file_not_exists "/etc/systemd/system/arcpanel-app-$U1-$id.service"
    assert_file_not_exists "$ARCPANEL/data/app-processes/$U1/$id.json"
    assert_equal "$(field $U1 $DA web ID)" ""
    assert_equal "$(tpl_of $U1 $DA)" "$prev"
    run port_app $U1 "$port"
    refute_output "app:$DA"
    assert_file_not_exists "/home/$U1/web/$DA/app-process/web.conf"
    assert_file_exists "/home/$U1/web/$DA/app-process/logs/web.log"
    run systemctl is-active "arcpanel-app-$U1-$id.service"
    assert_failure
    run nginx -t
    assert_success
    run v-delete-app-process $U1 $DA worker
    assert_success
    assert_file_not_exists "/etc/logrotate.d/arcpanel-app-$U1"
}
