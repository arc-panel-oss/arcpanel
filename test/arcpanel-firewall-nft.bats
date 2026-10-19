#!/usr/bin/env bats
# E40: the firewall on nftables, with IPv6
# docs/features/specs/2026-10-19-002-e40-nftables-firewall-design.md §12.
# Run as root on a test server with the firewall and Fail2Ban on. A probe
# network namespace, joined to the host by a veth pair with an IPv4 and an
# IPv6 address on each side, reaches listeners on the host, so every check
# is made from "outside" over both families. Uses ports 38950-38954.
# Some cases suspend the SSH and panel rules for a moment, mask or enable
# nftables.service, and turn the firewall off and on again.

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
    . /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

ARCPANEL=/usr/local/arcpanel
FW=$ARCPANEL/data/firewall
NS=arcfwprobe
HOST_IF=arcfw0
NS_IF=arcfw1
HOST4=10.254.77.1
NS4=10.254.77.2
HOST6=fd00:77::1
NS6=fd00:77::2
P1=38950
P2=38951
P3=38952
P4=38953
TAG=arcbats-fw

# HTTP status from the probe namespace, 000 when nothing answers
probe() {
    local host="$1" port="$2"
    [[ "$host" == *:* ]] && host="[$host]"
    ip netns exec "$NS" curl -s -m 2 -o /dev/null -w '%{http_code}' "http://$host:$port/" || true
}

# The rule number of the rule with a comment
rule_id() {
    v-list-firewall json | jq -r --arg c "$1" 'to_entries[] | select(.value.COMMENT == $c) | .key' | head -n 1
}

# Deleting a rule renumbers the rules after it: look the next one up each time
delete_tagged_rules() {
    local id
    for _ in $(seq 1 20); do
        id=$(v-list-firewall json | jq -r --arg t "$TAG" 'to_entries[] | select(.value.COMMENT | startswith($t)) | .key' | head -n 1)
        [ -n "$id" ] || break
        v-delete-firewall-rule "$id" > /dev/null 2>&1 || break
    done
}

ban_set_has() {
    nft list set inet arcpanel "$1" | grep -q -- "$2"
}

# The name of the SSH jail once Fail2Ban answers and lists it, within 30 s
f2b_ssh_jail() {
    local jail=''
    for _ in $(seq 1 30); do
        if fail2ban-client ping > /dev/null 2>&1; then
            jail=$(fail2ban-client status 2> /dev/null | sed -n 's/.*Jail list:\s*//p' | tr ',' '\n' | tr -d ' ' | grep -m 1 '^ssh') && break
        fi
        sleep 1
    done
    echo "$jail"
}

# Active ACCEPT rules whose PORT is exactly the port
accept_rules_for_port() {
    v-list-firewall json | jq -r --arg p "$1" 'to_entries[] | select(.value.PORT == $p and .value.ACTION == "ACCEPT" and .value.SUSPENDED == "no") | .key'
}

delete_rule_tagged() {
    local id
    id=$(rule_id "$1")
    [ -z "$id" ] || v-delete-firewall-rule "$id" > /dev/null
}

# Run the 2.3.0 upgrade step as func/upgrade.sh sources it
run_upgrade_step() {
    bash -c 'source /etc/arcpanel/arcpanel.conf; source "$ARCPANEL/func/main.sh"; source_conf "$ARCPANEL/conf/arcpanel.conf"; source "$ARCPANEL/install/upgrade/versions/2.3.0.sh"'
}

setup_file() {
    local port
    if [ "$(v-list-sys-config json | jq -r '.config.FIREWALL_SYSTEM')" != 'nftables' ]; then
        v-add-sys-firewall
    fi
    [ "$(v-list-sys-config json | jq -r '.config.FIREWALL_EXTENSION')" = 'fail2ban' ]

    ip netns del "$NS" 2> /dev/null || true
    ip link del "$HOST_IF" 2> /dev/null || true
    ip netns add "$NS"
    ip link add "$HOST_IF" type veth peer name "$NS_IF"
    ip link set "$NS_IF" netns "$NS"
    ip addr add "$HOST4/24" dev "$HOST_IF"
    ip -6 addr add "$HOST6/64" dev "$HOST_IF" nodad
    ip link set "$HOST_IF" up
    ip netns exec "$NS" ip addr add "$NS4/24" dev "$NS_IF"
    ip netns exec "$NS" ip -6 addr add "$NS6/64" dev "$NS_IF" nodad
    ip netns exec "$NS" ip link set "$NS_IF" up
    ip netns exec "$NS" ip link set lo up

    mkdir -p "$BATS_FILE_TMPDIR/www"
    echo ok > "$BATS_FILE_TMPDIR/www/index.html"
    for port in $P1 $P2 $P3 $P4; do
        (cd "$BATS_FILE_TMPDIR/www" && setsid python3 -m http.server --bind :: "$port" > /dev/null 2>&1 < /dev/null &)
    done
    sleep 1

    delete_tagged_rules
    rm -f "$FW/custom.nft"
    v-add-firewall-rule ACCEPT 0.0.0.0/0 "$P1" TCP "$TAG-open" > /dev/null
}

teardown_file() {
    rm -f "$FW/custom.nft"
    # A case that turns the firewall off, or leaves it on iptables, may stop
    # before it turns it back
    if [ "$(v-list-sys-config json | jq -r '.config.FIREWALL_SYSTEM')" != 'nftables' ]; then
        v-add-sys-firewall > /dev/null 2>&1 || true
    fi
    if ! cmp -s "$ARCPANEL/install/deb/fail2ban/action.d/arcpanel.conf" /etc/fail2ban/action.d/arcpanel.conf; then
        cp -f "$ARCPANEL/install/deb/fail2ban/action.d/arcpanel.conf" /etc/fail2ban/action.d/arcpanel.conf
        systemctl restart fail2ban
    fi
    if [ -s "$BATS_FILE_TMPDIR/suspended" ]; then
        while read -r id; do v-unsuspend-firewall-rule "$id" > /dev/null 2>&1 || true; done < "$BATS_FILE_TMPDIR/suspended"
    fi
    delete_tagged_rules
    for name in arcbats-l4 arcbats-l6; do
        v-delete-firewall-ipset "$name" > /dev/null 2>&1 || true
    done
    for chain in ARCBATS ARCBATSTWO; do
        v-delete-firewall-chain "$chain" > /dev/null 2>&1 || true
    done
    pkill -f "http.server --bind :: 3895" || true
    ip netns del "$NS" 2> /dev/null || true
    ip link del "$HOST_IF" 2> /dev/null || true
    v-update-firewall > /dev/null 2>&1 || true
    if [ -s "$BATS_FILE_TMPDIR/packages-added" ]; then
        # shellcheck disable=SC2046
        DEBIAN_FRONTEND=noninteractive apt-get purge -y -qq -o DPkg::Lock::Timeout=120 $(cat "$BATS_FILE_TMPDIR/packages-added") > /dev/null 2>&1 || true
    fi
    if [ -e "$BATS_FILE_TMPDIR/ip-filter-added" ]; then
        nft delete table ip filter 2> /dev/null || true
    fi
}

@test "firewall: the engine is nftables and the table is loaded" {
    run v-list-sys-config json
    assert_success
    assert_equal "$(jq -r '.config.FIREWALL_SYSTEM' <<< "$output")" nftables
    run nft list table inet arcpanel
    assert_success
    assert_file_exists /etc/arcpanel/firewall.nft
    run stat -c %a /etc/arcpanel/firewall.nft
    assert_output 600
    run systemctl is-enabled arcpanel-firewall
    assert_output enabled
}

@test "firewall: a port open to all answers over IPv4 and IPv6" {
    assert_equal "$(probe $HOST4 $P1)" 200
    assert_equal "$(probe $HOST6 $P1)" 200
}

@test "firewall: a port without a rule is closed over IPv4 and IPv6" {
    assert_equal "$(probe $HOST4 $P2)" 000
    assert_equal "$(probe $HOST6 $P2)" 000
}

@test "firewall: a suspended rule closes the port on both families" {
    local id
    id=$(rule_id "$TAG-open")
    run v-suspend-firewall-rule "$id"
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 000
    assert_equal "$(probe $HOST6 $P1)" 000
    run v-unsuspend-firewall-rule "$id"
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 200
    assert_equal "$(probe $HOST6 $P1)" 200
}

@test "firewall: a rule for an IPv6 network opens the port for it only" {
    run v-add-firewall-rule ACCEPT fd00:77::/64 "$P2" TCP "$TAG-v6"
    assert_success
    assert_equal "$(probe $HOST6 $P2)" 200
    assert_equal "$(probe $HOST4 $P2)" 000
}

@test "firewall: a rule for an IPv4 address opens the port for it only" {
    run v-add-firewall-rule ACCEPT "$NS4" "$P3" TCP "$TAG-v4"
    assert_success
    assert_equal "$(probe $HOST4 $P3)" 200
    assert_equal "$(probe $HOST6 $P3)" 000
}

@test "firewall: IPv6 sources are validated and kept in lower case" {
    run v-add-firewall-rule ACCEPT 2001:db8::/129 "$P4" TCP "$TAG-bad"
    assert_failure 2
    run v-add-firewall-rule ACCEPT 2001:DB8:AB::/48 "$P4" TCP "$TAG-case"
    assert_success
    run v-list-firewall-rule "$(rule_id "$TAG-case")" json
    assert_output --partial '"2001:db8:ab::/48"'
}

@test "firewall: the ICMP rule covers ping and ping6; neighbour discovery stays open" {
    local ping
    ping=$(rule_id PING)
    run ip netns exec "$NS" ping -c 1 -W 2 "$HOST4"
    assert_success
    run ip netns exec "$NS" ping -6 -c 1 -W 2 "$HOST6"
    assert_success
    v-suspend-firewall-rule "$ping"
    ip netns exec "$NS" ip -6 neigh flush dev "$NS_IF"
    run ip netns exec "$NS" ping -c 1 -W 2 "$HOST4"
    assert_failure
    run ip netns exec "$NS" ping -6 -c 1 -W 2 "$HOST6"
    assert_failure
    # TCP over IPv6 still works, so neighbour solicitations get through
    assert_equal "$(probe $HOST6 $P1)" 200
    v-unsuspend-firewall-rule "$ping"
}

@test "firewall: a ban blocks its family on the chain's ports without a reload" {
    local before
    run v-add-firewall-chain ARCBATS "$P1" TCP
    assert_success
    before=$(stat -c %Y /etc/arcpanel/firewall.nft)
    sleep 1

    run v-add-firewall-ban "$NS4" ARCBATS
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 000
    assert_equal "$(probe $HOST6 $P1)" 200

    run v-add-firewall-ban FD00:77::2 ARCBATS
    assert_success
    assert_equal "$(probe $HOST6 $P1)" 000
    run grep -c "IP='fd00:77::2' CHAIN='ARCBATS'" "$FW/banlist.conf"
    assert_output 1
    assert_equal "$(stat -c %Y /etc/arcpanel/firewall.nft)" "$before"

    run v-list-firewall-ban json
    assert_output --partial 'fd00:77::2'

    run v-delete-firewall-ban "$NS4" ARCBATS
    assert_success
    run v-delete-firewall-ban fd00:77::2 ALL
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 200
    assert_equal "$(probe $HOST6 $P1)" 200
    assert_equal "$(stat -c %Y /etc/arcpanel/firewall.nft)" "$before"
}

@test "firewall: overlapping bans fall back to a reload and stay in sync" {
    run v-add-firewall-ban 10.254.77.0/24 ARCBATS
    assert_success
    run v-add-firewall-ban "$NS4" ARCBATS
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 000
    run v-delete-firewall-ban 10.254.77.0/24 ARCBATS
    assert_success
    # The address ban is still listed and still blocks
    assert_equal "$(probe $HOST4 $P1)" 000
    run v-delete-firewall-ban "$NS4" ARCBATS
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 200
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
}

@test "firewall: a ban of the server's own IPv6 address is ignored" {
    run v-add-firewall-ban "$HOST6" ARCBATS
    assert_success
    run grep -c "IP='$HOST6'" "$FW/banlist.conf"
    assert_output 0
}

@test "firewall: deleting a chain removes its sets and bans" {
    v-add-firewall-ban "$NS4" ARCBATS
    run v-delete-firewall-chain ARCBATS
    assert_success
    run nft list set inet arcpanel f2b_ARCBATS_4
    assert_failure
    run grep -c "CHAIN='ARCBATS'" "$FW/banlist.conf"
    assert_output 0
    assert_equal "$(probe $HOST4 $P1)" 200
}

@test "firewall: IPv4 and IPv6 lists used by DROP rules block both families" {
    printf '%s\n' 10.254.77.0/24 192.0.2.1 192.0.2.2 192.0.2.3 192.0.2.4 > "$BATS_FILE_TMPDIR/l4.txt"
    printf '%s\n' fd00:77::/64 2001:db8::1 2001:db8::2 2001:db8::3 2001:db8::4 > "$BATS_FILE_TMPDIR/l6.txt"
    run v-add-firewall-ipset arcbats-l4 "file:$BATS_FILE_TMPDIR/l4.txt" v4 no
    assert_success
    run v-add-firewall-ipset arcbats-l6 "file:$BATS_FILE_TMPDIR/l6.txt" v6 no
    assert_success
    run v-add-firewall-rule DROP ipset:arcbats-l4 "$P1" TCP "$TAG-l4"
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 000
    assert_equal "$(probe $HOST6 $P1)" 200
    run v-add-firewall-rule DROP ipset:arcbats-l6 "$P1" TCP "$TAG-l6"
    assert_success
    assert_equal "$(probe $HOST6 $P1)" 000
    run nft list set inet arcpanel ipset_arcbats-l6_6
    assert_success
}

@test "firewall: a list that an active rule uses cannot be deleted" {
    run v-delete-firewall-ipset arcbats-l6
    assert_failure 7
    assert_output --partial "used by firewall rule $(rule_id "$TAG-l6")"
    v-delete-firewall-rule "$(rule_id "$TAG-l6")"
    v-delete-firewall-rule "$(rule_id "$TAG-l4")"
    run v-delete-firewall-ipset arcbats-l6
    assert_success
    run v-delete-firewall-ipset arcbats-l4
    assert_success
    assert_equal "$(probe $HOST4 $P1)" 200
    assert_equal "$(probe $HOST6 $P1)" 200
}

@test "firewall: a list with entries nft refuses is refused and changes nothing" {
    printf '%s\n' 2001:db8::1 2001:db8::2 2001:db8::3 2001:db8::4 1:2:3:4:5:6:7:8:9:10 > "$BATS_FILE_TMPDIR/bad6.txt"
    run v-add-firewall-ipset arcbats-bad "file:$BATS_FILE_TMPDIR/bad6.txt" v6 no
    assert_failure 2
    assert_output --partial 'nftables refuses'
    run grep -c "LISTNAME='arcbats-bad'" "$FW/ipset.conf"
    assert_output 0
    assert_file_not_exists "$FW/ipset/arcbats-bad.v6.iplist"
}

@test "firewall: the boot unit restores the table; a missing table is reported" {
    nft delete table inet arcpanel
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" missing
    assert_equal "$(probe $HOST6 $P2)" 200
    run systemctl restart arcpanel-firewall
    assert_success
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
    assert_equal "$(jq -r .ENGINE <<< "$output")" nftables
    assert_equal "$(probe $HOST4 $P2)" 000
    assert_equal "$(probe $HOST4 $P1)" 200
}

@test "firewall: a change outside the panel is drift until the next load" {
    nft add rule inet arcpanel input tcp dport 9 accept
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" drift
    run v-update-firewall
    assert_success
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
}

@test "firewall: custom.nft loads with the table; a broken one keeps the table" {
    echo "add rule inet arcpanel input tcp dport $P4 accept" > "$FW/custom.nft"
    run v-update-firewall
    assert_success
    assert_equal "$(probe $HOST4 $P4)" 200
    assert_equal "$(probe $HOST6 $P4)" 200
    run grep -c "dport $P4 accept" /etc/arcpanel/firewall.nft
    assert_output 1

    echo "add rule inet arcpanel input tcp dport bogus accept" > "$FW/custom.nft"
    run v-update-firewall
    assert_failure 19
    assert_output --partial 'nftables refused'
    assert_equal "$(probe $HOST4 $P4)" 200
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" ok

    rm -f "$FW/custom.nft"
    run v-update-firewall
    assert_success
    assert_equal "$(probe $HOST4 $P4)" 000
}

@test "firewall: the service row is nftables and running" {
    run v-list-sys-services json
    assert_success
    assert_equal "$(jq -r '.nftables.STATE' <<< "$output")" running
    assert_equal "$(jq -r '.nftables.SYSTEM' <<< "$output")" firewall
    run v-restart-service iptables
    assert_success
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
}

@test "firewall: Fail2Ban bans an IPv6 address into the chain's set" {
    local jail
    systemctl restart fail2ban
    # The sets outlive the restart: wait for the server to answer and to
    # list the SSH jail, not for the sets
    jail=$(f2b_ssh_jail)
    [ -n "$jail" ]
    run nft list set inet arcpanel f2b_SSH_6
    assert_success
    run fail2ban-client set "$jail" banip 2001:db8::77
    assert_success
    for _ in $(seq 1 10); do
        ban_set_has f2b_SSH_6 2001:db8::77 && break
        sleep 1
    done
    run nft list set inet arcpanel f2b_SSH_6
    assert_output --partial 2001:db8::77
    run fail2ban-client set "$jail" unbanip 2001:db8::77
    assert_success
    for _ in $(seq 1 10); do
        ban_set_has f2b_SSH_6 2001:db8::77 || break
        sleep 1
    done
    run nft list set inet arcpanel f2b_SSH_6
    refute_output --partial 2001:db8::77
    run grep -c "2001:db8::77" "$FW/banlist.conf"
    assert_output 0
}

@test "firewall: a stale Fail2Ban action is reported and replaced by a load" {
    local packaged=$ARCPANEL/install/deb/fail2ban/action.d/arcpanel.conf action=/etc/fail2ban/action.d/arcpanel.conf before
    # The 2.2 action: no lock variable, an iptables check; teardown_file
    # puts the packaged file back if the load does not
    sed -e 's/ARCPANEL_FIREWALL_LOCK_HELD=1 //' -e 's/^actioncheck = .*/actioncheck = iptables -n -L INPUT | grep -q fail2ban-<name>/' "$packaged" > "$action"
    run v-check-firewall json
    assert_success
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
    assert_output --partial '"fail2ban-action"'
    before=$(systemctl show -p ActiveEnterTimestampMonotonic --value fail2ban)
    run v-update-firewall
    assert_success
    run cmp "$packaged" "$action"
    assert_success
    run v-check-firewall json
    refute_output --partial fail2ban-action
    # Fail2Ban restarts without holding up the load and reads the new action
    for _ in $(seq 1 30); do
        [ "$(systemctl show -p ActiveEnterTimestampMonotonic --value fail2ban)" != "$before" ] && break
        sleep 1
    done
    assert_not_equal "$(systemctl show -p ActiveEnterTimestampMonotonic --value fail2ban)" "$before"
    [ -n "$(f2b_ssh_jail)" ]
}

@test "firewall: SSH and panel ports open to IPv4 sources only are reported while the host has IPv6" {
    local sshports panelport port id tag
    sshports=$(v-list-sys-sshd-port plain)
    panelport=$(v-list-sys-config json | jq -r '.config.BACKEND_PORT // "8083"')
    run v-check-firewall json
    refute_output --partial '"ipv6-'
    # Open both ports to the probe's IPv4 address only
    for port in $sshports $panelport; do
        for id in $(accept_rules_for_port "$port"); do
            v-suspend-firewall-rule "$id" > /dev/null
            echo "$id" >> "$BATS_FILE_TMPDIR/suspended"
        done
    done
    for port in $sshports; do
        v-add-firewall-rule ACCEPT "$NS4" "$port" TCP "$TAG-ssh4" > /dev/null
    done
    v-add-firewall-rule ACCEPT "$NS4" "$panelport" TCP "$TAG-panel4" > /dev/null
    run v-check-firewall json
    assert_success
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
    assert_output --partial '"ipv6-ssh"'
    assert_output --partial '"ipv6-panel"'
    # The upgrade step prints the warning
    run run_upgrade_step
    assert_success
    assert_output --partial 'over IPv6'
    # Without a global IPv6 address nothing is reported
    ip -6 addr del "$HOST6/64" dev "$HOST_IF"
    if [ -z "$(ip -6 -o addr show scope global)" ]; then
        run v-check-firewall json
        refute_output --partial '"ipv6-'
    fi
    ip -6 addr add "$HOST6/64" dev "$HOST_IF" nodad
    # A rule with an IPv6 source clears the warning of its port
    for port in $sshports; do
        v-add-firewall-rule ACCEPT fd00:77::/64 "$port" TCP "$TAG-ssh6" > /dev/null
    done
    run v-check-firewall json
    refute_output --partial '"ipv6-ssh"'
    assert_output --partial '"ipv6-panel"'
    for tag in ssh6 ssh4 panel4; do
        while [ -n "$(rule_id "$TAG-$tag")" ]; do delete_rule_tagged "$TAG-$tag"; done
    done
    while read -r id; do v-unsuspend-firewall-rule "$id" > /dev/null; done < "$BATS_FILE_TMPDIR/suspended"
    rm -f "$BATS_FILE_TMPDIR/suspended"
    run v-check-firewall json
    refute_output --partial '"ipv6-'
}

@test "firewall: the distribution's nftables unit is masked unless it is in use" {
    systemctl unmask nftables 2> /dev/null
    systemctl disable nftables 2> /dev/null
    run v-update-firewall
    assert_success
    run systemctl is-enabled nftables
    assert_output masked
    # Its start would flush the ruleset; masked, it cannot run
    run systemctl restart nftables
    assert_failure
    run nft list table inet arcpanel
    assert_success
    run grep -E '^After=.*nftables\.service' /etc/systemd/system/arcpanel-firewall.service
    assert_success
    # An enabled unit is the administrator's: left alone and reported
    systemctl unmask nftables
    systemctl enable nftables 2> /dev/null
    run v-update-firewall
    assert_success
    run systemctl is-enabled nftables
    assert_output enabled
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
    assert_output --partial '"nftables-service"'
    systemctl disable nftables 2> /dev/null
    run v-update-firewall
    assert_success
    run systemctl is-enabled nftables
    assert_output masked
    run v-check-firewall json
    refute_output --partial nftables-service
}

@test "firewall: disabling the firewall unmasks the distribution's unit" {
    run v-delete-sys-firewall
    assert_success
    run systemctl is-enabled nftables
    refute_output masked
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" off
    assert_equal "$(jq -c .WARNINGS <<< "$output")" '[]'
    run v-add-sys-firewall
    assert_success
    run systemctl is-enabled nftables
    assert_output masked
    run v-check-firewall json
    assert_equal "$(jq -r .STATUS <<< "$output")" ok
    assert_equal "$(probe $HOST4 $P1)" 200
}

@test "firewall: a failed move stays on iptables, refuses bans and finishes on a load" {
    local packaged=$ARCPANEL/install/deb/fail2ban/action.d/arcpanel.conf action=/etc/fail2ban/action.d/arcpanel.conf
    # A ban and a chain from before the move, while the table loads
    v-add-firewall-chain ARCBATS 9 TCP > /dev/null
    v-add-firewall-ban 10.254.77.98 ARCBATS > /dev/null
    # A 2.2 server whose table nft refuses; teardown_file moves it back and
    # puts the packaged action back if the case stops early
    sed -e 's/ARCPANEL_FIREWALL_LOCK_HELD=1 //' -e 's/^actioncheck = .*/actioncheck = iptables -n -L INPUT | grep -q fail2ban-<name>/' "$packaged" > "$action"
    cp "$action" "$BATS_TEST_TMPDIR/action-2.2"
    echo "add rule inet arcpanel input tcp dport bogus accept" > "$FW/custom.nft"
    v-change-sys-config-value FIREWALL_SYSTEM iptables

    run run_upgrade_step
    assert_success
    assert_output --partial 'stays on iptables'
    assert_output --partial 'Fail2Ban bans do not apply'
    assert_equal "$(v-list-sys-config json | jq -r '.config.FIREWALL_SYSTEM')" iptables
    # The 2.2 action stays: the nft one would fail its check and drop the
    # chain's bans through actionstop
    run cmp "$BATS_TEST_TMPDIR/action-2.2" "$action"
    assert_success
    run v-check-firewall json
    assert_success
    assert_equal "$(jq -c .WARNINGS <<< "$output" | grep -o '"iptables-engine"')" '"iptables-engine"'
    refute_output --partial fail2ban-action

    # Bans and chains are refused at once and change no file
    cp "$FW/banlist.conf" "$BATS_TEST_TMPDIR/banlist"
    cp "$FW/chains.conf" "$BATS_TEST_TMPDIR/chains"
    run v-add-firewall-ban 10.254.77.99 ARCBATS
    assert_failure 11
    assert_output --partial iptables
    run v-delete-firewall-ban 10.254.77.98 ARCBATS
    assert_failure 11
    run v-add-firewall-chain ARCBATSTWO 10 TCP
    assert_failure 11
    run v-delete-firewall-chain ARCBATS
    assert_failure 11
    run cmp "$BATS_TEST_TMPDIR/banlist" "$FW/banlist.conf"
    assert_success
    run cmp "$BATS_TEST_TMPDIR/chains" "$FW/chains.conf"
    assert_success

    # Once the cause is gone, a load finishes the move
    rm -f "$FW/custom.nft"
    run v-update-firewall
    assert_success
    assert_equal "$(v-list-sys-config json | jq -r '.config.FIREWALL_SYSTEM')" nftables
    run cmp "$packaged" "$action"
    assert_success
    run v-check-firewall json
    refute_output --partial iptables-engine
    run nft list set inet arcpanel f2b_ARCBATS_4
    assert_output --partial 10.254.77.98
    v-delete-firewall-chain ARCBATS > /dev/null
    [ -n "$(f2b_ssh_jail)" ]
}

@test "firewall: the iptables leftovers of 2.2 are removed once" {
    # A 2.2 server had iptables, a fresh install has nftables only: install
    # it for the test and let teardown_file purge what it brought in
    if ! command -v iptables > /dev/null 2>&1; then
        nft list table ip filter > /dev/null 2>&1 || touch "$BATS_FILE_TMPDIR/ip-filter-added"
        dpkg-query -W -f '${Package}\n' | sort > "$BATS_FILE_TMPDIR/packages-before"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq -o DPkg::Lock::Timeout=120 iptables > /dev/null
        dpkg-query -W -f '${Package}\n' | sort | comm -13 "$BATS_FILE_TMPDIR/packages-before" - > "$BATS_FILE_TMPDIR/packages-added"
    fi
    iptables -N fail2ban-ARCBATS
    iptables -A INPUT -p tcp --dport 9 -j fail2ban-ARCBATS
    iptables -P INPUT DROP
    echo '# legacy' > /etc/iptables.rules
    printf '[Unit]\nDescription=legacy\n[Service]\nType=oneshot\nExecStart=/bin/true\n[Install]\nWantedBy=multi-user.target\n' > /lib/systemd/system/arcpanel-iptables.service
    systemctl daemon-reload
    systemctl enable arcpanel-iptables 2> /dev/null

    run v-update-firewall
    assert_success
    run iptables -S INPUT
    assert_line '-P INPUT ACCEPT'
    refute_output --partial fail2ban-ARCBATS
    run iptables -S fail2ban-ARCBATS
    assert_failure
    assert_file_not_exists /etc/iptables.rules
    assert_file_not_exists /lib/systemd/system/arcpanel-iptables.service
    assert_equal "$(probe $HOST4 $P1)" 200
}
