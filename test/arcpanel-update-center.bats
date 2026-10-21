#!/usr/bin/env bats
# The Update Center of the panel packages (E33 phase 1 P2,
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md §7):
# the release manifest, the update policy, the pre-flight and the health
# check. The policy rules run against a throw-away ARCPANEL folder and need no
# server. The command cases run as root on a server installed with the
# ArcPanel installer, with a manifest signed by a test key and served from a
# file:// repository, and apt-get and apt-cache replaced by stubs on PATH;
# they leave the configuration and the cron table as they found them.

load 'test_helper/bats-support/load'
load 'test_helper/bats-assert/load'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
SERVER_ARCPANEL=/usr/local/arcpanel
CRONTAB=/var/spool/cron/crontabs/arcpanelweb

setup_file() {
	command -v gpg > /dev/null || return 0
	export GNUPGHOME="$BATS_FILE_TMPDIR/gnupg"
	install -d -m 700 "$GNUPGHOME"
	gpg --batch --passphrase '' --quick-gen-key 'ArcPanel Test <test@example.invalid>' ed25519 sign never 2> /dev/null
	gpg --export > "$BATS_FILE_TMPDIR/keyring.gpg"
	gpg --batch --passphrase '' --quick-gen-key 'Someone Else <else@example.invalid>' ed25519 sign never 2> /dev/null
	OTHER_KEY="$(gpg --list-secret-keys --with-colons 'else@example.invalid' | awk -F: '/^fpr/ {print $10; exit}')"
	export OTHER_KEY
}

setup() {
	for tool in dpkg jq gpg gpgv; do
		command -v "$tool" > /dev/null || skip "$tool is not installed"
	done
	TMP="$(mktemp -d)"
	export UPD_DIR="$TMP/updates"
	export UPD_APT_LIST="$TMP/arcpanel.list"
	mkdir -p "$TMP/repo" "$TMP/stubs" "$UPD_DIR"
	echo "deb [arch=amd64 signed-by=$BATS_FILE_TMPDIR/keyring.gpg] file://$TMP/repo/ noble main" > "$UPD_APT_LIST"
	# apt-cache madison PACKAGE offers the lines of $TMP/offer-PACKAGE
	printf '#!/bin/bash\n[ "$1" = madison ] || exit 0\nwhile read -r v; do echo " $2 | $v | file://repo noble/main"; done < %s/offer-$2 2> /dev/null\n' "$TMP" > "$TMP/stubs/apt-cache"
	# apt-get records its arguments and fails when $TMP/apt-get.fail exists
	printf '#!/bin/bash\necho "$*" >> %s/apt-get.log\n[ ! -f %s/apt-get.fail ]\n' "$TMP" "$TMP" > "$TMP/stubs/apt-get"
	chmod +x "$TMP/stubs/apt-cache" "$TMP/stubs/apt-get"
}

teardown() {
	if [ -n "${SERVER:-}" ]; then
		cp "$TMP/crontab" "$CRONTAB"
		cp "$TMP/arcpanel.conf" "$SERVER_ARCPANEL/conf/arcpanel.conf"
		[ -z "${BACKUP_PID:-}" ] || kill "$BACKUP_PID" 2> /dev/null || true
		for id in $(notification_ids); do
			grep -qx "$id" "$TMP/notifications" || "$SERVER_ARCPANEL/bin/v-delete-user-notification" "$ADMIN" "$id" > /dev/null 2>&1 || true
		done
	fi
	[ -z "${TMP:-}" ] || rm -rf "$TMP"
}

# A release entry of the manifest: VERSION DATE [JQ-ASSIGNMENTS]
release() {
	jq -cn --arg v "$1" --arg d "$2" '{version: $v, line: ($v | split(".")[0:2] | join(".")), date: $d,
		security: false, attention: false, attention_items: [], steps: false, min_from: null,
		engines: {"arcpanel-nginx": "1.30.5-3-1", "arcpanel-php": "8.5.11-1-1"},
		notes: "https://arcpanel.top/changelog", withdrawn: null}'"${3:+ | $3}"
}

# Write the signed manifest of the repository: GENERATED RELEASE...
manifest() {
	local generated="$1"
	shift
	printf '%s\n' "$@" | jq -s --arg g "$generated" '{format: 1, channel: "stable", generated: $g, releases: .}' > "$TMP/repo/releases.json"
	rm -f "$TMP/repo/releases.json.sig"
	gpg --batch --yes --detach-sign --output "$TMP/repo/releases.json.sig" "$TMP/repo/releases.json"
}

offer() {
	local package="$1"
	shift
	printf '%s\n' "$@" > "$TMP/offer-$package"
}

#----------------------------------------------------------#
#   Policy rules (func/updates.sh, no server)              #
#----------------------------------------------------------#

# A throw-away ARCPANEL with arcpanel.conf holding KEY=VALUE pairs
library() {
	export ARCPANEL="$TMP/arcpanel"
	mkdir -p "$ARCPANEL/conf"
	: > "$ARCPANEL/conf/arcpanel.conf"
	local pair
	for pair in "$@"; do
		echo "${pair%%=*}='${pair#*=}'" >> "$ARCPANEL/conf/arcpanel.conf"
	done
	# shellcheck source=../func/updates.sh
	source "$REPO_ROOT/func/updates.sh"
	PATH="$TMP/stubs:$PATH"
}

at() {
	date -d "$1" +%s
}

# Fails when COMMAND succeeds (a "! COMMAND" line does not fail a BATS test)
fails() {
	if "$@"; then
		return 1
	fi
}

@test "update center: the window, also past midnight, belongs to the day it starts on" {
	library
	upd_in_window "$(at '2026-11-02 03:30')" all 03-05
	fails upd_in_window "$(at '2026-11-02 05:00')" all 03-05
	fails upd_in_window "$(at '2026-11-02 02:59')" all 03-05
	# 2026-11-07 is a Saturday
	upd_in_window "$(at '2026-11-07 23:10')" sat 22-02
	upd_in_window "$(at '2026-11-08 01:10')" sat 22-02
	fails upd_in_window "$(at '2026-11-08 23:10')" sat 22-02
	fails upd_in_window "$(at '2026-11-07 01:10')" sat 22-02
	upd_in_window "$(at '2026-11-03 12:00')" mon,tue 00-24
	fails upd_in_window "$(at '2026-11-04 12:00')" mon,tue 00-24
}

@test "update center: the next window starts at the next allowed hour" {
	library
	run upd_next_window "$(at '2026-11-02 06:10')" all 03-05
	assert_output "$(at '2026-11-03 03:00')"
	run upd_next_window "$(at '2026-11-02 04:10')" all 03-05
	assert_output "$(at '2026-11-02 03:00')"
	run upd_next_window "$(at '2026-11-02 06:10')" sat 22-02
	assert_output "$(at '2026-11-07 22:00')"
}

@test "update center: policy values are checked" {
	library
	upd_policy_valid patch
	upd_policy_valid off
	fails upd_policy_valid all
	upd_days_valid all
	upd_days_valid mon,sun
	fails upd_days_valid mon,xyz
	fails upd_days_valid ''
	upd_hours_valid 03-05
	upd_hours_valid 23-02
	upd_hours_valid 00-24
	fails upd_hours_valid 05-05
	fails upd_hours_valid 00-00
	fails upd_hours_valid 3-5
	fails upd_hours_valid 24-02
	upd_delay_valid 0
	upd_delay_valid 30
	fails upd_delay_valid 31
	fails upd_delay_valid -1
	run upd_random_hours
	[[ "$output" =~ ^0[1-5]-0[3-7]$ ]]
}

@test "update center: a missing policy key never installs on its own" {
	library
	run upd_policy_get UPDATE_POLICY
	assert_output notify
	run upd_policy_get UPDATE_DELAY_DAYS
	assert_output 2
}

@test "update center: the window of the old switch starts at the hour of the nightly line" {
	export UPD_CRONTAB="$TMP/crontab"
	library
	run upd_hours_at 4
	assert_output 04-06
	run upd_hours_at 22
	assert_output 22-24
	run upd_hours_at 23
	assert_output 23-01
	echo '41 4 * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all auto' > "$UPD_CRONTAB"
	run upd_policy_args patch
	assert_output 'patch all 04-06 2'
	echo '*/30 * * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all auto' > "$UPD_CRONTAB"
	run upd_policy_args patch
	assert_output --regexp '^patch all 0[1-5]-0[3-7] 2$'
	: > "$UPD_CRONTAB"
	run upd_policy_args notify
	assert_output --regexp '^notify all 0[1-5]-0[3-7] 2$'
	# Keys that are set are kept
	library UPDATE_WINDOW_DAYS=mon UPDATE_WINDOW_HOURS=03-05 UPDATE_DELAY_DAYS=4
	echo '41 4 * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all auto' > "$UPD_CRONTAB"
	run upd_policy_args patch
	assert_output 'patch mon 03-05 4'
}

@test "update center: the kind of a release" {
	library
	run upd_release_kind 2.5.0 2.5.1
	assert_output patch
	run upd_release_kind 2.5.0~alpha 2.5.0
	assert_output patch
	run upd_release_kind 2.5.1 2.6.0
	assert_output minor
	run upd_release_kind 2.5.1 3.0.0
	assert_output major
}

@test "update center: APT versions are matched with and without the codename suffix" {
	library
	offer arcpanel 2.5.1-1+ubuntu24.04 2.5.10-1+ubuntu24.04
	offer arcpanel-php 8.5.11-1-1+ubuntu24.04
	run upd_apt_version arcpanel 2.5.1
	assert_output 2.5.1-1+ubuntu24.04
	run upd_apt_version arcpanel-php 8.5.11-1-1
	assert_output 8.5.11-1-1+ubuntu24.04
	run upd_apt_version arcpanel 2.5.2
	assert_failure
}

@test "update center: every reason of a release that the policy will not install" {
	library VERSION=2.5.0 UPDATE_POLICY=patch UPDATE_WINDOW_DAYS=all UPDATE_WINDOW_HOURS=03-05 UPDATE_DELAY_DAYS=2
	UPD_NOW="$(at '2026-11-10 03:30')"
	export UPD_NOW
	reason() {
		upd_release_reason "$(release "$1" "$2" "${3:-}")" 2.5.0 "${4:-yes}"
	}
	run reason 2.5.1 2026-11-01
	assert_output ''
	run reason 2.5.1 2026-11-01 '.withdrawn = {date: "2026-11-02", reason: "broken"}'
	assert_output withdrawn
	run reason 2.6.0 2026-11-01
	assert_output attention
	run reason 2.5.1 2026-11-01 '.attention = true'
	assert_output attention
	run reason 2.5.1 2026-11-01 '.min_from = "2.5.1"'
	assert_output 'min_from 2.5.1'
	run reason 2.5.1 2026-11-01 '' no
	assert_output 'not available'
	# A release rolled back on this server is not installed on its own again
	echo "ID='3' FROM='2.5.0' TO='2.5.1' TRIGGER='policy' ACTOR='policy' START='' END='' RESULT='rolled-back' REASON='panel' TASK=''" > "$UPD_DIR/history.conf"
	run reason 2.5.1 2026-11-01
	assert_output 'rolled back'
	run reason 2.5.2 2026-11-01
	assert_output ''
	rm "$UPD_DIR/history.conf"
	run reason 2.5.1 2026-11-09
	assert_output 'delay until 2026-11-11'
	run reason 2.5.1 2026-11-09 '.security = true'
	assert_output ''
	UPD_NOW="$(at '2026-11-10 06:30')"
	run reason 2.5.1 2026-11-01
	assert_output 'outside window'
	echo "UPDATE_PAUSED='the health check failed'" >> "$ARCPANEL/conf/arcpanel.conf"
	run reason 2.5.1 2026-11-01
	assert_output paused
	sed -i "s/^UPDATE_POLICY=.*/UPDATE_POLICY='notify'/" "$ARCPANEL/conf/arcpanel.conf"
	run reason 2.5.1 2026-11-01
	assert_output policy
}

@test "update center: the target is the highest eligible release of the line" {
	library VERSION=2.5.0 UPDATE_POLICY=patch UPDATE_WINDOW_DAYS=all UPDATE_WINDOW_HOURS=03-05 UPDATE_DELAY_DAYS=2
	UPD_NOW="$(at '2026-11-10 03:30')"
	export UPD_NOW
	offer arcpanel 2.5.1-1+ubuntu24.04 2.5.2-1+ubuntu24.04 2.5.3-1+ubuntu24.04 2.6.0-1+ubuntu24.04
	mkdir -p "$UPD_DIR"
	jq -n --argjson r "[$(release 2.6.0 2026-11-01),$(release 2.5.3 2026-11-09),$(release 2.5.2 2026-11-03),$(release 2.5.1 2026-11-01),$(release 2.5.0 2026-10-30)]" \
		'{format: 1, channel: "stable", generated: "2026-11-09T10:00:00Z", releases: $r}' > "$UPD_DIR/releases.json"
	run upd_policy_target
	assert_output 2.5.2
	run bash -c "source '$REPO_ROOT/func/updates.sh'; upd_release_rows | jq -r '.[] | \"\(.version) \(.kind) \(.eligible) \(.reason)\"'"
	assert_line --index 0 '2.6.0 minor false attention'
	assert_line --index 1 '2.5.3 patch false delay until 2026-11-11'
	assert_line --index 2 '2.5.2 patch true '
	assert_line --index 3 '2.5.1 patch true '
	refute_output --partial '2.5.0 '
}

#----------------------------------------------------------#
#   Commands (on the server)                               #
#----------------------------------------------------------#

notification_ids() {
	"$SERVER_ARCPANEL/bin/v-list-user-notifications" "$ADMIN" json 2> /dev/null | grep -oE '^ *"[0-9]+"' | tr -dc '0-9
'
}

# Notifications of the administrator added since server_setup
new_notifications() {
	notification_ids | grep -cvxF -f "$TMP/notifications" || true
}

server_setup() {
	[ "$(id -u)" -eq 0 ] && [ -x "$SERVER_ARCPANEL/bin/v-list-sys-arcpanel-releases" ] || skip "needs root on an ArcPanel server"
	SERVER=yes
	cp "$CRONTAB" "$TMP/crontab"
	cp "$SERVER_ARCPANEL/conf/arcpanel.conf" "$TMP/arcpanel.conf"
	ADMIN="$(grep -m1 "^ROOT_USER=" "$SERVER_ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)"
	ADMIN="${ADMIN:-admin}"
	notification_ids > "$TMP/notifications"
	INSTALLED="$(grep -m1 "^VERSION=" "$SERVER_ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2)"
	BASE="${INSTALLED%%~*}"
	LINE="${BASE%.*}"
	if [ "$INSTALLED" = "$BASE" ]; then
		PATCH="$LINE.$((${BASE##*.} + 1))"
	else
		# 2.5.0~alpha: 2.5.0 is the next patch of the line
		PATCH="$BASE"
	fi
	PATCH2="$LINE.$((${PATCH##*.} + 1))"
	MINOR="${LINE%%.*}.$((${LINE#*.} + 1)).0"
	TODAY="$(date +%F)"
	OLD="$(date -d '-10 days' +%F)"
	"$SERVER_ARCPANEL/bin/v-change-sys-update-policy" patch all 00-24 2 > /dev/null
	offer arcpanel "$PATCH-1+ubuntu24.04" "$PATCH2-1+ubuntu24.04" "$MINOR-1+ubuntu24.04"
	offer arcpanel-nginx 1.30.5-3-1+ubuntu24.04
	offer arcpanel-php 8.5.11-1-1+ubuntu24.04
	manifest 2026-11-09T10:00:00Z "$(release "$MINOR" "$OLD")" "$(release "$PATCH2" "$TODAY")" "$(release "$PATCH" "$OLD")"
}

cmd() {
	local command="$1"
	shift
	PATH="$TMP/stubs:$PATH" "$SERVER_ARCPANEL/bin/$command" "$@"
}

@test "update center: releases come from the signed manifest with their reasons" {
	server_setup
	run cmd v-list-sys-arcpanel-releases json yes
	assert_success
	run jq -r '.[] | "\(.VERSION) \(.KIND) \(.ELIGIBLE) \(.REASON)"' <<< "$output"
	assert_line --index 0 "$MINOR minor false attention"
	assert_line --index 1 "$PATCH2 patch false delay until $(date -d "$TODAY +2 days" +%F)"
	assert_line --index 2 "$PATCH patch true "
	run cmd v-list-sys-update-policy json
	run jq -r '.MANIFEST_GENERATED, .MANIFEST_ERROR, .CHANNEL' <<< "$output"
	assert_output "$(printf '2026-11-09T10:00:00Z\n\nstable')"
	run cmd v-list-sys-arcpanel-releases
	assert_success
	assert_output --partial "$PATCH"
}

@test "update center: an older, a badly signed or a foreign manifest is refused and the stored one stays" {
	server_setup
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	manifest 2026-11-01T10:00:00Z "$(release "$PATCH" "$OLD")"
	run cmd v-list-sys-arcpanel-releases json yes
	assert_success
	run cmd v-list-sys-update-policy json
	run jq -r '.MANIFEST_GENERATED, .MANIFEST_ERROR' <<< "$output"
	assert_line --index 0 2026-11-09T10:00:00Z
	assert_line --index 1 --partial 'is older than the stored one'
	# Signed by another key
	jq '.generated = "2026-11-12T10:00:00Z"' "$TMP/repo/releases.json" > "$TMP/r.json" && mv "$TMP/r.json" "$TMP/repo/releases.json"
	rm -f "$TMP/repo/releases.json.sig"
	gpg --batch --yes --local-user "$OTHER_KEY" --detach-sign --output "$TMP/repo/releases.json.sig" "$TMP/repo/releases.json"
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	run jq -r '.MANIFEST_GENERATED + " " + .MANIFEST_ERROR' <<< "$(cmd v-list-sys-update-policy json)"
	assert_output '2026-11-09T10:00:00Z the signature of the release manifest is not valid'
	# The beta manifest on the stable channel
	jq '.channel = "beta"' "$TMP/repo/releases.json" > "$TMP/r.json" && mv "$TMP/r.json" "$TMP/repo/releases.json"
	rm -f "$TMP/repo/releases.json.sig"
	gpg --batch --yes --detach-sign --output "$TMP/repo/releases.json.sig" "$TMP/repo/releases.json"
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	run jq -r '.MANIFEST_ERROR' <<< "$(cmd v-list-sys-update-policy json)"
	assert_output 'the release manifest is not a manifest of the stable channel'
	# A tampered stored copy is not used
	jq '.releases = []' "$UPD_DIR/releases.json" > "$TMP/r.json" && cp "$TMP/r.json" "$UPD_DIR/releases.json"
	rm -rf "$TMP/repo"
	run cmd v-list-sys-arcpanel-releases json
	assert_failure
	assert_output --partial 'the signature of the release manifest is not valid'
}

@test "update center: without a manifest the list fails with the reason" {
	server_setup
	rm -f "$TMP/repo/releases.json"
	run cmd v-list-sys-arcpanel-releases json yes
	assert_failure
	assert_output --partial 'the release manifest cannot be downloaded'
}

@test "update center: the policy command writes the keys and the hourly line" {
	server_setup
	sed -i '/v-run-sys-arcpanel-autoupdate/d' "$CRONTAB"
	sed -i -e '$a41 4 * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all auto' "$CRONTAB"
	run cmd v-change-sys-update-policy patch mon,fri 23-02 5
	assert_success
	run jq -r '"\(.POLICY) \(.WINDOW_DAYS) \(.WINDOW_HOURS) \(.DELAY_DAYS)"' <<< "$(cmd v-list-sys-update-policy json)"
	assert_output 'patch mon,fri 23-02 5'
	run grep -c 'v-run-sys-arcpanel-autoupdate' "$CRONTAB"
	assert_output 1
	run grep -c 'v-update-sys-arcpanel-all' "$CRONTAB"
	assert_output 0
	[[ "$(grep 'v-run-sys-arcpanel-autoupdate' "$CRONTAB")" =~ ^[0-9]{1,2}\ \*\ \*\ \*\ \*\ sudo\ /usr/local/arcpanel/bin/v-run-sys-arcpanel-autoupdate$ ]]
	# A value left out stays
	cmd v-change-sys-update-policy notify
	run jq -r '"\(.POLICY) \(.WINDOW_DAYS) \(.WINDOW_HOURS) \(.DELAY_DAYS)"' <<< "$(cmd v-list-sys-update-policy json)"
	assert_output 'notify mon,fri 23-02 5'
	run grep -c 'v-run-sys-arcpanel-autoupdate' "$CRONTAB"
	assert_output 1
	cmd v-change-sys-update-policy off
	run grep -c 'v-run-sys-arcpanel-autoupdate' "$CRONTAB"
	assert_output 0
}

@test "update center: invalid policy values are refused" {
	server_setup
	run cmd v-change-sys-update-policy all
	assert_failure
	assert_output --partial 'invalid policy all'
	run cmd v-change-sys-update-policy patch moon
	assert_output --partial 'invalid days moon'
	run cmd v-change-sys-update-policy patch all 05-05
	assert_output --partial 'invalid hours 05-05'
	run cmd v-change-sys-update-policy patch all 03-05 31
	assert_output --partial 'invalid delay 31'
	run cmd v-list-sys-arcpanel-releases json maybe
	assert_output --partial 'invalid refresh value maybe'
	run cmd v-check-sys-arcpanel-update '2.5.1;id'
	assert_output --partial 'invalid version'
}

# Status of CHECK in the JSON pre-flight or health output
status_of() {
	jq -r --arg c "$1" '[.[] | select(.CHECK == $c) | .STATUS] | join(",")' <<< "$2"
}

@test "update center: the pre-flight passes for an eligible patch release" {
	server_setup
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	run cmd v-check-sys-arcpanel-update "$PATCH" json
	assert_success
	json="$output"
	run jq -r '[.[] | .STATUS] | unique | join(",")' <<< "$json"
	assert_output ok
	run grep -c "install arcpanel=$PATCH-1+ubuntu24.04 arcpanel-nginx=1.30.5-3-1+ubuntu24.04 arcpanel-php=8.5.11-1-1+ubuntu24.04" "$TMP/apt-get.log"
	assert_output 1
}

@test "update center: each pre-flight check refuses alone" {
	server_setup
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	# Version
	run cmd v-check-sys-arcpanel-update "$BASE" json
	[ "$INSTALLED" != "$BASE" ] || assert_equal "$(status_of version "$output")" fail
	# Manifest: not listed, withdrawn
	run cmd v-check-sys-arcpanel-update "$LINE.99" json
	assert_failure
	assert_equal "$(status_of manifest "$output")" fail
	manifest 2026-11-10T10:00:00Z "$(release "$PATCH" "$OLD" '.withdrawn = {date: "2026-11-10", reason: "breaks NGINX"}')"
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	run cmd v-check-sys-arcpanel-update "$PATCH" json
	assert_failure
	assert_equal "$(status_of manifest "$output")" fail
	assert_output --partial 'breaks NGINX'
	manifest 2026-11-11T10:00:00Z "$(release "$PATCH" "$OLD" '.min_from = "99.0.0"')"
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	run cmd v-check-sys-arcpanel-update "$PATCH" json
	assert_equal "$(status_of version "$output")" fail
	manifest 2026-11-12T10:00:00Z "$(release "$PATCH" "$OLD")"
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	# Disk
	run env UPD_MIN_FREE_KB=999999999999 PATH="$TMP/stubs:$PATH" "$SERVER_ARCPANEL/bin/v-check-sys-arcpanel-update" "$PATCH" json
	assert_failure
	assert_equal "$(status_of disk "$output")" fail,fail
	# Packages: APT refuses, APT does not offer an engine
	touch "$TMP/apt-get.fail"
	run cmd v-check-sys-arcpanel-update "$PATCH" json
	assert_failure
	assert_equal "$(status_of packages "$output")" fail
	rm -f "$TMP/apt-get.fail"
	offer arcpanel-php 8.4.0-1-1+ubuntu24.04
	run cmd v-check-sys-arcpanel-update "$PATCH" json
	assert_equal "$(status_of packages "$output")" fail
	assert_output --partial 'APT does not offer arcpanel-php 8.5.11-1-1'
	offer arcpanel-php 8.5.11-1-1+ubuntu24.04
	# Activity: a backup
	bash -c "exec -a '$SERVER_ARCPANEL/bin/v-backup-user' sleep 60" &
	BACKUP_PID=$!
	run cmd v-check-sys-arcpanel-update "$PATCH" json
	assert_failure
	assert_equal "$(status_of activity "$output")" fail
	kill "$BACKUP_PID"
	BACKUP_PID=''
	# Every check passes again
	run cmd v-check-sys-arcpanel-update "$PATCH" json
	assert_success
}

@test "update center: a minor release is a warning with its items and the manifest is a warning for a manual run only" {
	server_setup
	manifest 2026-11-10T10:00:00Z "$(release "$MINOR" "$OLD" '.attention = true | .attention_items = ["The firewall moves to nftables."]')"
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
	run cmd v-check-sys-arcpanel-update "$MINOR" json
	assert_success
	assert_equal "$(status_of attention "$output")" warn
	assert_output --partial 'The firewall moves to nftables.'
	rm -rf "$UPD_DIR"/releases.json* "$TMP/repo"
	run cmd v-check-sys-arcpanel-update "$PATCH" json manual
	assert_equal "$(status_of manifest "$output")" warn
	run cmd v-check-sys-arcpanel-update "$PATCH" json policy
	assert_failure
	assert_equal "$(status_of manifest "$output")" fail
}

# Write a baseline of the server for TARGET into $UPD_DIR/baseline-1.conf
baseline() {
	BASELINE="$UPD_DIR/baseline-1.conf"
	(
		# The libraries are not written for errexit
		set +e
		source /etc/arcpanel/arcpanel.conf
		source "$SERVER_ARCPANEL/func/main.sh"
		source "$SERVER_ARCPANEL/func/updates.sh"
		upd_baseline_write "$BASELINE" "$1"
	)
	grep -q "^TARGET='$1'" "$BASELINE"
}

health() {
	PATH="$TMP/stubs:$PATH" UPD_SERVICE_WAIT=0 UPD_PANEL_WAIT=0 "$SERVER_ARCPANEL/bin/v-check-sys-arcpanel-health" "$BASELINE" json
}

@test "update center: the health check passes against a fresh baseline" {
	server_setup
	baseline "$INSTALLED"
	grep -q "^SERVICES='.\+'" "$BASELINE"
	run health
	assert_success
	run jq -r '[.[] | .STATUS] | unique | join(",")' <<< "$output"
	assert_output ok
}

@test "update center: each health check fails alone" {
	server_setup
	baseline "$INSTALLED"
	# Services
	sed -i "s/^SERVICES='/SERVICES='arcpanel-no-such-service /" "$BASELINE"
	run health
	assert_failure
	assert_equal "$(status_of services "$output")" fail
	assert_output --partial 'arcpanel-no-such-service'
	baseline "$INSTALLED"
	# Packages
	sed -i "s/^TARGET=.*/TARGET='$LINE.99'/" "$BASELINE"
	run health
	assert_equal "$(status_of packages "$output")" fail
	baseline "$INSTALLED"
	# Sites
	echo "SITE='arcpanel-health.invalid' IP='127.0.0.1' STATUS='299'" >> "$BASELINE"
	run health
	assert_equal "$(status_of sites "$output")" fail
	assert_output --partial 'arcpanel-health.invalid'
	baseline "$INSTALLED"
	# Web servers: the configuration test of NGINX fails
	printf '#!/bin/bash\nexit 1\n' > "$TMP/stubs/nginx"
	printf '#!/bin/bash\nexit 1\n' > "$TMP/stubs/apache2ctl"
	chmod +x "$TMP/stubs/nginx" "$TMP/stubs/apache2ctl"
	run health
	assert_equal "$(status_of web "$output")" fail
	rm -f "$TMP/stubs/nginx" "$TMP/stubs/apache2ctl"
	# Panel: the login page does not answer
	printf '#!/bin/bash\nif [[ "$*" == *127.0.0.1:* ]]; then echo -n 000; exit 7; fi\nexec /usr/bin/curl "$@"\n' > "$TMP/stubs/curl"
	chmod +x "$TMP/stubs/curl"
	run health
	assert_equal "$(status_of panel "$output")" fail
	rm -f "$TMP/stubs/curl"
	run health
	assert_success
}

@test "update center: a baseline outside the folder of the Update Center is refused" {
	server_setup
	run cmd v-check-sys-arcpanel-health /etc/passwd
	assert_failure
	assert_output --partial 'invalid baseline'
	run cmd v-check-sys-arcpanel-health "$UPD_DIR/baseline-../../x.conf"
	assert_failure
}

#----------------------------------------------------------#
#   History (func/updates.sh, no server)                   #
#----------------------------------------------------------#

@test "update center: history runs are numbered, changed in place and trimmed" {
	library
	UPD_HISTORY_MAX=3
	run upd_history_add FROM=2.5.0 TO=2.5.1 TRIGGER=admin ACTOR=arcadmin RESULT=running
	assert_output 1
	upd_history_add FROM=2.5.0 TO=2.5.1 RESULT=skipped "REASON=it's late" > /dev/null
	upd_history_set 1 RESULT=done "END=2026-11-02 03:10"
	run upd_field "$(upd_history_line 1)" RESULT
	assert_output done
	run upd_field "$(upd_history_line 1)" ACTOR
	assert_output arcadmin
	run upd_field "$(upd_history_line 2)" REASON
	assert_output 'its late'
	run upd_field "$(upd_history_newest_update)" ID
	assert_output 1
	upd_history_add TO=2.5.2 RESULT=done > /dev/null
	upd_history_add TO=2.5.3 RESULT=done > /dev/null
	run wc -l < "$UPD_DIR/history.conf"
	assert_output 3
	run upd_field "$(head -n1 "$UPD_DIR/history.conf")" ID
	assert_output 2
	run upd_field "$(upd_history_newest_update)" ID
	assert_output 4
}

#----------------------------------------------------------#
#   Update task, rollback and runner (on the server)       #
#----------------------------------------------------------#

# apt-get records its arguments; an install (not a simulation) sets VERSION to
# the arcpanel version it installs, unless $TMP/no-version exists
apt_installs() {
	cat > "$TMP/stubs/apt-get" << EOF
#!/bin/bash
echo "\$*" >> $TMP/apt-get.log
[[ " \$* " == *" -s "* ]] && exit 0
[ ! -f $TMP/apt-get.fail ] || exit 100
[ ! -f $TMP/no-version ] || exit 0
for a in "\$@"; do
	case "\$a" in
		arcpanel=*) v="\${a#arcpanel=}"; sed -i "s/^VERSION=.*/VERSION='\${v%-*}'/" $SERVER_ARCPANEL/conf/arcpanel.conf ;;
	esac
done
EOF
	chmod +x "$TMP/stubs/apt-get"
}

# The manifest with the installed version, PATCH (STEPS true or false) and MINOR
task_manifest() {
	offer arcpanel "$INSTALLED-1+ubuntu24.04" "$PATCH-1+ubuntu24.04" "$MINOR-1+ubuntu24.04"
	manifest 2026-11-10T10:00:00Z "$(release "$MINOR" "$OLD")" "$(release "$PATCH" "$OLD" ".steps = ${1:-false}")" \
		"$(release "$INSTALLED" "$OLD")"
	cmd v-list-sys-arcpanel-releases json yes > /dev/null
}

# Run the steps of the update task to VERSION as the system task would
task() {
	(
		set +e
		source /etc/arcpanel/arcpanel.conf
		source "$SERVER_ARCPANEL/func/main.sh"
		source "$SERVER_ARCPANEL/func/systask.sh"
		source "$SERVER_ARCPANEL/func/updates.sh"
		export PATH="$TMP/stubs:$PATH" UPD_SERVICE_WAIT=0 UPD_PANEL_WAIT=30
		upd_task_run "$1" admin arcadmin 4242
	)
}

history_json() {
	cmd v-list-sys-update-history json
}

key() {
	grep -m1 "^$1=" "$SERVER_ARCPANEL/conf/arcpanel.conf" | cut -d"'" -f2
}

@test "update center: the update task installs, checks and records a healthy update" {
	server_setup
	apt_installs
	task_manifest
	run task "$PATCH"
	assert_success
	assert_output --partial "ArcPanel $PATCH is installed and healthy"
	run key VERSION
	assert_output "$PATCH"
	run grep -c "install -y -q .* arcpanel=$PATCH-1+ubuntu24.04 arcpanel-nginx=1.30.5-3-1+ubuntu24.04 arcpanel-php=8.5.11-1-1+ubuntu24.04$" "$TMP/apt-get.log"
	assert_output 1
	run jq -r '.[0] | "\(.FROM) \(.TO) \(.TRIGGER) \(.ACTOR) \(.RESULT) \(.TASK)"' <<< "$(history_json)"
	assert_output "$INSTALLED $PATCH admin arcadmin done 4242"
	[ -f "$UPD_DIR/snapshot-1.tar.gz" ]
	grep -q "^TARGET='$PATCH'" "$UPD_DIR/baseline-1.conf"
}

@test "update center: a failed patch without steps is rolled back and checked again" {
	server_setup
	apt_installs
	task_manifest false
	touch "$TMP/no-version"
	run task "$PATCH"
	assert_failure
	assert_output --partial "== Rollback to $INSTALLED"
	run grep -c "install -y -q .* --allow-downgrades arcpanel=$INSTALLED-1+ubuntu24.04" "$TMP/apt-get.log"
	assert_output 1
	run jq -r '.[0] | "\(.RESULT) \(.REASON)"' <<< "$(history_json)"
	assert_output --partial "rolled-back packages: ArcPanel $INSTALLED is installed instead of $PATCH"
	run key VERSION
	assert_output "$INSTALLED"
	run key UPDATE_PAUSED
	assert_output ''
	run new_notifications
	assert_output 1
}

@test "update center: a failed release with steps pauses automatic updates until resumed" {
	server_setup
	apt_installs
	task_manifest true
	touch "$TMP/apt-get.fail"
	run task "$PATCH"
	assert_failure
	assert_output --partial 'No rollback: ArcPanel'
	run jq -r '.[0].RESULT' <<< "$(history_json)"
	assert_output failed
	run key UPDATE_PAUSED
	assert_output --partial "ArcPanel $PATCH failed (failed): apt-get failed with exit code 100"
	run jq -r --arg v "$PATCH" '.[] | select(.VERSION == $v) | .REASON' <<< "$(cmd v-list-sys-arcpanel-releases json)"
	assert_output paused
	run cmd v-resume-sys-arcpanel-autoupdate
	assert_success
	run key UPDATE_PAUSED
	assert_output ''
}

@test "update center: a refused pre-flight records a skipped run and installs nothing" {
	server_setup
	apt_installs
	task_manifest
	run env UPD_MIN_FREE_KB=999999999999 bash -c "$(declare -f task); $(declare -p TMP SERVER_ARCPANEL); task $PATCH"
	assert_failure
	run jq -r '.[0] | "\(.RESULT) \(.REASON)"' <<< "$(history_json)"
	assert_output --partial 'skipped disk: less than'
	run grep -c "install -y" "$TMP/apt-get.log"
	assert_output 0
}

# Add a finished run and its snapshot: FROM TO RESULT
finished_run() {
	(
		set +e
		source /etc/arcpanel/arcpanel.conf
		source "$SERVER_ARCPANEL/func/main.sh"
		source "$SERVER_ARCPANEL/func/updates.sh"
		id="$(upd_history_add "FROM=$1" "TO=$2" TRIGGER=policy ACTOR=policy "RESULT=$3")"
		upd_snapshot "$id"
		echo "$id"
	)
}

@test "update center: a rollback by hand names each refused condition" {
	server_setup
	apt_installs
	task_manifest false
	old_id="$(finished_run "$INSTALLED" "$PATCH" done)"
	id="$(finished_run "$INSTALLED" "$PATCH" done)"
	run jq -r '.[0] | "\(.ID) \(.ROLLBACK)"' <<< "$(history_json)"
	assert_output "$id yes"
	run cmd v-rollback-sys-arcpanel "$old_id"
	assert_failure
	assert_output --partial "run $old_id is not the newest update"
	run cmd v-rollback-sys-arcpanel 999
	assert_output --partial 'run 999 does not exist'
	rm -f "$UPD_DIR/snapshot-$id.tar.gz"
	run cmd v-rollback-sys-arcpanel "$id"
	assert_output --partial "the configuration saved before run $id is missing"
	finished_run "$INSTALLED" "$PATCH" done > /dev/null
	offer arcpanel "$PATCH-1+ubuntu24.04"
	run cmd v-rollback-sys-arcpanel "$((id + 1))"
	assert_output --partial "APT does not offer arcpanel $INSTALLED"
	task_manifest true
	run cmd v-rollback-sys-arcpanel "$((id + 1))"
	assert_output --partial "ArcPanel $PATCH has upgrade steps"
	run jq -r '.[0] | "\(.ROLLBACK) \(.ROLLBACK_REASON)"' <<< "$(history_json)"
	assert_output "no ArcPanel $PATCH has upgrade steps"
	finished_run "$INSTALLED" "$MINOR" done > /dev/null
	run cmd v-rollback-sys-arcpanel "$((id + 2))"
	assert_output --partial "ArcPanel $MINOR is not a patch release of the line of $INSTALLED"
	run cmd v-rollback-sys-arcpanel x1
	assert_output --partial 'invalid run ID x1'
}

@test "update center: a rollback by hand reinstalls the previous version" {
	server_setup
	apt_installs
	task_manifest false
	id="$(finished_run "$INSTALLED" "$PATCH" done)"
	sed -i "s/^VERSION=.*/VERSION='$PATCH'/" "$SERVER_ARCPANEL/conf/arcpanel.conf"
	run cmd v-rollback-sys-arcpanel "$id"
	assert_success
	run key VERSION
	assert_output "$INSTALLED"
	run jq -r '.[0] | "\(.RESULT) \(.ROLLBACK)"' <<< "$(history_json)"
	assert_output 'rolled-back no'
}

@test "update center: the update task kind is checked" {
	server_setup
	run cmd v-start-sys-task arcpanel-release '2.5.1;id' admin
	assert_failure
	assert_output --partial 'invalid version'
	run cmd v-start-sys-task arcpanel-release 2.5.1 cron
	assert_output --partial 'invalid trigger'
	run cmd v-start-sys-task arcpanel-release 2.5.1 admin 'a b'
	assert_output --partial 'invalid actor'
	run cmd v-start-sys-task arcpanel-release 2.5.1
	assert_failure
}

@test "update center: the rollback task kind refuses a rollback before the task starts" {
	server_setup
	apt_installs
	task_manifest true
	id="$(finished_run "$INSTALLED" "$PATCH" done)"
	run cmd v-start-sys-task arcpanel-rollback "$id"
	assert_failure 10
	assert_output --partial "the rollback is not allowed: ArcPanel $PATCH has upgrade steps"
	run cmd v-start-sys-task arcpanel-rollback 999
	assert_output --partial 'the rollback is not allowed: run 999 does not exist'
	run cmd v-start-sys-task arcpanel-rollback '1;id'
	assert_failure 2
	assert_output --partial 'invalid run ID'
	run cmd v-start-sys-task arcpanel-rollback
	assert_failure 1
	run cmd v-list-sys-task json
	refute_output --partial arcpanel-rollback
}

# The runner with a stub task starter that records its arguments
runner() {
	printf '#!/bin/bash\necho "$*" >> %s/start-task.log\necho 4343\n' "$TMP" > "$TMP/stubs/start-task"
	chmod +x "$TMP/stubs/start-task"
	PATH="$TMP/stubs:$PATH" UPD_START_TASK="$TMP/stubs/start-task" "$SERVER_ARCPANEL/bin/v-run-sys-arcpanel-autoupdate"
}

@test "update center: the runner starts the update of the eligible release and announces the minor one once" {
	server_setup
	apt_installs
	task_manifest
	sed -i '/^UPDATE_NOTIFIED_VERSION=/d' "$SERVER_ARCPANEL/conf/arcpanel.conf"
	run runner
	assert_success
	assert_output ''
	run cat "$TMP/start-task.log"
	assert_output "arcpanel-release $PATCH policy policy"
	run key UPDATE_NOTIFIED_VERSION
	assert_output "$MINOR"
	run new_notifications
	assert_output 1
	runner
	run new_notifications
	assert_output 1
}

@test "update center: the runner skips a refused target once and does nothing when off, notify-only or locked" {
	server_setup
	apt_installs
	task_manifest
	run env UPD_MIN_FREE_KB=999999999999 bash -c "$(declare -f runner); $(declare -p TMP SERVER_ARCPANEL); runner; runner"
	assert_success
	run jq -r '[.[] | select(.RESULT == "skipped")] | length' <<< "$(history_json)"
	assert_output 1
	[ ! -f "$TMP/start-task.log" ]
	cmd v-change-sys-update-policy notify > /dev/null
	runner
	[ ! -f "$TMP/start-task.log" ]
	cmd v-change-sys-update-policy patch > /dev/null
	exec 8> "$UPD_DIR/runner.lock"
	flock -x 8
	runner
	exec 8>&-
	[ ! -f "$TMP/start-task.log" ]
	cmd v-change-sys-update-policy off > /dev/null
	: > "$TMP/apt-get.log"
	runner
	run cat "$TMP/apt-get.log"
	assert_output ''
}

#----------------------------------------------------------#
#   The 2.5.0 step and the old switch (§10, server)        #
#----------------------------------------------------------#

# A server as 2.4 left it: no policy keys, no runner line, and the nightly
# line of v-update-sys-arcpanel-all at TIME (none when TIME is empty)
before_250() {
	sed -i -E '/^UPDATE_(POLICY|WINDOW_DAYS|WINDOW_HOURS|DELAY_DAYS)=/d' "$SERVER_ARCPANEL/conf/arcpanel.conf"
	sed -i -e '/v-run-sys-arcpanel-autoupdate/d' -e '/v-update-sys-arcpanel-all/d' -e '/v-update-sys-arcpanel-git/d' "$CRONTAB"
	[ -z "$1" ] || sed -i -e "\$a$1 * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all auto" "$CRONTAB"
}

step_250() {
	ARCPANEL="$SERVER_ARCPANEL" bash "$REPO_ROOT/install/upgrade/versions/2.5.0.sh"
}

policy_now() {
	jq -r '"\(.POLICY) \(.WINDOW_DAYS) \(.WINDOW_HOURS) \(.DELAY_DAYS)"' <<< "$(cmd v-list-sys-update-policy json)"
}

# The cron lines of automatic updates: runner, nightly apt line, git line
cron_counts() {
	echo "$(grep -c 'v-run-sys-arcpanel-autoupdate' "$CRONTAB") $(grep -c 'v-update-sys-arcpanel-all' "$CRONTAB") $(grep -c 'v-update-sys-arcpanel-git' "$CRONTAB")"
}

@test "update center: the 2.5.0 step turns the nightly line into patch with a window at its hour, once" {
	server_setup
	before_250 '41 4'
	run step_250
	assert_success
	run policy_now
	assert_output 'patch all 04-06 2'
	run cron_counts
	assert_output '1 0 0'
	line="$(grep 'v-run-sys-arcpanel-autoupdate' "$CRONTAB")"
	step_250
	run policy_now
	assert_output 'patch all 04-06 2'
	run grep 'v-run-sys-arcpanel-autoupdate' "$CRONTAB"
	assert_output "$line"
	# A late hour wraps past midnight
	before_250 '07 23'
	step_250
	run policy_now
	assert_output 'patch all 23-01 2'
}

@test "update center: the 2.5.0 step gives notify and a random window to a server without the nightly line" {
	server_setup
	before_250 ''
	run step_250
	assert_success
	run policy_now
	assert_output --regexp '^notify all 0[1-5]-0[3-7] 2$'
	run cron_counts
	assert_output '1 0 0'
}

@test "update center: the 2.5.0 step keeps a policy that is already set" {
	server_setup
	cmd v-change-sys-update-policy off mon 03-05 4 > /dev/null
	sed -i -e '$a13 2 * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all auto' "$CRONTAB"
	step_250
	run policy_now
	assert_output 'off mon 03-05 4'
	run cron_counts
	assert_output '0 0 0'
	cmd v-change-sys-update-policy notify > /dev/null
	sed -i -e '$a13 2 * * * sudo /usr/local/arcpanel/bin/v-update-sys-arcpanel-all auto' "$CRONTAB"
	step_250
	run policy_now
	assert_output 'notify mon 03-05 4'
	run cron_counts
	assert_output '1 0 0'
}

@test "update center: the old switch turns the patch policy on and off" {
	server_setup
	before_250 ''
	run cmd v-add-cron-arcpanel-autoupdate apt
	assert_success
	run policy_now
	assert_output --regexp '^patch all 0[1-5]-0[3-7] 2$'
	run cron_counts
	assert_output '1 0 0'
	run cmd v-list-sys-arcpanel-autoupdate plain
	assert_output 'Enabled'
	# Off keeps the window and the delay, and still announces new versions
	cmd v-change-sys-update-policy patch mon,fri 22-01 5 > /dev/null
	run cmd v-delete-cron-arcpanel-autoupdate
	assert_success
	run policy_now
	assert_output 'notify mon,fri 22-01 5'
	run cron_counts
	assert_output '1 0 0'
	run cmd v-list-sys-arcpanel-autoupdate plain
	assert_output 'Disabled'
	cmd v-add-cron-arcpanel-autoupdate apt
	run policy_now
	assert_output 'patch mon,fri 22-01 5'
	# The policy off stays off
	cmd v-change-sys-update-policy off > /dev/null
	cmd v-delete-cron-arcpanel-autoupdate
	run policy_now
	assert_output 'off mon,fri 22-01 5'
	run cron_counts
	assert_output '0 0 0'
}

@test "update center: the old switch takes the window from a nightly line it finds" {
	server_setup
	before_250 '20 3'
	cmd v-add-cron-arcpanel-autoupdate
	run policy_now
	assert_output 'patch all 03-05 2'
	run cron_counts
	assert_output '1 0 0'
}

@test "update center: git updates are added only when the patch policy is off" {
	server_setup
	cmd v-change-sys-update-policy patch > /dev/null
	cmd v-add-cron-arcpanel-autoupdate git
	run cron_counts
	assert_output '1 0 0'
	cmd v-change-sys-update-policy notify > /dev/null
	cmd v-add-cron-arcpanel-autoupdate git
	cmd v-add-cron-arcpanel-autoupdate git
	run cron_counts
	assert_output '1 0 1'
	cmd v-delete-cron-arcpanel-autoupdate
	run cron_counts
	assert_output '1 0 0'
}
