#!/usr/bin/env bats
# ArcPanel secret store: references in Node.js and Docker apps (E38 M2,
# docs/features/specs/2026-10-09-001-e38-secret-store-design.md §6).
# Run as root on a server installed with the ArcPanel installer, after
# test/arcpanel-node.bats has built its local Node.js mirror and with the
# Docker engine of test/arcpanel-docker.bats; the parts whose prerequisite
# is missing are skipped. The values are synthetic.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
CONF=$ARCPANEL/conf/arcpanel.conf
MIRROR=/root/arcpanel-node-mirror
FIXTURES="$BATS_TEST_DIRNAME/fixtures/docker-catalog"
export ARC_DOCKER_CATALOG="$FIXTURES"
PASS="Tsa-$(date +%s)-Pass"
U1=tsa1
DA=a.tsa.example
DB=b.tsa.example
WORK=/root/arcpanel-secret-store-apps-test

latest_of() {
    jq -r --arg m "$1" '[.[] | select(.version | startswith("v" + $m + ".")) | .version][0] | ltrimstr("v")' "$MIRROR/index.json"
}

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

value_file() {
    local file
    file=$(mktemp "$WORK/value.XXXXXX")
    printf '%s' "$1" > "$file"
    echo "$file"
}

env_file() {
    local file
    file=$(mktemp "$WORK/env.XXXXXX")
    printf '%s\n' "$@" > "$file"
    echo "$file"
}

node_ready() {
    [ -s "$MIRROR/index.json" ] || skip "run test/arcpanel-node.bats first (Node.js mirror)"
}

docker_ready() {
    command -v docker > /dev/null 2>&1 && docker info > /dev/null 2>&1 || skip "the Docker engine is not installed (test/arcpanel-docker.bats)"
}

wait_task() {
    local status i
    for i in $(seq 1 180); do
        status=$(v-list-docker-app-task "$1" "$2" 0 json | jq -r .STATUS)
        if [ "$status" != 'running' ]; then
            break
        fi
        sleep 1
    done
    echo "$status"
}

container_env() {
    local id
    id=$(docker ps -q --filter "label=top.arcpanel.user=$1" --filter "label=top.arcpanel.app=$2" | head -n1)
    docker exec "$id" printenv "$3"
}

cleanup() {
    if id "$U1" > /dev/null 2>&1; then
        for name in $(grep -oE "^NAME='[^']*'" "$ARCPANEL/data/users/$U1/dockerapps.conf" 2> /dev/null | cut -d"'" -f2); do
            v-delete-docker-app "$U1" "$name" > /dev/null 2>&1 || true
        done
        loginctl disable-linger "$U1" > /dev/null 2>&1 || true
        systemctl stop "user@$(id -u "$U1").service" > /dev/null 2>&1 || true
        pkill -9 -u "$U1" > /dev/null 2>&1 || true
    fi
    v-delete-user "$U1" > /dev/null 2>&1 || true
    rm -f "/etc/logrotate.d/arcpanel-node-$U1"
    v-delete-user-package tsa-dev > /dev/null 2>&1 || true
    rm -f "$PKG_DIR/tsa-dev.tenancy"
}

setup_file() {
    cleanup
    rm -rf "$WORK"
    mkdir -p "$WORK"
    chmod 700 "$WORK"
    v-add-sys-secret-key > /dev/null
    tmp=$(mktemp)
    cp "$PKG_DIR/default.pkg" "$tmp"
    v-add-user-package "$tmp" tsa-dev > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy tsa-dev NODE yes
    v-change-package-tenancy tsa-dev NODE_VERSIONS 3
    v-change-package-tenancy tsa-dev NODE_ALLOWED '*'
    v-change-package-tenancy tsa-dev NODE_APPS 2
    v-change-package-tenancy tsa-dev DOCKER yes
    v-change-package-tenancy tsa-dev DOCKER_APPS 2
    v-change-package-tenancy tsa-dev DOCKER_MEMORY 256M
    v-change-package-tenancy tsa-dev PORTS 10
    v-add-user $U1 "$PASS" $U1@example.invalid tsa-dev > /dev/null
    v-add-web-domain $U1 $DA > /dev/null
    v-add-web-domain $U1 $DB > /dev/null
    cat > "/home/$U1/web/$DA/public_html/server.js" << 'EOF'
const http = require('http');
http.createServer((req, res) => {
    res.end('tsa ' + (process.env.GREETING || '-') + ' ' + (process.env.DSN || '-'));
}).listen(process.env.PORT, process.env.HOST);
EOF
    chown "$U1:$U1" "/home/$U1/web/$DA/public_html/server.js"
    if [ -s "$MIRROR/index.json" ]; then
        v-change-sys-config-value NODE_RELEASES_URL "file://$MIRROR"
        v-change-sys-config-value NODE_SCHEDULE_URL "file://$MIRROR/schedule.json"
        v-change-sys-config-value NODE_NVM_URL "file://$MIRROR/nvm"
        v-update-sys-node-releases > /dev/null
        v-add-user-nvm $U1 > /dev/null
        v-add-user-node-version $U1 "$(latest_of 24)" > /dev/null
    fi
    v-add-user-secret $U1 /app/greeting "$(value_file 'tsa-greeting-one')" 'Greeting'
    v-add-user-secret $U1 /app/db "$(value_file 'tsa-db-pass')"
}

teardown_file() {
    cleanup
    sed -i "/^NODE_RELEASES_URL=/d; /^NODE_SCHEDULE_URL=/d; /^NODE_NVM_URL=/d" "$CONF"
    rm -rf "$WORK"
}

#----------------------------------------------------------#
#                       Node.js                            #
#----------------------------------------------------------#

@test "Node: the environment refuses a reference to a missing secret" {
    node_ready
    run v-add-node-app $U1 $DA
    assert_success
    run v-change-node-app-env $U1 $DA "$(env_file 'GREETING={{secret:/app/missing}}')"
    assert_failure 3
    assert_output --partial '/app/missing'
}

@test "Node: an app receives the referenced value and its files keep the reference" {
    node_ready
    run v-change-node-app-env $U1 $DA "$(env_file 'GREETING={{secret:/app/greeting}}' 'DSN=mysql://app:{{secret:/app/db}}@127.0.0.1/app')"
    assert_success
    run eventually "tsa tsa-greeting-one mysql://app:tsa-db-pass@127.0.0.1/app" fetch $U1 $DA
    assert_output "tsa tsa-greeting-one mysql://app:tsa-db-pass@127.0.0.1/app"
    grep -qF '{{secret:/app/greeting}}' "/home/$U1/web/$DA/nodeapp/.env"
    run v-list-node-app-env $U1 $DA json
    assert_output --partial '{{secret:/app/greeting}}'
    refute_output --partial 'tsa-greeting-one'
    run bash -c "grep -rlF tsa-greeting-one /home/$U1 2> /dev/null | grep -v '/.pm2/dump.pm2$'"
    assert_output ''
    run bash -c "ls -A /run/user/$(id -u $U1)/arcpanel 2> /dev/null"
    assert_output ''
}

@test "Node: the secret lists the app that uses it" {
    node_ready
    run bash -c "v-list-user-secret $U1 /app/greeting json | jq -r '.[\"/app/greeting\"].USED_BY | join(\",\")'"
    assert_output "node:$DA"
    run bash -c "v-list-user-secret-store $U1 json | jq -r '.[\"/app/greeting\"].USED_BY'"
    assert_output '1'
}

@test "Node: a change without restart lists the app and keeps the old value" {
    node_ready
    run v-change-user-secret $U1 /app/greeting "$(value_file 'tsa-greeting-two')"
    assert_success
    assert_output --partial "node:$DA"
    sleep 1
    run fetch $U1 $DA
    assert_output --partial 'tsa-greeting-one'
}

@test "Node: a change with restart reaches the running app" {
    node_ready
    run v-change-user-secret $U1 /app/greeting "$(value_file 'tsa-greeting-three')" yes
    assert_success
    run eventually "tsa tsa-greeting-three mysql://app:tsa-db-pass@127.0.0.1/app" fetch $U1 $DA
    assert_output "tsa tsa-greeting-three mysql://app:tsa-db-pass@127.0.0.1/app"
}

@test "Node: a rollback with restart brings the old value back" {
    node_ready
    run v-rollback-user-secret $U1 /app/greeting 1 yes
    assert_success
    run eventually "tsa tsa-greeting-one mysql://app:tsa-db-pass@127.0.0.1/app" fetch $U1 $DA
    assert_output "tsa tsa-greeting-one mysql://app:tsa-db-pass@127.0.0.1/app"
}

@test "Node: a multi-line secret cannot go into an environment value" {
    node_ready
    file=$(mktemp "$WORK/value.XXXXXX")
    printf 'one\ntwo' > "$file"
    v-add-user-secret $U1 /app/pem "$file"
    run v-change-node-app-env $U1 $DA "$(env_file 'GREETING={{secret:/app/pem}}')"
    assert_failure 2
}

@test "Node: deleting a used secret answers E_INUSE unless forced" {
    node_ready
    v-add-user-secret $U1 /app/temp "$(value_file 'tsa-temp-value')"
    v-change-node-app-env $U1 $DA "$(env_file 'GREETING={{secret:/app/greeting}}' 'DSN={{secret:/app/temp}}')"
    run v-delete-user-secret $U1 /app/temp
    assert_failure 7
    assert_output --partial "node:$DA"
    run v-delete-user-secret $U1 /app/temp yes
    assert_success
    run v-restart-node-app $U1 $DA
    assert_failure 3
    assert_output --partial '/app/temp'
    v-change-node-app-env $U1 $DA "$(env_file 'GREETING={{secret:/app/greeting}}')"
}

@test "Node: the pm2 dump is not in the user backup" {
    node_ready
    v-backup-user $U1 > /dev/null
    archive=$(ls -t /backup/$U1.*.tar | head -n1)
    mkdir -p "$WORK/bak"
    tar -xf "$archive" -C "$WORK/bak" ./user_dir
    run bash -c "for f in $WORK/bak/user_dir/.pm2.tar.*; do tar -tf \"\$f\" 2> /dev/null || zstd -dc \"\$f\" | tar -t; done | grep -c 'dump.pm2'"
    assert_output '0'
    rm -f "$archive"
}

#----------------------------------------------------------#
#                        Docker                            #
#----------------------------------------------------------#

@test "Docker: an app receives the referenced value and its listed environment keeps the reference" {
    docker_ready
    vars=$(env_file 'GREETING=hi' 'SECRET={{secret:/app/db}}')
    run v-add-docker-app $U1 tiny tiny $DB "$vars" 1.0.0
    assert_success
    assert_equal "$(wait_task $U1 tiny)" "done"
    assert_equal "$(container_env $U1 tiny SECRET)" 'tsa-db-pass'
    run v-list-docker-app-env $U1 tiny json
    assert_output --partial '{{secret:/app/db}}'
    refute_output --partial 'tsa-db-pass'
    rdir=$ARCPANEL/data/users/$U1/docker/tiny
    grep -qF '{{secret:/app/db}}' "$rdir/.env"
    grep -qF '{{secret:/app/db}}' "/home/$U1/apps/tiny/.env"
    run grep -rlF tsa-db-pass "/home/$U1"
    assert_output ''
    # the resolved values exist only while compose starts the containers
    assert_file_not_exists "$rdir/.env.run"
    run bash -c "v-list-user-secret $U1 /app/db json | jq -r '.[\"/app/db\"].USED_BY | join(\",\")'"
    assert_output 'docker:tiny'
}

@test "Docker: a change with restart recreates the containers with the new value" {
    docker_ready
    run v-change-user-secret $U1 /app/db "$(value_file 'tsa-db-pass-2')" yes
    assert_success
    assert_output --partial 'docker:tiny'
    assert_equal "$(container_env $U1 tiny SECRET)" 'tsa-db-pass-2'
}

@test "Docker: a value with a quote cannot go into a Docker app" {
    docker_ready
    run v-change-user-secret $U1 /app/db "$(value_file "tsa'quoted-pass")" yes
    assert_failure 2
    assert_equal "$(container_env $U1 tiny SECRET)" 'tsa-db-pass-2'
    v-change-user-secret $U1 /app/db "$(value_file 'tsa-db-pass-2')"
}

@test "Docker: a referenced value must pass the checks of the variable" {
    docker_ready
    # SECRET is a password of at least 8 characters
    v-add-user-secret $U1 /app/short "$(value_file 'abc')"
    run v-change-docker-app-env $U1 tiny "$(env_file 'SECRET={{secret:/app/short}}')"
    assert_failure 2
    # a password that mixes text and a reference stays hidden in the listing
    run v-change-docker-app-env $U1 tiny "$(env_file 'SECRET=prefix-{{secret:/app/db}}')"
    assert_success
    run bash -c "v-list-docker-app-env $U1 tiny json | jq -r '.VARIABLES[] | select(.NAME == \"SECRET\") | .VALUE'"
    assert_output ''
    v-change-docker-app-env $U1 tiny "$(env_file 'SECRET={{secret:/app/db}}')"
    v-delete-user-secret $U1 /app/short
}

@test "Docker: a changed secret must still pass the checks of the variables that use it (E39 W5)" {
    docker_ready
    # tiny's SECRET, a password of at least 8 characters, references /app/db
    run v-change-user-secret $U1 /app/db "$(value_file 'abc')"
    assert_failure 2
    assert_output --partial 'SECRET'
    refute_output --partial 'abc'
    assert_equal "$(container_env $U1 tiny SECRET)" 'tsa-db-pass-2'
}

@test "Docker: a re-added secret must suit the apps that still reference it" {
    docker_ready
    v-add-user-secret $U1 /app/gone "$(value_file 'tsa-gone-value')"
    v-change-docker-app-env $U1 tiny "$(env_file 'SECRET={{secret:/app/gone}}')"
    v-delete-user-secret $U1 /app/gone yes
    run v-add-user-secret $U1 /app/gone "$(value_file "tsa'quoted")"
    assert_failure 2
    run v-add-user-secret $U1 /app/gone "$(value_file 'tsa-gone-value-2')"
    assert_success
    v-change-docker-app-env $U1 tiny "$(env_file 'SECRET={{secret:/app/db}}')"
}

@test "Docker: an environment change refuses a missing secret" {
    docker_ready
    run v-change-docker-app-env $U1 tiny "$(env_file 'SECRET={{secret:/app/none}}')"
    assert_failure 3
    assert_equal "$(container_env $U1 tiny SECRET)" 'tsa-db-pass-2'
}
