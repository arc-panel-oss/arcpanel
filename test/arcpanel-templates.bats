#!/usr/bin/env bats
# ArcPanel web template metadata, per-domain variables and the batch 1
# templates (E6, docs/features/specs/2026-10-05-005-e6-templates-batch1-design.md).
# Run as root on a server installed with the ArcPanel installer and NGINX in
# front of Apache or as the web server. Uses ports 38500-38599 of the server
# range and 127.0.0.1:385xx listeners.

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

HESTIA=/usr/local/hestia
WEBTPL=$HESTIA/data/templates/web
PORTS_DIR=$HESTIA/data/ports
PASS="Tt-$(date +%s)-Pass"
U1=e6tu1
U2=e6tu2
U3=e6tu3
D1=e6a.example
D2=e6b.example
D3=e6c.example
D4=e6d.example
TEST_TPL=arctest-port
LISTEN_DIR=$(mktemp -d)

source_value() {
    grep "^$1=" "$HESTIA/conf/hestia.conf" | tail -n1 | cut -d"'" -f2
}
PROXY_SYSTEM=$(source_value PROXY_SYSTEM)
WEB_SYSTEM=$(source_value WEB_SYSTEM)

# Folder and command for the client-facing template on this server
if [ "$WEB_SYSTEM" = 'nginx' ]; then
    TPL_DIR=$WEBTPL/nginx/php-fpm
    TPL_KIND=web
    change_tpl() { v-change-web-domain-tpl "$1" "$2" "$3"; }
    default_tpl=default
else
    TPL_DIR=$WEBTPL/nginx
    TPL_KIND=proxy
    change_tpl() { v-change-web-domain-proxy-tpl "$1" "$2" "$3"; }
    default_tpl=default
fi

conf_of() {
    echo "/home/$1/conf/web/$2/nginx.conf"
}

# Local address of the domain: web.conf stores the NAT address when the IP
# has one
web_ip() {
    local ip
    ip=$(grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
    if [ -f "$HESTIA/data/ips/$ip" ]; then
        echo "$ip"
    else
        basename "$(grep -l "^NAT='$ip'" "$HESTIA"/data/ips/* | head -n1)"
    fi
}

# HTTP status and body of http://DOMAIN/ through the domain's IP
fetch() {
    curl -s --noproxy '*' --max-time 10 --resolve "$2:80:$(web_ip "$1" "$2")" "http://$2/"
}

fetch_status() {
    curl -s --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$2:80:$(web_ip "$1" "$2")" "http://$2/"
}

# Serve LISTEN_DIR/<port>/index.html on 127.0.0.1:PORT
start_listener() {
    mkdir -p "$LISTEN_DIR/$1"
    echo "e6-listener-$1" > "$LISTEN_DIR/$1/index.html"
    (cd "$LISTEN_DIR/$1" && exec python3 -m http.server "$1" --bind 127.0.0.1 > /dev/null 2>&1) &
    local i
    for i in $(seq 1 30); do
        if ss -Hltn "sport = :$1" | grep -q .; then
            return 0
        fi
        sleep 0.2
    done
    return 1
}

stop_listeners() {
    pkill -f "python3 -m http.server 385" > /dev/null 2>&1 || true
}

port_app() {
    grep "^PORT='$2' " "$PORTS_DIR/$1.conf" | grep -o "APP='[^']*'" | cut -d"'" -f2
}

install_test_template() {
    local ext
    for ext in tpl stpl; do
        cat > "$TPL_DIR/$TEST_TPL.$ext" << EOF
server {
	listen      %ip%:$([ "$TPL_KIND" = proxy ] && echo '%proxy_port%' || echo '%web_port%');
	server_name %domain_idn% %alias_idn%;
	error_log   /var/log/nginx/domains/%domain%.error.log error;

	# arc:app-begin
	location / {
		proxy_pass http://127.0.0.1:%arc_backend_port%;
	}
	# arc:app-end

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}
}
EOF
    done
    printf '#!/bin/bash\nsource /usr/local/hestia/func/arc-template.sh\narc_apply_tpl_vars "$1" "$2" "$4"\n' > "$TPL_DIR/$TEST_TPL.sh"
    chmod 755 "$TPL_DIR/$TEST_TPL.sh"
    mkdir -p "$WEBTPL/meta/$TPL_KIND"
    cat > "$WEBTPL/meta/$TPL_KIND/$TEST_TPL.json" << 'EOF'
{
  "title": "Test port template",
  "description": "Proxies to a port of the pool (BATS only).",
  "category": "proxy",
  "variables": [{ "key": "BACKEND_PORT", "type": "port", "required": true, "label": "App port" }]
}
EOF
}

remove_test_templates() {
    rm -f "$TPL_DIR/$TEST_TPL".{tpl,stpl,sh} "$WEBTPL/meta/$TPL_KIND/$TEST_TPL.json"
    rm -f "$TPL_DIR"/arctest-nometa.{tpl,stpl} "$TPL_DIR"/arctest-bad.{tpl,stpl} "$WEBTPL/meta/$TPL_KIND/arctest-bad.json"
}

cleanup() {
    stop_listeners
    for user in $U1 $U2 $U3; do
        v-delete-user "$user" > /dev/null 2>&1 || true
        rm -f "$PORTS_DIR/$user.conf"
    done
    rm -rf /backup/$U1.*.tar /backup/$U1.*.tar.zst
    remove_test_templates
}

setup_file() {
    cleanup
    v-add-sys-web-templates-meta > /dev/null
    install_test_template
    v-add-user $U1 "$PASS" $U1@example.invalid default > /dev/null
    v-add-user $U2 "$PASS" $U2@example.invalid default > /dev/null
    v-add-user $U3 "$PASS" $U3@example.invalid default > /dev/null
    rm -f "$PORTS_DIR/$U1.conf" "$PORTS_DIR/$U2.conf" "$PORTS_DIR/$U3.conf"
    v-add-user-port $U1 38500-38509 > /dev/null
    v-add-user-port $U2 38520-38524 > /dev/null
    v-add-web-domain $U1 $D1 > /dev/null
    v-add-web-domain $U1 $D2 > /dev/null
    v-add-web-domain $U2 $D3 > /dev/null
    v-add-web-domain $U3 $D4 > /dev/null
}

teardown_file() {
    cleanup
    rm -rf "$LISTEN_DIR"
}

#----------------------------------------------------------#
#                     M1: metadata                         #
#----------------------------------------------------------#

@test "Metadata setup is idempotent and installs the metadata folder" {
    run v-add-sys-web-templates-meta
    assert_success
    run v-add-sys-web-templates-meta
    assert_success
    assert_file_exist "$WEBTPL/meta/proxy/default.json"
    assert_file_exist "$WEBTPL/meta/wordpress.json"
}

@test "Meta list names exactly the templates of each kind" {
    local kind list meta
    for kind in web proxy backend; do
        case $kind in
            web) list=$(v-list-web-templates json | jq -r '.[]' | sort) ;;
            proxy) list=$(v-list-web-templates-proxy json | jq -r '.[]' | sort) ;;
            backend) list=$(v-list-web-templates-backend json | jq -r '.[]' | sort) ;;
        esac
        meta=$(v-list-web-templates-meta "$kind" json | jq -r '.[].NAME' | sort)
        assert_equal "$meta" "$list"
    done
}

@test "Meta list merges the metadata of shipped templates" {
    run bash -c "v-list-web-templates-meta proxy json | jq -r '.[] | select(.NAME == \"default\") | [.TITLE, .CATEGORY, .HAS_META] | join(\"|\")'"
    assert_output "Standard proxy|general|yes"
    run bash -c "v-list-web-templates-meta backend json | jq -r '.[] | select(.NAME | test(\"^PHP-\")) | .TITLE' | head -n1"
    assert_output --regexp '^PHP [0-9]+\.[0-9]+$'
    run bash -c "v-list-web-templates-meta $TPL_KIND json | jq -r '.[] | select(.NAME == \"$TEST_TPL\") | .VARIABLES[0].KEY'"
    assert_output "BACKEND_PORT"
    run bash -c "v-list-web-templates-meta $TPL_KIND json | jq -r '.[] | select(.NAME == \"suspended\") | .INTERNAL'"
    assert_output "yes"
}

@test "A template without metadata or with a malformed file falls back to its name" {
    touch "$TPL_DIR/arctest-nometa.tpl" "$TPL_DIR/arctest-nometa.stpl" "$TPL_DIR/arctest-bad.tpl" "$TPL_DIR/arctest-bad.stpl"
    echo '{"title": 42}' > "$WEBTPL/meta/$TPL_KIND/arctest-bad.json"
    run bash -c "v-list-web-templates-meta $TPL_KIND json | jq -r '.[] | select(.NAME == \"arctest-nometa\" or .NAME == \"arctest-bad\") | [.NAME, .TITLE, .CATEGORY, .HAS_META] | join(\"|\")'"
    assert_line "arctest-bad|arctest-bad|other|no"
    assert_line "arctest-nometa|arctest-nometa|other|no"
    run v-list-web-templates-meta "$TPL_KIND" csv
    assert_success
    assert_line --partial '"arctest-nometa","arctest-nometa","","other"'
    rm -f "$TPL_DIR"/arctest-nometa.{tpl,stpl} "$TPL_DIR"/arctest-bad.{tpl,stpl} "$WEBTPL/meta/$TPL_KIND/arctest-bad.json"
}

@test "Meta list refuses an unknown kind" {
    run v-list-web-templates-meta mail json
    assert_failure 2
}

#----------------------------------------------------------#
#                     M2: variables                        #
#----------------------------------------------------------#

@test "A port template without variables serves the 503 page and keeps NGINX valid" {
    run change_tpl $U1 $D1 $TEST_TPL
    assert_success
    run grep -c '%arc_' "$(conf_of $U1 $D1)"
    assert_output "0"
    run nginx -t
    assert_success
    run fetch_status $U1 $D1
    assert_output "503"
    run fetch $U1 $D1
    assert_output --partial "not configured yet"
    run bash -c "v-list-web-domain-tpl-vars $U1 $D1 json | jq -r '[.ACTIVE_TEMPLATE, (.MISSING | join(\",\"))] | join(\"|\")'"
    assert_output "$TEST_TPL|BACKEND_PORT"
}

@test "auto assigns a pool port to web:<domain> and the domain reaches the app" {
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=auto
    assert_success
    run v-list-web-domain-tpl-vars $U1 $D1 plain
    assert_output "BACKEND_PORT	38500"
    run port_app $U1 38500
    assert_output "web:$D1"
    run grep -c "127.0.0.1:38500;" "$(conf_of $U1 $D1)"
    assert_output "1"
    start_listener 38500
    run fetch $U1 $D1
    assert_output "e6-listener-38500"
    # auto again keeps the assigned port
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=auto
    assert_success
    run v-list-web-domain-tpl-vars $U1 $D1 plain
    assert_output "BACKEND_PORT	38500"
}

@test "Ports outside the owner's pool or used by another domain are refused" {
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=38520
    assert_failure 10
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=38590
    assert_failure 10
    run change_tpl $U1 $D2 $TEST_TPL
    assert_success
    run v-change-web-domain-tpl-vars $U1 $D2 BACKEND_PORT=38500
    assert_failure 7
    assert_output --partial "web:$D1"
    run v-list-web-domain-tpl-vars $U1 $D1 plain
    assert_output "BACKEND_PORT	38500"
}

@test "An empty limited pool, an undeclared key and a bad value are refused" {
    run change_tpl $U3 $D4 $TEST_TPL
    assert_success
    run v-change-web-domain-tpl-vars $U3 $D4 BACKEND_PORT=auto
    assert_failure 8
    run v-change-web-domain-tpl-vars $U1 $D1 OTHER_KEY=1
    assert_failure 2
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=80
    assert_failure 2
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=38501x
    assert_failure 2
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT
    assert_failure 2
}

@test "A manual port is used as it is and a port change releases the old one" {
    v-allocate-user-port $U1 manual tcp 38505 'run by hand' > /dev/null
    run v-change-web-domain-tpl-vars $U1 $D2 BACKEND_PORT=38505
    assert_success
    run port_app $U1 38505
    assert_output "manual"
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=38503
    assert_success
    run port_app $U1 38503
    assert_output "web:$D1"
    run port_app $U1 38500
    assert_output ""
    run grep -c "127.0.0.1:38503;" "$(conf_of $U1 $D1)"
    assert_output "1"
}

@test "Rebuilds and template changes keep the variables" {
    run v-rebuild-web-domain $U1 $D1
    assert_success
    run grep -c "127.0.0.1:38503;" "$(conf_of $U1 $D1)"
    assert_output "1"
    run change_tpl $U1 $D1 $default_tpl
    assert_success
    run change_tpl $U1 $D1 $TEST_TPL
    assert_success
    run grep -c "127.0.0.1:38503;" "$(conf_of $U1 $D1)"
    assert_output "1"
}

@test "A suspended domain refuses changes" {
    v-suspend-web-domain $U1 $D2 > /dev/null
    run v-change-web-domain-tpl-vars $U1 $D2 BACKEND_PORT=auto
    assert_failure 5
    v-unsuspend-web-domain $U1 $D2 > /dev/null
}

@test "A variables file pointing at another user's port renders the 503 page" {
    local file
    file=$(conf_of $U1 $D2).tplvars
    cp -p "$file" "$file.keep"
    echo "BACKEND_PORT='38520'" > "$file"
    v-rebuild-web-domain $U1 $D2 > /dev/null
    run grep -c "38520" "$(conf_of $U1 $D2)"
    assert_output "0"
    run fetch_status $U1 $D2
    assert_output "503"
    mv -f "$file.keep" "$file"
    v-rebuild-web-domain $U1 $D2 > /dev/null
}

@test "The nightly check finds a port left by a CLI template change and repair releases it" {
    run change_tpl $U1 $D1 $default_tpl
    assert_success
    run v-check-sys-ports
    assert_output --regexp "orphan-web $U1 38503 tcp"
    run v-check-sys-ports repair
    assert_success
    refute_output --regexp "orphan-web $U1 38503"
    run port_app $U1 38503
    assert_output ""
    run grep -c '"TYPE": "orphan-web"' "$PORTS_DIR/.report.json"
    assert_output "0"
}

@test "Delete releases web:<domain> ports, removes the file and is idempotent" {
    run change_tpl $U1 $D1 $TEST_TPL
    assert_success
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=auto
    assert_success
    port=$(v-list-web-domain-tpl-vars $U1 $D1 plain | cut -f2)
    run v-delete-web-domain-tpl-vars $U1 $D1
    assert_success
    run port_app $U1 "$port"
    assert_output ""
    assert_file_not_exist "$(conf_of $U1 $D1).tplvars"
    run fetch_status $U1 $D1
    assert_output "503"
    run v-delete-web-domain-tpl-vars $U1 $D1
    assert_success
    run v-delete-web-domain-tpl-vars $U1 no-such-domain.example
    assert_success
}

@test "Backup and restore keep the variables of a domain" {
    run v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=38500
    assert_success
    run v-backup-user $U1
    assert_success
    backup=$(ls -t /backup/$U1.*.tar* | head -n1)
    v-delete-web-domain $U1 $D1 > /dev/null
    assert_file_not_exist "$(conf_of $U1 $D1).tplvars"
    run v-restore-user $U1 "$(basename "$backup")" "$D1" no no no no no no
    assert_success
    run v-list-web-domain-tpl-vars $U1 $D1 plain
    assert_output "BACKEND_PORT	38500"
    start_listener 38500 || true
    run fetch $U1 $D1
    assert_output "e6-listener-38500"
}
