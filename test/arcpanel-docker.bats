#!/usr/bin/env bats
# ArcPanel Docker apps (E4,
# docs/features/specs/2026-10-05-009-e4-docker-apps-design.md).
# Run as root on a server installed with the ArcPanel installer. The first
# test installs the Docker engine (network: Docker's apt repository); the
# apps come from the fixture catalog test/fixtures/docker-catalog (busybox
# image, pulled once from Docker Hub).

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
FIXTURES="$BATS_TEST_DIRNAME/fixtures/docker-catalog"
export ARC_DOCKER_CATALOG="$FIXTURES"

# A copy of the fixture app tiny 1.0.0 in a catalog of its own, with LINES
# added to its web service; prints the catalog folder
bad_compose() {
    local dir
    dir=$(mktemp -d)
    mkdir -p "$dir/tiny/1.0.0"
    cp "$FIXTURES/tiny/icon.svg" "$dir/tiny/"
    cp "$FIXTURES/tiny/1.0.0/manifest.json" "$dir/tiny/1.0.0/"
    {
        cat "$FIXTURES/tiny/1.0.0/compose.yaml"
        printf '%s\n' "$@"
    } > "$dir/tiny/1.0.0/compose.yaml"
    echo "$dir"
}

# Same with the manifest changed by the jq FILTER
bad_manifest() {
    local dir
    dir=$(mktemp -d)
    mkdir -p "$dir/tiny/1.0.0"
    cp "$FIXTURES/tiny/icon.svg" "$dir/tiny/"
    cp "$FIXTURES/tiny/1.0.0/compose.yaml" "$dir/tiny/1.0.0/"
    jq "$1" "$FIXTURES/tiny/1.0.0/manifest.json" > "$dir/tiny/1.0.0/manifest.json"
    echo "$dir"
}

# Run v-check-docker-catalog on DIR and expect a refusal naming REASON
refused() {
    run v-check-docker-catalog "$1"
    rm -rf "$1"
    assert_failure 2
    assert_output --partial "$2"
}

PASS="Tdk-$(date +%s)-Pass"
USERS="tdk1 tdk2 tdkoff"
U1=tdk1
U2=tdk2
DA=a.tdk.example
DB=b.tdk.example
DC=c.tdk.example

source_value() {
    grep "^$1=" "$CONF" | tail -n1 | cut -d"'" -f2
}
if [ "$(source_value WEB_SYSTEM)" = 'nginx' ]; then
    tpl_of() { grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/web.conf" | grep -o " TPL='[^']*'" | cut -d"'" -f2; }
else
    tpl_of() { grep "^DOMAIN='$2' " "$HESTIA/data/users/$1/web.conf" | grep -o " PROXY='[^']*'" | cut -d"'" -f2; }
fi

make_package() {
    local name=$1 docker=$2 apps=$3 tmp
    tmp=$(mktemp)
    sed "s/^SHELL=.*/SHELL='bash'/" "$PKG_DIR/default.pkg" > "$tmp"
    v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy "$name" DOCKER "$docker"
    v-change-package-tenancy "$name" DOCKER_APPS "$apps"
    v-change-package-tenancy "$name" DOCKER_MEMORY 256M
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

# Run CMD until it prints EXPECTED, at most 30 seconds; prints the last output
eventually() {
    local expected="$1" out i
    shift
    for i in $(seq 1 120); do
        out=$("$@")
        if [ "$out" = "$expected" ]; then
            break
        fi
        sleep 0.25
    done
    echo "$out"
}

app_field() {
    grep "^NAME='$2' " "$HESTIA/data/users/$1/dockerapps.conf" | grep -oE "(^| )$3='[^']*'" | cut -d"'" -f2
}

port_app() {
    grep "^PORT='$2' " "$PORTS_DIR/$1.conf" | grep -o "APP='[^']*'" | cut -d"'" -f2
}

app_status() {
    v-list-docker-apps "$1" json | jq -r --arg n "$2" '.APPS[] | select(.NAME == $n) | .STATUS'
}

# Wait for the task of USER's NAME to end (at most 3 minutes); prints its
# status
wait_task() {
    local status i
    for i in $(seq 1 180); do
        status=$(v-list-docker-app-task "$1" "$2" 0 json | jq -r .STATUS)
        if [ "$status" != 'running' ]; then
            break
        fi
        sleep 1
    done
    if [ "$status" != 'done' ]; then
        v-list-docker-app-task "$1" "$2" 0 | tail -n 30 >&2
    fi
    echo "$status"
}

container_of() {
    docker ps -q --filter "label=top.arcpanel.user=$1" --filter "label=top.arcpanel.app=$2" | head -n1
}

cleanup() {
    local user name
    for user in $USERS; do
        for name in $(grep -oE "^NAME='[^']*'" "$HESTIA/data/users/$user/dockerapps.conf" 2> /dev/null | cut -d"'" -f2); do
            v-delete-docker-app "$user" "$name" > /dev/null 2>&1 || true
        done
        v-delete-user "$user" > /dev/null 2>&1 || true
    done
    for package in tdk-dev tdk-off; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
}

setup_file() {
    cleanup
    make_package tdk-dev yes 2
    make_package tdk-off no 2
    for user in $U1 $U2; do
        v-add-user "$user" "$PASS" "$user@example.invalid" tdk-dev > /dev/null
    done
    v-add-user tdkoff "$PASS" tdkoff@example.invalid tdk-off > /dev/null
    v-add-web-domain $U1 $DA > /dev/null
    v-add-web-domain $U1 $DB > /dev/null
    v-add-web-domain $U2 $DC > /dev/null
    v-add-web-domain tdkoff off.tdk.example > /dev/null
}

teardown_file() {
    cleanup
}

#----------------------------------------------------------#
#                    M1: engine, catalog                   #
#----------------------------------------------------------#

@test "Engine: v-add-sys-docker installs and configures the engine" {
    run v-add-sys-docker
    assert_success
    grep -q "^DOCKER_SYSTEM='yes'" "$CONF"
    jq -e '."userland-proxy" == false and ."live-restore" == true and ."log-opts"."max-size" == "10m"' /etc/docker/daemon.json
    run v-list-sys-docker json
    assert_success
    echo "$output" | jq -e '.SYSTEM == "yes" and .RUNNING == "yes" and (.VERSION | length > 0)'
}

@test "Engine: v-add-sys-docker is idempotent and keeps daemon.json" {
    cp /etc/docker/daemon.json /tmp/daemon.json.bak
    run v-add-sys-docker
    assert_success
    cmp /etc/docker/daemon.json /tmp/daemon.json.bak
    rm -f /tmp/daemon.json.bak
}

@test "Engine: no hosting user is in the docker group" {
    run bash -c "getent group docker | cut -d: -f4"
    assert_output ''
    run stat -c '%U:%G %a' /run/docker.sock
    assert_output 'root:docker 660'
}

@test "Engine: invalid data roots are refused" {
    run v-add-sys-docker /home/alice
    assert_failure 10
    run v-add-sys-docker 'relative/path'
    assert_failure 2
}

@test "Catalog: the shipped catalog passes v-check-docker-catalog" {
    run env -u ARC_DOCKER_CATALOG v-check-docker-catalog
    assert_success
    assert_output --partial "uptime-kuma 2.5.5: ok"
    assert_output --partial "redis 8.8.3: ok"
    [ "$(echo "$output" | grep -c ': ok$')" -eq 6 ]
}

@test "Catalog: the fixture catalog passes" {
    run v-check-docker-catalog "$FIXTURES" json
    assert_success
    echo "$output" | jq -e 'length == 4 and all(.STATUS == "ok")'
}

@test "Catalog: v-list-docker-catalog lists the latest versions with icons" {
    run env -u ARC_DOCKER_CATALOG v-list-docker-catalog all json
    assert_success
    echo "$output" | jq -e 'length == 6 and all(.ICON | startswith("data:image/svg+xml;base64,"))'
    echo "$output" | jq -e '[.[] | select(.SLUG == "redis")][0].WEB == false'
    run v-list-docker-catalog all json
    echo "$output" | jq -e '[.[] | select(.SLUG == "tiny")][0] | .VERSION == "3.0.0" and .VERSIONS == ["1.0.0", "2.0.0", "3.0.0"]'
    run v-list-docker-catalog monitoring json
    echo "$output" | jq -e 'length == 0'
    run v-list-docker-catalog nonsense
    assert_failure 2
}

@test "Catalog: v-list-docker-catalog-app renders safe defaults" {
    run v-list-docker-catalog-app tiny 1.0.0 json
    assert_success
    echo "$output" | jq -e '.version == "1.0.0" and (.variables[] | select(.name == "SECRET") | .secret == true and .default == "")'
    run v-list-docker-catalog-app tiny 9.9.9
    assert_failure 3
    run v-list-docker-catalog-app 'no/such'
    assert_failure 3
}

@test "Checks: privileged is refused" {
    refused "$(bad_compose '    privileged: true')" "privileged is not allowed"
}

@test "Checks: added capabilities are refused" {
    refused "$(bad_compose '    cap_add: [NET_ADMIN]')" "cap_add is not allowed"
}

@test "Checks: devices are refused" {
    refused "$(bad_compose '    devices: ["/dev/kmsg:/dev/kmsg"]')" "devices is not allowed"
}

@test "Checks: the host network and namespaces are refused" {
    refused "$(bad_compose '    network_mode: host')" "network_mode is not allowed"
    refused "$(bad_compose '    pid: host')" "pid is not allowed"
}

@test "Checks: the Docker socket and host paths are refused" {
    refused "$(bad_compose '      - /var/run/docker.sock:/var/run/docker.sock')" "is outside"
    refused "$(bad_compose '      - /etc:/host-etc')" "bind mount /etc is outside"
}

@test "Checks: named volumes are refused" {
    refused "$(bad_compose '      - appdata:/data' 'volumes:' '  appdata: {}')" "named volume appdata is not allowed"
}

@test "Checks: builds and env files are refused" {
    refused "$(bad_compose '    build: .')" "build is not allowed"
    refused "$(bad_compose '    env_file: /etc/hostname')" "env_file is not allowed"
}

@test "Checks: ports of the Compose file and extra security options are refused" {
    refused "$(bad_compose '    ports: ["8080:8080"]')" "is not an app port"
    refused "$(bad_compose '    security_opt: ["seccomp=unconfined"]')" "security_opt must be no-new-privileges only"
}

@test "Checks: images that are not in the manifest are refused" {
    dir=$(bad_compose)
    sed -i 's|image: busybox:1.37.0@sha256:[a-f0-9]*|image: busybox:1.37.0|' "$dir/tiny/1.0.0/compose.yaml"
    refused "$dir" "image busybox:1.37.0 is not in the manifest"
}

@test "Checks: external networks, configs and extra hosts are refused" {
    refused "$(bad_compose '    networks: [outside]' 'networks:' '  outside:' '    external: true')" "network outside must be a bridge"
    refused "$(bad_compose '    configs: [passwd]' 'configs:' '  passwd:' '    file: /etc/passwd')" "config passwd is not allowed"
    refused "$(bad_compose '    extra_hosts: ["evil:1.2.3.4"]')" "extra host"
}

@test "Checks: invalid manifests are refused" {
    refused "$(bad_manifest '.images = ["busybox:1.37.0"]')" "must be pinned by digest"
    refused "$(bad_manifest '.slug = "other"')" "slug must be tiny"
    refused "$(bad_manifest 'del(.ports.web)')" "an http health check needs a web port"
    refused "$(bad_manifest '.variables[0].name = "ARC_UID"')" "invalid variable name"
    refused "$(bad_manifest '.variables[0].type = "file"')" "invalid type"
    refused "$(bad_manifest '.ports.web.service = "nope"')" "service nope is not in compose.yaml"
}

@test "Checks: the override runs every service as the user without capabilities" {
    dir=$(mktemp -d)
    DOCKER_RENDER_UID=4321 DOCKER_RENDER_GID=4321 bash -c '
        source /etc/hestiacp/hestia.conf; source $HESTIA/func/main.sh; source $HESTIA/func/tenancy.sh
        source $HESTIA/func/ports.sh; source_conf $HESTIA/conf/hestia.conf; source $HESTIA/func/docker.sh
        m=$(docker_manifest tiny 1.0.0)
        dockerapp_env_render "$m" someone tiny app.example.com /dev/null /dev/null "$1/.env" install "web:30100:no extra:30101:yes"
        docker_render "$1" ap-4321-tiny someone tiny tiny 1.0.0 0.25 64 "web:30100:no extra:30101:yes"
        docker_compose_in "$1" ap-4321-tiny config --format json' render "$dir" > "$dir/config.json"
    jq -e '.services.web | .user == "4321:4321" and .cap_drop == ["ALL"] and .security_opt == ["no-new-privileges:true"]
        and .cgroup_parent == "user-4321.slice" and .restart == "unless-stopped"' "$dir/config.json"
    jq -e '[.services.web.ports[] | "\(.host_ip):\(.published):\(.target)"] | sort == ["0.0.0.0:30101:8081", "127.0.0.1:30100:8080"]' "$dir/config.json"
    grep -q "^ARC_PORT_WEB='30100'" "$dir/.env"
    grep -q "^ARC_DATA_WWW='/home/someone/apps/tiny/data/www'" "$dir/.env"
    grep -qE "^SECRET='[A-Za-z0-9]{16}'" "$dir/.env"
    rm -rf "$dir"
}

#----------------------------------------------------------#
#                 M2: install and lifecycle                #
#----------------------------------------------------------#

@test "Package: the Docker keys are package keys" {
    run v-list-package-tenancy tdk-dev json
    assert_success
    echo "$output" | jq -e '.["tdk-dev"] | .DOCKER == "yes" and .DOCKER_APPS == "2" and .DOCKER_MEMORY == "256M" and .DOCKER_CPUS == "unlimited"'
    run v-change-package-tenancy tdk-dev DOCKER_APPS many
    assert_failure 2
    run v-change-package-tenancy tdk-dev DOCKER_CPUS 0.5x
    assert_failure 2
    run v-change-package-tenancy tdk-dev DOCKER maybe
    assert_failure 2
    grep -q "^DOCKER='yes'" "$PKG_DIR/developer.tenancy"
}

@test "Install: refusals before anything changes" {
    run v-add-docker-app tdkoff tiny tiny off.tdk.example
    assert_failure 11
    run v-add-docker-app $U1 nothing nothing $DA
    assert_failure 3
    run v-add-docker-app $U1 tiny 'Bad_Name' $DA
    assert_failure 2
    run v-add-docker-app $U1 tiny tiny
    assert_failure 1
    run v-add-docker-app $U1 worker worker $DA
    assert_failure 2
    run v-add-docker-app $U1 tiny tiny $DC
    assert_failure 3
    printf '%s\n' "GREETING=bad'quote" > /tmp/tdk-vars
    run v-add-docker-app $U1 tiny tiny $DA /tmp/tdk-vars
    assert_failure 2
    echo "NOPE=1" > /tmp/tdk-vars
    run v-add-docker-app $U1 tiny tiny $DA /tmp/tdk-vars
    assert_failure 2
    assert_output --partial "unknown variable NOPE"
    rm -f /tmp/tdk-vars
    [ ! -s "$HESTIA/data/users/$U1/dockerapps.conf" ]
    [ ! -d "/home/$U1/apps/tiny" ]
}

@test "Install: tiny on a domain answers through NGINX" {
    prev=$(tpl_of $U1 $DA)
    echo "GREETING=hi-there" > /tmp/tdk-vars
    run v-add-docker-app $U1 tiny tiny $DA /tmp/tdk-vars 1.0.0
    rm -f /tmp/tdk-vars
    assert_success
    [[ "${lines[-1]}" =~ ^[0-9]+$ ]]
    assert_equal "$(wait_task $U1 tiny)" "done"
    assert_equal "$(app_field $U1 tiny STATUS)" "running"
    assert_equal "$(app_field $U1 tiny PREV_TPL)" "$prev"
    assert_equal "$(tpl_of $U1 $DA)" "docker-proxy"
    port=$(app_field $U1 tiny WEB_PORT)
    assert_equal "$(port_app $U1 "$port")" "docker:tiny"
    extra=$(app_field $U1 tiny EXTRA_PORTS)
    assert_equal "$(port_app $U1 "${extra#extra:}")" "docker:tiny.extra"
    grep -q "^BACKEND_PORT='$port'" "/home/$U1/conf/web/$DA/nginx.conf.tplvars"
    assert_equal "$(eventually "hi-there v1" fetch $U1 $DA)" "hi-there v1"
    run v-list-docker-app-task $U1 tiny 0 json
    echo "$output" | jq -e '.STATUS == "done" and (.LOG | contains("The app is running"))'
}

@test "Install: containers run as the user without capabilities in the user's slice" {
    cid=$(container_of $U1 tiny)
    [ -n "$cid" ]
    assert_equal "$(docker inspect -f '{{.Config.User}}' "$cid")" "$(id -u $U1):$(id -g $U1)"
    assert_equal "$(docker inspect -f '{{.HostConfig.CgroupParent}}' "$cid")" "user-$(id -u $U1).slice"
    pid=$(docker inspect -f '{{.State.Pid}}' "$cid")
    grep -qE "^CapEff:\s*0000000000000000" "/proc/$pid/status"
    grep -q "user-$(id -u $U1).slice" "/proc/$pid/cgroup"
    assert_equal "$(docker inspect -f '{{range .HostConfig.SecurityOpt}}{{.}}{{end}}' "$cid")" "no-new-privileges:true"
    assert_equal "$(stat -c %U "/home/$U1/apps/tiny/data/www/index.html")" "$U1"
    # ports on the loopback only, except the public ones
    docker port "$cid" 8080 | grep -q "^127.0.0.1:"
}

@test "Install: copies for backups without secrets in the record" {
    assert_file_exists "/home/$U1/apps/tiny/app.conf"
    assert_equal "$(stat -c '%U %a' "/home/$U1/apps/tiny/.env")" "$U1 600"
    grep -q "^GREETING='hi-there'" "/home/$U1/apps/tiny/.env"
    run grep -c '^ARC_' "/home/$U1/apps/tiny/.env"
    assert_output "0"
    run grep -c 'SECRET' "$HESTIA/data/users/$U1/dockerapps.conf"
    assert_output "0"
    assert_equal "$(stat -c '%U %a' "$HESTIA/data/users/$U1/docker/tiny/.env")" "root 600"
}

@test "Install: names, domains and the package limit" {
    run v-add-docker-app $U1 tiny tiny $DB
    assert_failure 4
    run v-add-docker-app $U1 tiny other $DA
    assert_failure 4
    assert_output --partial "has the Docker app tiny"
    run v-add-docker-app $U1 worker worker
    assert_success
    assert_equal "$(wait_task $U1 worker)" "done"
    assert_equal "$(app_field $U1 worker STATUS)" "running"
    run v-add-docker-app $U1 worker worker2
    assert_failure 8
    assert_output --partial "DOCKER_APPS"
}

@test "List: apps with their state" {
    run v-list-docker-apps $U1 json
    assert_success
    echo "$output" | jq -e '.COUNT == "2" and .LIMIT == "2"'
    echo "$output" | jq -e '[.APPS[] | select(.NAME == "tiny")][0] | .STATUS == "running" and .UPDATE == "yes" and .LATEST == "3.0.0" and .URL == "http://a.tdk.example"'
    echo "$output" | jq -e '[.APPS[] | select(.NAME == "worker")][0] | .STATUS == "running" and .DOMAIN == ""'
    run v-list-docker-app $U1 tiny json
    assert_success
    echo "$output" | jq -e '.SERVICES[0].SERVICE == "web" and .SERVICES[0].STATE == "running"'
    echo "$output" | jq -e '[.PORTS[].NAME] == ["web", "extra"] and .PORTS[0].CONTAINER == "8080" and .PORTS[0].PUBLIC == "no"'
    echo "$output" | jq -e '.POST_INSTALL == "Open http://a.tdk.example." and .CATALOG.name == "Tiny"'
    run v-list-docker-app $U2 tiny
    assert_failure 3
}

@test "Env: secrets are never listed; changes restart the app" {
    run v-list-docker-app-env $U1 tiny json
    assert_success
    echo "$output" | jq -e '[.VARIABLES[] | select(.NAME == "SECRET")][0] | .VALUE == "" and .SET == "yes" and .SECRET == "yes"'
    echo "$output" | jq -e '[.VARIABLES[] | select(.NAME == "GREETING")][0].VALUE == "hi-there"'
    secret=$(grep "^SECRET=" "$HESTIA/data/users/$U1/docker/tiny/.env")
    printf 'GREETING=changed\nSECRET=\n' > /tmp/tdk-vars
    run v-change-docker-app-env $U1 tiny /tmp/tdk-vars
    assert_success
    assert_equal "$(grep "^SECRET=" "$HESTIA/data/users/$U1/docker/tiny/.env")" "$secret"
    assert_equal "$(eventually "changed v1" fetch $U1 $DA)" "changed v1"
    echo "SEED=other" > /tmp/tdk-vars
    run v-change-docker-app-env $U1 tiny /tmp/tdk-vars
    assert_failure 2
    assert_output --partial "cannot be changed"
    printf '%s\n' 'GREETING=a$b' > /tmp/tdk-vars
    run v-change-docker-app-env $U1 tiny /tmp/tdk-vars
    assert_failure 2
    echo "PATH=/x" > /tmp/tdk-vars
    run v-change-docker-app-env $U1 tiny /tmp/tdk-vars
    assert_failure 2
    rm -f /tmp/tdk-vars
}

@test "Limits: within the package" {
    run v-change-docker-app-limits $U1 tiny 0.5 128
    assert_success
    cid=$(container_of $U1 tiny)
    assert_equal "$(docker inspect -f '{{.HostConfig.Memory}}' "$cid")" "$((128 * 1024 * 1024))"
    assert_equal "$(docker inspect -f '{{.HostConfig.NanoCpus}}' "$cid")" "500000000"
    run v-change-docker-app-limits $U1 tiny 0.5 512
    assert_failure 8
    run v-change-docker-app-limits $U1 tiny 0.5 16
    assert_failure 2
}

@test "Logs: last lines and a cursor" {
    fetch $U1 $DA /probe > /dev/null
    run v-list-docker-app-logs $U1 tiny all '' 50 json
    assert_success
    echo "$output" | jq -e '(.LINES | type == "array") and (.NEXT | type == "string")'
    next=$(echo "$output" | jq -r .NEXT)
    if [ -n "$next" ]; then
        run v-list-docker-app-logs $U1 tiny web "$next" 50 json
        assert_success
        echo "$output" | jq -e '.LINES | length == 0'
    fi
    run v-list-docker-app-logs $U1 tiny nope
    assert_failure 3
    run v-list-docker-app-logs $U1 tiny all 'yesterday'
    assert_failure 2
}

@test "Lifecycle: stop, start and restart" {
    run v-stop-docker-app $U1 tiny
    assert_success
    assert_equal "$(app_status $U1 tiny)" "stopped"
    assert_equal "$(app_field $U1 tiny STATE)" "stopped"
    run v-start-docker-app $U1 tiny
    assert_success
    assert_equal "$(app_field $U1 tiny STATE)" "running"
    assert_equal "$(eventually "changed v1" fetch $U1 $DA)" "changed v1"
    run v-restart-docker-app $U1 tiny
    assert_success
    assert_equal "$(eventually "running" app_status $U1 tiny)" "running"
}

@test "Lifecycle: a symlinked data folder is refused" {
    mv "/home/$U1/apps/tiny/data/www" "/home/$U1/apps/tiny/data/www.real"
    ln -s /etc "/home/$U1/apps/tiny/data/www"
    run v-restart-docker-app $U1 tiny
    assert_failure 10
    rm "/home/$U1/apps/tiny/data/www"
    mv "/home/$U1/apps/tiny/data/www.real" "/home/$U1/apps/tiny/data/www"
    run v-start-docker-app $U1 tiny
    assert_success
}

@test "Domain: move an app to another domain" {
    prev=$(tpl_of $U1 $DB)
    run v-change-docker-app-domain $U1 tiny $DB
    assert_success
    assert_equal "$(tpl_of $U1 $DB)" "docker-proxy"
    [ "$(tpl_of $U1 $DA)" != "docker-proxy" ]
    assert_equal "$(app_field $U1 tiny DOMAIN)" "$DB"
    assert_equal "$(app_field $U1 tiny PREV_TPL)" "$prev"
    grep -q "^ARC_URL='http://$DB'" "$HESTIA/data/users/$U1/docker/tiny/.env"
    assert_equal "$(eventually "changed v1" fetch $U1 $DB)" "changed v1"
}

@test "Delete: keep the data, free the ports and restore the template" {
    prev=$(app_field $U1 tiny PREV_TPL)
    port=$(app_field $U1 tiny WEB_PORT)
    echo keep > "/home/$U1/apps/tiny/data/www/keep.txt"
    run v-delete-docker-app $U1 tiny keep-data
    assert_success
    assert_equal "$(tpl_of $U1 $DB)" "$prev"
    [ -z "$(port_app $U1 "$port")" ]
    [ -z "$(container_of $U1 tiny)" ]
    [ ! -d "/home/$U1/apps/tiny" ]
    [ ! -d "$HESTIA/data/users/$U1/docker/tiny" ]
    ls /home/$U1/apps/.removed/tiny-*/www/keep.txt
    run v-delete-docker-app $U1 worker
    assert_success
    [ ! -d "/home/$U1/apps/worker" ]
    [ ! -s "$HESTIA/data/users/$U1/dockerapps.conf" ]
}

#----------------------------------------------------------#
#         M3: updates, backups and convergence             #
#----------------------------------------------------------#

@test "Cron: the check and the dumps 15 minutes before the backup" {
    grep -q "^\*/5 \* \* \* \* sudo /usr/local/hestia/bin/v-check-sys-docker repair" /var/spool/cron/crontabs/hestiaweb
    backup=$(grep -m1 -E "^[0-9]+ [0-9]+ .*v-backup-users" /var/spool/cron/crontabs/hestiaweb | awk '{ print $1 * 1 + $2 * 60 }')
    dumps=$(grep -m1 "v-backup-docker-apps all" /var/spool/cron/crontabs/hestiaweb | awk '{ print $1 * 1 + $2 * 60 }')
    assert_equal "$(((backup - dumps + 1440) % 1440))" "15"
}

@test "Update: to a newer version with a snapshot of the data" {
    run v-add-docker-app $U1 tiny upd $DA '' 1.0.0
    assert_success
    assert_equal "$(wait_task $U1 upd)" "done"
    echo keep > "/home/$U1/apps/upd/data/www/keep.txt"
    chown $U1:$U1 "/home/$U1/apps/upd/data/www/keep.txt"
    run v-start-docker-app-task $U1 upd update 1.0.0
    assert_failure 2
    run v-start-docker-app-task $U1 upd rollback
    assert_failure 3
    run v-start-docker-app-task $U1 upd update 2.0.0
    assert_success
    assert_equal "$(wait_task $U1 upd)" "done"
    assert_equal "$(app_field $U1 upd VERSION)" "2.0.0"
    assert_equal "$(app_field $U1 upd PREV_VERSION)" "1.0.0"
    assert_equal "$(eventually "hello v2" fetch $U1 $DA)" "hello v2"
    assert_file_exists "/home/$U1/apps/upd/.snapshots/1.0.0.tar.zst"
    assert_equal "$(stat -c %U "/home/$U1/apps/upd/.snapshots/1.0.0.tar.zst")" "$U1"
    run v-list-docker-app $U1 upd json
    echo "$output" | jq -e '.ROLLBACK == "yes" and .UPDATE == "yes"'
}

@test "Update: rollback to the previous version and its data" {
    echo after > "/home/$U1/apps/upd/data/www/after.txt"
    run v-start-docker-app-task $U1 upd rollback
    assert_success
    assert_equal "$(wait_task $U1 upd)" "done"
    assert_equal "$(app_field $U1 upd VERSION)" "1.0.0"
    assert_equal "$(app_field $U1 upd PREV_VERSION)" ""
    assert_equal "$(eventually "hello v1" fetch $U1 $DA)" "hello v1"
    assert_file_exists "/home/$U1/apps/upd/data/www/keep.txt"
    assert_file_not_exists "/home/$U1/apps/upd/data/www/after.txt"
    assert_file_not_exists "/home/$U1/apps/upd/.snapshots/1.0.0.tar.zst"
}

@test "Update: a failing version rolls back with the data intact" {
    run v-start-docker-app-task $U1 upd update 3.0.0
    assert_success
    assert_equal "$(wait_task $U1 upd)" "failed"
    run v-list-docker-app-task $U1 upd 0 json
    echo "$output" | jq -e '.LOG | contains("rolling back to 1.0.0") and contains("1.0.0 is running again")'
    assert_equal "$(app_field $U1 upd VERSION)" "1.0.0"
    assert_equal "$(app_field $U1 upd STATUS)" "running"
    assert_file_exists "/home/$U1/apps/upd/data/www/keep.txt"
    assert_equal "$(eventually "hello v1" fetch $U1 $DA)" "hello v1"
}

@test "Dumps: snapshot now and the nightly run" {
    run v-start-docker-app-task $U1 upd snapshot
    assert_success
    assert_equal "$(wait_task $U1 upd)" "done"
    assert_equal "$(cat "/home/$U1/apps/upd/data/.dumps/index.txt")" "hello v1"
    assert_equal "$(stat -c '%U %a' "/home/$U1/apps/upd/data/.dumps/index.txt")" "$U1 600"
    assert_file_exists "/home/$U1/apps/upd/data/.dumps/.last"
    rm -f "/home/$U1/apps/upd/data/.dumps/index.txt"
    run v-backup-docker-apps all
    assert_success
    assert_file_exists "/home/$U1/apps/upd/data/.dumps/index.txt"
    run v-list-docker-app $U1 upd json
    echo "$output" | jq -e '.LAST_DUMP | length > 0'
}

@test "Suspend: an app and the whole user" {
    run v-suspend-docker-app $U1 upd
    assert_success
    assert_equal "$(app_status $U1 upd)" "suspended"
    [ -z "$(container_of $U1 upd)" ]
    run v-start-docker-app $U1 upd
    assert_failure 5
    run v-unsuspend-docker-app $U1 upd
    assert_success
    assert_equal "$(eventually "hello v1" fetch $U1 $DA)" "hello v1"
    run v-suspend-user $U1
    assert_success
    assert_equal "$(app_field $U1 upd SUSPENDED)" "user"
    [ -z "$(container_of $U1 upd)" ]
    run v-unsuspend-user $U1
    assert_success
    assert_equal "$(app_field $U1 upd SUSPENDED)" "no"
    assert_equal "$(eventually "running" app_status $U1 upd)" "running"
}

@test "Check: stopped apps, orphan containers and public ports are repaired" {
    docker stop "$(container_of $U1 upd)" > /dev/null
    docker run -d --label top.arcpanel.user=nobody-tdk --label top.arcpanel.app=ghost \
        busybox:1.37.0@sha256:bdf57e528e45e4433820e045b29b4597825a1c9e38353532d90a01445013f82e sleep 600 > /dev/null
    run v-check-sys-docker check json
    assert_success
    echo "$output" | jq -e --arg u "$U1" '[.FINDINGS[] | select(.USER == $u and .APP == "upd" and .TYPE == "stopped")] | length == 1'
    echo "$output" | jq -e '[.FINDINGS[] | select(.TYPE == "orphan" and .APP == "ghost")] | length == 1'
    run v-check-sys-docker repair json
    assert_success
    assert_equal "$(eventually "hello v1" fetch $U1 $DA)" "hello v1"
    [ -z "$(docker ps -aq --filter label=top.arcpanel.app=ghost)" ]
    jq -e '.MODE == "repair"' "$HESTIA/data/docker/report.json"
    extra=$(app_field $U1 upd EXTRA_PORTS)
    port=${extra#extra:}
    v-change-user-port-public-ok $U1 "$port" yes > /dev/null
    v-change-user-port-public $U1 "$port" yes > /dev/null
    run v-check-sys-docker repair json
    echo "$output" | jq -e '[.FINDINGS[] | select(.TYPE == "public-mismatch")] | length == 1'
    docker port "$(container_of $U1 upd)" 8081 | grep -q "^0.0.0.0:$port"
    v-change-user-port-public $U1 "$port" no > /dev/null
    run v-check-sys-docker repair
    docker port "$(container_of $U1 upd)" 8081 | grep -q "^127.0.0.1:$port"
}

@test "Check: the app of a deleted web domain is removed" {
    run v-add-docker-app $U2 worker wk
    assert_success
    assert_equal "$(wait_task $U2 wk)" "done"
    v-add-web-domain $U2 gone.tdk.example > /dev/null
    run v-add-docker-app $U2 tiny gone gone.tdk.example '' 1.0.0
    assert_success
    assert_equal "$(wait_task $U2 gone)" "done"
    v-delete-web-domain $U2 gone.tdk.example > /dev/null
    run v-check-sys-docker repair json
    echo "$output" | jq -e --arg u "$U2" '[.FINDINGS[] | select(.USER == $u and .APP == "gone" and .TYPE == "domain-missing" and .REPAIRED == "yes")] | length == 1'
    [ -z "$(app_field $U2 gone NAME)" ]
    [ -z "$(container_of $U2 gone)" ]
    run v-delete-docker-app $U2 wk
    assert_success
}

@test "Persistence: backup, delete and restore bring the app back on its port" {
    run v-add-docker-app $U2 tiny keep $DC '' 1.0.0
    assert_success
    assert_equal "$(wait_task $U2 keep)" "done"
    echo restored > "/home/$U2/apps/keep/data/www/marker.txt"
    chown $U2:$U2 "/home/$U2/apps/keep/data/www/marker.txt"
    port=$(app_field $U2 keep WEB_PORT)
    run v-backup-user $U2
    assert_success
    backup=$(ls -t /backup/$U2.*.tar | head -n1)
    run v-delete-user $U2
    assert_success
    [ -z "$(container_of $U2 keep)" ]
    [ -z "$(port_app $U2 "$port")" ]
    run v-restore-user $U2 "$(basename "$backup")"
    assert_success
    run v-check-sys-docker check json
    echo "$output" | jq -e --arg u "$U2" '[.FINDINGS[] | select(.USER == $u and .TYPE == "import")] | length == 1'
    run v-check-sys-docker repair
    assert_success
    assert_equal "$(app_field $U2 keep WEB_PORT)" "$port"
    assert_equal "$(port_app $U2 "$port")" "docker:keep"
    assert_equal "$(eventually "hello v1" fetch $U2 $DC)" "hello v1"
    assert_file_exists "/home/$U2/apps/keep/data/www/marker.txt"
    assert_equal "$(app_field $U2 keep STATUS)" "running"
    rm -f /backup/$U2.*.tar
}

@test "Persistence: a forged app.conf copy is not imported" {
    mkdir -p "/home/$U2/apps/forged"
    printf "%s\n" "NAME='forged' SLUG='../../etc' VERSION='1.0.0' DOMAIN='' WEB_PORT='' EXTRA_PORTS='' STATE='running'" \
        > "/home/$U2/apps/forged/app.conf"
    chown -R $U2:$U2 "/home/$U2/apps/forged"
    run v-rebuild-docker-apps $U2
    assert_success
    assert_output --partial "forged not imported"
    [ -z "$(app_field $U2 forged NAME)" ]
    rm -rf "/home/$U2/apps/forged"
}

@test "Delete: the user's apps go with the user" {
    run v-delete-user $U2
    assert_success
    [ -z "$(docker ps -aq --filter label=top.arcpanel.user=$U2)" ]
    [ ! -d "$HESTIA/data/users/$U2" ]
    v-add-user $U2 "$PASS" "$U2@example.invalid" tdk-dev > /dev/null
}
