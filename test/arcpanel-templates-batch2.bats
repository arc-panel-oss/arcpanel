#!/usr/bin/env bats
# E6 batch 2 templates (docs/features/specs/
# 2026-10-06-006-e5-e6b2-system-packages-templates-design.md §9).
# Run as root on a server installed with the ArcPanel installer and NGINX in
# front of Apache or as the web server. Uses ports 38600-38609 of the server
# range and 127.0.0.1:386xx listeners.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
WEBTPL=$ARCPANEL/data/templates/web
PORTS_DIR=$ARCPANEL/data/ports
PASS="Tt-$(date +%s)-Pass"
U1=e6bu1
D1=e6b1.example
PORT=38605
LISTEN_DIR=$(mktemp -d)

# Templates of batch 2 and those that take BACKEND_PORT
B2_TEMPLATES="django flask-fastapi"
B2_PORT_TEMPLATES="django flask-fastapi"

source_value() {
    grep "^$1=" "$ARCPANEL/conf/arcpanel.conf" | tail -n1 | cut -d"'" -f2
}
PROXY_SYSTEM=$(source_value PROXY_SYSTEM)
WEB_SYSTEM=$(source_value WEB_SYSTEM)

if [ "$WEB_SYSTEM" = 'nginx' ]; then
    TPL_KIND=web
    change_tpl() { v-change-web-domain-tpl "$1" "$2" "$3"; }
else
    TPL_KIND=proxy
    change_tpl() { v-change-web-domain-proxy-tpl "$1" "$2" "$3"; }
fi

conf_of() {
    echo "/home/$1/conf/web/$2/nginx.conf"
}

ssl_conf_of() {
    echo "/home/$1/conf/web/$2/nginx.ssl.conf"
}

docroot_of() {
    echo "/home/$1/web/$2/public_html"
}

# Local address of the domain (web.conf stores the NAT address when the IP
# has one)
web_ip() {
    local ip
    ip=$(grep "^DOMAIN='$2' " "$ARCPANEL/data/users/$1/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
    if [ -f "$ARCPANEL/data/ips/$ip" ]; then
        echo "$ip"
    else
        basename "$(grep -l "^NAT='$ip'" "$ARCPANEL"/data/ips/* | head -n1)"
    fi
}

fetch_path() {
    curl -s --noproxy '*' --max-time 10 --resolve "$2:80:$(web_ip "$1" "$2")" "http://$2$3"
}

fetch_https() {
    curl -sk --noproxy '*' --max-time 10 --resolve "$2:443:$(web_ip "$1" "$2")" "https://$2$3"
}

status_path() {
    curl -s --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$2:80:$(web_ip "$1" "$2")" "http://$2$3"
}

# Run CMD until it prints EXPECTED (NGINX reloads in the background), at
# most 10 seconds; prints the last output
eventually() {
    local expected="$1" out i
    shift
    for i in $(seq 1 40); do
        out=$("$@")
        if [ "$out" = "$expected" ]; then
            break
        fi
        sleep 0.25
    done
    echo "$out"
}

# Serve LISTEN_DIR/<port>/ on 127.0.0.1:PORT; index.html says which listener
# answered, any other path answers with that path's file
start_listener() {
    mkdir -p "$LISTEN_DIR/$1"
    echo "e6b-listener-$1" > "$LISTEN_DIR/$1/index.html"
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
    pkill -f "python3 -m http.server 386" > /dev/null 2>&1 || true
}

# Switch the test domain to TEMPLATE on the listener's port
use_tpl() {
    change_tpl $U1 $D1 "$1" > /dev/null
    if [[ " $B2_PORT_TEMPLATES " == *" $1 "* ]]; then
        v-change-web-domain-tpl-vars $U1 $D1 BACKEND_PORT=$PORT > /dev/null
    fi
}

cleanup() {
    stop_listeners
    v-delete-user $U1 > /dev/null 2>&1 || true
    rm -f "$PORTS_DIR/$U1.conf"
}

setup_file() {
    cleanup
    v-add-sys-web-templates-meta > /dev/null
    v-add-user $U1 "$PASS" $U1@example.invalid default > /dev/null
    rm -f "$PORTS_DIR/$U1.conf"
    v-add-user-port $U1 38600-38609 > /dev/null
    v-add-web-domain $U1 $D1 > /dev/null
    local ssl_dir
    ssl_dir=$(mktemp -d)
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$D1" \
        -keyout "$ssl_dir/$D1.key" -out "$ssl_dir/$D1.crt" > /dev/null 2>&1
    v-add-web-domain-ssl $U1 $D1 "$ssl_dir" > /dev/null
    rm -rf "$ssl_dir"
    rm -f "$(docroot_of $U1 $D1)/index.html"
    start_listener $PORT
}

teardown_file() {
    cleanup
    rm -rf "$LISTEN_DIR"
}

#----------------------------------------------------------#
#                     Every template                       #
#----------------------------------------------------------#

@test "Batch 2: every template ships on both stacks with its trigger and metadata" {
    local dir name ext
    for dir in "$ARCPANEL/install/deb/templates/web/nginx" "$ARCPANEL/install/deb/templates/web/nginx/php-fpm"; do
        for name in $B2_TEMPLATES; do
            for ext in tpl stpl; do
                assert_file_exist "$dir/$name.$ext"
                run awk '/# arc:app-begin/ { a = 1 } /# arc:app-end/ { a = 0 } /%arc_/ && !a { bad = 1 } END { exit bad }' "$dir/$name.$ext"
                assert_success
            done
        done
        for name in $B2_PORT_TEMPLATES; do
            assert_file_executable "$dir/$name.sh"
        done
    done
    for name in $B2_TEMPLATES; do
        run bash -c "v-list-web-templates-meta $TPL_KIND json | jq -r --arg n $name '.[] | select(.NAME == \$n) | .HAS_META + \" \" + .RUNTIME'"
        assert_output --regexp '^yes (python|go|ruby|php)$'
    done
}

@test "Batch 2: the templates of the other stack pass nginx -t when rendered" {
    local dir name ext out
    if [ "$TPL_KIND" = proxy ]; then
        dir="$ARCPANEL/install/deb/templates/web/nginx/php-fpm"
    else
        dir="$ARCPANEL/install/deb/templates/web/nginx"
    fi
    mkdir -p /tmp/e6brender
    for name in $B2_TEMPLATES; do
        for ext in tpl stpl; do
            out="/etc/nginx/conf.d/domains/zz-e6brender-$name-$ext.conf"
            sed -e "s|%ip%|127.0.0.1|g" -e "s|%web_port%|18080|g" -e "s|%web_ssl_port%|18443|g" \
                -e "s|%proxy_port%|18081|g" -e "s|%proxy_ssl_port%|18444|g" -e "s|%web_system%|nginx|g" \
                -e "s|%domain_idn%|$name-$ext.e6brender.example|g" -e "s|%domain%|$name-$ext.e6brender.example|g" \
                -e "s|%alias_idn%||g" -e "s|%home%|/home|g" -e "s|%user%|$U1|g" \
                -e "s|%docroot%|/tmp/e6brender|g" -e "s|%sdocroot%|/tmp/e6brender|g" \
                -e "s|%backend_lsnr%|127.0.0.1:9000|g" \
                -e "s|%ssl_pem%|$ARCPANEL/ssl/certificate.crt|g" -e "s|%ssl_key%|$ARCPANEL/ssl/certificate.key|g" \
                -e "s|%arc_backend_port%|38609|g" "$dir/$name.$ext" > "$out"
        done
    done
    run nginx -t
    rm -f /etc/nginx/conf.d/domains/zz-e6brender-*.conf
    rm -rf /tmp/e6brender
    assert_success
}

@test "Batch 2: a port template without a port serves the 503 page" {
    local name
    for name in $B2_PORT_TEMPLATES; do
        v-delete-web-domain-tpl-vars $U1 $D1 > /dev/null 2>&1 || true
        run change_tpl $U1 $D1 $name
        assert_success
        run nginx -t
        assert_success
        run eventually "503" status_path $U1 $D1 /
        assert_output "503"
    done
}

@test "Batch 2: every port template proxies to the domain's port over HTTP and HTTPS" {
    local name
    for name in $B2_PORT_TEMPLATES; do
        use_tpl $name
        run grep -c '%arc_' "$(conf_of $U1 $D1)" "$(ssl_conf_of $U1 $D1)"
        refute_output --regexp ":[1-9]"
        run eventually "e6b-listener-$PORT" fetch_path $U1 $D1 /
        assert_output "e6b-listener-$PORT"
        run eventually "e6b-listener-$PORT" fetch_https $U1 $D1 /
        assert_output "e6b-listener-$PORT"
    done
}

#----------------------------------------------------------#
#                       Python                             #
#----------------------------------------------------------#

@test "Batch 2: django serves /static/ and /media/ from the document root" {
    local docroot
    docroot=$(docroot_of $U1 $D1)
    mkdir -p "$docroot/static" "$docroot/media"
    echo "e6b-static" > "$docroot/static/app.css"
    echo "e6b-media" > "$docroot/media/photo.txt"
    chown -R --reference="$docroot" "$docroot/static" "$docroot/media"
    use_tpl django
    run eventually "e6b-static" fetch_path $U1 $D1 /static/app.css
    assert_output "e6b-static"
    run eventually "e6b-media" fetch_path $U1 $D1 /media/photo.txt
    assert_output "e6b-media"
    run grep -c 'client_max_body_size 64m' "$(conf_of $U1 $D1)"
    assert_output "1"
    rm -rf "$docroot/static" "$docroot/media"
}

@test "Batch 2: flask-fastapi passes WebSockets and does not buffer" {
    use_tpl flask-fastapi
    run grep -c 'Upgrade \$http_upgrade' "$(conf_of $U1 $D1)"
    assert_output "1"
    run grep -c 'proxy_buffering    off' "$(ssl_conf_of $U1 $D1)"
    assert_output "1"
    run grep -c 'proxy_hide_header Upgrade' "$(ssl_conf_of $U1 $D1)"
    assert_output "0"
}
