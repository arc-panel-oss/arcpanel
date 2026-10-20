#!/bin/bash

#===========================================================================#
#                                                                           #
# ArcPanel updates of the panel packages (E33 Update Center phase 1).      #
# See                                                                      #
# docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md   #
# §6 (the line rule of 2.2.2) and §7 (manifest, policy and checks).        #
#                                                                           #
#===========================================================================#

# Upstream version of a package version: 2.2.2-1+ubuntu24.04 -> 2.2.2
upd_upstream_version() {
	if [[ "$1" == *-* ]]; then
		echo "${1%-*}"
	else
		echo "$1"
	fi
}

# Release line of a version: 2.2.2 -> 2.2, 2.3.0~beta.1 -> 2.3
upd_version_line() {
	local version="${1%%~*}"
	version="$(upd_upstream_version "$version")"
	echo "${version%.*}"
}

# Reads the package versions APT offers (one per line) and prints
# "<target> <newer>": the newest package version of the line of INSTALLED that
# is newer than it, and the newest upstream version of a newer line, each "-"
# when there is none. INSTALLED is an upstream version (VERSION).
upd_pick_versions() {
	local installed="$1" line candidate upstream target='' newer=''
	line="$(upd_version_line "$installed")"
	while read -r candidate; do
		[ -n "$candidate" ] || continue
		upstream="$(upd_upstream_version "$candidate")"
		dpkg --compare-versions "$upstream" gt "$installed" || continue
		if [ "$(upd_version_line "$upstream")" = "$line" ]; then
			if [ -z "$target" ] || dpkg --compare-versions "$candidate" gt "$target"; then
				target="$candidate"
			fi
		elif [ -z "$newer" ] || dpkg --compare-versions "$upstream" gt "$newer"; then
			newer="$upstream"
		fi
	done
	echo "${target:--} ${newer:--}"
}

# Whether VERSION is in the comma list LIST
upd_in_list() {
	[[ ",$2," == *",$1,"* ]]
}

#----------------------------------------------------------#
#                 Repository and manifest                  #
#----------------------------------------------------------#

# Files of the Update Center; the variables are overridden by the tests only
UPD_DIR="${UPD_DIR:-$ARCPANEL/data/updates}"
UPD_APT_LIST="${UPD_APT_LIST:-/etc/apt/sources.list.d/arcpanel.list}"
UPD_DEFAULT_KEYRING=/usr/share/keyrings/arcpanel-keyring.gpg
# A manifest older than this is downloaded again; a failed download is
# retried after UPD_RETRY seconds
UPD_MAX_AGE=3600
UPD_RETRY=300

# The time of the checks, in seconds since the epoch
upd_now() {
	echo "${UPD_NOW:-$(date +%s)}"
}

# Create the folder of the Update Center (root only)
upd_dir() {
	mkdir -p "$UPD_DIR"
	chown root:root "$UPD_DIR"
	chmod 750 "$UPD_DIR"
}

# The ArcPanel repository of the APT list: prints "<url> <keyring>"
upd_repo() {
	local line url keyring
	line="$(grep -m1 -E '^deb ' "$UPD_APT_LIST" 2> /dev/null)"
	[ -n "$line" ] || return 1
	keyring="$(sed -nE 's/.*signed-by=([^] ]+).*/\1/p' <<< "$line")"
	url="$(sed -E 's/^deb +(\[[^]]*\] +)?//' <<< "$line" | awk '{print $1}')"
	[ -n "$url" ] || return 1
	echo "${url%/} ${keyring:-$UPD_DEFAULT_KEYRING}"
}

# The channel of the repository: beta for https://apt.arcpanel.top/beta/
upd_channel() {
	local url keyring
	read -r url keyring < <(upd_repo) || true
	if [[ "$url" == */beta ]]; then
		echo beta
	else
		echo stable
	fi
}

# Value of KEY in the state of the last manifest check (manifest.conf)
upd_state_get() {
	if [ -f "$UPD_DIR/manifest.conf" ]; then
		grep -oE "(^| )$1='[^']*'" "$UPD_DIR/manifest.conf" | head -n1 | cut -d\' -f2
	fi
}

# Set CHECKED (last verified download), TRIED (last attempt) and ERROR
upd_state_set() {
	local checked tried error pair
	checked="$(upd_state_get CHECKED)"
	tried="$(upd_state_get TRIED)"
	error="$(upd_state_get ERROR)"
	for pair in "$@"; do
		case "$pair" in
			CHECKED=*) checked="${pair#*=}" ;;
			TRIED=*) tried="${pair#*=}" ;;
			ERROR=*) error="$(printf '%s' "${pair#*=}" | tr -d "'\n\r")" ;;
		esac
	done
	echo "CHECKED='$checked' TRIED='$tried' ERROR='$error'" > "$UPD_DIR/manifest.conf.$$"
	chmod 640 "$UPD_DIR/manifest.conf.$$"
	mv -f "$UPD_DIR/manifest.conf.$$" "$UPD_DIR/manifest.conf"
}

# Whether JSON with signature SIG is a manifest of CHANNEL signed by KEYRING
upd_manifest_valid() {
	local json="$1" sig="$2" keyring="$3" channel="$4"
	if ! gpgv --keyring "$keyring" "$sig" "$json" > /dev/null 2>&1; then
		UPD_ERROR="the signature of the release manifest is not valid"
		return 1
	fi
	if ! jq -e --arg channel "$channel" '.format == 1 and .channel == $channel
		and (.generated | type) == "string" and (.releases | type) == "array"' "$json" > /dev/null 2>&1; then
		UPD_ERROR="the release manifest is not a manifest of the $channel channel"
		return 1
	fi
}

# Download the manifest of the repository, verify it and store it; on a
# failure the stored copy stays and UPD_ERROR says why
upd_manifest_refresh() {
	local url keyring channel tmp new old rc=0
	UPD_ERROR=''
	upd_dir
	if ! read -r url keyring < <(upd_repo); then
		UPD_ERROR="no ArcPanel repository in $UPD_APT_LIST"
		upd_state_set "TRIED=$(upd_now)" "ERROR=$UPD_ERROR"
		return 1
	fi
	channel="$(upd_channel)"
	tmp="$(mktemp -d)"
	if ! curl -fsSL --max-time 30 -o "$tmp/releases.json" "$url/releases.json" 2> /dev/null \
		|| ! curl -fsSL --max-time 30 -o "$tmp/releases.json.sig" "$url/releases.json.sig" 2> /dev/null; then
		UPD_ERROR="the release manifest cannot be downloaded from $url"
		rc=1
	elif upd_manifest_valid "$tmp/releases.json" "$tmp/releases.json.sig" "$keyring" "$channel"; then
		new="$(jq -r '.generated' "$tmp/releases.json")"
		old=''
		if [ -f "$UPD_DIR/releases.json" ]; then
			old="$(jq -r '.generated // ""' "$UPD_DIR/releases.json" 2> /dev/null)"
		fi
		if [ -n "$old" ] && [[ "$new" < "$old" ]]; then
			# A stale copy must not hide a withdrawal (§5.4)
			UPD_ERROR="the downloaded release manifest ($new) is older than the stored one ($old)"
			rc=1
		else
			install -m 640 "$tmp/releases.json.sig" "$UPD_DIR/releases.json.sig.new"
			install -m 640 "$tmp/releases.json" "$UPD_DIR/releases.json.new"
			mv -f "$UPD_DIR/releases.json.sig.new" "$UPD_DIR/releases.json.sig"
			mv -f "$UPD_DIR/releases.json.new" "$UPD_DIR/releases.json"
		fi
	else
		rc=1
	fi
	rm -rf "$tmp"
	if [ "$rc" -eq 0 ]; then
		upd_state_set "CHECKED=$(upd_now)" "TRIED=$(upd_now)" "ERROR="
	else
		upd_state_set "TRIED=$(upd_now)" "ERROR=$UPD_ERROR"
	fi
	return "$rc"
}

# Make the verified manifest available as $UPD_DIR/releases.json. It is
# downloaded again when REFRESH is yes or the copy is older than an hour.
# Returns 1 when no verified copy exists; UPD_ERROR then, or when only the
# download failed, says why.
# shellcheck disable=SC2120 # the commands pass REFRESH
upd_manifest_load() {
	local refresh="${1:-no}" now checked tried url keyring error
	now="$(upd_now)"
	checked="$(upd_state_get CHECKED)"
	tried="$(upd_state_get TRIED)"
	UPD_ERROR=''
	if [ "$refresh" = 'yes' ] || [ ! -f "$UPD_DIR/releases.json" ] \
		|| { [ $((now - ${checked:-0})) -ge "$UPD_MAX_AGE" ] && [ $((now - ${tried:-0})) -ge "$UPD_RETRY" ]; }; then
		upd_manifest_refresh || true
	fi
	error="${UPD_ERROR:-$(upd_state_get ERROR)}"
	if [ ! -f "$UPD_DIR/releases.json" ]; then
		UPD_ERROR="${error:-the release manifest has not been downloaded}"
		return 1
	fi
	if ! read -r url keyring < <(upd_repo); then
		UPD_ERROR="no ArcPanel repository in $UPD_APT_LIST"
		return 1
	fi
	if ! upd_manifest_valid "$UPD_DIR/releases.json" "$UPD_DIR/releases.json.sig" "$keyring" "$(upd_channel)"; then
		return 1
	fi
	UPD_ERROR="$error"
}

# The manifest entry of VERSION as JSON, or nothing
upd_release() {
	jq -c --arg version "$1" '.releases[] | select(.version == $version)' "$UPD_DIR/releases.json" 2> /dev/null | head -n1
}

#----------------------------------------------------------#
#                       Versions                           #
#----------------------------------------------------------#

# The installed version of ArcPanel (VERSION of arcpanel.conf)
upd_installed() {
	local version
	version="$(grep -m1 "^VERSION=" "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null | cut -d"'" -f2)"
	echo "${version:-$(upd_upstream_version "$(dpkg-query -W -f='${Version}' arcpanel 2> /dev/null)")}"
}

# patch, minor or major: what moving from INSTALLED to VERSION is
upd_release_kind() {
	local installed="${1%%~*}" version="${2%%~*}"
	if [ "$(upd_version_line "$installed")" = "$(upd_version_line "$version")" ]; then
		echo patch
	elif [ "${installed%%.*}" = "${version%%.*}" ]; then
		echo minor
	else
		echo major
	fi
}

# The package version of PACKAGE that APT offers for VERSION, a version of
# the manifest without the codename suffix: arcpanel 2.2.2 ->
# 2.2.2-1+ubuntu24.04, arcpanel-php 8.5.11-1-1 -> 8.5.11-1-1+ubuntu24.04
upd_apt_version() {
	local package="$1" version="$2" candidate
	while read -r candidate; do
		if [ "$candidate" = "$version" ] || [ "${candidate%%+*}" = "$version" ] \
			|| [ "$(upd_upstream_version "$candidate")" = "$version" ]; then
			echo "$candidate"
			return 0
		fi
	done < <(apt-cache madison "$package" 2> /dev/null | awk -F'|' '{gsub(/ /, "", $2); print $2}')
	return 1
}

# The packages and versions to install for the release JSON: "package=version"
# per line, for arcpanel and the engines this server has; prints why and
# returns 1 when APT does not offer one
upd_release_packages() {
	local release="$1" version package engine apt_version
	version="$(jq -r '.version' <<< "$release")"
	if ! apt_version="$(upd_apt_version arcpanel "$version")"; then
		echo "APT does not offer arcpanel $version"
		return 1
	fi
	echo "arcpanel=$apt_version"
	for package in arcpanel-nginx arcpanel-php arcpanel-web-terminal; do
		# "install ok installed", or "hold ok installed" for a held package
		dpkg-query -W -f='${Status}' "$package" 2> /dev/null | grep -q ' ok installed$' || continue
		engine="$(jq -r --arg p "$package" '.engines[$p] // empty' <<< "$release")"
		[ -n "$engine" ] || continue
		if ! apt_version="$(upd_apt_version "$package" "$engine")"; then
			echo "APT does not offer $package $engine"
			return 1
		fi
		echo "$package=$apt_version"
	done
}

#----------------------------------------------------------#
#                        Policy                            #
#----------------------------------------------------------#

UPD_WEEK_DAYS="mon tue wed thu fri sat sun"

# Value of a policy key of arcpanel.conf, with its default
upd_policy_get() {
	local value
	value="$(grep -m1 "^$1=" "$ARCPANEL/conf/arcpanel.conf" 2> /dev/null | cut -d"'" -f2)"
	if [ -z "$value" ]; then
		case "$1" in
			# A server without the key never installs on its own
			UPDATE_POLICY) value=notify ;;
			UPDATE_WINDOW_DAYS) value=all ;;
			UPDATE_WINDOW_HOURS) value=02-04 ;;
			UPDATE_DELAY_DAYS) value=2 ;;
		esac
	fi
	echo "$value"
}

upd_policy_valid() {
	[[ "$1" =~ ^(off|notify|patch)$ ]]
}

upd_days_valid() {
	local day
	[ "$1" = all ] && return 0
	[ -n "$1" ] || return 1
	for day in ${1//,/ }; do
		[[ " $UPD_WEEK_DAYS " == *" $day "* ]] || return 1
	done
	[[ "$1" =~ ^[a-z]{3}(,[a-z]{3})*$ ]]
}

# HH-HH: start 00-23, end 01-24, not equal; may wrap past midnight
upd_hours_valid() {
	[[ "$1" =~ ^([01][0-9]|2[0-3])-([01][0-9]|2[0-4])$ ]] || return 1
	[ "$((10#${BASH_REMATCH[1]}))" -ne "$((10#${BASH_REMATCH[2]}))" ]
}

upd_delay_valid() {
	[[ "$1" =~ ^[0-9]{1,2}$ ]] && [ "$((10#$1))" -le 30 ]
}

# A random 2-hour window starting between 01 and 05
upd_random_hours() {
	local start=$((RANDOM % 5 + 1))
	printf '%02d-%02d\n' "$start" "$((start + 2))"
}

# Whether the time T (seconds) is inside the window DAYS HOURS. A window
# that wraps past midnight belongs to the day it starts on.
upd_in_window() {
	local t="$1" days="$2" hours="$3" start end hour day
	start=$((10#${hours%-*}))
	end=$((10#${hours#*-}))
	hour=$((10#$(date -d "@$t" +%H)))
	day="$(LC_ALL=C date -d "@$t" +%a | tr '[:upper:]' '[:lower:]')"
	if [ "$start" -lt "$end" ]; then
		[ "$hour" -ge "$start" ] && [ "$hour" -lt "$end" ] || return 1
	elif [ "$hour" -ge "$start" ]; then
		:
	elif [ "$hour" -lt "$end" ]; then
		day="$(LC_ALL=C date -d "@$((t - 86400))" +%a | tr '[:upper:]' '[:lower:]')"
	else
		return 1
	fi
	[ "$days" = all ] || upd_in_list "$day" "$days"
}

# The start of the current or next window after T, in seconds, or nothing
upd_next_window() {
	local t="$1" days="$2" hours="$3" hour_start i
	hour_start="$(date -d "$(date -d "@$t" '+%F %H:00')" +%s)"
	if upd_in_window "$t" "$days" "$hours"; then
		# Back to the start of the current window
		i=0
		while [ "$i" -lt 24 ] && upd_in_window "$((hour_start - 3600))" "$days" "$hours"; do
			hour_start=$((hour_start - 3600))
			i=$((i + 1))
		done
		echo "$hour_start"
		return 0
	fi
	for i in $(seq 1 192); do
		if upd_in_window "$((hour_start + i * 3600))" "$days" "$hours"; then
			echo "$((hour_start + i * 3600))"
			return 0
		fi
	done
}

# Why the policy would not install the release JSON now, or nothing when it
# would (§7.2). AVAILABLE is yes or no.
upd_release_reason() {
	local release="$1" installed="$2" available="$3" now version kind attention min_from security date due
	now="$(upd_now)"
	version="$(jq -r '.version' <<< "$release")"
	kind="$(upd_release_kind "$installed" "$version")"
	attention="$(jq -r '.attention' <<< "$release")"
	min_from="$(jq -r '.min_from // empty' <<< "$release")"
	security="$(jq -r '.security' <<< "$release")"
	date="$(jq -r '.date' <<< "$release")"
	if [ "$(jq -r '.withdrawn != null' <<< "$release")" = true ]; then
		echo withdrawn
	elif [ "$kind" != patch ] || [ "$attention" = true ]; then
		echo attention
	elif [ -n "$min_from" ] && dpkg --compare-versions "$installed" lt "$min_from"; then
		echo "min_from $min_from"
	elif [ "$available" != yes ]; then
		echo 'not available'
	elif [ "$(upd_policy_get UPDATE_POLICY)" != patch ]; then
		echo policy
	elif [ -n "$(upd_policy_get UPDATE_PAUSED)" ]; then
		echo paused
	else
		due=$(($(date -d "$date" +%s) + 10#$(upd_policy_get UPDATE_DELAY_DAYS) * 86400))
		if [ "$security" != true ] && [ "$now" -lt "$due" ]; then
			echo "delay until $(date -d "@$due" +%F)"
		elif ! upd_in_window "$now" "$(upd_policy_get UPDATE_WINDOW_DAYS)" "$(upd_policy_get UPDATE_WINDOW_HOURS)"; then
			echo 'outside window'
		fi
	fi
}

# The releases of the loaded manifest newer than the installed version, as a
# JSON array, newest first, each with kind, available, eligible and reason
upd_release_rows() {
	local installed release version available reason
	installed="$(upd_installed)"
	while read -r release; do
		[ -n "$release" ] || continue
		version="$(jq -r '.version' <<< "$release")"
		dpkg --compare-versions "$version" gt "$installed" || continue
		available=no
		upd_apt_version arcpanel "$version" > /dev/null && available=yes
		reason="$(upd_release_reason "$release" "$installed" "$available")"
		jq -c --arg kind "$(upd_release_kind "$installed" "$version")" --arg available "$available" \
			--arg reason "$reason" \
			'. + {kind: $kind, available: ($available == "yes"), eligible: ($reason == ""), reason: $reason}' <<< "$release"
		# The manifest lists its releases newest first (§5.4)
	done < <(jq -c '.releases[]' "$UPD_DIR/releases.json") | jq -s '.'
}

# The target of a policy run: the highest eligible release, or nothing
upd_policy_target() {
	upd_release_rows | jq -r '[.[] | select(.eligible)][0].version // empty'
}

#----------------------------------------------------------#
#                     Health baseline                      #
#----------------------------------------------------------#

# Services that v-list-sys-services reports running, one per line
upd_running_services() {
	"$BIN/v-list-sys-services" json 2> /dev/null | jq -r 'to_entries[] | select(.value.STATE == "running") | .key' 2> /dev/null
}

# HTTP status of DOMAIN through IP
upd_site_status() {
	curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$1:80:$2" "http://$1/" 2> /dev/null
}

# Up to five active web domains as "domain ip" lines
upd_sample_domains() {
	local user count=0 line
	for user in $("$BIN/v-list-users" plain 2> /dev/null | cut -f1); do
		while read -r line; do
			[ -n "$line" ] || continue
			echo "$line"
			count=$((count + 1))
			[ "$count" -lt 5 ] || return 0
		done < <("$BIN/v-list-web-domains" "$user" json 2> /dev/null \
			| jq -r 'to_entries[] | select(.value.SUSPENDED != "yes") | "\(.key) \(.value.IP)"' 2> /dev/null)
	done
}

# Write the baseline of the health check for the update to TARGET (§7.4)
upd_baseline_write() {
	local file="$1" target="$2" domain ip
	{
		echo "TARGET='$target'"
		echo "SERVICES='$(upd_running_services | tr '\n' ' ' | sed 's/ $//')'"
		while read -r domain ip; do
			[ -n "$domain" ] || continue
			echo "SITE='$domain' IP='$ip' STATUS='$(upd_site_status "$domain" "$ip")'"
		done < <(upd_sample_domains)
	} > "$file"
	chmod 640 "$file"
}

#----------------------------------------------------------#
#                        Checks                            #
#----------------------------------------------------------#

# Collect one check result: NAME STATUS (ok, warn, fail) MESSAGE
UPD_CHECKS=()
upd_check() {
	UPD_CHECKS+=("$(jq -cn --arg check "$1" --arg status "$2" --arg message "$3" \
		'{CHECK: $check, STATUS: $status, MESSAGE: $message}')")
}

# The collected checks as a JSON array
upd_checks_json() {
	if [ "${#UPD_CHECKS[@]}" -eq 0 ]; then
		echo '[]'
	else
		printf '%s\n' "${UPD_CHECKS[@]}" | jq -s '.'
	fi
}

# Whether a collected check failed
upd_checks_failed() {
	[ "$(upd_checks_json | jq '[.[] | select(.STATUS == "fail")] | length')" -gt 0 ]
}

# Print the collected checks in FORMAT
upd_checks_print() {
	case "$1" in
		json) upd_checks_json ;;
		plain) upd_checks_json | jq -r '.[] | [.CHECK, .STATUS, .MESSAGE] | @tsv' ;;
		csv)
			echo "CHECK,STATUS,MESSAGE"
			upd_checks_json | jq -r '.[] | [.CHECK, .STATUS, .MESSAGE] | @csv'
			;;
		*)
			upd_checks_json | jq -r '.[] | "\(.CHECK)\t\(.STATUS)\t\(.MESSAGE)"' \
				| awk -F'\t' 'BEGIN {printf "%-16s %-6s %s\n%-16s %-6s %s\n", "CHECK", "STATUS", "MESSAGE", "-----", "------", "-------"}
					{printf "%-16s %-6s %s\n", $1, $2, $3}'
			;;
	esac
}

#----------------------------------------------------------#
#                     Runner cron line                     #
#----------------------------------------------------------#

UPD_CRONTAB="${UPD_CRONTAB:-/var/spool/cron/crontabs/arcpanelweb}"

# Whether the hourly line of v-run-sys-arcpanel-autoupdate exists
upd_cron_installed() {
	grep -q 'v-run-sys-arcpanel-autoupdate' "$UPD_CRONTAB" 2> /dev/null
}

# Install the hourly runner line at a random minute (§7.5); it replaces the
# nightly line of v-update-sys-arcpanel-all. The file is changed in place with
# sed: the crontabs folder is sticky, so even root may not open the file of
# arcpanelweb for writing (fs.protected_regular).
upd_cron_install() {
	local changed=no
	if grep -q 'v-update-sys-arcpanel-all' "$UPD_CRONTAB" 2> /dev/null; then
		sed -i '/v-update-sys-arcpanel-all/d' "$UPD_CRONTAB"
		changed=yes
	fi
	if ! upd_cron_installed; then
		sed -i -e "\$a$((RANDOM % 60)) * * * * sudo /usr/local/arcpanel/bin/v-run-sys-arcpanel-autoupdate" "$UPD_CRONTAB"
		changed=yes
	fi
	[ "$changed" = no ] || "$BIN/v-restart-cron" > /dev/null
}

# Remove the runner line and the nightly line
upd_cron_remove() {
	if upd_cron_installed || grep -q 'v-update-sys-arcpanel-all' "$UPD_CRONTAB" 2> /dev/null; then
		sed -i -e '/v-run-sys-arcpanel-autoupdate/d' -e '/v-update-sys-arcpanel-all/d' "$UPD_CRONTAB"
		"$BIN/v-restart-cron" > /dev/null
	fi
}

#----------------------------------------------------------#
#                       Pre-flight                         #
#----------------------------------------------------------#

# Free KiB needed on the file systems of the panel and the APT cache
UPD_MIN_FREE_KB="${UPD_MIN_FREE_KB:-1048576}"

# Whether the package manager is in use: the dpkg locks are held, or APT,
# dpkg or an unattended upgrade runs (the shutdown helper of
# unattended-upgrades, which always runs, is not counted)
upd_dpkg_busy() {
	if command -v fuser > /dev/null 2>&1 && fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock > /dev/null 2>&1; then
		return 0
	fi
	pgrep '^(apt|apt-get|aptitude|dpkg)$' > /dev/null 2>&1 || pgrep -f '^/usr/bin/python3 /usr/bin/unattended-upgrade( |$)' > /dev/null 2>&1
}

# The pre-flight of an update to VERSION (§7.3) into UPD_CHECKS. MODE is
# manual or policy: a manifest that cannot be loaded is a warning for a manual
# run and a failure for a policy run.
upd_preflight() {
	local version="$1" mode="${2:-manual}" installed release='' min_from kind items path free packages='' out
	installed="$(upd_installed)"
	UPD_CHECKS=()

	# Manifest
	if ! upd_manifest_load; then
		if [ "$mode" = policy ]; then
			upd_check manifest fail "$UPD_ERROR"
		else
			upd_check manifest warn "$UPD_ERROR"
		fi
	else
		release="$(upd_release "$version")"
		if [ -z "$release" ]; then
			upd_check manifest fail "ArcPanel $version is not in the release manifest"
		elif [ "$(jq -r '.withdrawn != null' <<< "$release")" = true ]; then
			upd_check manifest fail "ArcPanel $version was withdrawn on $(jq -r '.withdrawn.date' <<< "$release"): $(jq -r '.withdrawn.reason' <<< "$release")"
		else
			upd_check manifest ok "ArcPanel $version is in the release manifest${UPD_ERROR:+ (the last download failed: $UPD_ERROR)}"
		fi
	fi

	# Version
	min_from=''
	[ -z "$release" ] || min_from="$(jq -r '.min_from // empty' <<< "$release")"
	if ! dpkg --compare-versions "$version" gt "$installed"; then
		upd_check version fail "ArcPanel $version is not newer than the installed $installed"
	elif [ -n "$min_from" ] && dpkg --compare-versions "$installed" lt "$min_from"; then
		upd_check version fail "ArcPanel $version needs at least $min_from installed first"
	else
		upd_check version ok "$installed to $version"
	fi

	# Disk
	for path in "$ARCPANEL" /var/cache/apt; do
		free="$(df -Pk "$path" 2> /dev/null | awk 'NR == 2 {print $4}')"
		if [ -z "$free" ] || [ "$free" -lt "$UPD_MIN_FREE_KB" ]; then
			upd_check disk fail "less than $((UPD_MIN_FREE_KB / 1024)) MiB free for $path"
		else
			upd_check disk ok "$((free / 1024)) MiB free for $path"
		fi
	done

	# Package manager
	if upd_dpkg_busy; then
		upd_check packages fail "another package manager is running"
	elif [ -n "$(dpkg --audit 2> /dev/null)" ]; then
		upd_check packages fail "dpkg reports broken packages (dpkg --audit)"
	elif [ -z "$release" ]; then
		if packages="$(upd_apt_version arcpanel "$version")"; then
			packages="arcpanel=$packages"
		else
			packages=''
			upd_check packages fail "APT does not offer arcpanel $version"
		fi
	elif ! packages="$(upd_release_packages "$release")"; then
		upd_check packages fail "$(tail -n1 <<< "$packages")"
		packages=''
	fi
	if [ -n "${packages:-}" ]; then
		# shellcheck disable=SC2086
		if out="$(apt-get -s -q -o Debug::NoLocking=1 install $packages 2>&1)"; then
			upd_check packages ok "APT can install $(echo $packages)"
		else
			upd_check packages fail "APT cannot install $(echo $packages): $(tail -n1 <<< "$out")"
		fi
	fi

	# Activity: another system task (not the update task itself) or a backup
	if st_task_running && ! { [ "$(st_task_get KIND)" = arcpanel-release ] \
		&& [ "$(st_task_get ARGS | awk '{print $1}')" = "$version" ]; }; then
		upd_check activity fail "another system task is running ($(st_task_get KIND))"
	elif pgrep -f "$ARCPANEL/bin/v-backup-user" > /dev/null 2>&1; then
		upd_check activity fail "a backup is running"
	else
		upd_check activity ok "no system task or backup is running"
	fi

	# Attention
	kind="$(upd_release_kind "$installed" "$version")"
	items=''
	[ -z "$release" ] || items="$(jq -r '.attention_items | join(" ")' <<< "$release")"
	if [ "$kind" != patch ]; then
		upd_check attention warn "ArcPanel $version is a $kind release; read the release notes first.${items:+ $items}"
	elif [ -n "$release" ] && [ "$(jq -r '.attention' <<< "$release")" = true ]; then
		upd_check attention warn "ArcPanel $version needs your attention.${items:+ $items}"
	else
		upd_check attention ok "a patch release of ArcPanel $(upd_version_line "$installed")"
	fi
}

#----------------------------------------------------------#
#                      Health check                        #
#----------------------------------------------------------#

# Seconds to wait for the services and the panel after an update
UPD_SERVICE_WAIT="${UPD_SERVICE_WAIT:-60}"
UPD_PANEL_WAIT="${UPD_PANEL_WAIT:-30}"

# Value of KEY in the first line of the baseline FILE that has it
upd_baseline_get() {
	grep -m1 -oE "(^| )$2='[^']*'" "$1" | cut -d\' -f2
}

# The health check after an update (§7.4) against the baseline FILE, into
# UPD_CHECKS
upd_health() {
	local file="$1" target running missing service waited code version out line domain ip before after
	UPD_CHECKS=()
	target="$(upd_baseline_get "$file" TARGET)"

	# Services that ran before run again
	waited=0
	while :; do
		running=" $(upd_running_services | tr '\n' ' ') "
		missing=''
		for service in $(upd_baseline_get "$file" SERVICES); do
			[[ "$running" == *" $service "* ]] || missing="$missing $service"
		done
		[ -n "$missing" ] && [ "$waited" -lt "$UPD_SERVICE_WAIT" ] || break
		sleep 5
		waited=$((waited + 5))
	done
	if [ -n "$missing" ]; then
		upd_check services fail "not running:$missing"
	else
		upd_check services ok "every service that ran before runs"
	fi

	# Panel
	waited=0
	while :; do
		code="$(curl -k -s -o /dev/null -w '%{http_code}' --max-time 10 "https://127.0.0.1:${BACKEND_PORT:-8083}/login" 2> /dev/null)"
		[ "$code" != 200 ] && [ "$waited" -lt "$UPD_PANEL_WAIT" ] || break
		sleep 5
		waited=$((waited + 5))
	done
	if [ "$code" = 200 ]; then
		upd_check panel ok "the panel answers its login page"
	else
		upd_check panel fail "the panel login page answers ${code:-nothing} instead of 200"
	fi

	# Web servers: the system NGINX, Apache and the panel's NGINX
	out=''
	if { [ "$WEB_SYSTEM" = nginx ] || [ "$PROXY_SYSTEM" = nginx ]; } && ! nginx -t > /dev/null 2>&1; then
		out="$out nginx"
	fi
	if [ "$WEB_SYSTEM" = apache2 ] && ! apache2ctl -t > /dev/null 2>&1; then
		out="$out apache2"
	fi
	if [ -x "$ARCPANEL/nginx/sbin/arcpanel-nginx" ] && ! "$ARCPANEL/nginx/sbin/arcpanel-nginx" -t > /dev/null 2>&1; then
		out="$out arcpanel-nginx"
	fi
	if [ -n "$out" ]; then
		upd_check web fail "the configuration test fails:$out"
	else
		upd_check web ok "the web server configurations pass their test"
	fi

	# Firewall
	if [ -n "$FIREWALL_SYSTEM" ]; then
		if out="$("$BIN/v-check-firewall" 2>&1)"; then
			upd_check firewall ok "the loaded firewall matches its configuration"
		else
			upd_check firewall fail "$(tail -n1 <<< "$out")"
		fi
	fi

	# Packages
	version="$(upd_installed)"
	if [ -n "$(dpkg --audit 2> /dev/null)" ]; then
		upd_check packages fail "dpkg reports broken packages (dpkg --audit)"
	elif [ -n "$target" ] && [ "$version" != "$target" ]; then
		upd_check packages fail "ArcPanel $version is installed instead of $target"
	else
		upd_check packages ok "ArcPanel $version is installed"
	fi

	# Sites answer as before
	out=''
	while read -r line; do
		domain="$(cut -d\' -f2 <<< "$line")"
		ip="$(grep -oE "IP='[^']*'" <<< "$line" | cut -d\' -f2)"
		before="$(grep -oE "STATUS='[^']*'" <<< "$line" | cut -d\' -f2)"
		after="$(upd_site_status "$domain" "$ip")"
		[ "$after" = "$before" ] || out="$out $domain ($before, now $after)"
	done < <(grep "^SITE=" "$file")
	if [ -n "$out" ]; then
		upd_check sites fail "answer differently:$out"
	else
		upd_check sites ok "the sample sites answer as before"
	fi
}
