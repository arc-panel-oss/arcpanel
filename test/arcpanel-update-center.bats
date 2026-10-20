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

server_setup() {
	[ "$(id -u)" -eq 0 ] && [ -x "$SERVER_ARCPANEL/bin/v-list-sys-arcpanel-releases" ] || skip "needs root on an ArcPanel server"
	SERVER=yes
	cp "$CRONTAB" "$TMP/crontab"
	cp "$SERVER_ARCPANEL/conf/arcpanel.conf" "$TMP/arcpanel.conf"
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
