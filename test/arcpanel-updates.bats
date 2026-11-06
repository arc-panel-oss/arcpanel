#!/usr/bin/env bats
# Automatic updates that stay on the installed release line (E33 phase 1 P1,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §6).
# The version choice of func/updates.sh needs no server. The other cases run
# as root on a server installed with the ArcPanel installer, with apt-get and
# apt-cache replaced by stubs on PATH; they leave the cron line, the
# configuration, the notifications and the ArcPanel APT source and keyring
# (which some cases move away) as they found them.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
ARCPANEL=/usr/local/arcpanel
CONF=$ARCPANEL/conf/arcpanel.conf
CRONTAB=/var/spool/cron/crontabs/arcpanelweb
APT_SOURCE=/etc/apt/sources.list.d/arcpanel.list
APT_KEYRING=/usr/share/keyrings/arcpanel-keyring.gpg

setup() {
	command -v dpkg > /dev/null || skip "dpkg is not installed"
	TMP="$(mktemp -d)"
}

teardown() {
	if [ -n "${SERVER:-}" ]; then
		# Put back the APT source and keyring a case moved away
		[ ! -e "$TMP/arcpanel.list" ] || mv -f "$TMP/arcpanel.list" "$APT_SOURCE"
		[ ! -e "$TMP/arcpanel-keyring.gpg" ] || mv -f "$TMP/arcpanel-keyring.gpg" "$APT_KEYRING"
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
#   The ArcPanel APT source (func/updates.sh)              #
#----------------------------------------------------------#

# A source and keyring under TMP, as the installer writes them, and an apt-get
# that prints $TMP/apt-out and exits with $TMP/apt-rc
apt_files() {
	source "$REPO_ROOT/func/updates.sh"
	UPD_APT_SOURCE="$TMP/arcpanel.list"
	echo "deb [arch=amd64 signed-by=$TMP/arcpanel-keyring.gpg] https://apt.arcpanel.org/ noble main" > "$UPD_APT_SOURCE"
	echo key > "$TMP/arcpanel-keyring.gpg"
	chmod 0644 "$TMP/arcpanel-keyring.gpg"
	mkdir -p "$TMP/stubs"
	printf '#!/bin/bash\ncat %s/apt-out 2> /dev/null\nexit "$(cat %s/apt-rc 2> /dev/null || echo 0)"\n' "$TMP" "$TMP" > "$TMP/stubs/apt-get"
	chmod +x "$TMP/stubs/apt-get"
	PATH="$TMP/stubs:$PATH"
}

@test "updates: a source with a deb line and a readable keyring passes the check" {
	apt_files
	run upd_check_apt_source
	assert_success
	assert_output ""
}

@test "updates: a missing source, a source without a deb line and a missing keyring are found" {
	apt_files
	mv "$UPD_APT_SOURCE" "$TMP/saved.list"
	run upd_check_apt_source
	assert_failure
	assert_output "the ArcPanel APT source $TMP/arcpanel.list is missing"
	echo "# deb https://apt.arcpanel.org/ noble main" > "$UPD_APT_SOURCE"
	run upd_check_apt_source
	assert_failure
	assert_output "the ArcPanel APT source $TMP/arcpanel.list has no deb line"
	mv -f "$TMP/saved.list" "$UPD_APT_SOURCE"
	rm "$TMP/arcpanel-keyring.gpg"
	run upd_check_apt_source
	assert_failure
	assert_output "the ArcPanel APT keyring $TMP/arcpanel-keyring.gpg is missing"
}

@test "updates: an empty keyring or one that apt cannot read is found" {
	apt_files
	chmod 0600 "$TMP/arcpanel-keyring.gpg"
	run upd_check_apt_source
	assert_failure
	assert_output "the ArcPanel APT keyring $TMP/arcpanel-keyring.gpg is empty or not readable by apt"
	: > "$TMP/arcpanel-keyring.gpg"
	chmod 0644 "$TMP/arcpanel-keyring.gpg"
	run upd_check_apt_source
	assert_failure
	assert_output "the ArcPanel APT keyring $TMP/arcpanel-keyring.gpg is empty or not readable by apt"
}

@test "updates: the refresh stops before apt-get without a source and says how to restore it" {
	apt_files
	rm "$UPD_APT_SOURCE"
	echo "apt-get ran" > "$TMP/apt-out"
	run upd_apt_update
	assert_equal "$status" 3
	assert_line --index 0 "Error: the ArcPanel APT source $TMP/arcpanel.list is missing, so ArcPanel cannot see new releases."
	assert_line --partial "signed-by=/usr/share/keyrings/arcpanel-keyring.gpg] https://apt.arcpanel.org/"
	refute_line "apt-get ran"
}

@test "updates: a warning of apt-get fails the refresh although apt-get exits 0" {
	apt_files
	echo "W: Failed to fetch https://apt.arcpanel.org/dists/noble/InRelease  NO_PUBKEY A1A6B935BE659E7C" > "$TMP/apt-out"
	run upd_apt_update
	assert_equal "$status" 19
	assert_line --index 0 --partial "W: Failed to fetch"
	assert_line --index 1 "Error: apt-get update could not read the ArcPanel APT repository of $TMP/arcpanel.list, so ArcPanel cannot see new releases."
	: > "$TMP/apt-out"
	echo 100 > "$TMP/apt-rc"
	run upd_apt_update
	assert_equal "$status" 19
}

@test "updates: a quiet apt-get refresh passes" {
	apt_files
	run upd_apt_update
	assert_success
	assert_output ""
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
	ERR_LINES="$(wc -l < "$ARCPANEL/log/error.log" 2> /dev/null || echo 0)"
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

# Moves the APT source or keyring of the server into TMP until teardown
hide() {
	mv "$1" "$TMP/${1##*/}"
}

# Lines added to autoupdate.log and error.log since server_setup
new_log() {
	tail -n +$((LOG_LINES + 1)) "$ARCPANEL/log/autoupdate.log"
}
new_errors() {
	tail -n +$((ERR_LINES + 1)) "$ARCPANEL/log/error.log"
}

@test "updates: auto without the APT source fails, says why and notifies the administrator once" {
	server_setup
	offer "$PATCH"
	hide "$APT_SOURCE"
	run auto
	assert_equal "$status" 3
	assert_output "Error: the automatic update of ArcPanel failed, see $ARCPANEL/log/autoupdate.log"
	run new_log
	assert_line "Error: the ArcPanel APT source $APT_SOURCE is missing, so ArcPanel cannot see new releases."
	assert_line --partial "signed-by=$APT_KEYRING] https://apt.arcpanel.org/"
	refute_line --partial "patch release:"
	run cat "$TMP/apt-get.log"
	assert_output ""
	run new_errors
	assert_line --regexp 'v-update-sys-arcpanel-all.* \[Error 3\]$'
	run new_notifications
	assert_output 1
	run auto
	assert_equal "$status" 3
	run new_notifications
	assert_output 1
}

@test "updates: auto without the APT keyring fails and names it" {
	server_setup
	offer "$PATCH"
	hide "$APT_KEYRING"
	run auto
	assert_equal "$status" 3
	run new_log
	assert_line "Error: the ArcPanel APT keyring $APT_KEYRING is missing, so ArcPanel cannot see new releases."
	run cat "$TMP/apt-get.log"
	assert_output ""
}

@test "updates: auto fails when apt-get update only warns about the repository" {
	server_setup
	offer "$PATCH"
	printf '#!/bin/bash\necho "$*" >> %s/apt-get.log\n[ "$1" != update ] || echo "W: Failed to fetch https://apt.arcpanel.org/dists/noble/InRelease  NO_PUBKEY A1A6B935BE659E7C"\n' "$TMP" > "$TMP/stubs/apt-get"
	run auto
	assert_equal "$status" 19
	run new_log
	assert_line --partial "W: Failed to fetch"
	assert_line "Error: apt-get update could not read the ArcPanel APT repository of $APT_SOURCE, so ArcPanel cannot see new releases."
	run grep -c install "$TMP/apt-get.log"
	assert_output 0
	run new_notifications
	assert_output 1
}

@test "updates: a manual update without the APT source fails with one error and installs nothing" {
	server_setup
	hide "$APT_SOURCE"
	run env PATH="$TMP/stubs:$PATH" "$ARCPANEL/bin/v-update-sys-arcpanel" arcpanel
	assert_equal "$status" 3
	assert_line --index 0 "Error: the ArcPanel APT source $APT_SOURCE is missing, so ArcPanel cannot see new releases."
	run env PATH="$TMP/stubs:$PATH" "$ARCPANEL/bin/v-update-sys-arcpanel-all"
	assert_equal "$status" 3
	run grep -c '^Error:' <<< "$output"
	assert_output 1
	run cat "$TMP/apt-get.log"
	assert_output ""
	run new_notifications
	assert_output 0
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
