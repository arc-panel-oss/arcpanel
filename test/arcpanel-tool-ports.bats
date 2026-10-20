#!/usr/bin/env bats
# ArcPanel webmail and database tools ports (E46,
# docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md).
# Run as root on a server installed with the ArcPanel installer, with
# Roundcube, phpMyAdmin and the firewall on. Uses ports 38450-38469 of the
# server range; leaves the tool ports on 8086 and 8087.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
CONF=$ARCPANEL/conf/arcpanel.conf
RULES=$ARCPANEL/data/firewall/rules.conf
RESERVED=$ARCPANEL/data/ports/.reserved.conf
WEBMAIL_FILE=/etc/nginx/conf.d/arcpanel-webmail-port.conf
DB_FILE=/etc/nginx/conf.d/arcpanel-db-admin-port.conf
TPL=$ARCPANEL/install/deb/nginx/tool-ports

key() {
    grep -m1 "^$1=" "$CONF" | cut -d"'" -f2
}

drop_key() {
    sed -i "/^$1=/d" "$CONF"
}

rule_ports() {
    grep "COMMENT='arcpanel-$1-port'" "$RULES" 2> /dev/null | grep -o "PORT='[0-9]*'" | cut -d"'" -f2 | sort -u
}

reserved_port() {
    grep "COMMENT='arcpanel-$1-port'" "$RESERVED" 2> /dev/null | cut -d"'" -f2
}

# HTTP status of https://HOST:PORT/PATH
status() {
    curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://$1:$2$3"
}

# Wait until something listens (or no longer listens) on PORT
wait_port() {
    local port=$1 want=$2 i
    for i in $(seq 1 20); do
        if [ -n "$(ss -ltnH "( sport = :$port )")" ]; then
            [ "$want" = 'up' ] && return 0
        else
            [ "$want" = 'down' ] && return 0
        fi
        sleep 0.5
    done
    return 1
}

server_ip() {
    ip -4 -o addr show scope global | awk '{print $4}' | cut -d/ -f1 | head -n1
}

cleanup() {
    pkill -f "nc -l 0.0.0.0 3845" > /dev/null 2>&1 || true
    pkill -f "nc -l 0.0.0.0 8086" > /dev/null 2>&1 || true
    v-delete-sys-port-reserved 38451 > /dev/null 2>&1 || true
    if [ -f "$TPL/roundcube.tpl.bats" ]; then
        mv -f "$TPL/roundcube.tpl.bats" "$TPL/roundcube.tpl"
    fi
}

setup_file() {
    cleanup
    v-change-sys-webmail-port none > /dev/null 2>&1 || true
    v-change-sys-db-admin-port none > /dev/null 2>&1 || true
    drop_key WEBMAIL_PORT
    drop_key DB_ADMIN_PORT
    drop_key WEBMAIL_LINK
}

teardown_file() {
    cleanup
    v-change-sys-webmail-port 8086 > /dev/null 2>&1 || true
    v-change-sys-db-admin-port 8087 > /dev/null 2>&1 || true
    v-change-sys-webmail-link port > /dev/null 2>&1 || true
}

@test "setup: turns both ports on with their rules and reservations" {
    run v-add-sys-tool-ports
    assert_success
    assert_equal "$(key WEBMAIL_PORT)" 8086
    assert_equal "$(key DB_ADMIN_PORT)" 8087
    assert_equal "$(key WEBMAIL_LINK)" port
    assert_file_exist "$WEBMAIL_FILE"
    assert_file_exist "$DB_FILE"
    assert_equal "$(rule_ports webmail)" 8086
    assert_equal "$(rule_ports db-admin)" 8087
    assert_equal "$(reserved_port webmail)" 8086
    assert_equal "$(reserved_port db-admin)" 8087
    run wait_port 8086 up
    assert_success
}

@test "setup: lists the keys" {
    run v-list-sys-config json
    assert_success
    run jq -r '.config.WEBMAIL_PORT, .config.DB_ADMIN_PORT, .config.WEBMAIL_LINK' <<< "$(v-list-sys-config json)"
    assert_output "8086
8087
port"
}

@test "webmail port: Roundcube answers by name and by IP, private files do not" {
    run status 127.0.0.1 8086 /
    assert_output 200
    curl -sk "https://127.0.0.1:8086/" | grep -qi 'roundcube'
    run status "$(server_ip)" 8086 /
    assert_output 200
    run status 127.0.0.1 8086 /config/config.inc.php
    assert_output 404
    run status 127.0.0.1 8086 /.htaccess
    assert_output 404
}

@test "webmail port: plain HTTP is sent to HTTPS" {
    run curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://127.0.0.1:8086/"
    assert_output --regexp '^30[0-9] https://127.0.0.1:8086/'
}

@test "database tools port: / goes to phpMyAdmin, which answers" {
    run curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "https://127.0.0.1:8087/"
    assert_output "302 https://127.0.0.1:8087/$(key DB_PMA_ALIAS)/"
    run status 127.0.0.1 8087 "/$(key DB_PMA_ALIAS)/"
    assert_output 200
    run status 127.0.0.1 8087 "/$(key DB_PMA_ALIAS)/setup/"
    assert_output 404
}

@test "setup: runs again without changing a key that is set" {
    v-change-sys-webmail-port none
    run v-add-sys-tool-ports
    assert_success
    assert_equal "$(key WEBMAIL_PORT)" ''
    assert_file_not_exist "$WEBMAIL_FILE"
    v-change-sys-webmail-port 8086
}

@test "setup: a busy default port stays off with a warning" {
    v-change-sys-webmail-port none
    drop_key WEBMAIL_PORT
    wait_port 8086 down
    nc -l 0.0.0.0 8086 > /dev/null 2>&1 &
    wait_port 8086 up
    ss -ltnpH "( sport = :8086 )" | grep -q '"nc"'
    run v-add-sys-tool-ports
    assert_success
    assert_output --partial 'port 8086 is in use'
    assert_equal "$(key WEBMAIL_PORT)" ''
    assert_file_not_exist "$WEBMAIL_FILE"
    pkill -f "nc -l 0.0.0.0 8086"
    wait_port 8086 down
    run v-change-sys-webmail-port 8086
    assert_success
}

@test "change: refuses invalid and taken ports" {
    for port in abc 80 1023 65536 8083 8085 8087 "$(key WEB_SSL_PORT)"; do
        run v-change-sys-webmail-port "$port"
        assert_failure
    done
    nc -l 0.0.0.0 38450 > /dev/null 2>&1 &
    wait_port 38450 up
    run v-change-sys-webmail-port 38450
    assert_failure
    assert_output --partial 'in use'
    v-add-sys-port-reserved 38451 'bats other'
    run v-change-sys-webmail-port 38451
    assert_failure
    assert_output --partial 'reserved'
    assert_equal "$(key WEBMAIL_PORT)" 8086
}

@test "change: moves the port, its rule and its reservation" {
    run v-change-sys-webmail-port 38460
    assert_success
    assert_equal "$(key WEBMAIL_PORT)" 38460
    assert_equal "$(rule_ports webmail)" 38460
    assert_equal "$(reserved_port webmail)" 38460
    run grep -c 'listen      38460 ssl;' "$WEBMAIL_FILE"
    assert_output 1
    run wait_port 38460 up
    assert_success
    run wait_port 8086 down
    assert_success
    run status 127.0.0.1 38460 /
    assert_output 200
}

@test "change: a refused NGINX configuration keeps the previous one" {
    mv "$TPL/roundcube.tpl" "$TPL/roundcube.tpl.bats"
    echo 'this is not nginx;' > "$TPL/roundcube.tpl"
    run v-change-sys-webmail-port 38461
    assert_failure
    mv -f "$TPL/roundcube.tpl.bats" "$TPL/roundcube.tpl"
    assert_equal "$(key WEBMAIL_PORT)" 38460
    assert_equal "$(rule_ports webmail)" 38460
    assert_equal "$(reserved_port webmail)" 38460
    run grep -c 'listen      38460 ssl;' "$WEBMAIL_FILE"
    assert_output 1
    run nginx -t
    assert_success
}

@test "change: none removes the file, the rule and the reservation" {
    run v-change-sys-webmail-port none
    assert_success
    assert_equal "$(key WEBMAIL_PORT)" ''
    assert_file_not_exist "$WEBMAIL_FILE"
    assert_equal "$(rule_ports webmail)" ''
    assert_equal "$(reserved_port webmail)" ''
    run wait_port 38460 down
    assert_success
    run v-change-sys-webmail-port 8086
    assert_success
}

@test "other port commands refuse the tool ports" {
    run v-change-sys-port 8086
    assert_failure
    run v-change-sys-web-terminal-port 8087
    assert_failure
    assert_equal "$(key BACKEND_PORT)" 8083
}

@test "webmail link: port and domain, nothing else" {
    run v-change-sys-webmail-link domain
    assert_success
    assert_equal "$(key WEBMAIL_LINK)" domain
    run v-change-sys-webmail-link other
    assert_failure
    run v-change-sys-webmail-link port
    assert_success
    assert_equal "$(key WEBMAIL_LINK)" port
}

@test "update: renders nothing new and reloads on request" {
    before=$(md5sum "$WEBMAIL_FILE" "$DB_FILE")
    run v-update-sys-tool-ports yes
    assert_success
    assert_equal "$(md5sum "$WEBMAIL_FILE" "$DB_FILE")" "$before"
    run status 127.0.0.1 8086 /
    assert_output 200
}

@test "update: puts back a lost firewall rule" {
    rule=$(grep -m1 "COMMENT='arcpanel-db-admin-port'" "$RULES" | cut -d"'" -f2)
    v-delete-firewall-rule "$rule"
    run v-update-sys-tool-ports
    assert_success
    assert_equal "$(rule_ports db-admin)" 8087
    want=1
    [ "$(key FIREWALL_SYSTEM)" = 'nftables' ] && want=2
    run grep -c "COMMENT='arcpanel-db-admin-port'" "$RULES"
    assert_output "$want"
}
