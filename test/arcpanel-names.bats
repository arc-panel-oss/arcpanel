#!/usr/bin/env bats
# Full rename (ADR-0007, docs/features/specs/2026-10-06-005-e9-full-rename-design.md
# §5, §10.2): a fresh install has no HestiaCP name in its paths, users,
# packages, units and processes, and the runtime identifiers are ArcPanel's.
# Run as root on a server installed with the ArcPanel installer. This file
# names the old names on purpose (scripts/rename/keep.txt).

if [ "${PATH#*/usr/local/arcpanel/bin*}" = "$PATH" ]; then
	. /etc/profile.d/arcpanel.sh
fi

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'
load 'test_helper/bats-file/load'

PANEL="https://127.0.0.1:8083"

@test "Paths: /usr/local/arcpanel, /etc/arcpanel and /var/log/arcpanel, no hestia path" {
	assert_dir_exists /usr/local/arcpanel
	refute [ -L /usr/local/arcpanel ]
	assert_file_exists /etc/arcpanel/arcpanel.conf
	assert_file_exists /usr/local/arcpanel/conf/arcpanel.conf
	assert_dir_exists /var/log/arcpanel
	run bash -c 'ls -d /usr/local/hestia* /etc/hestia* /var/log/hestia* /etc/profile.d/hestia* /etc/sudoers.d/hestia* /etc/cron.d/hestia* /etc/logrotate.d/hestia* /etc/init.d/hestia* 2> /dev/null'
	assert_output ''
}

@test "Users and groups: arcpanelweb, arcpanelmail and arcpanel-users, no hestia account" {
	run id -u arcpanelweb
	assert_success
	run id -u arcpanelmail
	assert_success
	run getent group arcpanel-users
	assert_success
	run bash -c "getent passwd | cut -d: -f1 | grep -i hestia; getent group | cut -d: -f1 | grep -i hestia"
	assert_output ''
}

@test "Packages and units: arcpanel only" {
	run bash -c "dpkg-query -W -f='\${Package}\n' | grep -i hestia"
	assert_output ''
	run dpkg-query -W -f='${Status}' arcpanel arcpanel-nginx arcpanel-php
	assert_success
	run bash -c "systemctl list-unit-files --no-legend | awk '{print \$1}' | grep -i hestia"
	assert_output ''
	run systemctl is-active arcpanel
	assert_output 'active'
}

@test "Processes: no command line names hestia" {
	run bash -c "ps -eo args= | grep -i hestia | grep -v grep"
	assert_output ''
	run bash -c "ps -eo args= | grep -c '[a]rcpanel-nginx\|[a]rcpanel-php'"
	refute_output '0'
}

@test "The panel runs commands as arcpanelweb through sudo" {
	run runuser -u arcpanelweb -- sudo -n /usr/local/arcpanel/bin/v-list-sys-info json
	assert_success
	run bash -c "cat /etc/sudoers.d/* | grep -i hestia"
	assert_output ''
}

@test "Firewall: the panel chain is ARCPANEL" {
	run grep -c "CHAIN='ARCPANEL'" /usr/local/arcpanel/data/firewall/chains.conf
	assert_output '1'
	run grep -c "COMMENT='ARCPANEL'" /usr/local/arcpanel/data/firewall/rules.conf
	refute_output '0'
	run bash -c "grep -i hestia /usr/local/arcpanel/data/firewall/rules.conf /usr/local/arcpanel/data/firewall/chains.conf"
	assert_output ''
	if [ "$(v-list-sys-config json | jq -r '.config.FIREWALL_SYSTEM')" = 'iptables' ]; then
		run bash -c "iptables -S | grep -c -- '-N fail2ban-ARCPANEL'"
		refute_output '0'
		run bash -c "iptables -S | grep -ci hestia"
		assert_output '0'
	fi
}

@test "API: answers carry ArcPanel-Exit-Code" {
	if grep -q '^die("Error: Disabled");' /usr/local/arcpanel/lib/api/index.php; then
		skip "the API is disabled on this server"
	fi
	run curl -sk -D - -o /dev/null -X POST --data-urlencode "access_key=ARCNAMESNOKEY00000000" \
		--data-urlencode "secret_key=x" --data-urlencode "cmd=v-list-sys-info" "$PANEL/api/index.php"
	assert_output --partial 'ArcPanel-Exit-Code:'
	refute_output --partial 'Hestia-Exit-Code'
}

@test "DNS cluster: the system value is arcpanel" {
	run bash -c "grep '^DNS_CLUSTER_SYSTEM=' /usr/local/arcpanel/conf/arcpanel.conf"
	if [ -n "$output" ]; then
		assert_output --regexp "^DNS_CLUSTER_SYSTEM='(arcpanel|arcpanel-zone)'$"
	fi
}

@test "Configuration files written by ArcPanel carry its markers" {
	run grep -rl "Hestia" /etc/ssh/sshd_config /etc/dovecot/dovecot.conf /etc/apparmor.d/local/proftpd /etc/ssl/openssl.cnf
	assert_output ''
	run bash -c "grep -rli hestia /etc/nginx /etc/apache2 /etc/exim4 /etc/dovecot /etc/fail2ban /etc/cron.d /etc/logrotate.d 2> /dev/null"
	assert_output ''
}

@test "The gettext catalogue is arcpanel" {
	assert_file_exists /usr/local/arcpanel/lib/locale/arcpanel.pot
	run bash -c "ls /usr/local/arcpanel/lib/locale/*/LC_MESSAGES/ | grep -v '^arcpanel\.mo$' | grep '\.mo$'"
	assert_output ''
}
