#!/usr/bin/env bats
# ArcPanel Docker apps (E4): every app of the shipped catalog installs with
# its default variables, passes its health check and runs as the hosting
# user without capabilities. Slow: pulls the real images from Docker Hub
# and GHCR. Run as root after test/arcpanel-docker.bats (engine installed).

if [ "${PATH#*/usr/local/hestia/bin*}" = "$PATH" ]; then
    . /etc/profile.d/hestia.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

HESTIA=/usr/local/hestia
PKG_DIR=$HESTIA/data/packages
PASS="Tdc-$(date +%s)-Pass"
U=tdcat
APPS="uptime-kuma n8n gitea vaultwarden miniflux redis"

web_ip() {
    local ip
    ip=$(grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/web.conf" | grep -o " IP='[^']*'" | cut -d"'" -f2)
    if [ -f "$HESTIA/data/ips/$ip" ]; then
        echo "$ip"
    else
        basename "$(grep -l "^NAT='$ip'" "$HESTIA"/data/ips/* | head -n1)"
    fi
}

# HTTP status of https://DOMAIN/PATH through NGINX (self-signed certificate)
status_of() {
    curl -sk --noproxy '*' --max-time 15 -o /dev/null -w '%{http_code}' \
        --resolve "$1:443:$(web_ip $U "$1")" "https://$1${2:-/}"
}

wait_task() {
    local status i
    for i in $(seq 1 900); do
        status=$(v-list-docker-app-task "$1" "$2" 0 json | jq -r .STATUS)
        if [ "$status" != 'running' ]; then
            break
        fi
        sleep 1
    done
    echo "$status"
}

cleanup() {
    local name
    for name in $(grep -oE "^NAME='[^']*'" "$HESTIA/data/users/$U/dockerapps.conf" 2> /dev/null | cut -d"'" -f2); do
        v-delete-docker-app "$U" "$name" > /dev/null 2>&1 || true
    done
    v-delete-user "$U" > /dev/null 2>&1 || true
    v-delete-user-package tdc-dev > /dev/null 2>&1 || true
    rm -f "$PKG_DIR/tdc-dev.tenancy"
}

setup_file() {
    [ "$(grep "^DOCKER_SYSTEM=" "$HESTIA/conf/hestia.conf" | cut -d"'" -f2)" = 'yes' ] || skip "run test/arcpanel-docker.bats first"
    cleanup
    local tmp dir slug
    tmp=$(mktemp)
    sed "s/^SHELL=.*/SHELL='bash'/" "$PKG_DIR/default.pkg" > "$tmp"
    v-add-user-package "$tmp" tdc-dev yes > /dev/null 2>&1 || v-add-user-package "$tmp" tdc-dev > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy tdc-dev DOCKER yes
    v-change-package-tenancy tdc-dev DOCKER_APPS 10
    v-change-package-tenancy tdc-dev PORTS 20
    v-add-user "$U" "$PASS" "$U@example.invalid" tdc-dev > /dev/null
    dir=$(mktemp -d)
    for slug in $APPS; do
        [ "$slug" = 'redis' ] && continue
        v-add-web-domain "$U" "$slug.tdc.example" > /dev/null
        openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$slug.tdc.example" \
            -keyout "$dir/$slug.tdc.example.key" -out "$dir/$slug.tdc.example.crt" > /dev/null 2>&1
        v-add-web-domain-ssl "$U" "$slug.tdc.example" "$dir" > /dev/null
    done
    rm -rf "$dir"
}

teardown_file() {
    cleanup
}

# Install SLUG, wait for its task and check the containers
install_app() {
    local slug=$1 domain=$2 vars=$3 cid pid
    run v-add-docker-app $U "$slug" "$slug" "$domain" $vars
    assert_success
    run wait_task $U "$slug"
    if [ "$output" != 'done' ]; then
        v-list-docker-app-task $U "$slug" 0 | tail -n 60
    fi
    assert_output 'done'
    for cid in $(docker ps -q --filter "label=top.arcpanel.user=$U" --filter "label=top.arcpanel.app=$slug"); do
        assert_equal "$(docker inspect -f '{{.Config.User}}' "$cid")" "$(id -u $U):$(id -g $U)"
        pid=$(docker inspect -f '{{.State.Pid}}' "$cid")
        grep -qE "^CapEff:\s*0000000000000000" "/proc/$pid/status"
    done
}

@test "Uptime Kuma answers over HTTPS" {
    install_app uptime-kuma uptime-kuma.tdc.example
    run status_of uptime-kuma.tdc.example
    assert_output --regexp '^(200|302)$'
}

@test "n8n answers over HTTPS" {
    install_app n8n n8n.tdc.example
    run status_of n8n.tdc.example /healthz
    assert_output '200'
}

@test "Gitea answers over HTTPS" {
    install_app gitea gitea.tdc.example
    run status_of gitea.tdc.example /api/healthz
    assert_output '200'
}

@test "Vaultwarden answers over HTTPS" {
    install_app vaultwarden vaultwarden.tdc.example
    run status_of vaultwarden.tdc.example /alive
    assert_output '200'
}

@test "Miniflux answers over HTTPS and its administrator logs in" {
    echo "ADMIN_PASSWORD=Tdc-admin-pass-1" > /tmp/tdc-vars
    install_app miniflux miniflux.tdc.example /tmp/tdc-vars
    rm -f /tmp/tdc-vars
    run status_of miniflux.tdc.example /healthcheck
    assert_output '200'
    run curl -sk --noproxy '*' --max-time 15 -u admin:Tdc-admin-pass-1 \
        --resolve "miniflux.tdc.example:443:$(web_ip $U miniflux.tdc.example)" https://miniflux.tdc.example/v1/me
    assert_output --partial '"username":"admin"'
}

@test "Redis answers on its private port with the password" {
    install_app redis ''
    port=$(grep "^NAME='redis' " "$HESTIA/data/users/$U/dockerapps.conf" | grep -oE "EXTRA_PORTS='[^']*'" | cut -d"'" -f2)
    port=${port#redis:}
    password=$(grep "^PASSWORD=" "$HESTIA/data/users/$U/docker/redis/.env" | cut -d"'" -f2)
    run bash -c "printf 'AUTH %s\r\nPING\r\n' '$password' | timeout 5 nc 127.0.0.1 $port"
    assert_output --partial 'PONG'
    run bash -c "printf 'PING\r\n' | timeout 5 nc 127.0.0.1 $port"
    assert_output --partial 'NOAUTH'
}
