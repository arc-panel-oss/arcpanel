#!/usr/bin/env bats
# Automatic updates that stay on the installed release line (E33 phase 1 P1,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §6).
# The version choice of func/updates.sh needs no server. The other cases run
# as root on a server installed with the ArcPanel installer, with apt-get and
# apt-cache replaced by stubs on PATH; they leave the cron line, the
# configuration and the notifications as they found them.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
ARCPANEL=/usr/local/arcpanel
CONF=$ARCPANEL/conf/arcpanel.conf
CRONTAB=/var/spool/cron/crontabs/arcpanelweb

setup() {
	command -v dpkg > /dev/null || skip "dpkg is not installed"
	TMP="$(mktemp -d)"
}

teardown() {
	if [ -n "${SERVER:-}" ]; then
		cp "$TMP/crontab" "$CRONTAB"
		cp "$TMP/arcpanel.conf" "$CONF"
		for id in $(notification_ids); do
			grep -qx "$id" "$TMP/notifications" || "$ARCPANEL/bin/v-delete-user-notification" "$ADMIN" "$id" > /dev/null 2>&1 || true
		done
	fi
	[ -z "${TMP:-}" ] || rm -rf "$TMP"
}

#----------------------------------------------------------#
#   Version choice (func/updates.sh)                       #
#----------------------------------------------------------#

pick() {
	# shellcheck source=../func/updates.sh
	source "$REPO_ROOT/func/updates.sh"
	printf '%s\n' "${@:2}" | upd_pick_versions "$1"
}

@test "updates: the upstream version and the line of a package version" {
	source "$REPO_ROOT/func/updates.sh"
	run upd_upstream_version 2.2.2-1+ubuntu24.04
	assert_output 2.2.2
	run upd_upstream_version 2.2.2
	assert_output 2.2.2
	run upd_version_line 2.3.0~beta.1
	assert_output 2.3
	run upd_version_line 2.10.4
	assert_output 2.10
}

@test "updates: the newest patch of the installed line is the target" {
	run pick 2.2.1 2.2.1-1+debian12 2.2.2-1+debian12 2.2.3-1+debian12
	assert_output "2.2.3-1+debian12 -"
}

@test "updates: a newer line is announced, not installed" {
	run pick 2.2.1 2.2.1-1+debian12 2.2.2-1+debian12 2.3.0-1+debian12 2.4.1-1+debian12 2.4.0-1+debian12
	assert_output "2.2.2-1+debian12 2.4.1"
}

@test "updates: only a newer line gives no target" {
	run pick 2.2.2 2.2.1-1+debian12 2.2.2-1+debian12 2.3.0-1+debian12
	assert_output "- 2.3.0"
}

@test "updates: nothing newer gives nothing" {
	run pick 2.2.2 2.2.1-1+debian12 2.2.2-1+debian12
	assert_output "- -"
}

@test "updates: versions compare as numbers and pre-releases come before their release" {
	run pick 2.2.9 2.2.10-1+ubuntu24.04 2.2.9-1+ubuntu24.04
	assert_output "2.2.10-1+ubuntu24.04 -"
	run pick 2.3.0~beta.1 2.3.0~beta.2-1+ubuntu24.04 2.3.0-1+ubuntu24.04 2.2.2-1+ubuntu24.04
	assert_output "2.3.0-1+ubuntu24.04 -"
	run pick 2.2.2~alpha 2.2.2-1+ubuntu24.04
	assert_output "2.2.2-1+ubuntu24.04 -"
}

@test "updates: the comma list of announced versions" {
	source "$REPO_ROOT/func/updates.sh"
	upd_in_list 2.3.0 "2.3.0,2.4.0"
	upd_in_list 2.4.0 "2.3.0,2.4.0"
	run upd_in_list 2.3.1 "2.3.0,2.4.0"
	assert_failure
	run upd_in_list 2.3.0 ""
	assert_failure
}

#----------------------------------------------------------#
#   On the server                                          #
#----------------------------------------------------------#

notification_ids() {
	"$ARCPANEL/bin/v-list-user-notifications" "$ADMIN" json 2> /dev/null | grep -oE '^ *"[0-9]+"' | tr -dc '0-9\n'
}

# Notifications of the administrator added since server_setup
new_notifications() {
	notification_ids | grep -cvxF -f "$TMP/notifications" || true
}

key() {
	grep -m1 "^$1=" "$CONF" | cut -d"'" -f2
}

server_setup() {
	[ "$(id -u)" -eq 0 ] && [ -x "$ARCPANEL/bin/v-update-sys-arcpanel-all" ] || skip "needs root on an ArcPanel server"
	SERVER=yes
	cp "$CRONTAB" "$TMP/crontab"
	cp "$CONF" "$TMP/arcpanel.conf"
	ADMIN="$(key ROOT_USER)"
	ADMIN="${ADMIN:-admin}"
	notification_ids > "$TMP/notifications"
	LOG_LINES="$(wc -l < "$ARCPANEL/log/autoupdate.log" 2> /dev/null || echo 0)"
	"$ARCPANEL/bin/v-change-sys-config-value" UPGRADE_SEND_EMAIL false > /dev/null
	sed -i '/^UPDATE_NOTIFIED_VERSION=/d' "$CONF"
	INSTALLED="$(key VERSION)"
	LINE="${INSTALLED%%~*}"
	PATCH="${LINE%.*}.$((${LINE##*.} + 1))-1+test"
	MINOR="${LINE%%.*}.$(($(echo "$LINE" | cut -d. -f2) + 1)).0"
	# apt-get records its arguments; apt-cache offers OFFER
	mkdir -p "$TMP/stubs"
	printf '#!/bin/bash\necho "$*" >> %s/apt-get.log\n' "$TMP" > "$TMP/stubs/apt-get"
	printf '#!/bin/bash\nfor v in $(cat %s/offer); do echo " arcpanel | $v | stub"; done\n' "$TMP" > "$TMP/stubs/apt-cache"
	chmod +x "$TMP/stubs/apt-get" "$TMP/stubs/apt-cache"
	touch "$TMP/apt-get.log"
}

offer() {
	printf '%s\n' "$@" > "$TMP/offer"
}

auto() {
	PATH="$TMP/stubs:$PATH" "$ARCPANEL/bin/v-update-sys-arcpanel-all" auto
}

# Since 2.5.0 the old switch sets the update policy (E33 phase 1 §10), so
# the 2.2.2 step, run before the 2.5.0 step on an upgrade from 2.2.1, hands
# the nightly line to the policy; the cases of the switch itself are in
# test/arcpanel-update-center.bats.
@test "updates: the 2.2.2 step hands a nightly line to the policy and leaves a server without one alone" {
	server_setup
	sed -i -E '/^UPDATE_(POLICY|WINDOW_DAYS|WINDOW_HOURS|DELAY_DAYS)=/d' "$CONF"
	sed -i -e '/v-update-sys-arcpanel-all/d' -e '/v-run-sys-arcpanel-autoupdate/d' "$CRONTAB"
	ARCPANEL="$ARCPANEL" bash "$REPO_ROOT/install/upgrade/versions/2.2.2.sh"
	run grep -cE 'v-update-sys-arcpanel-all|v-run-sys-arcpanel-autoupdate' "$CRONTAB"
	assert_output 0
	run key UPDATE_POLICY
	assert_output ""
	sed -i -e "\$a41 4 * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all" "$CRONTAB"
	ARCPANEL="$ARCPANEL" bash "$REPO_ROOT/install/upgrade/versions/2.2.2.sh"
	ARCPANEL="$ARCPANEL" bash "$REPO_ROOT/install/upgrade/versions/2.2.2.sh"
	run grep -c 'v-update-sys-arcpanel-all' "$CRONTAB"
	assert_output 0
	run grep -c 'v-run-sys-arcpanel-autoupdate' "$CRONTAB"
	assert_output 1
	run echo "$(key UPDATE_POLICY) $(key UPDATE_WINDOW_HOURS)"
	assert_output 'patch 04-06'
}

@test "updates: auto installs the patch release of the line and updates the engines" {
	server_setup
	offer "${INSTALLED%%~*}-1+test" "$PATCH"
	run auto
	assert_success
	assert_output ""
	run grep -c "install -y -q .* arcpanel=$PATCH$" "$TMP/apt-get.log"
	assert_output 1
	run grep -cE "install -y -q .* arcpanel-(nginx|php)$" "$TMP/apt-get.log"
	assert_output 2
	run key UPDATE_NOTIFIED_VERSION
	assert_output ""
}

@test "updates: auto announces a newer line once and keeps the engines on the line" {
	server_setup
	offer "$PATCH" "$MINOR-1+test"
	run auto
	assert_success
	run grep -c "arcpanel=$PATCH$" "$TMP/apt-get.log"
	assert_output 1
	run grep -cE "arcpanel-(nginx|php)$" "$TMP/apt-get.log"
	assert_output 0
	run key UPDATE_NOTIFIED_VERSION
	assert_output "$MINOR"
	run new_notifications
	assert_output 1
	auto
	run key UPDATE_NOTIFIED_VERSION
	assert_output "$MINOR"
	run bash -c "tail -n +$((LOG_LINES + 1)) $ARCPANEL/log/autoupdate.log | grep -c 'Announced ArcPanel $MINOR'"
	assert_output 1
}

@test "updates: a failed install prints one error line for cron" {
	server_setup
	offer "$PATCH"
	printf '#!/bin/bash\necho "$*" >> %s/apt-get.log\n[ "$1" = update ] || exit 100\n' "$TMP" > "$TMP/stubs/apt-get"
	run auto
	assert_failure
	assert_output "Error: the automatic update of ArcPanel failed, see $ARCPANEL/log/autoupdate.log"
}

@test "updates: the mode, the package and the version are checked" {
	server_setup
	run "$ARCPANEL/bin/v-update-sys-arcpanel-all" nightly
	assert_failure
	assert_output --partial "invalid mode nightly"
	run "$ARCPANEL/bin/v-update-sys-arcpanel" arcpanel '2.2.2;id'
	assert_failure
	assert_output --partial "invalid package version"
	run "$ARCPANEL/bin/v-update-sys-arcpanel" nginx
	assert_failure
	assert_output --partial "is not controlled by ArcPanel"
}
