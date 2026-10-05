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
