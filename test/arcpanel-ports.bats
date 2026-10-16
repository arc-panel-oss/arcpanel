#!/usr/bin/env bats
# ArcPanel port registry (E12,
# docs/features/specs/2026-10-05-004-e12-port-registry-design.md).
# Run as root on a server installed with the ArcPanel installer, with the
# iptables firewall on. Uses ports 38000-38399 of the server range.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
PKG_DIR=$ARCPANEL/data/packages
PORTS_DIR=$ARCPANEL/data/ports
RULES=$ARCPANEL/data/firewall/rules.conf
PASS="Tp-$(date +%s)-Pass"
USERS="tpu1 tpu2 tpu3 tpu4 tpu5"

make_package() {
    local name=$1 ports=$2 tmp
    tmp=$(mktemp)
    cp "$PKG_DIR/default.pkg" "$tmp"
    v-add-user-package "$tmp" "$name" yes > /dev/null 2>&1 || v-add-user-package "$tmp" "$name" > /dev/null
    rm -f "$tmp"
    v-change-package-tenancy "$name" PORTS "$ports"
}

port_line() {
    grep "^PORT='$2' " "$PORTS_DIR/$1.conf"
}

kill_listeners() {
    pkill -f "nc -l 127.0.0.1 3800" > /dev/null 2>&1 || true
    pkill -f "nc -l 0.0.0.0 383" > /dev/null 2>&1 || true
}

cleanup() {
    kill_listeners
    for user in tph1 tpc1 tpc2 tpc3 tpres tpres3 $USERS; do
        v-delete-user "$user" > /dev/null 2>&1 || true
        rm -f "$PORTS_DIR/$user.conf"
    done
    for package in tp-zero tp-five tp-unl tp-twenty tp-res tp-cust tp-bigp; do
        v-delete-user-package "$package" > /dev/null 2>&1 || true
        rm -f "$PKG_DIR/$package.tenancy"
    done
    rm -f "$PORTS_DIR/tpghost.conf"
    for rule in $(grep -E "COMMENT='(arcpanel-port-38[0-9]{3}-|tp-manual)" "$RULES" 2> /dev/null | cut -d"'" -f2); do
        v-delete-firewall-rule "$rule" > /dev/null 2>&1 || true
    done
    v-delete-sys-port-reserved 38100-38104 > /dev/null 2>&1 || true
}

setup_file() {
    cleanup
    make_package tp-zero 0
    make_package tp-five 5
    make_package tp-unl unlimited
    make_package tp-twenty 20
    make_package tp-res 20
    make_package tp-cust 3
    make_package tp-bigp 50
    v-change-package-tenancy tp-res USERS 5
    v-change-package-tenancy tp-res RESELLER_PACKAGES 'tp-cust,tp-bigp'
    v-add-user tpu1 "$PASS" tpu1@example.invalid tp-zero > /dev/null
    v-add-user tpu2 "$PASS" tpu2@example.invalid tp-zero > /dev/null
    v-add-user tpu3 "$PASS" tpu3@example.invalid tp-five > /dev/null
    v-add-user tpu4 "$PASS" tpu4@example.invalid tp-unl > /dev/null
    v-add-user tpu5 "$PASS" tpu5@example.invalid tp-twenty > /dev/null
    v-add-user tpres "$PASS" tpres@example.invalid tp-res > /dev/null
    v-change-user-role tpres reseller
    # Earlier runs may have given the test users pools through the hooks
    for user in tpu1 tpu2 tpu3 tpu4 tpu5 tpres; do
        rm -f "$PORTS_DIR/$user.conf"
    done
}

teardown_file() {
    cleanup
}

@test "Registry setup is idempotent and lists its settings" {
    run v-add-sys-port-registry
    assert_success
    run v-add-sys-port-registry
    assert_success
    run grep -c "^PORT_RANGE_START=" "$ARCPANEL/conf/arcpanel.conf"
    assert_output "1"
    run grep "^PORT='8083' .*APP='system'" "$PORTS_DIR/.reserved.conf"
    assert_success
    run v-list-sys-config json
    assert_output --partial '"PORT_REGISTRY": "yes"'
    run grep -c "v-check-sys-ports notify" /var/spool/cron/crontabs/arcpanelweb
    assert_output "1"
}

@test "Package PORTS is validated and listed" {
    run v-change-package-tenancy tp-five PORTS lots
    assert_failure 2
    run v-list-package-tenancy tp-five json
    assert_output --partial '"PORTS": "5"'
}

@test "Admin allocates a range; taken, reserved and outside ports are refused" {
    run v-add-user-port tpu1 38000-38009 no 'test block'
    assert_success
    run port_line tpu1 38000-38009
    assert_success
    run v-add-user-port tpu2 38005
    assert_failure 4
    run v-add-sys-port-reserved 38100-38104 'test reservation'
    assert_success
    run v-add-user-port tpu2 38102
    assert_failure 10
    run v-add-user-port tpu2 29999
    assert_failure 10
    run v-add-user-port tpu2 38020-38010
    assert_failure 2
}

@test "Allocation splits a block and release merges it back" {
    run v-allocate-user-port tpu1 manual
    assert_success
    assert_output "38000"
    run v-allocate-user-port tpu1 manual tcp 38005 'game server'
    assert_success
    assert_output "38005"
    run port_line tpu1 38005
    assert_output --partial "APP='manual'"
    assert_output --partial "COMMENT='game server'"
    run port_line tpu1 38006-38009
    assert_success
    run v-allocate-user-port tpu1 manual tcp 38005
    assert_failure 7
    run v-release-user-port tpu1 38000
    assert_success
    run v-release-user-port tpu1 38005
    assert_success
    run grep -c "^PORT=" "$PORTS_DIR/tpu1.conf"
    assert_output "1"
    run v-release-user-port tpu1 38000
    assert_failure 3
}

@test "A limited user with an empty pool is refused" {
    run v-allocate-user-port tpu2 manual
    assert_failure 8
}

@test "Package PORTS tops the pool up on first use; unlimited takes any port" {
    run v-allocate-user-port tpu3 manual
    assert_success
    run v-list-user-ports tpu3 json
    assert_output --partial '"ALLOCATED": "5"'
    assert_output --partial '"ASSIGNED": "1"'
    run v-allocate-user-port tpu4 manual
    assert_success
    run v-list-user-ports tpu4 json
    assert_output --partial '"ALLOCATED": "1"'
}

@test "Public needs an assigned, public-capable port and writes one tagged rule" {
    run v-change-user-port-public tpu1 38001 yes
    assert_failure 3
    v-allocate-user-port tpu1 manual tcp 38001
    run v-change-user-port-public tpu1 38001 yes
    assert_failure 10
    run v-change-user-port-public-ok tpu1 38000-38009 yes
    assert_success
    run v-change-user-port-public tpu1 38001 yes
    assert_success
    run grep -c "COMMENT='arcpanel-port-38001-tpu1'" "$RULES"
    assert_output "1"
    rule=$(grep "COMMENT='arcpanel-port-38001-tpu1'" "$RULES" | cut -d"'" -f2)
    run port_line tpu1 38001
    assert_output --partial "PUBLIC='yes'"
    run v-list-user-ports tpu1 json
    assert_output --partial '"PORT": "38001", "START": "38001", "END": "38001", "SIZE": "1", "PROTO": "tcp", "APP": "manual", "PUBLIC": "yes", "PUBLIC_OK": "yes", "FW_RULE": "'"$rule"'"'
    run v-change-user-port-public tpu1 38001 yes
    assert_success
    run grep -c "COMMENT='arcpanel-port-38001-tpu1'" "$RULES"
    assert_output "1"
    run v-change-user-port-public tpu1 38001 no
    assert_success
    run grep -c "COMMENT='arcpanel-port-38001-tpu1'" "$RULES"
    assert_output "0"
}

@test "The tag keeps the link when firewall rule ids move" {
    v-add-firewall-rule ACCEPT 0.0.0.0/0 38399 TCP tp-manual-shift
    v-change-user-port-public tpu1 38001 yes
    before=$(grep -c "^RULE=" "$RULES")
    # Deleting an earlier rule renumbers every later rule
    v-delete-firewall-rule "$(grep "COMMENT='tp-manual-shift'" "$RULES" | cut -d"'" -f2)"
    run v-change-user-port-public tpu1 38001 no
    assert_success
    run grep -c "^RULE=" "$RULES"
    assert_output "$((before - 2))"
    run grep -c "COMMENT='arcpanel-port-38001-tpu1'" "$RULES"
    assert_output "0"
}

@test "Forbidding public ports closes the open ones" {
    v-change-user-port-public tpu1 38001 yes
    run v-change-user-port-public-ok tpu1 38000-38009 no
    assert_success
    run grep -c "COMMENT='arcpanel-port-38001-tpu1'" "$RULES"
    assert_output "0"
    run port_line tpu1 38001
    assert_output --partial "PUBLIC='no'"
    assert_output --partial "PUBLIC_OK='no'"
    v-change-user-port-public-ok tpu1 38000-38009 yes
}

@test "Port check sees the owner's listener and a foreign one" {
    v-allocate-user-port tpu1 manual tcp 38002
    runuser -u tpu1 -- nc -l 127.0.0.1 38002 > /dev/null 2>&1 &
    nc -l 127.0.0.1 38004 > /dev/null 2>&1 &
    sleep 1
    run v-check-user-port tpu1 38002
    assert_success
    run v-check-user-port tpu1 38004
    assert_failure 7
    run v-check-user-port tpu2 38002
    assert_failure 3
    run v-list-user-ports tpu1 json
    assert_output --partial '"PORT": "38002", "START": "38002", "END": "38002", "SIZE": "1", "PROTO": "tcp", "APP": "manual", "PUBLIC": "no", "PUBLIC_OK": "yes", "FW_RULE": "", "COMMENT": "", "SUSPENDED": "no", "LISTEN": "local"'
    kill_listeners
}

@test "Twenty parallel allocations give twenty distinct ports" {
    v-add-user-port-pool tpu5
    out=$(mktemp)
    for i in $(seq 1 20); do
        v-allocate-user-port tpu5 manual >> "$out" 2>&1 &
    done
    wait
    run sort -u "$out"
    assert_equal "$(sort -u "$out" | grep -c '^[0-9]*$')" "20"
    run v-allocate-user-port tpu5 manual
    assert_failure 8
    rm -f "$out"
}

@test "Reseller pool, customer pools, give and reclaim stay inside PORTS" {
    run v-add-user-port-pool tpres
    assert_success
    run v-list-user-ports tpres json
    assert_output --partial '"ALLOCATED": "20"'
    # v-add-reseller-user gives the customer its pool from the reseller's
    v-add-reseller-user tpres tpc1 "$PASS" tpc1@example.invalid tp-cust > /dev/null
    run v-add-user-port-pool tpc1
    assert_success
    run v-list-user-ports tpc1 json
    assert_output --partial '"ALLOCATED": "3"'
    run v-list-user-ports tpres json
    assert_output --partial '"ALLOCATED": "17"'
    assert_output --partial '"TREE_TOTAL": "20"'
    run v-change-user-port-owner tpres +5 tpc1
    assert_success
    run v-list-user-ports tpc1 json
    assert_output --partial '"ALLOCATED": "8"'
    run v-change-user-port-owner tpres +50 tpc1
    assert_failure 8
    run v-add-user-port tpres 38200
    assert_failure 8
    assert_output --partial "PORTS"
    run v-check-reseller-quota tpres tp-bigp
    assert_failure 8
    assert_output "PORTS"
}

@test "Reclaim and delete return a customer's ports to the reseller" {
    first=$(grep -m1 "^PORT=" "$PORTS_DIR/tpc1.conf" | cut -d"'" -f2)
    start=${first%-*}
    run v-change-user-port-owner tpc1 "$start" tpres
    assert_success
    run v-list-user-ports tpres json
    assert_output --partial '"ALLOCATED": "13"'
    v-allocate-user-port tpc1 manual > /dev/null
    range=$(awk -F"'" '/^PORT=/ { print $2 }' "$PORTS_DIR/tpc1.conf" | head -n1)
    run v-delete-user-port tpc1 "$range"
    assert_failure 7
    all_start=$(awk -F"'" '/^PORT=/ { print $2 }' "$PORTS_DIR/tpc1.conf" | head -n1 | cut -d- -f1)
    all_end=$(awk -F"'" '/^PORT=/ { print $2 }' "$PORTS_DIR/tpc1.conf" | tail -n1 | sed 's/.*-//')
    run v-delete-user-port tpc1 "$all_start-$all_end" force
    assert_success
    run v-list-user-ports tpres json
    assert_output --partial '"ALLOCATED": "20"'
    run v-list-user-ports tpc1 json
    assert_output --partial '"ALLOCATED": "0"'
}

@test "Range change refuses to leave allocations outside" {
    run v-change-sys-port-range 30000 37999
    assert_failure 7
    run v-change-sys-port-range 30000 39999
    assert_success
}

@test "Reconciliation finds and repairs registry and firewall drift" {
    v-allocate-user-port tpu1 manual tcp 38003 > /dev/null
    v-change-user-port-public tpu1 38003 yes
    rule=$(grep "COMMENT='arcpanel-port-38003-tpu1'" "$RULES" | cut -d"'" -f2)
    v-delete-firewall-rule "$rule"
    v-add-firewall-rule ACCEPT 0.0.0.0/0 38301 TCP arcpanel-port-38301-tpnobody
    v-add-firewall-rule ACCEPT 0.0.0.0/0 38302 TCP tp-manual
    echo "PORT='38390' PROTO='tcp' APP='' PUBLIC='no' PUBLIC_OK='no' FW_RULE='' COMMENT='' SUSPENDED='no' TIME='' DATE=''" > "$PORTS_DIR/tpghost.conf"
    nc -l 0.0.0.0 38300 > /dev/null 2>&1 &
    sleep 1

    run v-list-sys-ports json orphans
    assert_success
    assert_output --partial '"TYPE": "missing-rule", "USER": "tpu1", "PORT": "38003"'
    assert_output --partial '"TYPE": "stale-rule"'
    assert_output --partial '"TYPE": "unmanaged-rule", "USER": "", "PORT": "38302"'
    assert_output --partial '"TYPE": "orphan-file", "USER": "tpghost"'
    assert_output --partial '"TYPE": "orphan-socket", "USER": "root", "PORT": "38300"'

    run v-check-sys-ports repair
    assert_success
    run grep -c "COMMENT='arcpanel-port-38003-tpu1'" "$RULES"
    assert_output "1"
    run grep -c "COMMENT='arcpanel-port-38301-tpnobody'" "$RULES"
    assert_output "0"
    assert_file_not_exist "$PORTS_DIR/tpghost.conf"
    run cat "$PORTS_DIR/.report.json"
    assert_output --partial '"FINDINGS"'
    refute_output --partial 'missing-rule'

    # Adopting the unmanaged rule links it to an assigned port
    manual=$(grep "COMMENT='tp-manual'" "$RULES" | cut -d"'" -f2)
    v-allocate-user-port tpu1 manual tcp 38006 > /dev/null
    run v-change-user-port-public tpu1 38006 yes "$manual"
    assert_failure 3
    v-add-user-port tpu1 38302 yes
    v-allocate-user-port tpu1 manual tcp 38302 > /dev/null
    run v-change-user-port-public tpu1 38302 yes "$manual"
    assert_success
    run v-list-sys-ports json orphans
    refute_output --partial '"PORT": "38302"'
    # A reused rule id is not mistaken for the port's rule
    v-delete-firewall-rule "$manual"
    v-add-firewall-rule ACCEPT 0.0.0.0/0 38303 TCP tp-manual-other
    run v-list-sys-ports json orphans
    assert_output --partial '"TYPE": "missing-rule", "USER": "tpu1", "PORT": "38302"'
    v-change-user-port-public tpu1 38302 no
    run grep -c "COMMENT='tp-manual-other'" "$RULES"
    assert_output "1"
    kill_listeners
}

@test "Lists render in every format" {
    for format in json plain csv shell; do
        run v-list-user-ports tpu1 "$format"
        assert_success
        run v-list-sys-ports "$format"
        assert_success
    done
    run v-list-sys-ports json
    assert_output --partial '"RANGE_START": "30000"'
    assert_output --partial '"tpu1": {"OWNER": "'
    run bash -c "v-list-sys-ports json | python3 -m json.tool > /dev/null"
    assert_success
    run bash -c "v-list-user-ports tpres json | python3 -m json.tool > /dev/null"
    assert_success
}

@test "Hook: a new user gets its package pool, a customer from its reseller" {
    run v-add-user tph1 "$PASS" tph1@example.invalid tp-five
    assert_success
    run v-list-user-ports tph1 json
    assert_output --partial '"ALLOCATED": "5"'
    run v-add-reseller-user tpres tpc2 "$PASS" tpc2@example.invalid tp-cust
    assert_success
    run v-list-user-ports tpc2 json
    assert_output --partial '"ALLOCATED": "3"'
    run v-list-user-ports tpres json
    assert_output --partial '"ALLOCATED": "17"'
    assert_output --partial '"TREE_TOTAL": "20"'
}

@test "Hook: a package change tops the pool up and never shrinks it" {
    run v-change-user-package tph1 tp-twenty
    assert_success
    run v-list-user-ports tph1 json
    assert_output --partial '"ALLOCATED": "20"'
    run v-change-user-package tph1 tp-five
    assert_success
    run v-list-user-ports tph1 json
    assert_output --partial '"ALLOCATED": "20"'
}

@test "Hook: suspend closes public ports, unsuspend reopens them" {
    range=$(awk -F"'" '/^PORT=/ { print $2; exit }' "$PORTS_DIR/tph1.conf")
    v-change-user-port-public-ok tph1 "$range" yes
    port=$(v-allocate-user-port tph1 manual)
    v-change-user-port-public tph1 "$port" yes
    run grep -c "COMMENT='arcpanel-port-$port-tph1'" "$RULES"
    assert_output "1"
    run v-suspend-user tph1
    assert_success
    run grep -c "COMMENT='arcpanel-port-$port-tph1'" "$RULES"
    assert_output "0"
    run port_line tph1 "$port"
    assert_output --partial "PUBLIC='yes'"
    assert_output --partial "SUSPENDED='yes'"
    run v-list-sys-ports json orphans
    refute_output --partial '"USER": "tph1"'
    run v-change-user-port-public tph1 "$port" yes
    assert_failure 10
    run v-unsuspend-user tph1
    assert_success
    run grep -c "COMMENT='arcpanel-port-$port-tph1'" "$RULES"
    assert_output "1"
    run port_line tph1 "$port"
    assert_output --partial "SUSPENDED='no'"
}

@test "Hook: deleting a customer closes its rules and returns its ports" {
    range=$(awk -F"'" '/^PORT=/ { print $2; exit }' "$PORTS_DIR/tpc2.conf")
    v-change-user-port-public-ok tpc2 "$range" yes
    port=$(v-allocate-user-port tpc2 manual)
    v-change-user-port-public tpc2 "$port" yes
    run v-delete-user tpc2
    assert_success
    run grep -c "COMMENT='arcpanel-port-$port-tpc2'" "$RULES"
    assert_output "0"
    assert_file_not_exist "$PORTS_DIR/tpc2.conf"
    run bash -c "ls $PORTS_DIR/.deleted/ | grep -c '^tpc2-'"
    refute_output "0"
    run v-list-user-ports tpres json
    assert_output --partial '"ALLOCATED": "20"'
}

@test "Hook: a failing hook is logged and does not fail the command" {
    hook=$ARCPANEL/func/hooks.d/post_suspend_user/99-tp-fail.sh
    echo 'exit 3' > "$hook"
    run v-suspend-user tph1
    rm -f "$hook"
    assert_success
    run grep "hook post_suspend_user/99-tp-fail.sh failed with code 3 (tph1)" "$ARCPANEL/log/system.log"
    assert_success
    v-unsuspend-user tph1
}

@test "A reseller without a pool is topped up when it gives ports" {
    v-add-user tpres3 "$PASS" tpres3@example.invalid tp-res > /dev/null
    v-change-user-role tpres3 reseller
    # A user turned into a reseller has no pool yet
    v-delete-user-ports tpres3
    run v-add-reseller-user tpres3 tpc3 "$PASS" tpc3@example.invalid tp-cust
    assert_success
    run v-list-user-ports tpc3 json
    assert_output --partial '"ALLOCATED": "3"'
    run v-change-user-port-owner tpres3 +5 tpc3
    assert_success
    run v-list-user-ports tpc3 json
    assert_output --partial '"ALLOCATED": "8"'
    run v-list-user-ports tpres3 json
    assert_output --partial '"TREE_TOTAL": "8"'
    run v-change-user-port-owner tpres3 +13 tpc3
    assert_failure 8
    assert_output --partial "PORTS"
    v-delete-user tpc3 > /dev/null
    v-delete-user tpres3 > /dev/null
}

@test "ports_fw_find matches the tag literally, so a dot in a user name is a dot" {
    rules=$(mktemp)
    printf "RULE='7' ACTION='ACCEPT' PROTOCOL='TCP' PORT='38390' IP='0.0.0.0/0' COMMENT='arcpanel-port-38390-axb' SUSPENDED='no' TIME='10:00:00' DATE='2026-10-16'\n" > "$rules"
    printf "RULE='8' ACTION='ACCEPT' PROTOCOL='TCP' PORT='38391' IP='0.0.0.0/0' COMMENT='arcpanel-port-38391-a.b' SUSPENDED='no' TIME='10:00:00' DATE='2026-10-16'\n" >> "$rules"
    run bash -c "source /etc/arcpanel/arcpanel.conf; source $ARCPANEL/func/main.sh; source $ARCPANEL/func/ports.sh
        ports_fw_rules_file() { echo '$rules'; }
        echo \"[\$(ports_fw_find arcpanel-port-38390-a.b)] [\$(ports_fw_find arcpanel-port-38391-a.b)]\""
    rm -f "$rules"
    assert_success
    assert_output '[] [8]'
}
