#!/usr/bin/env bats
# ArcPanel Node.js apps (E14,
# docs/features/specs/2026-10-05-007-e14-node-apps-design.md).
# Run as root on a server installed with the ArcPanel installer, after
# test/arcpanel-node.bats has built its local Node.js mirror
# (/root/arcpanel-node-mirror). The first app of each user installs pm2 from
# the npm registry.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

HESTIA=/usr/local/hestia
PKG_DIR=$HESTIA/data/packages
PORTS_DIR=$HESTIA/data/ports
CONF=$HESTIA/conf/hestia.conf
MIRROR=/root/arcpanel-node-mirror
PASS="Tna-$(date +%s)-Pass"
USERS="tna1 tna2 tnaoff"
U1=tna1
U2=tna2
DA=a.tna.example
DB=b.tna.example
DC=c.tna.example
DD=d.tna.example

source_value() {
    grep "^$1=" "$CONF" | tail -n1 | cut -d"'" -f2
}
if [ "$(source_value WEB_SYSTEM)" = 'nginx' ]; then
    tpl_of() { grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/web.conf" | grep -o " TPL='[^']*'" | cut -d"'" -f2; }
else
    tpl_of() { grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/web.conf" | grep -o " PROXY='[^']*'" | cut -d"'" -f2; }
fi

latest_of() {
    jq -r --arg m "$1" '[.[] | select(.version | startswith("v" + $m + ".")) | .version][0] | ltrimstr("v")' "$MIRROR/index.json"
}

make_package() {
    local name=$1 node=$2 apps=$3 tmp
    tmp=$(mktemp)
    sed "s/^SHELL=.*/SHELL='bash'/" "$PKG_DIR/default.pkg" > "$tmp"
    v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy "$name" NODE "$node"
    v-change-package-tenancy "$name" NODE_VERSIONS 3
    v-change-package-tenancy "$name" NODE_ALLOWED '*'
    v-change-package-tenancy "$name" NODE_APPS "$apps"
    v-change-package-tenancy "$name" PORTS 10
}

# Local address of the domain (web.conf may hold the NAT address)
web_ip() {
    local ip
    ip=$(grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
    if [ -f "$HESTIA/data/ips/$ip" ]; then
        echo "$ip"
    else
        basename "$(grep -l "^NAT='$ip'" "$HESTIA"/data/ips/* | head -n1)"
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

# A test app: answers "<tag> <PORT> <GREETING>" on every path
write_app() {
    local user=$1 domain=$2 file=$3 tag=$4 dir
    dir=/home/$user/web/$domain/public_html
    cat > "$dir/$file" << EOF
const http = require('http');
http.createServer((req, res) => {
    res.end('$tag ' + process.env.PORT + ' ' + (process.env.GREETING || '-'));
}).listen(process.env.PORT, process.env.HOST);
EOF
    chown "$user:$user" "$dir/$file"
}

write_package_json() {
    local user=$1 domain=$2 content=$3 dir
    dir=/home/$user/web/$domain/public_html
    printf '%s\n' "$content" > "$dir/package.json"
    chown "$user:$user" "$dir/package.json"
}

app_field() {
    grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/nodeapps.conf" | grep -oE "(^| )$3='[^']*'" | cut -d"'" -f2
}

port_app() {
    grep "^PORT='$2' " "$PORTS_DIR/$1.conf" | grep -o "APP='[^']*'" | cut -d"'" -f2
}

app_status() {
    v-list-node-apps "$1" json | jq -r --arg d "$2" '.APPS[] | select(.DOMAIN == $d) | .STATUS'
}

cleanup() {
    for user in $USERS; do
        if id "$user" > /dev/null 2>&1; then
            loginctl disable-linger "$user" > /dev/null 2>&1 || true
            systemctl stop "user@$(id -u "$user").service" > /dev/null 2>&1 || true
            pkill -9 -u "$user" > /dev/null 2>&1 || true
        fi
        v-delete-user "$user" > /dev/null 2>&1 || true
        rm -f "/etc/logrotate.d/arcpanel-node-$user"
    done
    for package in tna-dev tna-off; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
}

setup_file() {
    cleanup
    [ -s "$MIRROR/index.json" ] || skip "run test/arcpanel-node.bats first (Node.js mirror)"
    v-change-sys-config-value NODE_RELEASES_URL "file://$MIRROR"
    v-change-sys-config-value NODE_SCHEDULE_URL "file://$MIRROR/schedule.json"
    v-change-sys-config-value NODE_NVM_URL "file://$MIRROR/nvm"
    v-update-sys-node-releases > /dev/null
    make_package tna-dev yes 2
    make_package tna-off no 2
    V24=$(latest_of 24)
    V22=$(latest_of 22)
    for user in $U1 $U2; do
        v-add-user "$user" "$PASS" "$user@example.invalid" tna-dev > /dev/null
        v-add-user-nvm "$user" > /dev/null
        v-add-user-node-version "$user" "$V24" > /dev/null
    done
    v-add-user-node-version $U1 "$V22" > /dev/null
    v-add-user tnaoff "$PASS" tnaoff@example.invalid tna-off > /dev/null
    v-add-web-domain $U1 $DA > /dev/null
    v-add-web-domain $U1 $DB > /dev/null
    v-add-web-domain $U1 $DC > /dev/null
    v-add-web-domain $U2 $DD > /dev/null
    v-add-web-domain tnaoff off.tna.example > /dev/null
    write_app $U1 $DA server.js tna-a
    write_app $U1 $DB srv.js tna-b
    write_package_json $U1 $DB '{"name":"b","scripts":{"start":"node srv.js","build":"echo built > built.txt"}}'
    write_app $U1 $DC server.js tna-c
    write_app $U2 $DD app.js tna-d
    write_app tnaoff off.tna.example server.js tna-off
    export V24 V22
}

teardown_file() {
    cleanup
    sed -i "/^NODE_RELEASES_URL=/d; /^NODE_SCHEDULE_URL=/d; /^NODE_NVM_URL=/d" "$CONF"
}

setup() {
    V24=$(latest_of 24)
    V22=$(latest_of 22)
}

#----------------------------------------------------------#
#                           Core                           #
#----------------------------------------------------------#

@test "Core: NODE_APPS is a package key" {
    run v-list-package-tenancy tna-dev json
    assert_success
    echo "$output" | jq -e '.["tna-dev"].NODE_APPS == "2"'
    run v-change-package-tenancy tna-dev NODE_APPS many
    assert_failure 2
    grep -q "^NODE_APPS='10'" "$PKG_DIR/developer.tenancy"
}

@test "Core: add an app with the detected start file" {
    prev=$(tpl_of $U1 $DA)
    run v-add-node-app $U1 $DA
    assert_success
    assert_equal "$(app_field $U1 $DA START)" "server.js"
    assert_equal "$(app_field $U1 $DA PREV_TPL)" "$prev"
    assert_equal "$(tpl_of $U1 $DA)" "nodejs-proxy"
    port=$(app_field $U1 $DA PORT)
    assert_equal "$(port_app $U1 "$port")" "node:$DA"
    grep -q "^BACKEND_PORT='$port'" "/home/$U1/conf/web/$DA/nginx.conf.tplvars"
    assert_file_exists "/home/$U1/web/$DA/nodeapp/ecosystem.config.cjs"
    assert_file_exists "/home/$U1/web/$DA/nodeapp/app.conf"
    assert_file_exists "/home/$U1/.config/systemd/user/arcpanel-pm2.service"
    assert_file_exists "/var/lib/systemd/linger/$U1"
    assert_file_exists "/etc/logrotate.d/arcpanel-node-$U1"
    assert_equal "$(stat -c %U "/home/$U1/web/$DA/nodeapp/.env")" "$U1"
    assert_equal "$(stat -c %a "/home/$U1/web/$DA/nodeapp/.env")" "600"
    run nginx -t
    assert_success
    run eventually "tna-a $port -" fetch $U1 $DA
    assert_output "tna-a $port -"
}

@test "Core: the app runs in the user's pm2 daemon under systemd --user" {
    run app_status $U1 $DA
    assert_output "online"
    pid=$(v-list-node-apps $U1 json | jq -r --arg d "$DA" '.APPS[] | select(.DOMAIN == $d) | .PID')
    assert_equal "$(stat -c %U "/proc/$pid")" "$U1"
    grep -q "user@$(id -u $U1).service" "/proc/$pid/cgroup"
    run v-list-node-app $U1 $DA json
    assert_success
    echo "$output" | jq -e --arg d "$DA" --arg v "$V24" '.[$d].RUNTIME == $v and .[$d].PM2_RUNTIME == $v and .[$d].URL == "http://" + $d + "/"'
    run v-list-node-apps $U1
    assert_success
    assert_line --partial "$DA"
}

@test "Core: an npm script as start command" {
    run v-add-node-app $U1 $DB npm:start "$V22"
    assert_success
    port=$(app_field $U1 $DB PORT)
    run eventually "tna-b $port -" fetch $U1 $DB
    assert_output "tna-b $port -"
    run v-list-node-app $U1 $DB json
    echo "$output" | jq -e --arg d "$DB" --arg v "$V22" '.[$d].RUNTIME == $v'
}

@test "Core: limits, duplicates and the package" {
    run v-add-node-app $U1 $DC
    assert_failure 8
    assert_output --partial "NODE_APPS"
    run v-add-node-app $U1 $DA
    assert_failure 4
    run v-add-node-app tnaoff off.tna.example
    assert_failure 11
    run v-add-node-app $U2 $DA
    assert_failure 3
}

@test "Core: invalid arguments leave nothing behind" {
    prev=$(tpl_of $U2 $DD)
    run v-add-node-app $U2 $DD missing.js
    assert_failure 3
    run v-add-node-app $U2 $DD ../../../etc/passwd
    assert_failure 2
    run v-add-node-app $U2 $DD app.js 99.0.0
    assert_failure 3
    run v-add-node-app $U2 $DD app.js default auto public_html 1 default
    assert_failure 2
    run v-add-node-app $U2 $DD npm:start default auto public_html 2
    assert_failure 2
    run v-add-node-app $U2 $DD app.js default auto public_html 1 nodejs-proxy 'Bad Name'
    assert_failure 2
    assert_equal "$(tpl_of $U2 $DD)" "$prev"
    run grep -c "node:$DD" "$PORTS_DIR/$U2.conf"
    assert_output "0"
    assert_file_not_exists "/home/$U2/web/$DD/nodeapp"
}

@test "Core: a port held by another process is refused and rolled back" {
    port=$(v-allocate-user-port $U2 manual)
    v-release-user-port $U2 "$port" > /dev/null
    python3 -m http.server "$port" --bind 127.0.0.1 > /dev/null 2>&1 &
    holder=$!
    sleep 1
    prev=$(tpl_of $U2 $DD)
    run v-add-node-app $U2 $DD app.js default "$port"
    kill "$holder"
    assert_failure 7
    assert_output --partial "python3"
    assert_equal "$(tpl_of $U2 $DD)" "$prev"
    [ ! -f "$HESTIA/data/users/$U2/nodeapps.conf" ] || ! grep -q "$DD" "$HESTIA/data/users/$U2/nodeapps.conf"
    assert_file_not_exists "/var/lib/systemd/linger/$U2"
}

@test "Core: delete an app restores the template and frees the port" {
    prev=$(app_field $U1 $DB PREV_TPL)
    port=$(app_field $U1 $DB PORT)
    run v-delete-node-app $U1 $DB
    assert_success
    assert_equal "$(tpl_of $U1 $DB)" "$prev"
    assert_equal "$(port_app $U1 "$port")" ""
    assert_file_not_exists "/home/$U1/web/$DB/nodeapp"
    assert_file_not_exists "/home/$U1/conf/web/$DB/nginx.conf.tplvars"
    run app_status $U1 $DA
    assert_output "online"
    run v-delete-node-app $U1 $DB
    assert_failure 3
}

#----------------------------------------------------------#
#                        Last app                          #
#----------------------------------------------------------#

@test "Last app: deleting it retires the daemon and the linger" {
    run v-delete-node-app $U1 $DA
    assert_success
    assert_file_not_exists "/home/$U1/.config/systemd/user/arcpanel-pm2.service"
    assert_file_not_exists "/var/lib/systemd/linger/$U1"
    assert_file_not_exists "/etc/logrotate.d/arcpanel-node-$U1"
    assert_file_not_exists "$HESTIA/data/users/$U1/pm2.conf"
    run pgrep -u $U1 -f "PM2"
    assert_failure
}
